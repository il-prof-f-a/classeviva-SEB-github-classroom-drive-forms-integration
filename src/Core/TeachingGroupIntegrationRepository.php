<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class TeachingGroupIntegrationRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function link(array $data): array
    {
        $provider = trim((string)($data['provider'] ?? ''));
        $context = trim((string)($data['external_context_id'] ?? ''));
        if ($provider === '' || $context === '') {
            throw new RuntimeException('provider ed external_context_id sono obbligatori');
        }
        $subject = trim((string)($data['external_subject_id'] ?? ''));
        $existing = $this->findByExternal($provider, $context, $subject === '' ? null : $subject);
        if ($existing !== null) {
            if ((string)($existing['id_gruppo'] ?? '') !== (string)($data['id_gruppo'] ?? '')) {
                throw new RuntimeException('external ID già collegato a un altro gruppo');
            }
            return $existing;
        }

        $row = [
            'id_collegamento' => (string)($data['id_collegamento'] ?? ('GIN_' . bin2hex(random_bytes(12)))),
            'id_gruppo' => (string)($data['id_gruppo'] ?? ''),
            'provider' => $provider,
            'tipo_risorsa' => (string)($data['tipo_risorsa'] ?? 'context'),
            'external_context_id' => $context,
            'external_subject_id' => $subject,
            'external_name' => (string)($data['external_name'] ?? ''),
            'principale' => (string)($data['principale'] ?? '1'),
            'stato' => (string)($data['stato'] ?? 'attivo'),
            'metadata_json' => (string)($data['metadata_json'] ?? '{}'),
            'data_creazione' => (string)($data['data_creazione'] ?? date('Y-m-d H:i:s')),
            'ultima_modifica' => (string)($data['ultima_modifica'] ?? date('Y-m-d H:i:s')),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('GRUPPI_INTEGRAZIONI', $row);
        return $row;
    }

    public function findByExternal(string $provider, string $contextId, ?string $subjectId = null): ?array
    {
        $rows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'provider' => $provider,
            'external_context_id' => $contextId,
            'external_subject_id' => $subjectId ?? '',
        ]);
        foreach ($rows as $row) {
            if (($row['stato'] ?? 'attivo') !== 'disattivo') {
                return $row;
            }
        }
        return null;
    }

    public function findByContext(string $provider, string $contextId): ?array
    {
        $rows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'provider' => $provider,
            'external_context_id' => $contextId,
        ]);
        foreach ($rows as $row) {
            if (($row['stato'] ?? 'attivo') !== 'disattivo') {
                return $row;
            }
        }
        return null;
    }

    public function listForGroup(string $groupId): array
    {
        return $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'id_gruppo' => $groupId,
        ]);
    }

    public function deactivate(string $id): bool
    {
        return $this->db->updateRow('GRUPPI_INTEGRAZIONI', 'id_collegamento', $id, [
            'stato' => 'disattivo',
            'ultima_modifica' => date('Y-m-d H:i:s'),
        ]);
    }
}
