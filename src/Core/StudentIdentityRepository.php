<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class StudentIdentityRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function findByExternal(string $provider, string $externalUserId): ?array
    {
        return $this->db->findWhere('STUDENTI_IDENTITA_ESTERNE', [
            'id_utente' => $this->userId,
            'provider' => $provider,
            'external_user_id' => $externalUserId,
        ])[0] ?? null;
    }

    public function listForStudent(string $studentId): array
    {
        return $this->db->findWhere('STUDENTI_IDENTITA_ESTERNE', [
            'id_utente' => $this->userId,
            'id_studente' => $studentId,
        ]);
    }

    public function attach(string $studentId, array $data): array
    {
        $provider = trim((string)($data['provider'] ?? ''));
        $externalUserId = trim((string)($data['external_user_id'] ?? ''));
        if ($provider === '' || $externalUserId === '') {
            throw new RuntimeException('provider ed external_user_id sono obbligatori');
        }
        if ($this->db->findWhere('STUDENTI', [
            'id_utente' => $this->userId,
            'id_studente' => $studentId,
        ]) === []) {
            throw new RuntimeException('studente non appartenente all’utente corrente');
        }
        $existing = $this->findByExternal($provider, $externalUserId);
        if ($existing !== null) {
            if ((string)$existing['id_studente'] !== $studentId) {
                throw new RuntimeException('identità esterna già associata a un altro studente');
            }
            return $existing;
        }
        $row = [
            'id_identita' => (string)($data['id_identita'] ?? ('SID_' . bin2hex(random_bytes(12)))),
            'id_studente' => $studentId,
            'provider' => $provider,
            'external_user_id' => $externalUserId,
            'external_context_id' => (string)($data['external_context_id'] ?? ''),
            'tipo_identificatore' => (string)($data['tipo_identificatore'] ?? 'user'),
            'stato' => (string)($data['stato'] ?? 'attivo'),
            'data_prima_associazione' => (string)($data['data_prima_associazione'] ?? date('Y-m-d H:i:s')),
            'ultima_verifica' => (string)($data['ultima_verifica'] ?? date('Y-m-d H:i:s')),
            'metadata_json' => (string)($data['metadata_json'] ?? '{}'),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('STUDENTI_IDENTITA_ESTERNE', $row);
        return $row;
    }

    public function reassign(string $identityId, string $studentId): bool
    {
        $this->assertStudentOwned($studentId);
        return $this->scopedUpdate($identityId, [
            'id_studente' => $studentId,
            'ultima_verifica' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Update the provider context without persisting provider supplied labels. */
    public function updateContext(string $provider, string $externalUserId, string $contextId): bool
    {
        $identity = $this->findByExternal($provider, $externalUserId);
        if ($identity === null) {
            return false;
        }

        return $this->scopedUpdate((string)$identity['id_identita'], [
            'external_context_id' => $contextId,
            'ultima_verifica' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Remove one externally owned identity, scoped by the identity key. */
    public function detach(string $provider, string $externalUserId): bool
    {
        $identity = $this->findByExternal($provider, $externalUserId);
        if ($identity === null) {
            return false;
        }

        return $this->scopedDelete((string)$identity['id_identita']);
    }

    private function scopedUpdate(string $identityId, array $changes): bool
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof \PDO) {
            throw new RuntimeException('mutazione identità senza ownership verificabile');
        }
        $assignments = [];
        $params = [':id_identita' => $identityId, ':id_utente' => $this->userId];
        foreach ($changes as $column => $value) {
            $assignments[] = $column . ' = :' . $column;
            $params[':' . $column] = $value;
        }
        $statement = $connection->prepare(
            'UPDATE STUDENTI_IDENTITA_ESTERNE SET ' . implode(', ', $assignments)
            . ' WHERE id_identita = :id_identita AND id_utente = :id_utente'
        );
        $statement->execute($params);
        return $statement->rowCount() > 0;
    }

    private function assertStudentOwned(string $studentId): void
    {
        if ($this->db->findWhere('STUDENTI', [
            'id_utente' => $this->userId,
            'id_studente' => $studentId,
        ]) === []) {
            throw new RuntimeException('target studente non appartenente all’utente corrente');
        }
    }

    private function scopedDelete(string $identityId): bool
    {
        $connection = $this->db->getConnection();
        if (!$connection instanceof \PDO) {
            throw new RuntimeException('cancellazione identità senza ownership verificabile');
        }
        $statement = $connection->prepare(
            'DELETE FROM STUDENTI_IDENTITA_ESTERNE WHERE id_identita = :id_identita AND id_utente = :id_utente'
        );
        $statement->execute([':id_identita' => $identityId, ':id_utente' => $this->userId]);
        return $statement->rowCount() > 0;
    }
}
