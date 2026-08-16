<?php

declare(strict_types=1);

namespace App\Core;

final class TeachingGroupService
{
    private const ALLOWED_PROVIDERS = [
        'classeviva',
        'google_classroom',
        'github_classroom',
    ];

    public function __construct(
        private TeachingGroupRepository $groups,
        private TeachingGroupIntegrationRepository $integrations
    ) {
    }

    public function createGroup(array $data): array
    {
        return $this->groups->create($data);
    }

    public function updateGroup(string $groupId, array $changes): array
    {
        $this->requireGroup($groupId);
        if (!$this->groups->update($groupId, $changes)) {
            throw new \RuntimeException('impossibile aggiornare il gruppo didattico');
        }
        $updated = $this->groups->findById($groupId);
        if ($updated === null) {
            throw new \RuntimeException('Gruppo didattico non trovato');
        }
        return $updated;
    }

    public function linkProvider(string $groupId, array $data): array
    {
        $this->requireGroup($groupId);
        $data['id_gruppo'] = $groupId;
        return $this->integrations->link($data);
    }

    public function unlinkProvider(string $groupId, string $provider): bool
    {
        if (!in_array($provider, self::ALLOWED_PROVIDERS, true)) {
            throw new \RuntimeException('provider non consentito');
        }
        $this->requireGroup($groupId);
        return $this->integrations->deactivateForGroupProvider($groupId, $provider);
    }

    private function requireGroup(string $groupId): array
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            throw new \RuntimeException('Gruppo didattico non trovato');
        }
        return $group;
    }
}
