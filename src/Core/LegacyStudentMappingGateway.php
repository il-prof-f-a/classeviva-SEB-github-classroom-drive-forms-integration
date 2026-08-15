<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;

/** Compatibilità temporanea per MAPPATURA_STUDENTI: nessuna riga legacy viene persistita. */
final class LegacyStudentMappingGateway
{
    /** @return list<array<string,mixed>> */
    public static function findAll(DatabaseAdapterInterface $db, ?string $userId = null): array
    {
        $result = [];
        foreach (ProviderNeutralMappingService::legacyRows($db, 'google_classroom', $userId) as $mapping) {
            $groupId = (string)($mapping['id_gruppo'] ?? '');
            if ($groupId === '') continue;
            $memberships = $db->findWhere('GRUPPI_STUDENTI', [
                'id_utente' => (string)($mapping['id_utente'] ?? $userId ?? ''),
                'id_gruppo' => $groupId,
            ]);
            foreach ($memberships as $membership) {
                $studentId = (string)($membership['id_studente'] ?? '');
                $cvId = null;
                $googleId = null;
                foreach ($db->findWhere('STUDENTI_IDENTITA_ESTERNE', [
                    'id_utente' => (string)($mapping['id_utente'] ?? $userId ?? ''),
                    'id_studente' => $studentId,
                ]) as $identity) {
                    if (($identity['provider'] ?? '') === 'classeviva') $cvId = (string)$identity['external_user_id'];
                    if (($identity['provider'] ?? '') === 'google_classroom') $googleId = (string)$identity['external_user_id'];
                }
                if ($cvId === null || $googleId === null) continue;
                $result[] = [
                    'id_mappatura' => (string)($membership['id_iscrizione'] ?? ''),
                    'id_mapping_materia' => (string)($mapping['id_mapping'] ?? ''),
                    'id_studente_cv' => $cvId,
                    'id_studente_gc' => $googleId,
                    'external_user_id' => $googleId,
                    'data_associazione' => (string)($membership['ultima_sincronizzazione'] ?? ''),
                    'stato' => (string)($membership['stato'] ?? 'attivo'),
                    'id_utente' => (string)($mapping['id_utente'] ?? $userId ?? ''),
                ];
            }
        }
        return $result;
    }

    /** @param array<string,mixed> $where @return list<array<string,mixed>> */
    public static function findWhere(DatabaseAdapterInterface $db, array $where, ?string $userId = null): array
    {
        $rows = self::findAll($db, $userId);
        return ProviderNeutralMappingService::filterLegacyRows($rows, $where);
    }

    public static function insert(DatabaseAdapterInterface $db, array $data): bool
    {
        $userId = trim((string)($data['id_utente'] ?? 'system')) ?: 'system';
        $mappingId = (string)($data['id_mapping_materia'] ?? '');
        $mapping = null;
        foreach (ProviderNeutralMappingService::legacyRows($db, 'google_classroom', $userId) as $candidate) {
            if ((string)($candidate['id_mapping'] ?? '') === $mappingId) {
                $mapping = $candidate;
                break;
            }
        }
        if ($mapping === null) return false;
        (new StudentProviderMappingService($db, $userId))->link(
            (string)($mapping['id_gruppo'] ?? ''),
            (string)($data['id_studente_cv'] ?? ''),
            (string)($data['id_studente_gc'] ?? $data['external_user_id'] ?? ''),
            (string)($mapping['id_corso_gc'] ?? '')
        );
        return true;
    }

    public static function update(DatabaseAdapterInterface $db, string $id, array $data): bool
    {
        $existing = self::findWhere($db, ['id_mappatura' => $id], (string)($data['id_utente'] ?? ''))[0] ?? null;
        if ($existing === null) return false;
        $data['id_mapping_materia'] = $existing['id_mapping_materia'];
        return self::insert($db, array_merge($existing, $data));
    }
}
