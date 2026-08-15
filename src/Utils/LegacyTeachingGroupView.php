<?php

declare(strict_types=1);

namespace App\Utils;

final class LegacyTeachingGroupView
{
    /**
     * Facade per le pagine che chiedono ancora il catalogo CLASSI.
     * La sorgente Ã¨ il gruppo interno; gli identificativi ClasseViva restano
     * solo nell'array temporaneo restituito alla GUI.
     *
     * @return list<array<string,mixed>>
     */
    public static function classes(\App\Core\Database\DatabaseAdapterInterface $db, ?string $userId = null): array
    {
        $rows = [];
        foreach ($db->findAll('GRUPPI_DIDATTICI') as $group) {
            if ($userId !== null && (string)($group['id_utente'] ?? '') !== $userId) {
                continue;
            }
            $integrations = [];
            foreach ($db->findWhere('GRUPPI_INTEGRAZIONI', [
                'id_utente' => (string)($group['id_utente'] ?? $userId ?? ''),
                'id_gruppo' => (string)($group['id_gruppo'] ?? ''),
            ]) as $integration) {
                $integrations[] = $integration;
            }
            $cv = self::provider($integrations, 'classeviva');
            $rows[] = [
                'id_classe' => (string)($cv['external_context_id'] ?? $group['id_gruppo'] ?? ''),
                'nome' => (string)($group['nome_classe'] ?? $group['nome_gruppo'] ?? ''),
                'anno_scolastico' => (string)($group['anno_scolastico'] ?? ''),
                'sezione' => '',
                'corso' => (string)($group['nome_materia'] ?? ''),
                'attiva' => (string)($group['stato'] ?? 'attivo') === 'attivo' ? 1 : 0,
                'id_gruppo' => (string)($group['id_gruppo'] ?? ''),
                'id_utente' => (string)($group['id_utente'] ?? $userId ?? ''),
            ];
        }
        return $rows;
    }

    /** @param list<array<string,mixed>> $rows @param array<string,mixed> $where */
    public static function filter(array $rows, array $where): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if ((string)($row[$field] ?? '') !== (string)$value) {
                    return false;
                }
            }
            return true;
        }));
    }

    /**
     * Adatta il modello interno al contratto dati delle viste esistenti.
     * Le chiavi legacy vivono solo nell'array di presentazione.
     *
     * @param array<string,mixed> $assignment
     * @param array<string,mixed> $group
     * @param list<array<string,mixed>> $integrations
     * @param list<array<string,mixed>> $publications
     * @return array<string,mixed>
     */
    public static function assignment(
        array $assignment,
        array $group,
        array $integrations = [],
        array $publications = []
    ): array {
        $cv = self::provider($integrations, 'classeviva');
        $classroom = self::provider($integrations, 'google_classroom')
            ?? self::provider($publications, 'google_classroom');

        return [
            'id_assegnazione' => (string)($assignment['id_assegnazione'] ?? ''),
            'id_uda' => (string)($assignment['id_uda'] ?? ''),
            'id_utente' => (string)($assignment['id_utente'] ?? $group['id_utente'] ?? ''),
            'id_gruppo' => (string)($assignment['id_gruppo'] ?? $group['id_gruppo'] ?? ''),
            'id_classe' => (string)($cv['external_context_id'] ?? ''),
            'id_materia_cv' => (string)($cv['external_subject_id'] ?? ''),
            'nome_classe' => (string)($group['nome_classe'] ?? $group['nome_gruppo'] ?? ''),
            'nome_materia' => (string)($group['nome_materia'] ?? ''),
            'data_assegnazione' => (string)($assignment['data_assegnazione'] ?? ''),
            'data_inizio' => (string)($assignment['data_inizio'] ?? ''),
            'data_fine' => (string)($assignment['data_fine'] ?? ''),
            'note' => (string)($assignment['note'] ?? ''),
            'stato' => (string)($assignment['stato'] ?? 'assegnata'),
            'pubblicato_classroom' => $classroom !== null,
            'classroom_url' => (string)($classroom['external_url'] ?? $classroom['external_context_id'] ?? ''),
        ];
    }

    /** @param list<array<string,mixed>> $rows */
    private static function provider(array $rows, string $provider): ?array
    {
        foreach ($rows as $row) {
            if (($row['provider'] ?? '') === $provider) {
                return $row;
            }
        }
        return null;
    }
}
