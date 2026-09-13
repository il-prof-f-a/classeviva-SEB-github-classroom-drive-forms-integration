<?php

namespace App\Integration;

/**
 * GitHub Integration
 *
 * Gestisce l'autenticazione OAuth e le chiamate API a GitHub/GitHub Classroom
 */
class GitHubIntegration
{
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;

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
    public function getAuthorizationUrl($state = null, $returnTo = null, $scopes = 'read:user read:org repo user:email admin:org')
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
     * Ottiene informazioni sull'utente autenticato
     */
    public function getUser()
    {
        return $this->apiRequest('GET', '/user');
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
            curl_close($ch);

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
