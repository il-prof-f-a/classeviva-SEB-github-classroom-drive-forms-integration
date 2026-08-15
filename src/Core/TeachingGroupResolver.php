<?php

declare(strict_types=1);

namespace App\Core;

final class TeachingGroupResolver
{
    public function __construct(
        private TeachingGroupRepository $groups,
        private TeachingGroupIntegrationRepository $integrations
    ) {
    }

    public function resolveById(string $groupId): ?array
    {
        return $this->groups->findById($groupId);
    }

    public function resolveByExternal(string $provider, string $contextId, ?string $subjectId = null): ?array
    {
        $link = $this->integrations->findByExternal($provider, $contextId, $subjectId);
        if ($link === null) {
            return null;
        }
        return $this->groups->findById((string)$link['id_gruppo']);
    }
}
