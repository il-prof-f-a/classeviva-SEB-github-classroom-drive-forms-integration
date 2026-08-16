<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use InvalidArgumentException;

final class TeachingGroupRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function create(array $data): array
    {
        $name = trim((string)($data['nome_gruppo'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('nome_gruppo obbligatorio');
        }

        $id = (string)($data['id_gruppo'] ?? ('GRP_' . bin2hex(random_bytes(12))));
        $row = [
            'id_gruppo' => $id,
            'nome_gruppo' => $name,
            'nome_classe' => trim((string)($data['nome_classe'] ?? '')),
            'nome_materia' => trim((string)($data['nome_materia'] ?? '')),
            'anno_scolastico' => trim((string)($data['anno_scolastico'] ?? '')),
            'descrizione' => (string)($data['descrizione'] ?? ''),
            'stato' => (string)($data['stato'] ?? 'attivo'),
            'data_creazione' => (string)($data['data_creazione'] ?? date('Y-m-d H:i:s')),
            'ultima_modifica' => (string)($data['ultima_modifica'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('GRUPPI_DIDATTICI', $row);
        return $row;
    }

    public function findById(string $id): ?array
    {
        foreach ($this->db->findWhere('GRUPPI_DIDATTICI', [
            'id_gruppo' => $id,
            'id_utente' => $this->userId,
        ]) as $row) {
            return $row;
        }
        return null;
    }

    public function listActive(): array
    {
        return $this->db->findWhere('GRUPPI_DIDATTICI', [
            'id_utente' => $this->userId,
            'stato' => 'attivo',
        ]);
    }

    /** @return list<array<string, mixed>> */
    public function listAll(): array
    {
        return $this->db->findWhere('GRUPPI_DIDATTICI', [
            'id_utente' => $this->userId,
        ]);
    }

    public function deactivate(string $id): bool
    {
        if ($this->findById($id) === null) {
            return false;
        }

        return $this->updateOwnedRow($id, [
            'stato' => 'disattivo',
            'ultima_modifica' => date('Y-m-d H:i:s'),
        ]);
    }

    public function update(string $id, array $changes): bool
    {
        if ($this->findById($id) === null) {
            return false;
        }
        $allowed = ['nome_gruppo', 'nome_classe', 'nome_materia', 'anno_scolastico', 'descrizione', 'stato', 'ultima_modifica'];
        $payload = array_intersect_key($changes, array_flip($allowed));
        if ($payload === []) {
            return false;
        }
        $payload['ultima_modifica'] = $payload['ultima_modifica'] ?? date('Y-m-d H:i:s');
        return $this->updateOwnedRow($id, $payload);
    }

    private function updateOwnedRow(string $id, array $payload): bool
    {
        if ($this->findById($id) === null) {
            return false;
        }
        $connection = $this->db->getConnection();
        if ($connection instanceof \PDO) {
            $assignments = [];
            $params = [':id_gruppo' => $id, ':id_utente' => $this->userId];
            foreach ($payload as $column => $value) {
                // All callers provide a fixed, internal allow-list of columns.
                $assignments[] = $column . ' = :' . $column;
                $params[':' . $column] = $value;
            }
            $statement = $connection->prepare(
                'UPDATE GRUPPI_DIDATTICI SET ' . implode(', ', $assignments)
                . ' WHERE id_gruppo = :id_gruppo AND id_utente = :id_utente'
            );
            $statement->execute($params);
            return $statement->rowCount() > 0;
        }

        // This domain is SQL-backed; without a composite WHERE operation an
        // adapter cannot guarantee ownership, so fail explicitly.
        return false;
    }
}
