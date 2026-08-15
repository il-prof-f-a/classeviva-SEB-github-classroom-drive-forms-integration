<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use PDO;

/** Facade per il contratto storico degli abbinamenti studenti GitHub. */
final class LegacyGithubStudentMapGateway
{
    private const CANONICAL_TABLE = 'GITHUB_ASSIGNMENT_STUDENT_LINKS';

    /** @return list<array<string,mixed>> */
    public static function findAll(DatabaseAdapterInterface $db, ?string $userId = null): array
    {
        if (!$db->sheetExists(self::CANONICAL_TABLE)) return [];
        $result = [];
        $connection = $db->getConnection();
        if (!$connection instanceof PDO) return [];
        $rawRows = $connection->query('SELECT * FROM ' . self::CANONICAL_TABLE)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rawRows as $row) {
            $owner = (string)($row['id_utente'] ?? '');
            if ($userId !== null && $owner !== $userId) continue;
            $studentId = (string)($row['id_studente'] ?? '');
            $cv = $github = '';
            foreach ($db->findWhere('STUDENTI_IDENTITA_ESTERNE', ['id_utente' => $owner, 'id_studente' => $studentId]) as $identity) {
                if (($identity['provider'] ?? '') === 'classeviva') $cv = (string)$identity['external_user_id'];
                if (($identity['provider'] ?? '') === 'github_classroom') $github = (string)$identity['external_user_id'];
            }
            $result[] = [
                'id_map' => (string)($row['id_map'] ?? ''),
                'id_assignment' => (string)($row['id_assignment'] ?? ''),
                'github_username' => $github,
                'roster_identifier' => '',
                'student_repository_url' => (string)($row['student_repository_url'] ?? ''),
                'id_studente_cv' => $cv,
                'match_confidence' => (string)($row['match_confidence'] ?? ''),
                'note' => (string)($row['note'] ?? ''),
                'data_creazione' => (string)($row['data_creazione'] ?? ''),
                'id_utente' => $owner,
            ];
        }
        return $result;
    }

    /** @param array<string,mixed> $where @return list<array<string,mixed>> */
    public static function findWhere(DatabaseAdapterInterface $db, array $where, ?string $userId = null): array
    {
        return ProviderNeutralMappingService::filterLegacyRows(self::findAll($db, $userId), $where);
    }

    public static function insert(DatabaseAdapterInterface $db, array $data): bool
    {
        $userId = trim((string)($data['id_utente'] ?? 'system')) ?: 'system';
        $cvId = trim((string)($data['id_studente_cv'] ?? ''));
        $ghId = trim((string)($data['github_username'] ?? $data['external_user_id'] ?? ''));
        if ($cvId === '' && $ghId === '') return false;
        $students = new StudentRepository($db, $userId);
        $identities = new StudentIdentityRepository($db, $userId);
        $memberships = new GroupStudentRepository($db, $userId);
        $resources = new StudentResourceRepository($db, $userId);
        $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
        $student = $ghId !== '' ? $resolver->resolveOrCreate('github_classroom', $ghId) : $resolver->resolveOrCreate('classeviva', $cvId);
        if ($cvId !== '') {
            $cvStudent = $resolver->resolveOrCreate('classeviva', $cvId);
            if ((string)$cvStudent['id_studente'] !== (string)$student['id_studente']) {
                $resolver->merge((string)$student['id_studente'], (string)$cvStudent['id_studente']);
                $student = $cvStudent;
            }
        }
        $row = [
            'id_map' => (string)($data['id_map'] ?? ('GHMAP_' . bin2hex(random_bytes(10)))),
            'id_assignment' => (string)($data['id_assignment'] ?? ''),
            'student_repository_url' => (string)($data['student_repository_url'] ?? ''),
            'id_studente' => (string)$student['id_studente'],
            'match_confidence' => (string)($data['match_confidence'] ?? ''),
            'note' => (string)($data['note'] ?? ''),
            'data_creazione' => (string)($data['data_creazione'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $userId,
        ];
        $existing = $db->findWhere(self::CANONICAL_TABLE, ['id_map' => $row['id_map']]);
        $connection = $db->getConnection();
        if (!$connection instanceof PDO) return false;
        if ($existing !== []) {
            $stmt = $connection->prepare(
                'UPDATE ' . self::CANONICAL_TABLE . ' SET id_assignment = ?, student_repository_url = ?, '
                . 'id_studente = ?, match_confidence = ?, note = ?, data_creazione = ?, id_utente = ? WHERE id_map = ?'
            );
            return $stmt->execute([
                $row['id_assignment'], $row['student_repository_url'], $row['id_studente'],
                $row['match_confidence'], $row['note'], $row['data_creazione'], $row['id_utente'], $row['id_map'],
            ]);
        }
        $stmt = $connection->prepare(
            'INSERT INTO ' . self::CANONICAL_TABLE . ' '
            . '(id_map, id_assignment, student_repository_url, id_studente, match_confidence, note, data_creazione, id_utente) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        return $stmt->execute([
            $row['id_map'], $row['id_assignment'], $row['student_repository_url'], $row['id_studente'],
            $row['match_confidence'], $row['note'], $row['data_creazione'], $row['id_utente'],
        ]);
    }

    public static function update(DatabaseAdapterInterface $db, string $id, array $data): bool
    {
        $existing = self::findWhere($db, ['id_map' => $id], (string)($data['id_utente'] ?? ''))[0] ?? null;
        if ($existing === null) return false;
        return self::insert($db, array_merge($existing, $data, ['id_map' => $id]));
    }
}
