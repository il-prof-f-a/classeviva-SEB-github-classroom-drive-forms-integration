<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\GitHubProjectGatewayInterface;
use App\Core\GitHubProjectService;

if (!interface_exists(GitHubProjectGatewayInterface::class)) {
    fwrite(STDERR, "FAIL: manca il contratto GitHubProjectGatewayInterface.\n");
    exit(1);
}

final class FakeGitHubProjectGateway implements GitHubProjectGatewayInterface
{
    public array $calls = [];
    public array $deleted = [];
    public array $unlinked = [];
    public ?string $failOnRepository = null;

    public function listOrganizationProjectTemplates(string $org): array { return []; }
    public function getOrganizationNodeId(string $org): string { $this->calls[] = ['organization', $org]; return 'ORG_NODE'; }
    public function getAuthenticatedUserNodeId(): string { $this->calls[] = ['user']; return 'USER_NODE'; }
    public function getRepositoryNodeId(string $owner, string $repo): string { return 'NODE_' . $repo; }
    public function copyProjectV2(string $projectId, string $ownerId, string $title, bool $includeDraftIssues = false): array
    {
        $this->calls[] = ['copy', $projectId, $ownerId, $title, $includeDraftIssues];
        return ['id' => 'PVT_' . count(array_filter($this->calls, static fn(array $call): bool => $call[0] === 'copy')), 'number' => 1, 'title' => $title, 'url' => 'https://github.com/org/projects/1'];
    }
    public function linkProjectV2ToRepository(string $projectId, string $repositoryId): array
    {
        $this->calls[] = ['link', $projectId, $repositoryId];
        if ($this->failOnRepository === $repositoryId) {
            throw new RuntimeException('link failure');
        }
        return ['repository_id' => $repositoryId];
    }
    public function updateProjectV2Collaborators(string $projectId, string $actorId, string $role = 'ADMIN'): void
    {
        $this->calls[] = ['collaborator', $projectId, $actorId, $role];
    }
    public function unlinkProjectV2FromRepository(string $projectId, string $repositoryId): void
    {
        $this->unlinked[] = [$projectId, $repositoryId];
    }
    public function deleteProjectV2(string $projectId): void
    {
        $this->deleted[] = $projectId;
    }
}

$failures = [];
$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$template = [
    'id' => 'PVT_TEMPLATE',
    'organization' => 'org',
    'is_template' => true,
    'closed' => false,
];
$repositories = [
    ['owner' => 'org', 'name' => 'repo-one', 'url' => 'https://github.com/org/repo-one', 'node_id' => 'R1'],
    ['owner' => 'org', 'name' => 'repo-two', 'url' => 'https://github.com/org/repo-two', 'node_id' => 'R2'],
];

try {
    $gateway = new FakeGitHubProjectGateway();
    $service = new GitHubProjectService($gateway);
    $instances = $service->copyAndLinkForRepositories('org', $template, $repositories);
    $assert(count($instances) === 2, 'deve creare una copia Project per ogni repository');
    $assert(($instances[0]['repo_url'] ?? '') === $repositories[0]['url'], 'la prima istanza deve conservare la repo');
    $assert(count(array_filter($gateway->calls, static fn(array $call): bool => $call[0] === 'copy')) === 2, 'le copie Project devono essere due');
    $assert(count(array_filter($gateway->calls, static fn(array $call): bool => $call[0] === 'collaborator')) === 2, 'il docente deve ricevere accesso admin a ogni copia');
    $assert(!array_filter($gateway->calls, static fn(array $call): bool => $call[0] === 'copy' && $call[4] === true), 'le draft issue non devono essere copiate di default');

    $failingGateway = new FakeGitHubProjectGateway();
    $failingGateway->failOnRepository = 'R2';
    try {
        (new GitHubProjectService($failingGateway))->copyAndLinkForRepositories('org', $template, $repositories);
        $failures[] = 'un errore di link Project deve interrompere il batch';
    } catch (RuntimeException $expected) {
        $assert($failingGateway->deleted === ['PVT_2', 'PVT_1'], 'il cleanup deve cancellare solo le copie create');
        $assert($failingGateway->unlinked === [['PVT_1', 'R1']], 'il cleanup deve scollegare solo la copia già collegata');
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: servizio Project assignment verificato.\n");
