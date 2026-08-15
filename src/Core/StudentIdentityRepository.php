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
        return $this->db->updateRow('STUDENTI_IDENTITA_ESTERNE', 'id_identita', $identityId, [
            'id_studente' => $studentId,
            'ultima_verifica' => date('Y-m-d H:i:s'),
        ]);
    }
}
