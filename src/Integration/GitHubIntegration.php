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
    public function getAuthorizationUrl($state = null, $returnTo = null)
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
            'scope' => 'read:user read:org repo',
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
     * Lista tutti i GitHub Classrooms dell'utente
     */
    public function listClassrooms($page = 1, $perPage = 30)
    {
        return $this->apiRequest('GET', '/classrooms', null, [
            'page' => $page,
            'per_page' => $perPage
        ]);
    }

    /**
     * Ottiene dettagli di un classroom specifico
     */
    public function getClassroom($classroomId)
    {
        return $this->apiRequest('GET', "/classrooms/{$classroomId}");
    }

    /**
     * Lista assignment di un classroom
     */
    public function listAssignments($classroomId, $page = 1, $perPage = 30)
    {
        return $this->apiRequest('GET', "/classrooms/{$classroomId}/assignments", null, [
            'page' => $page,
            'per_page' => $perPage
        ]);
    }

    /**
     * Ottiene dettagli di un assignment
     */
    public function getAssignment($assignmentId)
    {
        return $this->apiRequest('GET', "/assignments/{$assignmentId}");
    }

    /**
     * Lista le accepted assignments (roster con repo) per un assignment
     */
    public function listAcceptedAssignments($assignmentId, $page = 1, $perPage = 100)
    {
        return $this->apiRequest('GET', "/assignments/{$assignmentId}/accepted_assignments", null, [
            'page' => $page,
            'per_page' => $perPage
        ]);
    }

    /**
     * Recupera i grades (username + roster_identifier) di un assignment
     */
    public function getAssignmentGrades($assignmentId)
    {
        return $this->apiRequest('GET', "/assignments/{$assignmentId}/grades");
    }

    /**
     * Elenco commit di un repository
     */
    public function listRepoCommits($owner, $repo, $since = null, $until = null, $perPage = 20, $page = 1)
    {
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
     * Dettagli di un commit (include files e stats)
     */
    public function getCommit($owner, $repo, $sha)
    {
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
    public function createRepositoryFromTemplate($templateOwner, $templateRepo, $name, $owner, $description = '')
    {
        return $this->apiRequest('POST', "/repos/{$templateOwner}/{$templateRepo}/generate", [
            'owner' => $owner,
            'name' => $name,
            'description' => $description,
            'private' => false
        ]);
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
