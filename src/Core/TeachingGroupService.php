<?php

declare(strict_types=1);

namespace App\Core;

final class TeachingGroupService
{
    public function __construct(
        private TeachingGroupRepository $groups,
        private TeachingGroupIntegrationRepository $integrations
    ) {
    }

    public function createGroup(array $data): array
    {
        return $this->groups->create($data);
    }

    public function linkProvider(string $groupId, array $data): array
    {
        $group = $this->groups->findById($groupId);
        if ($group === null) {
            throw new \RuntimeException('Gruppo didattico non trovato');
        }
        $data['id_gruppo'] = $groupId;
        return $this->integrations->link($data);
    }
}
