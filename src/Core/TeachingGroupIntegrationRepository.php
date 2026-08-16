<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class TeachingGroupIntegrationRepository
{
    private const ALLOWED_PROVIDERS = [
        'classeviva',
        'google_classroom',
        'github_classroom',
    ];

    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    /** Compatibility facade for the original link API. */
    public function link(array $data): array
    {
        return $this->upsertForGroupProvider((string)($data['id_gruppo'] ?? ''), $data);
    }

    public function findForGroupProvider(string $groupId, string $provider): ?array
    {
        $this->assertProvider($provider);
        foreach ($this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'id_gruppo' => $groupId,
            'provider' => $provider,
        ]) as $row) {
            if (($row['stato'] ?? 'attivo') !== 'disattivo') {
                return $row;
            }
        }
        return null;
    }

    public function upsertForGroupProvider(string $groupId, array $data): array
    {
        $groupId = trim($groupId);
        $provider = trim((string)($data['provider'] ?? ''));
        $context = trim((string)($data['external_context_id'] ?? ''));
        if ($groupId === '' || $provider === '' || $context === '') {
            throw new RuntimeException('provider ed external_context_id sono obbligatori');
        }
        if (!in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new RuntimeException('provider non consentito');
        }

        // Un contesto esterno non può appartenere a due gruppi dello stesso utente.
        $contextRows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'provider' => $provider,
            'external_context_id' => $context,
        ]);
        foreach ($contextRows as $row) {
            if (($row['stato'] ?? 'attivo') !== 'disattivo'
                && (string)($row['id_gruppo'] ?? '') !== $groupId) {
                throw new RuntimeException('external ID gia collegato a un altro gruppo');
            }
        }
        $reusable = null;
        foreach ($contextRows as $row) {
            if (($row['stato'] ?? 'attivo') === 'disattivo') {
                $reusable = $row;
                break;
            }
        }

        $rows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'id_gruppo' => $groupId,
            'provider' => $provider,
        ]);
        $existing = null;
        foreach ($rows as $row) {
            if ((string)($row['external_context_id'] ?? '') === $context
                && ($row['stato'] ?? 'attivo') !== 'disattivo') {
                $existing = $row;
                break;
            }
        }
        if ($existing === null) {
            foreach ($rows as $row) {
                if (($row['stato'] ?? 'attivo') !== 'disattivo') {
                    $existing = $row;
                    break;
                }
            }
        }
        if ($existing === null && $reusable !== null) {
            // Reuse the inactive row itself when the group has no active row.
            $existing = $reusable;
        }
        if ($existing === null && $rows !== []) {
            $existing = $rows[0];
        }

        $now = date('Y-m-d H:i:s');
        if ($existing !== null) {
            if ($reusable !== null
                && (string)$reusable['id_collegamento'] !== (string)$existing['id_collegamento']) {
                // Free the unique external key held by an inactive historical
                // row before moving the active row to this context.
                if (!$this->deleteOwnedRow((string)$reusable['id_collegamento'])) {
                    throw new RuntimeException('impossibile rimuovere il collegamento disattivato');
                }
            }
            $changes = [
                'id_gruppo' => $groupId,
                'tipo_risorsa' => (string)($data['tipo_risorsa'] ?? $existing['tipo_risorsa'] ?? 'context'),
                'external_context_id' => $context,
                'external_subject_id' => trim((string)($data['external_subject_id'] ?? $existing['external_subject_id'] ?? '')),
                'external_name' => (string)($data['external_name'] ?? $existing['external_name'] ?? ''),
                'principale' => (string)($data['principale'] ?? $existing['principale'] ?? '1'),
                'stato' => (string)($data['stato'] ?? 'attivo'),
                'metadata_json' => (string)($data['metadata_json'] ?? $existing['metadata_json'] ?? '{}'),
                'ultima_modifica' => (string)($data['ultima_modifica'] ?? $now),
            ];
            if (!$this->updateOwnedRow((string)$existing['id_collegamento'], $changes)) {
                throw new RuntimeException('impossibile aggiornare il collegamento');
            }
            $result = array_merge($existing, $changes);
        } else {
            $result = [
                'id_collegamento' => (string)($data['id_collegamento'] ?? ('GIN_' . bin2hex(random_bytes(12)))),
                'id_gruppo' => $groupId,
                'provider' => $provider,
                'tipo_risorsa' => (string)($data['tipo_risorsa'] ?? 'context'),
                'external_context_id' => $context,
                'external_subject_id' => trim((string)($data['external_subject_id'] ?? '')),
                'external_name' => (string)($data['external_name'] ?? ''),
                'principale' => (string)($data['principale'] ?? '1'),
                'stato' => (string)($data['stato'] ?? 'attivo'),
                'metadata_json' => (string)($data['metadata_json'] ?? '{}'),
                'data_creazione' => (string)($data['data_creazione'] ?? $now),
                'ultima_modifica' => (string)($data['ultima_modifica'] ?? $now),
                'id_utente' => $this->userId,
            ];
            $this->db->insertRow('GRUPPI_INTEGRAZIONI', $result);
        }

        // Ripulisce eventuali duplicati storici lasciando un solo collegamento attivo.
        foreach ($rows as $row) {
            if ((string)($row['id_collegamento'] ?? '') === (string)$result['id_collegamento']) {
                continue;
            }
            if (($row['stato'] ?? 'attivo') !== 'disattivo') {
                if (!$this->updateOwnedRow((string)$row['id_collegamento'], [
                    'stato' => 'disattivo',
                    'ultima_modifica' => $now,
                ])) {
                    throw new RuntimeException('impossibile disattivare il collegamento duplicato');
                }
            }
        }

        return $result;
    }

    public function deactivateForGroupProvider(string $groupId, string $provider): bool
    {
        $this->assertProvider($provider);
        $active = array_values(array_filter(
            $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
                'id_utente' => $this->userId,
                'id_gruppo' => $groupId,
                'provider' => $provider,
            ]),
            static fn(array $row): bool => ($row['stato'] ?? 'attivo') !== 'disattivo'
        ));
        if ($active === []) {
            return false;
        }
        $now = date('Y-m-d H:i:s');
        foreach ($active as $row) {
            if (!$this->updateOwnedRow((string)$row['id_collegamento'], [
                'stato' => 'disattivo',
                'ultima_modifica' => $now,
            ])) {
                throw new RuntimeException('impossibile disattivare il collegamento');
            }
        }
        return true;
    }

    public function findByExternal(string $provider, string $contextId, ?string $subjectId = null): ?array
    {
        $this->assertProvider($provider);
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
        $this->assertProvider($provider);
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
        $rows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'id_collegamento' => $id,
        ]);
        if ($rows === []) {
            return false;
        }
        return $this->updateOwnedRow($id, [
            'stato' => 'disattivo',
            'ultima_modifica' => date('Y-m-d H:i:s'),
        ]);
    }

    private function updateOwnedRow(string $id, array $payload): bool
    {
        $rows = $this->db->findWhere('GRUPPI_INTEGRAZIONI', [
            'id_utente' => $this->userId,
            'id_collegamento' => $id,
        ]);
        if ($rows === []) {
            return false;
        }
        $connection = $this->db->getConnection();
        if ($connection instanceof \PDO) {
            $assignments = [];
            $params = [':id_collegamento' => $id, ':id_utente' => $this->userId];
            foreach ($payload as $column => $value) {
                // All callers provide fixed internal columns, never user identifiers.
                $assignments[] = $column . ' = :' . $column;
                $params[':' . $column] = $value;
            }
            $statement = $connection->prepare(
                'UPDATE GRUPPI_INTEGRAZIONI SET ' . implode(', ', $assignments)
                . ' WHERE id_collegamento = :id_collegamento AND id_utente = :id_utente'
            );
            $statement->execute($params);
            return $statement->rowCount() > 0;
        }

        // This domain is SQL-backed; without a composite WHERE operation an
        // adapter cannot guarantee ownership, so fail explicitly.
        return false;
    }

    private function deleteOwnedRow(string $id): bool
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof \PDO) {
            return false;
        }
        $statement = $connection->prepare(
            'DELETE FROM GRUPPI_INTEGRAZIONI WHERE id_collegamento = :id_collegamento AND id_utente = :id_utente'
        );
        $statement->execute([':id_collegamento' => $id, ':id_utente' => $this->userId]);
        return $statement->rowCount() > 0;
    }

    private function assertProvider(string $provider): void
    {
        if (!in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new RuntimeException('provider non consentito');
        }
    }
}
