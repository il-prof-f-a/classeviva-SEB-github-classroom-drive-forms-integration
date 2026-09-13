<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use PDO;

/**
 * Persistenza dei gruppi didattici destinatari di un assignment GitHub.
 *
 * La tabella è user-scoped: il repository riceve sempre l'utente corrente e
 * applica il filtro anche quando viene usato direttamente con un adapter SQL
 * non ancora wrappato da UserScopedDatabaseAdapter.
 */
final class GitHubAssignmentGroupRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    /**
     * Sostituisce in modo idempotente i gruppi collegati a un test.
     *
     * @param list<string> $groupIds
     */
    public function replaceForTest(string $testId, array $groupIds): int
    {
        $testId = trim($testId);
        if ($testId === '') {
            throw new \InvalidArgumentException('ID test obbligatorio.');
        }
        $normalized = self::normalizeGroupIds($groupIds);
        if ($normalized === []) {
            throw new \InvalidArgumentException('È necessario indicare almeno un gruppo didattico.');
        }

        $connection = $this->db->getConnection();
        $transactional = $connection instanceof PDO && !$connection->inTransaction();
        if ($transactional) {
            $connection->beginTransaction();
        }
        try {
            $this->db->deleteWhere('TEST_GRUPPI', [
                'id_test' => $testId,
                'id_utente' => $this->userId,
            ]);
            foreach ($normalized as $groupId) {
                $this->db->insertRow('TEST_GRUPPI', [
                    'id_collegamento' => 'TESTGRP_' . bin2hex(random_bytes(10)),
                    'id_test' => $testId,
                    'id_gruppo' => $groupId,
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'id_utente' => $this->userId,
                ]);
            }
            if ($transactional) {
                $connection->commit();
            }
        } catch (\Throwable $exception) {
            if ($transactional && $connection->inTransaction()) {
                $connection->rollBack();
            }
            throw $exception;
        }

        return count($normalized);
    }

    /** @return list<array<string,mixed>> */
    public function listForTest(string $testId): array
    {
        $rows = $this->db->findWhere('TEST_GRUPPI', [
            'id_test' => trim($testId),
            'id_utente' => $this->userId,
        ]);
        usort($rows, static fn(array $a, array $b): int => strcmp(
            (string)($a['data_creazione'] ?? ''),
            (string)($b['data_creazione'] ?? '')
        ));
        return array_values($rows);
    }

    /** @return list<string> */
    public function listGroupIdsForTest(string $testId): array
    {
        $result = [];
        foreach ($this->listForTest($testId) as $row) {
            $id = trim((string)($row['id_gruppo'] ?? ''));
            if ($id !== '' && !in_array($id, $result, true)) {
                $result[] = $id;
            }
        }
        return $result;
    }

    /** @param list<mixed> $groupIds @return list<string> */
    public static function normalizeGroupIds(array $groupIds): array
    {
        $result = [];
        foreach ($groupIds as $groupId) {
            if (!is_scalar($groupId)) {
                continue;
            }
            $normalized = trim((string)$groupId);
            if ($normalized !== '' && !in_array($normalized, $result, true)) {
                $result[] = $normalized;
            }
        }
        return $result;
    }
}
