<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class UdaGroupRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function assign(string $udaId, string $groupId, array $data = []): array
    {
        $existing = $this->db->findWhere('UDA_GRUPPI', [
            'id_utente' => $this->userId,
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
        ]);
        if ($existing !== []) {
            return $existing[0];
        }

        $row = [
            'id_assegnazione' => (string)($data['id_assegnazione'] ?? ('ASSIGN_' . bin2hex(random_bytes(12)))),
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
            'data_assegnazione' => (string)($data['data_assegnazione'] ?? date('Y-m-d H:i:s')),
            'data_inizio' => (string)($data['data_inizio'] ?? ''),
            'data_fine' => (string)($data['data_fine'] ?? ''),
            'note' => (string)($data['note'] ?? ''),
            'stato' => (string)($data['stato'] ?? 'assegnata'),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('UDA_GRUPPI', $row);
        return $row;
    }

    public function listForUda(string $udaId): array
    {
        return $this->db->findWhere('UDA_GRUPPI', [
            'id_utente' => $this->userId,
            'id_uda' => $udaId,
        ]);
    }

    public function findById(string $assignmentId): ?array
    {
        $rows = $this->db->findWhere('UDA_GRUPPI', [
            'id_utente' => $this->userId,
            'id_assegnazione' => $assignmentId,
        ]);
        return $rows[0] ?? null;
    }

    public function delete(string $assignmentId): bool
    {
        if ($this->findById($assignmentId) === null) {
            return false;
        }
        return $this->db->deleteRow('UDA_GRUPPI', $assignmentId, 'id_assegnazione');
    }
}
