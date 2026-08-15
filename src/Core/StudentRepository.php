<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use InvalidArgumentException;

final class StudentRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function create(array $data = []): array
    {
        $row = [
            'id_studente' => (string)($data['id_studente'] ?? ('STD_' . bin2hex(random_bytes(12)))),
            'stato' => (string)($data['stato'] ?? 'attivo'),
            'data_creazione' => (string)($data['data_creazione'] ?? date('Y-m-d H:i:s')),
            'ultima_modifica' => (string)($data['ultima_modifica'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('STUDENTI', $row);
        return $row;
    }

    public function findById(string $studentId): ?array
    {
        return $this->db->findWhere('STUDENTI', [
            'id_utente' => $this->userId,
            'id_studente' => $studentId,
        ])[0] ?? null;
    }

    public function listAll(): array
    {
        return $this->db->findWhere('STUDENTI', ['id_utente' => $this->userId]);
    }

    public function delete(string $studentId): bool
    {
        if ($this->findById($studentId) === null) {
            return false;
        }
        return $this->db->deleteRow('STUDENTI', $studentId, 'id_studente');
    }
}
