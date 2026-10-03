<?php

namespace App\Integration;

use App\Core\GitHubProjectGatewayInterface;
use App\Core\GitHubBlameLocAttributor;
use App\Core\Security\DiagnosticsLogger;

/**
 * GitHub Integration
 *
 * Gestisce l'autenticazione OAuth e le chiamate API a GitHub/GitHub Classroom
 */
class GitHubIntegration implements GitHubProjectGatewayInterface
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;
    /** @var array<string,mixed>|null */
    private ?array $diagnosticsContext = null;

    public function __construct($config)
    {
        // Le credenziali vengono lette prima dalla configurazione per utente
        // e poi dalle variabili d'ambiente come fallback legacy.
        $this->clientId = $config['github']['client_id'] ?? ($_ENV['GITHUB_CLIENT_ID'] ?? null);
        $this->clientSecret = $config['github']['client_secret'] ?? ($_ENV['GITHUB_CLIENT_SECRET'] ?? null);
        $this->redirectUri = app_url('public/github_callback.php');
    }

    /**
     * Genera URL per autorizzazione OAuth GitHub
     */
    public function getAuthorizationUrl($state = null, $returnTo = null, $scopes = 'read:user read:org repo user:email admin:org project')
    {
        if (!$state) {
            $state = bin2hex(random_bytes(16));
        }

        $_SESSION['github_oauth_state'] = $state;
        if ($returnTo) {
            $_SESSION['github_oauth_return_to'] = $returnTo;
        } else {
            unset($_SESSION['github_oauth_return_to']);
        }

        $params = http_build_query([
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'scope' => $scopes,
            'state' => $state
        ]);

        return "https://github.com/login/oauth/authorize?{$params}";
    }

    /**
     * Scambia il code OAuth per un access token
     */
    public function exchangeCodeForToken($code)
    {
        $ch = curl_init('https://github.com/login/oauth/access_token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Accept: application/json']);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'redirect_uri' => $this->redirectUri
        ]));

        $response = json_decode(curl_exec($ch), true);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200 || !isset($response['access_token'])) {
            throw new \Exception('Errore nell\'ottenere il token GitHub: ' . ($response['error_description'] ?? 'Unknown error'));
        }

        return $response['access_token'];
    }

    /**
     * Imposta l'access token per le chiamate API
     */
    public function setAccessToken($token)
    {
        $this->accessToken = $token;
    }

    /**
     * Attiva il tracciamento strutturato solo per la richiesta corrente della
     * review. Il contesto non contiene mai il token GitHub.
     *
     * @param array<string,mixed> $context
     */
    public function setDiagnosticsContext(string $requestId, string $action, array $context = []): void
    {
        $this->diagnosticsContext = array_merge([
            'request_id' => $requestId,
            'action' => $action,
        ], $context);
    }

    /** @param array<string,mixed> $context */
    private function diagnosticLog(string $event, array $context = []): void
    {
        if ($this->diagnosticsContext === null) {
            return;
        }
        DiagnosticsLogger::log(
            'github_review',
            $event,
            array_merge($this->diagnosticsContext, $context)
        );
    }

    /**
     * Ottiene informazioni sull'utente autenticato
     */
    public function getUser()
    {
        return $this->apiRequest('GET', '/user');
    }

    /**
     * Metadati di un repository, inclusa l'opzione GitHub "Template repository".
     *
     * L'endpoint /generate accetta esclusivamente repository marcati come
     * template: il controllo viene esposto al flusso di creazione assignment
     * per evitare di salvare link studenti senza repository.
     */
    public function getRepository($owner, $repo): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}");
    }

    /**
     * Profilo pubblico GitHub di un utente (login -> nome reale).
     * Usato per mostrare il nome degli studenti senza persistirlo.
     */
    public function getUserByLogin(string $login)
    {
        $response = $this->apiRequest('GET', '/users/' . rawurlencode($login));
        return is_array($response) ? $response : null;
    }

    /**
     * Elenco commit di un repository
     */
    public function listRepoCommits($owner, $repo, $since = null, $until = null, $perPage = 20, $page = 1)
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $params = [
            'per_page' => $perPage,
            'page' => $page
        ];
        if ($since) {
            $params['since'] = $since;
        }
        if ($until) {
            $params['until'] = $until;
        }
        return $this->apiRequest('GET', "/repos/{$owner}/{$repo}/commits", null, $params);
    }

    /**
     * Restituisce tutti i commit disponibili entro un limite di pagine. Il
     * limite evita di bruciare la quota API per repository molto grandi.
     */
    public function listRepoCommitsAll($owner, $repo, $perPage = 100, $maxPages = 20): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return $this->collectPages(
            fn (int $page): array => (array)$this->listRepoCommits($owner, $repo, null, null, $perPage, $page),
            $perPage,
            $maxPages
        );
    }

    /**
     * Issue della repository. `state=all` consente di mostrare anche la data
     * di chiusura; le pull request vengono filtrate dal normalizzatore della
     * Review perché GitHub le espone anche dall'endpoint Issues.
     */
    public function listRepoIssues($owner, $repo, $state = 'all', $perPage = 100, $page = 1): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $state = in_array($state, ['open', 'closed', 'all'], true) ? $state : 'all';
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/issues", null, [
            'state' => $state,
            'sort' => 'created',
            'direction' => 'asc',
            'per_page' => min(100, max(1, (int)$perPage)),
            'page' => max(1, (int)$page),
        ]);
    }

    public function listRepoIssuesAll($owner, $repo, $state = 'all', $perPage = 100, $maxPages = 10): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return $this->collectPages(
            fn (int $page): array => $this->listRepoIssues($owner, $repo, $state, $perPage, $page),
            $perPage,
            $maxPages
        );
    }

    /** Timeline di un issue; include gli eventi `referenced` con commit_id. */
    public function listIssueTimeline($owner, $repo, $issueNumber, $perPage = 100, $page = 1): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $issueNumber = $this->validatedIssueNumber($issueNumber);
        return (array)$this->apiRequest(
            'GET',
            "/repos/{$owner}/{$repo}/issues/{$issueNumber}/timeline",
            null,
            [
                'per_page' => min(100, max(1, (int)$perPage)),
                'page' => max(1, (int)$page),
            ]
        );
    }

    public function listIssueTimelineAll($owner, $repo, $issueNumber, $perPage = 100, $maxPages = 5): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $issueNumber = $this->validatedIssueNumber($issueNumber);
        return $this->collectPages(
            fn (int $page): array => $this->listIssueTimeline($owner, $repo, $issueNumber, $perPage, $page),
            $perPage,
            $maxPages
        );
    }

    /** Branch presenti nella repository. */
    public function listRepoBranches($owner, $repo, $perPage = 100, $page = 1): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/branches", null, [
            'per_page' => min(100, max(1, (int)$perPage)),
            'page' => max(1, (int)$page),
        ]);
    }

    public function listRepoBranchesAll($owner, $repo, $perPage = 100, $maxPages = 10): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return $this->collectPages(
            fn (int $page): array => $this->listRepoBranches($owner, $repo, $perPage, $page),
            $perPage,
            $maxPages
        );
    }

    /** Dettagli di un branch, incluso il commit HEAD. */
    public function getBranch(string $owner, string $repo, string $branch): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $branch = $this->validatedBranch($branch);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/branches/{$branch}");
    }

    /**
     * Confronta due ref GitHub. L'endpoint restituisce i commit presenti in
     * head ma non nella base, utili per individuare l'origine di un branch.
     */
    public function compareCommits(string $owner, string $repo, string $base, string $head): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $base = $this->validatedBranch($base);
        $head = $this->validatedBranch($head);
        return (array)$this->apiRequest(
            'GET',
            "/repos/{$owner}/{$repo}/compare/{$base}...{$head}"
        );
    }

    /** Albero completo della repository per un ref. */
    public function getRepositoryTree(string $owner, string $repo, string $ref): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $ref = $this->validatedBranch($ref);
        return (array)$this->apiRequest(
            'GET',
            "/repos/{$owner}/{$repo}/git/trees/{$ref}",
            null,
            ['recursive' => '1']
        );
    }

    /**
     * Contenuto testuale di un blob GitHub.
     *
     * L'endpoint Git Data usa base64 per i blob; la decodifica resta qui così
     * i chiamanti non devono conoscere il formato di trasporto dell'API.
     */
    public function getRepositoryBlob(string $owner, string $repo, string $sha): string
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $sha = $this->validatedSha($sha);
        $blob = (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/git/blobs/{$sha}");
        $encoding = strtolower(trim((string)($blob['encoding'] ?? '')));
        $content = (string)($blob['content'] ?? '');
        if ($encoding === 'base64') {
            $decoded = base64_decode((string)preg_replace('/\s+/', '', $content), true);
            if ($decoded === false) {
                throw new \RuntimeException('Blob GitHub non decodificabile.');
            }
            return $decoded;
        }
        if ($encoding === 'utf-8' || $encoding === '') {
            return $content;
        }
        throw new \RuntimeException('Encoding blob GitHub non supportato.');
    }

    /** Tag della repository con lo SHA del commit puntato. */
    public function listRepoTags($owner, $repo, $perPage = 100, $page = 1): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/tags", null, [
            'per_page' => min(100, max(1, (int)$perPage)),
            'page' => max(1, (int)$page),
        ]);
    }

    public function listRepoTagsAll($owner, $repo, $perPage = 100, $maxPages = 10): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        return $this->collectPages(
            fn (int $page): array => $this->listRepoTags($owner, $repo, $perPage, $page),
            $perPage,
            $maxPages
        );
    }

    /** Branch che hanno esattamente questo commit come HEAD. */
    public function listCommitBranches($owner, $repo, $sha): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $sha = $this->validatedSha($sha);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/commits/{$sha}/branches-where-head");
    }

    /** Pull request associate a un commit, utili per ricavare head.ref. */
    public function listCommitPullRequests($owner, $repo, $sha): array
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $sha = $this->validatedSha($sha);
        return (array)$this->apiRequest('GET', "/repos/{$owner}/{$repo}/commits/{$sha}/pulls");
    }

    /**
     * Dettagli di un commit (include files e stats)
     */
    public function getCommit($owner, $repo, $sha)
    {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $sha = $this->validatedSha($sha);
        return $this->apiRequest('GET', "/repos/{$owner}/{$repo}/commits/{$sha}");
    }

    /**
     * Commenti su un commit
     */
    public function listCommitComments($owner, $repo, $sha, $page = 1, $perPage = 50)
    {
        return $this->apiRequest('GET', "/repos/{$owner}/{$repo}/commits/{$sha}/comments", null, [
            'page' => $page,
            'per_page' => $perPage
        ]);
    }

    /**
     * Lista organizzazioni GitHub dell'utente
     */
    public function listOrganizations()
    {
        return $this->apiRequest('GET', '/user/orgs');
    }

    /**
     * Ottiene dettagli di un'organizzazione
     */
    public function getOrganization($orgName)
    {
        return $this->apiRequest('GET', "/orgs/{$orgName}");
    }

    /**
     * Elenca i ProjectV2 template aperti di un'organizzazione.
     * @return list<array<string,mixed>>
     */
    public function listOrganizationProjectTemplates(string $org): array
    {
        $org = trim($org);
        if ($org === '' || preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $org) !== 1) {
            throw new \InvalidArgumentException('Organizzazione GitHub non valida.');
        }
        $data = $this->graphqlRequest(
            <<<'GRAPHQL'
            query($login: String!) {
              organization(login: $login) {
                id
                login
                projectsV2(first: 100, orderBy: {field: NUMBER, direction: DESC}) {
                  nodes { id number title url shortDescription closed template }
                }
              }
            }
            GRAPHQL,
            ['login' => $org]
        );
        $organization = (array)($data['organization'] ?? []);
        $organizationId = trim((string)($organization['id'] ?? ''));
        $login = trim((string)($organization['login'] ?? $org));
        $projects = [];
        foreach ((array)($organization['projectsV2']['nodes'] ?? []) as $project) {
            if (!is_array($project) || empty($project['template']) || !empty($project['closed'])) {
                continue;
            }
            $projects[] = [
                'id' => (string)($project['id'] ?? ''),
                'number' => (int)($project['number'] ?? 0),
                'title' => (string)($project['title'] ?? ''),
                'url' => (string)($project['url'] ?? ''),
                'short_description' => (string)($project['shortDescription'] ?? ''),
                'organization' => $login,
                'organization_id' => $organizationId,
                'is_template' => true,
                'closed' => false,
            ];
        }
        return $projects;
    }

    public function getOrganizationNodeId(string $org): string
    {
        $data = $this->graphqlRequest(
            'query($login: String!) { organization(login: $login) { id } }',
            ['login' => trim($org)]
        );
        $id = trim((string)($data['organization']['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Node ID organizzazione GitHub non disponibile.');
        }
        return $id;
    }

    public function getAuthenticatedUserNodeId(): string
    {
        $data = $this->graphqlRequest('query { viewer { id } }');
        $id = trim((string)($data['viewer']['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Node ID dell’utente GitHub non disponibile.');
        }
        return $id;
    }

    public function getRepositoryNodeId(string $owner, string $repo): string
    {
        $data = $this->graphqlRequest(
            'query($owner: String!, $name: String!) { repository(owner: $owner, name: $name) { id } }',
            ['owner' => trim($owner), 'name' => trim($repo)]
        );
        $id = trim((string)($data['repository']['id'] ?? ''));
        if ($id === '') {
            throw new \RuntimeException('Node ID repository GitHub non disponibile.');
        }
        return $id;
    }

    /** @return array<string,mixed> */
    public function copyProjectV2(string $projectId, string $ownerId, string $title, bool $includeDraftIssues = false): array
    {
        $data = $this->graphqlRequest(
            'mutation($input: CopyProjectV2Input!) { copyProjectV2(input: $input) { projectV2 { id number title url } } }',
            ['input' => [
                'projectId' => trim($projectId),
                'ownerId' => trim($ownerId),
                'title' => trim($title),
                'includeDraftIssues' => $includeDraftIssues,
            ]]
        );
        return (array)($data['copyProjectV2']['projectV2'] ?? []);
    }

    /** @return array<string,mixed> */
    public function linkProjectV2ToRepository(string $projectId, string $repositoryId): array
    {
        $data = $this->graphqlRequest(
            'mutation($input: LinkProjectV2ToRepositoryInput!) { linkProjectV2ToRepository(input: $input) { repository { id name url } } }',
            ['input' => ['projectId' => trim($projectId), 'repositoryId' => trim($repositoryId)]]
        );
        return (array)($data['linkProjectV2ToRepository']['repository'] ?? []);
    }

    public function updateProjectV2Collaborators(string $projectId, string $actorId, string $role = 'ADMIN'): void
    {
        $role = strtoupper(trim($role));
        if (!in_array($role, ['READ', 'WRITE', 'ADMIN'], true)) {
            throw new \InvalidArgumentException('Ruolo Project non valido.');
        }
        $this->graphqlRequest(
            'mutation($input: UpdateProjectV2CollaboratorsInput!) { updateProjectV2Collaborators(input: $input) { collaborators(first: 1) { totalCount } } }',
            ['input' => [
                'projectId' => trim($projectId),
                'collaborators' => [[
                    'userId' => trim($actorId),
                    'role' => $role,
                ]],
            ]]
        );
    }

    public function unlinkProjectV2FromRepository(string $projectId, string $repositoryId): void
    {
        $this->graphqlRequest(
            'mutation($input: UnlinkProjectV2FromRepositoryInput!) { unlinkProjectV2FromRepository(input: $input) { repository { id } } }',
            ['input' => ['projectId' => trim($projectId), 'repositoryId' => trim($repositoryId)]]
        );
    }

    public function deleteProjectV2(string $projectId): void
    {
        $this->graphqlRequest(
            'mutation($input: DeleteProjectV2Input!) { deleteProjectV2(input: $input) { projectV2 { id } } }',
            ['input' => ['projectId' => trim($projectId)]]
        );
    }

    /**
     * Lista repository di un'organizzazione
     */
    public function listOrgRepositories($orgName, $type = 'all')
    {
        return $this->apiRequest('GET', "/orgs/{$orgName}/repos", null, [
            'type' => $type,
            'per_page' => 100
        ]);
    }

    /**
     * Crea un repository da template (per assignment manuali)
     */
    public function createRepositoryFromTemplate($templateOwner, $templateRepo, $name, $owner, $description = '', $private = true)
    {
        return $this->apiRequest('POST', "/repos/{$templateOwner}/{$templateRepo}/generate", [
            'owner' => $owner,
            'name' => $name,
            'description' => $description,
            'private' => (bool) $private
        ]);
    }

    /**
     * Invita un utente all'organizzazione tramite email (richiede scope admin:org).
     */
    public function inviteUserByEmail($org, $email, $role = 'direct_member')
    {
        return $this->apiRequest('POST', "/orgs/{$org}/invitations", [
            'email' => $email,
            'role' => $role,
        ]);
    }

    /**
     * Elimina un repository dell'organizzazione (per la pulizia del test).
     */
    public function deleteRepository($owner, $repo)
    {
        return $this->apiRequest('DELETE', "/repos/{$owner}/{$repo}");
    }

    /**
     * Aggiunge un collaboratore a un repository (assegnazione diretta).
     * Richiede un token con accesso admin al repo (es. org owner).
     */
    public function addCollaborator($owner, $repo, $username)
    {
        return $this->apiRequest('PUT', "/repos/{$owner}/{$repo}/collaborators/{$username}");
    }

    /**
     * Email verificate dell'utente autenticato (richiede scope user:email).
     */
    public function getUserEmails(): array
    {
        return $this->apiRequest('GET', '/user/emails');
    }

    /**
     * Verifica se un utente è membro attivo dell'organizzazione (ha accettato l'invito).
     */
    public function isOrgMember($org, $username): bool
    {
        try {
            $this->apiRequest('GET', "/orgs/{$org}/members/{$username}");
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Aggiunge direttamente un utente all'organizzazione (bypassa l'invito pendente).
     * Richiede scope admin:org.
     */
    public function addOrgMember($org, $username, $role = 'member')
    {
        return $this->apiRequest('PUT', "/orgs/{$org}/memberships/{$username}", [
            'role' => $role,
        ]);
    }

    /**
     * Scope OAuth realmente concessi dal token corrente (header X-OAuth-Scopes).
     */
    public function getTokenScopes(): array
    {
        if (!$this->accessToken) {
            return [];
        }
        $ch = curl_init('https://api.github.com/user');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HEADER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Sistema-UDA-PHP',
        ]);
        $response = curl_exec($ch);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);
        if ($response === false) {
            return [];
        }
        $headers = substr((string) $response, 0, $headerSize);
        if (preg_match('/^x-oauth-scopes:\s*(.+)$/mi', $headers, $matches)) {
            return array_values(array_filter(array_map('trim', explode(',', $matches[1]))));
        }
        return [];
    }

    /** @return array{0:string,1:string} */
    private function validatedRepository($owner, $repo): array
    {
        $owner = trim((string)$owner);
        $repo = trim((string)$repo);
        if (preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $owner) !== 1
            || preg_match('/^[A-Za-z0-9_.-]{1,100}$/', $repo) !== 1) {
            throw new \InvalidArgumentException('Repository GitHub non valida.');
        }
        return [rawurlencode($owner), rawurlencode($repo)];
    }

    private function validatedSha($sha): string
    {
        $sha = strtolower(trim((string)$sha));
        if (preg_match('/^[0-9a-f]{7,64}$/', $sha) !== 1) {
            throw new \InvalidArgumentException('SHA commit non valido.');
        }
        return rawurlencode($sha);
    }

    private function validatedBranch($branch): string
    {
        $branch = trim((string)$branch);
        if ($branch === '' || preg_match('/^[A-Za-z0-9_.\/-]{1,200}$/', $branch) !== 1) {
            throw new \InvalidArgumentException('Branch GitHub non valido.');
        }
        return rawurlencode($branch);
    }

    private function validatedIssueNumber($issueNumber): int
    {
        $issueNumber = filter_var($issueNumber, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
        if ($issueNumber === false) {
            throw new \InvalidArgumentException('Numero issue non valido.');
        }
        return (int)$issueNumber;
    }

    /**
     * Recupera pagine fino a esaurimento o al limite. `truncated` è true solo
     * quando l'ultima pagina piena coincide con il limite configurato.
     *
     * @param callable(int):array $fetchPage
     * @return array{items:array<int,mixed>,truncated:bool,pages:int}
     */
    private function collectPages(callable $fetchPage, int $perPage, int $maxPages): array
    {
        $perPage = min(100, max(1, $perPage));
        $maxPages = max(1, min(100, $maxPages));
        $items = [];
        $pages = 0;
        $truncated = false;
        for ($page = 1; $page <= $maxPages; $page++) {
            $batch = $fetchPage($page);
            $batch = is_array($batch) ? array_values($batch) : [];
            $pages++;
            if ($batch === []) {
                break;
            }
            array_push($items, ...$batch);
            if (count($batch) < $perPage) {
                break;
            }
            if ($page === $maxPages) {
                $truncated = true;
            }
        }
        return ['items' => $items, 'truncated' => $truncated, 'pages' => $pages];
    }

    /**
     * Esegue una richiesta GraphQL autenticata a GitHub.
     * @param array<string,mixed> $variables
     * @return array<string,mixed>
     */
    public function graphqlRequest(string $query, array $variables = []): array
    {
        if (!$this->accessToken) {
            throw new \Exception('Access token non impostato. Effettua prima l’autenticazione.');
        }
        $payload = json_encode(['query' => $query, 'variables' => $variables], JSON_THROW_ON_ERROR);
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/vnd.github+json',
            'Content-Type: application/json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Sistema-UDA-PHP',
        ];
        $lastError = 'risposta non valida';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            $startedAt = microtime(true);
            $this->diagnosticLog('graphql_request_start', [
                'attempt' => $attempt,
                'query_bytes' => strlen($payload),
            ]);
            $ch = curl_init('https://api.github.com/graphql');
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 3,
                CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            ]);
            $response = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->diagnosticLog('graphql_request_result', [
                'attempt' => $attempt,
                'http_status' => $httpCode,
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'response_bytes' => is_string($response) ? strlen($response) : 0,
                'curl_error' => $curlError !== '' ? $curlError : null,
            ]);

            if ($response === false) {
                $lastError = $curlError !== '' ? $curlError : 'errore di rete';
            } else {
                $decoded = json_decode((string)$response, true);
                if ($httpCode >= 200 && $httpCode < 300 && is_array($decoded)) {
                    if (!empty($decoded['errors']) && is_array($decoded['errors'])) {
                        $messages = array_map(
                            static fn(mixed $error): string => is_array($error) ? (string)($error['message'] ?? 'errore GraphQL') : 'errore GraphQL',
                            $decoded['errors']
                        );
                        throw new \Exception('GitHub GraphQL error: ' . implode('; ', $messages));
                    }
                    return (array)($decoded['data'] ?? []);
                }
                if (is_array($decoded)) {
                    $lastError = (string)($decoded['message'] ?? ('HTTP ' . $httpCode));
                } else {
                    $lastError = 'HTTP ' . $httpCode;
                }
            }
            if (in_array($httpCode, [403, 429, 0], true) && $attempt < 3) {
                sleep($attempt);
                continue;
            }
            break;
        }
        throw new \Exception('GitHub GraphQL error: ' . $lastError);
    }

    /**
     * Recupera una snapshot lineare della repository corrente: contenuto
     * testuale classificato per riga e intervalli GitHub blame. Il cache
     * contiene solo metadati LOC, mai il sorgente dei file. Quando presente,
     * il callback riceve ogni file elaborato come chunk: file, indice 1-based
     * e totale.
     *
     * @return array<string,mixed>
     */
    public function getRepositoryBlameSnapshot(
        string $owner,
        string $repo,
        string $ref,
        int $maxFiles = 250,
        int $maxBytes = 8_000_000,
        ?string $cacheDir = null,
        ?callable $onFile = null
    ): array {
        [$owner, $repo] = $this->validatedRepository($owner, $repo);
        $rawRef = trim($ref);
        $ref = $this->validatedBranch($ref);
        $maxFiles = max(1, min(1000, $maxFiles));
        $maxBytes = max(100_000, min(50_000_000, $maxBytes));
        $blameStartedAt = microtime(true);
        $this->diagnosticLog('blame_snapshot_start', [
            'owner_hash' => substr(hash('sha256', $owner), 0, 16),
            'repo_hash' => substr(hash('sha256', $repo), 0, 16),
            'ref_hash' => substr(hash('sha256', $rawRef), 0, 16),
            'max_files' => $maxFiles,
            'max_bytes' => $maxBytes,
        ]);

        $tree = $this->apiRequest('GET', "/repos/{$owner}/{$repo}/git/trees/{$ref}", null, ['recursive' => '1']);
        if (!is_array($tree)) {
            throw new \RuntimeException('Albero GitHub non disponibile.');
        }
        if (!empty($tree['truncated'])) {
            $this->diagnosticLog('blame_snapshot_result', [
                'enabled' => false,
                'reason' => 'tree_truncated',
                'elapsed_ms' => (int)round((microtime(true) - $blameStartedAt) * 1000),
            ]);
            return [
                'enabled' => false,
                'reason' => 'tree_truncated',
                'details' => ['max_files' => $maxFiles],
            ];
        }

        $entries = is_array($tree['tree'] ?? null) ? $tree['tree'] : [];
        $eligibleEntries = [];
        $binaryExtensions = ['7z', 'avi', 'bmp', 'class', 'dll', 'doc', 'docx', 'gif', 'gz', 'ico', 'jar', 'jpeg', 'jpg', 'mov', 'mp3', 'mp4', 'pdf', 'png', 'ppt', 'pptx', 'so', 'tar', 'wav', 'webp', 'xls', 'xlsx', 'zip'];
        foreach ($entries as $entry) {
            if (!is_array($entry) || ($entry['type'] ?? '') !== 'blob') {
                continue;
            }
            $path = trim((string)($entry['path'] ?? ''));
            if ($path === '' || preg_match('~(^|/)(?:\.git|vendor|node_modules|storage)(?:/|$)~i', $path)) {
                continue;
            }
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($extension !== '' && in_array($extension, $binaryExtensions, true)) {
                continue;
            }
            $size = max(0, (int)($entry['size'] ?? 0));
            $eligibleEntries[] = ['path' => $path, 'size' => $size];
        }

        $selection = GitHubBlameLocAttributor::selectSnapshotFiles($eligibleEntries, $maxFiles, $maxBytes);
        $files = $selection['files'];
        $bytes = (int)$selection['bytes'];
        $partial = (bool)$selection['partial'];
        $skippedFiles = (int)$selection['skipped_files'];
        $skippedBytes = (int)$selection['skipped_bytes'];
        if ($files === [] && $eligibleEntries !== [] && $skippedFiles > 0) {
            $reason = count($eligibleEntries) > $maxFiles ? 'file_limit' : 'byte_limit';
            $details = [
                'files' => count($eligibleEntries),
                'bytes' => array_sum(array_map(static fn(array $entry): int => (int)($entry['size'] ?? 0), $eligibleEntries)),
                'max_files' => $maxFiles,
                'max_bytes' => $maxBytes,
                'skipped_files' => $skippedFiles,
                'skipped_bytes' => $skippedBytes,
            ];
            $this->diagnosticLog('blame_snapshot_result', [
                'enabled' => false,
                'reason' => $reason,
                ...$details,
                'elapsed_ms' => (int)round((microtime(true) - $blameStartedAt) * 1000),
            ]);
            return ['enabled' => false, 'reason' => $reason, 'details' => $details];
        }

        $treeSha = strtolower(trim((string)($tree['sha'] ?? '')));
        $cacheFile = null;
        if ($cacheDir !== null && preg_match('/^[0-9a-f]{40}$/', $treeSha) === 1) {
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0700, true);
            }
            $cacheFile = rtrim($cacheDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . hash('sha256', $owner . '/' . $repo . ':' . $treeSha . ':' . $maxFiles . ':' . $maxBytes) . '.json';
            if (is_file($cacheFile)) {
                $cached = json_decode((string)@file_get_contents($cacheFile), true);
                if (is_array($cached) && ($cached['tree_sha'] ?? '') === $treeSha && ($cached['enabled'] ?? false) === true) {
                    $cachedFiles = is_array($cached['files'] ?? null) ? array_values($cached['files']) : [];
                    if ($onFile !== null) {
                        foreach ($cachedFiles as $cachedIndex => $cachedFile) {
                            if (is_array($cachedFile)) {
                                $onFile($cachedFile, $cachedIndex + 1, count($cachedFiles));
                            }
                        }
                    }
                    $this->diagnosticLog('blame_snapshot_result', [
                        'enabled' => true,
                        'cached' => true,
                        'files' => count($cachedFiles),
                        'partial' => (bool)($cached['partial'] ?? false),
                        'skipped_files' => (int)($cached['skipped_files'] ?? 0),
                        'elapsed_ms' => (int)round((microtime(true) - $blameStartedAt) * 1000),
                    ]);
                    return array_merge($cached, ['cached' => true]);
                }
            }
        }

        $quote = static fn(string $value): string => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $rootExpression = $quote($rawRef);
        $fields = [];
        foreach ($files as $index => $file) {
            $path = (string)$file['path'];
            $fields[] = 'file' . $index . ': object(expression: ' . $quote($rawRef . ':' . $path) . ') { ... on Blob { text isBinary } }';
            $fields[] = 'blame' . $index . ': object(expression: ' . $rootExpression . ') { ... on Commit { blame(path: ' . $quote($path) . ') { ranges { startingLine endingLine commit { oid message author { name email user { login } } committer { name email user { login } } } } } } }';
        }
        $query = 'query { repository(owner: ' . $quote($owner) . ', name: ' . $quote($repo) . ') { ' . implode(' ', $fields) . ' } }';
        $data = $this->graphqlRequest($query);
        $repository = is_array($data['repository'] ?? null) ? $data['repository'] : [];
        $snapshotFiles = [];
        foreach ($files as $index => $file) {
            $blob = is_array($repository['file' . $index] ?? null) ? $repository['file' . $index] : [];
            $text = $blob['text'] ?? null;
            if (!is_string($text)) {
                continue;
            }
            $ranges = [];
            $blameObject = is_array($repository['blame' . $index] ?? null) ? $repository['blame' . $index] : [];
            $blame = is_array($blameObject['blame'] ?? null) ? $blameObject['blame'] : [];
            foreach ((array)($blame['ranges'] ?? []) as $range) {
                if (!is_array($range)) {
                    continue;
                }
                $commit = is_array($range['commit'] ?? null) ? $range['commit'] : [];
                $author = is_array($commit['author'] ?? null) ? $commit['author'] : [];
                $authorUser = is_array($author['user'] ?? null) ? $author['user'] : [];
                $committer = is_array($commit['committer'] ?? null) ? $commit['committer'] : [];
                $committerUser = is_array($committer['user'] ?? null) ? $committer['user'] : [];
                $ranges[] = [
                    'starting_line' => (int)($range['startingLine'] ?? 0),
                    'ending_line' => (int)($range['endingLine'] ?? 0),
                    'commit' => [
                        'oid' => strtolower(trim((string)($commit['oid'] ?? ''))),
                        'message' => (string)($commit['message'] ?? ''),
                        'author_login' => trim((string)($authorUser['login'] ?? '')) ?: null,
                        'author_email' => trim((string)($author['email'] ?? '')) ?: null,
                        'committer_login' => trim((string)($committerUser['login'] ?? '')) ?: null,
                        'committer_email' => trim((string)($committer['email'] ?? '')) ?: null,
                    ],
                ];
            }
            $snapshotFile = [
                'path' => $file['path'],
                'bytes' => strlen($text),
                'language' => GitHubBlameLocAttributor::languageForPath((string)$file['path']),
                'line_types' => GitHubBlameLocAttributor::lineTypesForText((string)$file['path'], $text),
                'ranges' => $ranges,
            ];
            $snapshotFiles[] = $snapshotFile;
            if ($onFile !== null) {
                $onFile($snapshotFile, count($snapshotFiles), count($files));
            }
        }

        $snapshot = [
            'enabled' => true,
            'reason' => '',
            'tree_sha' => $treeSha,
            'files' => $snapshotFiles,
            'partial' => $partial,
            'skipped_files' => $skippedFiles,
            'skipped_bytes' => $skippedBytes,
            'selected_bytes' => $bytes,
            'max_files' => $maxFiles,
            'max_bytes' => $maxBytes,
            'cached' => false,
        ];
        if ($cacheFile !== null) {
            @file_put_contents($cacheFile, json_encode($snapshot, JSON_THROW_ON_ERROR), LOCK_EX);
            @chmod($cacheFile, 0600);
        }
        $this->diagnosticLog('blame_snapshot_result', [
            'enabled' => true,
            'cached' => false,
            'files' => count($snapshotFiles),
            'partial' => $partial,
            'skipped_files' => $skippedFiles,
            'skipped_bytes' => $skippedBytes,
            'tree_sha' => $treeSha,
            'elapsed_ms' => (int)round((microtime(true) - $blameStartedAt) * 1000),
        ]);
        return $snapshot;
    }

    /**
     * Esegue una richiesta API a GitHub
     *
     * @param string $method HTTP method (GET, POST, PUT, DELETE)
     * @param string $endpoint API endpoint (es: /user)
     * @param array|null $data Dati da inviare (per POST/PUT)
     * @param array|null $queryParams Query parameters (per GET)
     * @return array Response decodificata
     */
    private function apiRequest($method, $endpoint, $data = null, $queryParams = null)
    {
        if (!$this->accessToken) {
            throw new \Exception('Access token non impostato. Effettua prima l\'autenticazione.');
        }

        $url = "https://api.github.com{$endpoint}";

        // Aggiungi query parameters per GET
        if ($method === 'GET' && $queryParams) {
            $url .= '?' . http_build_query($queryParams);
        }

        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Sistema-UDA-PHP'
        ];

        $payload = null;
        if (in_array($method, ['POST', 'PUT']) && $data) {
            $payload = json_encode($data);
            $headers[] = 'Content-Type: application/json';
        }

        $attempts = 0;
        $maxAttempts = 3;
        $lastError = null;

        while ($attempts < $maxAttempts) {
            $attempts++;
            $startedAt = microtime(true);
            $this->diagnosticLog('api_request_start', [
                'method' => $method,
                'endpoint' => $endpoint,
                'attempt' => $attempts,
            ]);
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            // Segue i redirect (301/302) interni all'API GitHub: quando un repo o una
            // organizzazione viene rinominata, l'API risponde con un redirect verso
            // api.github.com/repositories/{id} (stesso host), senza perdere il token.
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_MAXREDIRS, 5);
            curl_setopt($ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                if ($payload) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            } elseif ($method === 'PUT') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($payload) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
                }
            } elseif ($method === 'DELETE') {
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            }

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            $this->diagnosticLog('api_request_result', [
                'method' => $method,
                'endpoint' => $endpoint,
                'attempt' => $attempts,
                'http_status' => $httpCode,
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
                'response_bytes' => is_string($response) ? strlen($response) : 0,
                'curl_error' => $curlError !== '' ? $curlError : null,
            ]);

            if ($httpCode >= 200 && $httpCode < 300) {
                return json_decode($response, true);
            }

            $error = json_decode($response, true);
            $errorMsg = $error['message'] ?? "HTTP {$httpCode}";
            $lastError = $errorMsg;

            // Se rate limit (429 o abuse/403) tenta un semplice backoff
            if (in_array($httpCode, [403, 429]) && $attempts < $maxAttempts) {
                sleep($attempts); // backoff lineare semplice
                continue;
            }

            throw new \Exception("GitHub API error: {$errorMsg}");
        }

        throw new \Exception("GitHub API error: {$lastError}");
    }

    /**
     * Verifica se l'utente è autenticato
     */
    public function isAuthenticated()
    {
        return !empty($this->accessToken);
    }

    /**
     * Salva il token in sessione
     */
    public function saveTokenToSession($token)
    {
        $_SESSION['github_access_token'] = $token;
        $this->setAccessToken($token);
    }

    /**
     * Carica il token dalla sessione
     */
    public function loadTokenFromSession()
    {
        // L'autenticazione GitHub usa esclusivamente il token OAuth di sessione
        // (l'app "UDA system" approvata dall'organizzazione copre anche le API
        // Classroom e le repository).
        if (isset($_SESSION['github_access_token'])) {
            $this->setAccessToken($_SESSION['github_access_token']);
            return true;
        }
        return false;
    }

    /**
     * Revoca l'autenticazione
     */
    public function logout()
    {
        unset($_SESSION['github_access_token']);
        unset($_SESSION['github_oauth_state']);
        $this->accessToken = null;
    }
}
