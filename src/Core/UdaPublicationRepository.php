<?php

declare(strict_types=1);

namespace App\Core;

use App\Core\Database\DatabaseAdapterInterface;
use RuntimeException;

final class UdaPublicationRepository
{
    public function __construct(
        private DatabaseAdapterInterface $db,
        private string $userId
    ) {
    }

    public function publish(string $udaId, string $groupId, array $data): array
    {
        $provider = trim((string)($data['provider'] ?? ''));
        if ($provider === '') {
            throw new RuntimeException('Provider pubblicazione obbligatorio');
        }
        $row = [
            'id_pubblicazione' => (string)($data['id_pubblicazione'] ?? ('PUB_' . bin2hex(random_bytes(12)))),
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
            'provider' => $provider,
            'external_resource_id' => (string)($data['external_resource_id'] ?? ''),
            'external_url' => (string)($data['external_url'] ?? ''),
            'stato' => (string)($data['stato'] ?? 'pubblicato'),
            'data_pubblicazione' => (string)($data['data_pubblicazione'] ?? date('Y-m-d H:i:s')),
            'metadata_json' => (string)($data['metadata_json'] ?? '{}'),
            'id_utente' => $this->userId,
        ];
        $this->db->insertRow('UDA_PUBBLICAZIONI', $row);
        return $row;
    }

    public function listForUda(string $udaId): array
    {
        return $this->db->findWhere('UDA_PUBBLICAZIONI', [
            'id_utente' => $this->userId,
            'id_uda' => $udaId,
        ]);
    }
}
