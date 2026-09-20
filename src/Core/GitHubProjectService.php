<?php

declare(strict_types=1);

namespace App\Core;

use Throwable;

final class GitHubProjectService
{
    public function __construct(private readonly GitHubProjectGatewayInterface $gateway)
    {
    }

    /**
     * Copia e collega un Project template a ogni repository del batch.
     *
     * @param array<string,mixed> $template
     * @param list<array<string,mixed>> $repositories
     * @return list<array<string,mixed>>
     */
    public function copyAndLinkForRepositories(
        string $organization,
        array $template,
        array $repositories,
        bool $includeDraftIssues = false
    ): array {
        $organization = trim($organization);
        $templateId = trim((string)($template['id'] ?? ''));
        $templateOrganization = strtolower(trim((string)($template['organization'] ?? $template['owner'] ?? '')));
        $isTemplate = filter_var($template['is_template'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $isClosed = filter_var($template['closed'] ?? false, FILTER_VALIDATE_BOOLEAN);

        if ($organization === '' || $templateId === '') {
            throw new \InvalidArgumentException('Organizzazione o Project template mancanti.');
        }
        if ($templateOrganization !== '' && $templateOrganization !== strtolower($organization)) {
            throw new \InvalidArgumentException('Il Project template appartiene a un’altra organizzazione.');
        }
        if (!$isTemplate) {
            throw new \InvalidArgumentException('Il Project selezionato non è marcato come template.');
        }
        if ($isClosed) {
            throw new \InvalidArgumentException('Il Project template selezionato è chiuso.');
        }
        if ($repositories === []) {
            return [];
        }

        $ownerNodeId = trim($this->gateway->getOrganizationNodeId($organization));
        $teacherNodeId = trim($this->gateway->getAuthenticatedUserNodeId());
        if ($ownerNodeId === '' || $teacherNodeId === '') {
            throw new \RuntimeException('GitHub non ha restituito gli identificativi necessari per il Project.');
        }

        $created = [];
        $seenRepositories = [];
        try {
            foreach ($repositories as $repository) {
                $owner = trim((string)($repository['owner'] ?? $organization));
                $name = trim((string)($repository['name'] ?? ''));
                $repoUrl = trim((string)($repository['url'] ?? $repository['html_url'] ?? ''));
                if ($owner === '' || $name === '') {
                    throw new \InvalidArgumentException('Repository non valida per il collegamento del Project.');
                }
                $key = strtolower($owner . '/' . $name);
                if (isset($seenRepositories[$key])) {
                    throw new \InvalidArgumentException('La stessa repository non può ricevere due Project nello stesso batch.');
                }
                $seenRepositories[$key] = true;

                $repositoryNodeId = trim((string)($repository['node_id'] ?? ''));
                if ($repositoryNodeId === '') {
                    $repositoryNodeId = trim($this->gateway->getRepositoryNodeId($owner, $name));
                }
                if ($repositoryNodeId === '') {
                    throw new \RuntimeException("Node ID repository mancante per {$owner}/{$name}.");
                }

                $projectTitle = trim((string)($repository['project_title'] ?? ($name . ' — Project')));
                if ($projectTitle === '') {
                    throw new \InvalidArgumentException('Titolo Project vuoto.');
                }
                $projectTitle = function_exists('mb_substr')
                    ? mb_substr($projectTitle, 0, 255)
                    : substr($projectTitle, 0, 255);

                $copied = $this->gateway->copyProjectV2($templateId, $ownerNodeId, $projectTitle, $includeDraftIssues);
                $projectId = trim((string)($copied['id'] ?? ''));
                if ($projectId === '') {
                    throw new \RuntimeException("GitHub non ha restituito l’ID del Project copiato per {$owner}/{$name}.");
                }
                $created[] = [
                    'project_id' => $projectId,
                    'repository_id' => $repositoryNodeId,
                    'repo_url' => $repoUrl,
                    'repo_full_name' => $owner . '/' . $name,
                    'linked' => false,
                ];
                $createdIndex = array_key_last($created);

                $this->gateway->linkProjectV2ToRepository($projectId, $repositoryNodeId);
                $created[$createdIndex]['linked'] = true;
                $this->gateway->updateProjectV2Collaborators($projectId, $teacherNodeId, 'ADMIN');

                $created[$createdIndex]['project_number'] = $copied['number'] ?? null;
                $created[$createdIndex]['project_title'] = (string)($copied['title'] ?? $projectTitle);
                $created[$createdIndex]['project_url'] = (string)($copied['url'] ?? '');
            }
        } catch (Throwable $exception) {
            $this->cleanupCreatedProjects($created);
            throw $exception;
        }

        return array_map(static function (array $item): array {
            unset($item['repository_id'], $item['linked']);
            return $item;
        }, $created);
    }

    /** @param list<array<string,mixed>> $created */
    private function cleanupCreatedProjects(array $created): void
    {
        foreach (array_reverse($created) as $item) {
            $projectId = trim((string)($item['project_id'] ?? ''));
            if ($projectId === '') {
                continue;
            }
            if (!empty($item['linked'])) {
                try {
                    $this->gateway->unlinkProjectV2FromRepository(
                        $projectId,
                        (string)($item['repository_id'] ?? '')
                    );
                } catch (Throwable $cleanupException) {
                    error_log('[GitHubProjectService] Cleanup unlink fallito per ' . $projectId . ': ' . $cleanupException->getMessage());
                }
            }
            try {
                $this->gateway->deleteProjectV2($projectId);
            } catch (Throwable $cleanupException) {
                error_log('[GitHubProjectService] Cleanup delete fallito per ' . $projectId . ': ' . $cleanupException->getMessage());
            }
        }
    }
}
