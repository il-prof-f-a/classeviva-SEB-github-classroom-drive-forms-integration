<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/**
 * Adattatore di compatibilità per le pagine che usano ancora gli ID provider.
 * Gli ID esterni vengono risolti in STUDENTI_IDENTITA_ESTERNE e solo l'ID
 * interno viene scritto nelle tabelle didattiche.
 */
final class StudentReferenceGateway
{
    /** @var list<string> */
    private const TABLES = [
        'STUDENTI', 'VOTI', 'VALUTAZIONI_RUBRICA', 'VALUTAZIONI_LABORATORIO',
        'PLUSMINUS_QUEUE', 'TEST_CBM_RISPOSTE', 'GITHUB_SUBMISSIONS', 'GITHUB_REPO_LOC_SNAPSHOTS',
    ];

    public static function handles(string $table): bool
    {
        return in_array($table, self::TABLES, true);
    }

    /** @param array<string,mixed> $data @return array<string,mixed> */
    public static function normalizeWrite(DatabaseAdapterInterface $db, string $table, array $data): array
    {
        $userId = trim((string)($data['id_utente'] ?? 'system')) ?: 'system';
        $students = new StudentRepository($db, $userId);
        $identities = new StudentIdentityRepository($db, $userId);
        $memberships = new GroupStudentRepository($db, $userId);
        $resources = new StudentResourceRepository($db, $userId);
        $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
        $internalId = trim((string)($data['id_studente'] ?? ''));

        $external = [
            'classeviva' => trim((string)($data['id_studente_cv'] ?? '')),
            'google_classroom' => trim((string)($data['id_studente_gc'] ?? '')),
            'github_classroom' => trim((string)($data['github_username'] ?? $data['external_user_id'] ?? '')),
        ];
        foreach ($external as $provider => $externalId) {
            if ($externalId === '') continue;
            $student = $resolver->resolveOrCreate($provider, $externalId);
            $resolvedId = (string)$student['id_studente'];
            if ($internalId === '') {
                $internalId = $resolvedId;
            } elseif ($internalId !== $resolvedId) {
                $resolver->merge($resolvedId, $internalId);
            }
        }
        unset($data['github_username'], $data['id_studente_cv'], $data['id_studente_gc'], $data['nome_studente'], $data['email_studente']);
        if ($internalId !== '') {
            $data['id_studente'] = $internalId;
        }
        $data['id_utente'] = $userId;

        $allowed = SchemaDefinitions::getSheetColumns($table) ?? [];
        return array_intersect_key($data, array_flip($allowed));
    }

    /** @param list<array<string,mixed>> $rows @return list<array<string,mixed>> */
    public static function exposeRows(DatabaseAdapterInterface $db, string $table, array $rows): array
    {
        if (!self::handles($table)) return $rows;
        $result = [];
        foreach ($rows as $row) {
            $userId = (string)($row['id_utente'] ?? '');
            $studentId = (string)($row['id_studente'] ?? '');
            if ($studentId !== '' && $userId !== '') {
                foreach ($db->findWhere('STUDENTI_IDENTITA_ESTERNE', [
                    'id_utente' => $userId,
                    'id_studente' => $studentId,
                ]) as $identity) {
                    $provider = (string)($identity['provider'] ?? '');
                    $externalId = (string)($identity['external_user_id'] ?? '');
                    if ($provider === 'classeviva') $row['id_studente_cv'] = $externalId;
                    if ($provider === 'google_classroom') $row['id_studente_gc'] = $externalId;
                    if ($provider === 'github_classroom') $row['github_username'] = $externalId;
                }
            }
            $result[] = $row;
        }
        return $result;
    }

    /** @param array<string,mixed> $where */
    public static function hasExternalCriteria(array $where): bool
    {
        return (bool)array_intersect(array_keys($where), [
            'id_studente_cv', 'id_studente_gc', 'github_username', 'nome_studente', 'email_studente'
        ]);
    }

    /** @param array<string,mixed> $rows @param array<string,mixed> $where @return list<array<string,mixed>> */
    public static function filterExposed(array $rows, array $where): array
    {
        return array_values(array_filter($rows, static function (array $row) use ($where): bool {
            foreach ($where as $field => $value) {
                if ((string)($row[$field] ?? '') !== (string)$value) return false;
            }
            return true;
        }));
    }
}
