<?php

declare(strict_types=1);

namespace App\Core;

interface GitHubProjectGatewayInterface
{
    /** @return list<array<string,mixed>> */
    public function listOrganizationProjectTemplates(string $org): array;

    public function getOrganizationNodeId(string $org): string;

    public function getAuthenticatedUserNodeId(): string;

    public function getRepositoryNodeId(string $owner, string $repo): string;

    /** @return array<string,mixed> */
    public function copyProjectV2(string $projectId, string $ownerId, string $title, bool $includeDraftIssues = false): array;

    /** @return array<string,mixed> */
    public function linkProjectV2ToRepository(string $projectId, string $repositoryId): array;

    public function updateProjectV2Collaborators(string $projectId, string $actorId, string $role = 'ADMIN'): void;

    public function unlinkProjectV2FromRepository(string $projectId, string $repositoryId): void;

    public function deleteProjectV2(string $projectId): void;
}
