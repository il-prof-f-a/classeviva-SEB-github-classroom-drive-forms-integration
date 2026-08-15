<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class StudentResourceRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function attach(string $studentId, array $data): array
    {
        $provider = trim((string)($data['provider'] ?? ''));
        $context = trim((string)($data['external_context_id'] ?? ''));
        $resource = trim((string)($data['external_resource_id'] ?? ''));
        if ($provider === '' || $context === '' || $resource === '') {
            throw new RuntimeException('provider, contesto e risorsa esterna sono obbligatori');
        }
        $where = [
            'id_utente' => $this->userId,
            'provider' => $provider,
            'external_context_id' => $context,
            'external_resource_id' => $resource,
        ];
        $existing = $this->db->findWhere('STUDENTI_RISORSE_ESTERNE', $where);
        if ($existing !== []) {
            return $existing[0];
        }
        $row = [
            'id_risorsa' => (string)($data['id_risorsa'] ?? ('RES_' . bin2hex(random_bytes(12)))),
            'id_studente' => $studentId,
            'provider' => $provider,
            'external_context_id' => $context,
            'external_resource_id' => $resource,
            'external_url' => (string)($data['external_url'] ?? ''),
            'tipo_risorsa' => (string)($data['tipo_risorsa'] ?? 'resource'),
            'metadata_json' => (string)($data['metadata_json'] ?? '{}'),
            'data_creazione' => (string)($data['data_creazione'] ?? date('Y-m-d H:i:s')),
            'ultima_modifica' => (string)($data['ultima_modifica'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('STUDENTI_RISORSE_ESTERNE', $row);
        return $row;
    }

    public function listForStudent(string $studentId): array
    {
        return $this->db->findWhere('STUDENTI_RISORSE_ESTERNE', [
            'id_utente' => $this->userId,
            'id_studente' => $studentId,
        ]);
    }

    public function reassign(string $resourceId, string $studentId): bool
    {
        return $this->db->updateRow('STUDENTI_RISORSE_ESTERNE', 'id_risorsa', $resourceId, ['id_studente' => $studentId]);
    }
}
