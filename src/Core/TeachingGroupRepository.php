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

    public function update(string $id, array $changes): bool
    {
        $allowed = ['nome_gruppo', 'nome_classe', 'nome_materia', 'anno_scolastico', 'descrizione', 'stato', 'ultima_modifica'];
        $payload = array_intersect_key($changes, array_flip($allowed));
        if ($payload === []) {
            return false;
        }
        $payload['ultima_modifica'] = $payload['ultima_modifica'] ?? date('Y-m-d H:i:s');
        return $this->db->updateRow('GRUPPI_DIDATTICI', 'id_gruppo', $id, $payload);
    }
}
