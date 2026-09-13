<?php
/**
 * Riepilogo assignment GitHub Classroom e assegnazione voti manuali
 */

require_once '../bootstrap.php';

// La review contiene voti e attribuzioni per-studente: anche la pagina HTML
// deve arrivare sempre dallo stato corrente, senza cache del browser/proxy.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentService;
use App\Core\GitHubReviewMetadata;
use App\Core\NotificationManager;
use App\Core\RuntimeStudentNameService;
use App\Core\Security\Csrf;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$github = new GitHubIntegration($config);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$github->loadTokenFromSession();
$isAuthenticated = $github->isAuthenticated();
$csrfToken = Csrf::token($_SESSION);

function jsonResponse($data, $status = 200, bool $noStore = false)
{
    http_response_code($status);
    if ($noStore) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$requestMethod = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$getAction = $requestMethod === 'GET' ? ($_GET['action'] ?? null) : null;
$jsonRequest = [];
if ($requestMethod === 'POST' && str_contains(strtolower((string)($_SERVER['CONTENT_TYPE'] ?? '')), 'application/json')) {
    $decodedRequest = json_decode((string)file_get_contents('php://input'), true);
    $jsonRequest = is_array($decodedRequest) ? $decodedRequest : [];
}
$postAction = $requestMethod === 'POST' ? ($_POST['action'] ?? $jsonRequest['action'] ?? null) : null;
if ($postAction === 'commit_details') {
    try {
        if (!$isAuthenticated) {
            throw new Exception('Non autenticato su GitHub');
        }

        $repoFull = trim((string)($_POST['repo'] ?? $jsonRequest['repo'] ?? ''));
        $sha = trim((string)($_POST['sha'] ?? $jsonRequest['sha'] ?? ''));
        $withComments = (string)($_POST['with_comments'] ?? $jsonRequest['with_comments'] ?? '0') === '1';

        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repoFull)) {
            throw new Exception('Repository non valido');
        }
        if (!preg_match('~^[0-9a-f]{7,40}$~i', $sha)) {
            throw new Exception('SHA non valido');
        }

        [$owner, $repo] = explode('/', $repoFull, 2);
        $commit = $github->getCommit($owner, $repo, $sha);

        $files = $commit['files'] ?? [];
        $filesTruncated = false;
        $maxFiles = 50;
        if (is_array($files) && count($files) > $maxFiles) {
            $files = array_slice($files, 0, $maxFiles);
            $filesTruncated = true;
        }
        $commit['files'] = $files;

        $commitMessage = (string)($commit['commit']['message'] ?? '');
        $issueReferences = GitHubReviewMetadata::extractIssueReferences($commitMessage, $owner, $repo);

        $comments = null;
        if ($withComments) {
            $comments = $github->listCommitComments($owner, $repo, $sha, 1, 50);
        }

        jsonResponse([
            'ok' => true,
            'repo' => $repoFull,
            'sha' => $sha,
            'with_comments' => $withComments,
            'files_truncated' => $filesTruncated,
            'commit' => $commit,
            'issue_refs' => $issueReferences,
            'comments' => $comments
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'ok' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'github_commit_details')
        ], 400);
    }
}

$testId = $_GET['test_id'] ?? null;
if (!$testId) {
    die("test_id mancante");
}

// Helpers
function getClasseVivaGrades()
{
    $grades = [];
    for ($i = 1; $i <= 10; $i++) {
        $grades[] = number_format($i, 1);
        if ($i < 10) {
            $grades[] = number_format($i + 0.5, 1);
        }
    }
    $grades[] = 'i';
    $grades[] = 'a';
    $grades[] = 'skip';
    return $grades;
}

function normalizeGithubReviewGrade($value): string
{
    $value = trim((string)$value);
    if ($value === '' || in_array($value, ['i', 'a', 'skip'], true)) {
        return $value;
    }
    if (is_numeric($value)) {
        $numeric = round((float)$value * 2) / 2;
        return number_format((float)$numeric, 1, '.', '');
    }
    return $value;
}

// Risolve le email degli studenti di un gruppo (Google Classroom primario, ClasseViva fallback).
// Le email non vengono mai persistite: sono ricavate just-in-time dai roster.
function gh_review_resolve_students(string $groupId): array
{
    global $config, $dbAdapter, $userId;
    $svc = new TeachingGroupStudentService($dbAdapter, $userId);
    $integrations = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
    $gcCourse = '';
    $cvClass = '';
    foreach ($integrations->listForGroup($groupId) as $it) {
        if (($it['stato'] ?? 'attivo') === 'disattivo') continue;
        $p = (string)($it['provider'] ?? '');
        if ($p === 'google_classroom') $gcCourse = (string)($it['external_context_id'] ?? '');
        elseif ($p === 'classeviva') $cvClass = (string)($it['external_context_id'] ?? '');
    }
    $rosters = [];
    if ($gcCourse !== '') {
        try { $rosters['google_classroom'] = (new GoogleClassroomAPI($config))->getCourseStudents($gcCourse); }
        catch (Throwable $e) { $rosters['google_classroom'] = []; }
    }
    if ($cvClass !== '') {
        try { $rosters['classeviva'] = (new ClasseVivaAPI($config))->getStudentiClasse($cvClass); }
        catch (Throwable $e) { $rosters['classeviva'] = []; }
    }
    $profile = $config['user_profile'] ?? [];
    $emailTemplate = (string)($profile['school_student_email_template'] ?? '');
    $emailDomain = (string)($profile['school_email_domain'] ?? '');
    $matrix = $svc->matrix($groupId);
    return (new GitHubAssignmentService($emailTemplate, $emailDomain))->resolveStudents($matrix, $rosters, 'google_classroom');
}

// Carica test
$test = $dbAdapter->findOne('TEST', 'id_test', $testId);
if (!$test) {
    die("Test non trovato");
}
if (strtolower($test['piattaforma'] ?? '') !== 'github') {
    die("Questo test non è un assignment GitHub");
}

// Le rubriche GitHub vengono caricate esclusivamente da RUBRICA. La pagina
// di gestione usa il template Excel in Materiale e non mantiene fallback
// hardcoded, così review e editor condividono sempre la stessa definizione.
if ($getAction === 'rubric_load') {
    try {
        $studentId = trim((string)($_GET['student_id'] ?? ''));
        if ($studentId === '') {
            throw new Exception('student_id mancante');
        }

        $rubricId = (string)$testId;
        $rubricaRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $rubricId]);
        $hasRubric = !empty($rubricaRows);

        usort($rubricaRows, function ($a, $b) {
            return (int)($a['ordine'] ?? 0) <=> (int)($b['ordine'] ?? 0);
        });

        $idUda = (string)($test['id_uda'] ?? '');
        $idGruppo = (string)($_GET['id_gruppo'] ?? '');

        $where = [
            'id_uda' => $idUda,
            'id_rubrica' => $rubricId,
            'id_studente' => $studentId
        ];
        if ($idGruppo !== '') $where['id_gruppo'] = $idGruppo;

        $saved = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
        // Compatibilità con le valutazioni create prima dell'associazione al
        // gruppo: se non c'è una riga nel gruppo corrente, recupera quella
        // dello stesso studente/rubrica anche senza id_gruppo.
        if (empty($saved) && $idGruppo !== '') {
            $legacyWhere = [
                'id_uda' => $idUda,
                'id_rubrica' => $rubricId,
                'id_studente' => $studentId
            ];
            $saved = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $legacyWhere);
        }
        $savedRow = $saved[0] ?? null;
        $savedJson = null;
        if ($savedRow && !empty($savedRow['dati_json'])) {
            $decoded = json_decode((string)$savedRow['dati_json'], true);
            if (is_array($decoded)) {
                $savedJson = $decoded;
            }
        }

        jsonResponse([
            'ok' => true,
            'rubric_id' => $rubricId,
            'id_uda' => $idUda,
            'has_rubric' => $hasRubric,
            'rubric_rows' => $rubricaRows,
            'saved' => $savedRow,
            'saved_json' => $savedJson
        ], 200, true);
    } catch (Exception $e) {
        jsonResponse(['ok' => false, 'error' => \App\Core\Security\PublicError::message($e, 'github_rubric_load')], 400, true);
    }
}

// Le attribuzioni sono per-studente e devono essere sempre lette dallo stato
// corrente del database. La rubrica statica viene invece precaricata nella
// pagina: questo endpoint restituisce quindi solo la valutazione salvata.
if ($getAction === 'rubric_saved') {
    try {
        $studentId = trim((string)($_GET['student_id'] ?? ''));
        if ($studentId === '') {
            throw new Exception('student_id mancante');
        }

        $rubricId = (string)$testId;
        $idUda = (string)($test['id_uda'] ?? '');
        $idGruppo = trim((string)($_GET['id_gruppo'] ?? ''));
        $where = [
            'id_uda' => $idUda,
            'id_rubrica' => $rubricId,
            'id_studente' => $studentId
        ];
        if ($idGruppo !== '') {
            $where['id_gruppo'] = $idGruppo;
        }

        $saved = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
        // Compatibilità con le valutazioni create prima dell'associazione al
        // gruppo: manteniamo il fallback solo per l'attribuzione corrente.
        if (empty($saved) && $idGruppo !== '') {
            unset($where['id_gruppo']);
            $saved = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
        }

        $savedRow = $saved[0] ?? null;
        $savedJson = null;
        if ($savedRow && !empty($savedRow['dati_json'])) {
            $decoded = json_decode((string)$savedRow['dati_json'], true);
            if (is_array($decoded)) {
                $savedJson = $decoded;
            }
        }

        jsonResponse([
            'ok' => true,
            'rubric_id' => $rubricId,
            'id_uda' => $idUda,
            'saved' => $savedRow,
            'saved_json' => $savedJson
        ], 200, true);
    } catch (Exception $e) {
        jsonResponse([
            'ok' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'github_rubric_saved')
        ], 400, true);
    }
}

if ($postAction === 'rubric_save') {
    try {
        $payload = $jsonRequest !== [] ? $jsonRequest : $_POST;

        $studentId = trim((string)($payload['student_id'] ?? ''));
        if ($studentId === '') {
            throw new Exception('student_id mancante');
        }

        $rubricId = (string)$testId;
        $idUda = (string)($test['id_uda'] ?? '');
        $idGruppo = (string)($payload['id_gruppo'] ?? '');
        $nomeStudente = (string)($payload['nome_studente'] ?? '');

        $datiJson = $payload['dati_json'] ?? null;
        if (!is_array($datiJson)) {
            throw new Exception('dati_json non valido');
        }

        $votoNumerico = $payload['voto_numerico'] ?? null;
        if ($votoNumerico !== null && $votoNumerico !== '') {
            $votoNumerico = (float)$votoNumerico;
        } else {
            $votoNumerico = null;
        }

        $where = [
            'id_uda' => $idUda,
            'id_rubrica' => $rubricId,
            'id_studente' => $studentId
        ];
        if ($idGruppo !== '') $where['id_gruppo'] = $idGruppo;

        $existing = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
        $rowData = [
            'id_uda' => $idUda,
            'id_rubrica' => $rubricId,
            'id_studente' => $studentId,
            'id_gruppo' => $idGruppo,
            'data_valutazione' => date('Y-m-d H:i:s'),
            'voto_finale' => $votoNumerico !== null ? (string)$votoNumerico : '',
            'voto_numerico' => $votoNumerico !== null ? (string)$votoNumerico : '',
            'giudizio' => (string)($payload['giudizio'] ?? ''),
            'note' => 'github_rubric',
            'pubblicato' => '0',
            'pubblicato_cv' => '0',
            'id_annotazione_cv' => '',
            'nome_studente' => $nomeStudente,
            'dati_json' => json_encode($datiJson, JSON_UNESCAPED_UNICODE)
        ];

        if (!empty($existing)) {
            $idVal = (string)($existing[0]['id_valutazione'] ?? '');
            if ($idVal === '') {
                $idVal = 'VAL_RUB_GH_' . uniqid();
            }
            $rowData['id_valutazione'] = $idVal;
            $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', 'id_valutazione', $idVal, $rowData);
            jsonResponse(['ok' => true, 'updated' => true, 'id_valutazione' => $idVal]);
        } else {
            $idVal = 'VAL_RUB_GH_' . uniqid();
            $rowData['id_valutazione'] = $idVal;
            $dbAdapter->insertRow('VALUTAZIONI_RUBRICA', $rowData);
            jsonResponse(['ok' => true, 'inserted' => true, 'id_valutazione' => $idVal]);
        }
    } catch (Exception $e) {
        jsonResponse(['ok' => false, 'error' => \App\Core\Security\PublicError::message($e, 'github_rubric_save')], 400);
    }
}

if (!$isAuthenticated) {
    $authUrl = $github->getAuthorizationUrl(null, $_SERVER['REQUEST_URI'] ?? null);
    ?>
    <!DOCTYPE html>
    <html lang="it">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Autenticazione GitHub richiesta</title>
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    </head>
    <body>
            <?php
    $pageTitle = '<i class="bi bi-github"></i> Autenticazione GitHub richiesta';
    $headerContainerClass = 'container-fluid px-3 github-review-header';
    $headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($test['id_uda'] ?? '') . '"><i class="bi bi-arrow-left"></i> Torna ai Test</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


        <div class="container mt-4">
            <div class="alert alert-warning">
                <h5 class="mb-2"><i class="bi bi-exclamation-triangle"></i> Autenticazione GitHub richiesta</h5>
                <div class="mb-3">Per visualizzare il riepilogo e caricare i dati da GitHub Classroom devi autenticarti.</div>
                <a href="<?= htmlspecialchars($authUrl) ?>" class="btn btn-dark">
                    <i class="bi bi-github"></i> Autentica con GitHub
                </a>
            </div>
        </div>
        <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
    exit;
}

// Metadati repository: endpoint read-only caricato dalla UI solo quando
// l'utente apre i dettagli. Nessun dato viene scritto nel database.
if ($postAction === 'repo_metadata') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? $jsonRequest['csrf_token'] ?? null);

        $repoFull = trim((string)($_POST['repo'] ?? $jsonRequest['repo'] ?? ''));
        if (!preg_match('~^[A-Za-z0-9_.-]{1,100}/[A-Za-z0-9_.-]{1,100}$~', $repoFull)) {
            throw new Exception('Repository non valida');
        }
        [$owner, $repo] = explode('/', $repoFull, 2);

        $limiter = new \App\Core\Security\RateLimiter(ROOT_PATH . '/storage/rate_limits');
        if (!$limiter->allow('github_repo_metadata:' . $userId, 20, 600)) {
            header('Retry-After: 600');
            jsonResponse(['ok' => false, 'error' => 'Troppe richieste di metadati. Riprova tra alcuni minuti.'], 429, true);
        }

        set_time_limit(180);
        $warnings = [];

        $commitResult = $github->listRepoCommitsAll($owner, $repo, 100, 20);
        $rawCommits = $commitResult['items'] ?? [];
        $commitTruncated = (bool)($commitResult['truncated'] ?? false);

        $commitPayload = [];
        $originFailures = 0;
        $originLimit = 250;
        foreach (array_values($rawCommits) as $commitIndex => $commit) {
            if (!is_array($commit)) {
                continue;
            }
            $sha = strtolower(trim((string)($commit['sha'] ?? '')));
            if (!preg_match('~^[0-9a-f]{7,64}$~', $sha)) {
                continue;
            }
            $message = (string)($commit['commit']['message'] ?? '');
            $messageParts = preg_split("/\\r\\n|\\n|\\r/", $message, 2);
            $title = (string)($messageParts[0] ?? '');
            $body = (string)($messageParts[1] ?? '');

            $origin = ['label' => 'Origine non determinabile', 'source' => 'unknown', 'branches' => []];
            if ($commitIndex < $originLimit) {
                try {
                    $pullRequests = $github->listCommitPullRequests($owner, $repo, $sha);
                    // Il branch head della PR è la fonte più precisa; chiediamo
                    // i branch HEAD solo quando GitHub non restituisce una PR.
                    $headBranches = $pullRequests !== []
                        ? []
                        : $github->listCommitBranches($owner, $repo, $sha);
                    $origin = GitHubReviewMetadata::resolveBranchOrigin($pullRequests, $headBranches);
                } catch (Throwable $originError) {
                    $originFailures++;
                }
            } else {
                $originFailures++;
            }

            $commitPayload[] = [
                'sha' => $sha,
                'short_sha' => substr($sha, 0, 7),
                'title' => $title,
                'body' => $body,
                'date' => (string)($commit['commit']['author']['date'] ?? ''),
                'url' => GitHubReviewMetadata::commitUrl($owner, $repo, $sha),
                'issue_refs' => GitHubReviewMetadata::extractIssueReferences($message, $owner, $repo),
                'branch_origin' => $origin,
            ];
        }

        $tagResult = [];
        try {
            $tagResult = $github->listRepoTagsAll($owner, $repo, 100, 10);
        } catch (Throwable $tagError) {
            $warnings[] = 'Tag non disponibili.';
        }
        $tagsBySha = GitHubReviewMetadata::mapTagsBySha($tagResult['items'] ?? []);
        foreach ($commitPayload as &$commitRow) {
            $commitRow['tags'] = $tagsBySha[$commitRow['sha']] ?? [];
        }
        unset($commitRow);

        $branches = [];
        try {
            $branchResult = $github->listRepoBranchesAll($owner, $repo, 100, 10);
            $branches = GitHubReviewMetadata::normalizeBranches($branchResult['items'] ?? []);
        } catch (Throwable $branchError) {
            $warnings[] = 'Branch non disponibili.';
        }

        $rawIssues = [];
        $issueResult = [];
        try {
            $issueResult = $github->listRepoIssuesAll($owner, $repo, 'all', 100, 10);
            $rawIssues = $issueResult['items'] ?? [];
        } catch (Throwable $issueError) {
            $warnings[] = 'Issue non disponibili.';
        }

        $timelines = [];
        foreach ($rawIssues as $issue) {
            if (!is_array($issue) || isset($issue['pull_request'])) {
                continue;
            }
            $number = (int)($issue['number'] ?? 0);
            if ($number < 1) {
                continue;
            }
            try {
                $timelineResult = $github->listIssueTimelineAll($owner, $repo, $number, 100, 5);
                $timelines[$number] = $timelineResult['items'] ?? [];
            } catch (Throwable $timelineError) {
                $timelines[$number] = [];
                $warnings[] = 'Timeline issue #' . $number . ' non disponibile.';
            }
        }
        $issues = GitHubReviewMetadata::normalizeIssues($rawIssues, $timelines, $owner, $repo);

        if ($commitTruncated) {
            $warnings[] = 'Elenco commit parziale: raggiunto il limite di paginazione.';
        }
        if ($originFailures > 0) {
            $warnings[] = 'Branch di origine determinati solo per i primi ' . $originLimit . ' commit; per gli altri l’origine può non essere determinabile.';
        }

        jsonResponse([
            'ok' => true,
            'repo' => $repoFull,
            'commits' => $commitPayload,
            'issues' => $issues,
            'branches' => $branches,
            'tags' => $tagsBySha,
            'truncated' => $commitTruncated,
            'warnings' => array_values(array_unique($warnings)),
        ], 200, true);
    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'github_repo_metadata')
        ], 400, true);
    }
}

function ghRemoveDirRecursive($dir)
{
    if (!$dir || !is_dir($dir)) {
        return;
    }
    $items = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
        if ($item->isDir()) {
            @rmdir($item->getRealPath());
        } else {
            @unlink($item->getRealPath());
        }
    }
    @rmdir($dir);
}

function ghIsBinaryFile($filePath)
{
    $h = @fopen($filePath, 'rb');
    if (!$h) return true;
    $chunk = @fread($h, 8000);
    @fclose($h);
    if ($chunk === false) return true;
    return strpos($chunk, "\0") !== false;
}

function ghGuessLanguageByExtension($filePath)
{
    $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    $map = [
        'php' => 'PHP',
        'js' => 'JavaScript',
        'ts' => 'TypeScript',
        'jsx' => 'JavaScript',
        'tsx' => 'TypeScript',
        'py' => 'Python',
        'java' => 'Java',
        'c' => 'C',
        'h' => 'C/C++ Header',
        'cpp' => 'C++',
        'hpp' => 'C++ Header',
        'cs' => 'C#',
        'html' => 'HTML',
        'htm' => 'HTML',
        'css' => 'CSS',
        'scss' => 'SCSS',
        'json' => 'JSON',
        'xml' => 'XML',
        'yml' => 'YAML',
        'yaml' => 'YAML',
        'md' => 'Markdown',
        'sql' => 'SQL',
        'sh' => 'Shell',
        'bat' => 'Batch',
        'ps1' => 'PowerShell'
    ];
    return $map[$ext] ?? strtoupper($ext ?: 'OTHER');
}

function ghComputeLocInternal($rootDir)
{
    $excludeDirs = ['.git', 'vendor', 'node_modules', '.idea', '.vscode', 'storage'];
    $totals = ['total' => 0, 'blank' => 0, 'comment' => 0, 'code' => 0, 'files' => 0];
    $byLang = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootDir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $fileInfo) {
        $path = $fileInfo->getPathname();
        if ($fileInfo->isDir()) {
            continue;
        }

        $parts = preg_split('~[\\\\/]+~', $path);
        foreach ($excludeDirs as $ex) {
            if (in_array($ex, $parts, true)) {
                continue 2;
            }
        }

        if (ghIsBinaryFile($path)) {
            continue;
        }

        $lang = ghGuessLanguageByExtension($path);
        if (!isset($byLang[$lang])) {
            $byLang[$lang] = ['total' => 0, 'blank' => 0, 'comment' => 0, 'code' => 0, 'files' => 0];
        }

        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $inBlock = false;
        $blockStart = null;
        $blockEnd = null;

        if (in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'java', 'c', 'cpp', 'h', 'hpp', 'cs', 'css', 'scss', 'sql'], true)) {
            $blockStart = '/*';
            $blockEnd = '*/';
        } elseif (in_array($ext, ['html', 'htm', 'xml'], true)) {
            $blockStart = '<!--';
            $blockEnd = '-->';
        } elseif (in_array($ext, ['py'], true)) {
            $blockStart = '"""';
            $blockEnd = '"""';
        }

        $fh = @fopen($path, 'rb');
        if (!$fh) {
            continue;
        }

        $totals['files']++;
        $byLang[$lang]['files']++;

        while (($line = fgets($fh)) !== false) {
            $totals['total']++;
            $byLang[$lang]['total']++;

            $trim = trim($line);
            if ($trim === '') {
                $totals['blank']++;
                $byLang[$lang]['blank']++;
                continue;
            }

            $isComment = false;

            if ($blockStart && $blockEnd) {
                if ($inBlock) {
                    $isComment = true;
                    if (strpos($trim, $blockEnd) !== false) {
                        $inBlock = false;
                    }
                } else {
                    if (strpos($trim, $blockStart) === 0) {
                        $isComment = true;
                        if (strpos($trim, $blockEnd) === false || $blockStart === $blockEnd) {
                            if ($blockStart === $blockEnd) {
                                if (substr_count($trim, $blockStart) === 1) {
                                    $inBlock = true;
                                }
                            } else {
                                $inBlock = true;
                            }
                        }
                    }
                }
            }

            if (!$isComment) {
                if (in_array($ext, ['php', 'js', 'ts', 'jsx', 'tsx', 'java', 'c', 'cpp', 'h', 'hpp', 'cs'], true) && strpos($trim, '//') === 0) {
                    $isComment = true;
                } elseif (in_array($ext, ['php', 'py', 'sh', 'yml', 'yaml'], true) && strpos($trim, '#') === 0) {
                    $isComment = true;
                } elseif ($ext === 'sql' && strpos($trim, '--') === 0) {
                    $isComment = true;
                }
            }

            if ($isComment) {
                $totals['comment']++;
                $byLang[$lang]['comment']++;
            } else {
                $totals['code']++;
                $byLang[$lang]['code']++;
            }
        }

        @fclose($fh);
    }

    ksort($byLang);
    return ['totals' => $totals, 'by_language' => $byLang];
}

function ghComputeLocWithClocIfAvailable($rootDir)
{
    // Aruba può disabilitare shell_exec: il conteggio interno deve restare
    // disponibile anche quando cloc non è installato o non è invocabile.
    if (!function_exists('shell_exec')) {
        return null;
    }
    $probe = PHP_OS_FAMILY === 'Windows' ? 'where cloc 2>NUL' : 'command -v cloc 2>/dev/null';
    $where = @shell_exec($probe);
    if (!is_string($where) || trim($where) === '') {
        return null;
    }

    $dirArg = escapeshellarg($rootDir);
    $cmd = "cloc --json --quiet --skip-uniqueness --exclude-dir=vendor,node_modules --timeout 0 {$dirArg} 2>&1";
    $out = @shell_exec($cmd);
    if (!is_string($out) || trim($out) === '') {
        return null;
    }

    $data = json_decode($out, true);
    if (!is_array($data) || empty($data['SUM'])) {
        return null;
    }

    $totals = [
        'total' => (int)($data['SUM']['code'] ?? 0) + (int)($data['SUM']['comment'] ?? 0) + (int)($data['SUM']['blank'] ?? 0),
        'blank' => (int)($data['SUM']['blank'] ?? 0),
        'comment' => (int)($data['SUM']['comment'] ?? 0),
        'code' => (int)($data['SUM']['code'] ?? 0),
        'files' => (int)($data['SUM']['nFiles'] ?? 0)
    ];

    $byLang = [];
    foreach ($data as $lang => $row) {
        if (!is_array($row) || $lang === 'SUM') continue;
        $byLang[$lang] = [
            'total' => (int)($row['code'] ?? 0) + (int)($row['comment'] ?? 0) + (int)($row['blank'] ?? 0),
            'blank' => (int)($row['blank'] ?? 0),
            'comment' => (int)($row['comment'] ?? 0),
            'code' => (int)($row['code'] ?? 0),
            'files' => (int)($row['nFiles'] ?? 0)
        ];
    }
    ksort($byLang);

    return ['totals' => $totals, 'by_language' => $byLang, 'raw' => $data];
}

if ($postAction === 'repo_loc') {
    try {
        if (!$isAuthenticated) {
            throw new Exception('Non autenticato su GitHub');
        }

        $limiter = new \App\Core\Security\RateLimiter(ROOT_PATH . '/storage/rate_limits');
        if (!$limiter->allow('github_repo_loc:' . $userId, 20, 600)) {
            header('Retry-After: 600');
            jsonResponse(['ok' => false, 'error' => 'Troppe analisi richieste. Riprova tra alcuni minuti.'], 429);
        }

        set_time_limit(180);

        $repoFull = trim((string)($_POST['repo'] ?? ''));
        $ref = trim((string)($_POST['ref'] ?? ''));
        $force = (string)($_POST['force'] ?? '0') === '1';

        if (!preg_match('~^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$~', $repoFull)) {
            throw new Exception('Repository non valido');
        }
        if ($ref === '' || !preg_match('~^[A-Za-z0-9_.\\-/]+$~', $ref)) {
            throw new Exception('Ref non valido');
        }

        $userId = (string)($_SESSION['user_id'] ?? '');

        if (!$force) {
            try {
                $existing = $dbAdapter->findWhere('GITHUB_REPO_LOC_SNAPSHOTS', [
                    'id_test' => $testId,
                    'repo_full_name' => $repoFull,
                    'ref' => $ref
                ]);
                $latest = null;
                $latestTs = 0;
                foreach ($existing as $row) {
                    $ts = strtotime((string)($row['data_creazione'] ?? '')) ?: 0;
                    if ($ts > $latestTs) {
                        $latestTs = $ts;
                        $latest = $row;
                    }
                }
                if ($latest && $latestTs > 0 && (time() - $latestTs) < 86400) {
                    $json = json_decode((string)($latest['loc_json'] ?? ''), true);
                    jsonResponse([
                        'ok' => true,
                        'cached' => true,
                        'repo' => $repoFull,
                        'ref' => $ref,
                        'source' => $latest['source'] ?? 'UNKNOWN',
                        'data_creazione' => $latest['data_creazione'] ?? null,
                        'totals' => $json['totals'] ?? null,
                        'by_language' => $json['by_language'] ?? null
                    ]);
                }
            } catch (Exception $e) {
                // continua
            }
        }

        [$owner, $repo] = explode('/', $repoFull, 2);
        // L'autenticazione GitHub usa esclusivamente il token OAuth di sessione.
        $token = $_SESSION['github_access_token'] ?? null;
        if (!$token) {
            throw new Exception('Token GitHub non disponibile (configurazione o sessione)');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'uda-ghzip-');
        if (!$tmpZip) {
            throw new Exception('Impossibile creare file temporaneo');
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$ref}";

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Sistema-UDA-PHP'
        ];

        // Seguiamo i redirect manualmente: il token OAuth viene inviato solo alla
        // prima richiesta (api.github.com) e non è mai inoltrato ai domini di
        // download. Accettiamo un piccolo numero di hop HTTPS.
        $redirectUrl = $url;
        $downloaded = false;
        $httpCode = 0;
        $curlErr = '';
        for ($hop = 0; $hop <= 5; $hop++) {
            $redirectLocation = '';
            $fh = fopen($tmpZip, 'wb');
            if (!$fh) {
                throw new Exception('Impossibile scrivere zip temporaneo');
            }
            $ch = curl_init($redirectUrl);
            curl_setopt($ch, CURLOPT_FILE, $fh);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $hop === 0
                ? $headers
                : ['Accept: application/vnd.github+json', 'User-Agent: Sistema-UDA-PHP']);
            curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($curl, string $header) use (&$redirectLocation): int {
                if (stripos($header, 'Location:') === 0) {
                    $redirectLocation = trim(substr($header, 9));
                }
                return strlen($header);
            });
            curl_setopt($ch, CURLOPT_TIMEOUT, 120);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
            $ok = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);
            fclose($fh);

            if (!$ok) {
                break;
            }
            if ($httpCode >= 300 && $httpCode < 400 && $redirectLocation !== '') {
                $redirectParts = parse_url($redirectLocation);
                if (($redirectParts['scheme'] ?? '') !== 'https') {
                    @unlink($tmpZip);
                    throw new Exception('Redirect GitHub non consentito');
                }
                $redirectUrl = $redirectLocation;
                continue;
            }
            $downloaded = true;
            break;
        }

        if (!$downloaded || $httpCode < 200 || $httpCode >= 300) {
            $bodySnippet = '';
            try {
                if (is_file($tmpZip)) {
                    $raw = (string)@file_get_contents($tmpZip, false, null, 0, 4096);
                    $json = json_decode($raw, true);
                    if (is_array($json) && !empty($json['message'])) {
                        $bodySnippet = ' - ' . $json['message'];
                    } elseif ($raw !== '') {
                        $rawTrim = trim(preg_replace('/\\s+/', ' ', $raw));
                        if ($rawTrim !== '' && strlen($rawTrim) < 400) {
                            $bodySnippet = ' - ' . $rawTrim;
                        }
                    }
                }
            } catch (\Throwable $e) {
                // ignore
            }
            @unlink($tmpZip);
            throw new Exception('Download zip fallito: ' . ($curlErr ?: "HTTP {$httpCode}") . $bodySnippet);
        }

        if (!class_exists('ZipArchive')) {
            @unlink($tmpZip);
            throw new Exception('ZipArchive non disponibile su PHP');
        }

        $extractDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda-ghloc-' . uniqid();
        if (!@mkdir($extractDir, 0777, true) && !is_dir($extractDir)) {
            @unlink($tmpZip);
            throw new Exception('Impossibile creare cartella temporanea');
        }

        try {
            \App\Core\Security\GitHubArchiveExtractor::extract($tmpZip, $extractDir);
        } catch (\Throwable $archiveError) {
            @unlink($tmpZip);
            ghRemoveDirRecursive($extractDir);
            throw new Exception($archiveError->getMessage(), 0, $archiveError);
        }
        @unlink($tmpZip);

        $dirs = glob($extractDir . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR);
        $rootDir = (is_array($dirs) && !empty($dirs)) ? $dirs[0] : $extractDir;

        $result = ghComputeLocWithClocIfAvailable($rootDir);
        $source = 'CLOC';
        if (!$result) {
            $result = ghComputeLocInternal($rootDir);
            $source = 'INTERNAL';
        }

        ghRemoveDirRecursive($extractDir);

        $payload = [
            'totals' => $result['totals'],
            'by_language' => $result['by_language']
        ];

        try {
            $snapshot = [
                'id_snapshot' => 'GHLOC_' . uniqid(),
                'id_test' => $testId,
                'repo_full_name' => $repoFull,
                'repo_html_url' => "https://github.com/{$repoFull}",
                'ref' => $ref,
                'default_branch' => '',
                'github_username' => '',
                'loc_total' => $payload['totals']['total'] ?? 0,
                'loc_code' => $payload['totals']['code'] ?? 0,
                'loc_comment' => $payload['totals']['comment'] ?? 0,
                'loc_blank' => $payload['totals']['blank'] ?? 0,
                'loc_json' => json_encode($payload),
                'source' => $source,
                'data_creazione' => date('Y-m-d H:i:s'),
                'id_utente' => $userId ?: null
            ];
            $dbAdapter->insertRow('GITHUB_REPO_LOC_SNAPSHOTS', $snapshot);
        } catch (Exception $e) {
            // non bloccare
        }

        jsonResponse([
            'ok' => true,
            'cached' => false,
            'repo' => $repoFull,
            'ref' => $ref,
            'source' => $source,
            'data_creazione' => date('Y-m-d H:i:s'),
            'totals' => $payload['totals'],
            'by_language' => $payload['by_language']
        ]);
    } catch (Throwable $e) {
        jsonResponse([
            'ok' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'github_repo_loc')
        ], 400);
    }
}

$successMessage = null;
$errorMessage = null;
$warningMessage = null;

if (!function_exists('ghSlugify')) {
    function ghSlugify(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }
        $value = strtolower($value);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
            if (is_string($converted) && $converted !== '') {
                $value = $converted;
            }
        }
        $value = preg_replace('/[^a-z0-9]+/i', '-', $value);
        $value = preg_replace('/-+/', '-', $value);
        return trim($value, '-');
    }
}

// ---- Studenti (provider-neutral): fonte primaria GITHUB_ASSIGNMENT_STUDENT_LINKS ----
// Ogni riga rappresenta uno studente del gruppo con codice di accettazione personale,
// username GitHub (valorizzato all'accettazione) e repository (creata alla configurazione).
$groupId = trim((string)($test['id_gruppo'] ?? ''));
$idGruppo = $groupId;

// La definizione della rubrica è comune a tutti gli studenti del test: viene
// letta una sola volta durante il rendering della pagina e riusata dal popup.
$pageRubricRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => (string)$testId]);
usort($pageRubricRows, static function (array $a, array $b): int {
    return (int)($a['ordine'] ?? 0) <=> (int)($b['ordine'] ?? 0);
});

$studentMap = [];
foreach ($dbAdapter->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $link) {
    $sid = trim((string)($link['id_studente'] ?? ''));
    if ($sid === '') {
        continue;
    }
    $studentMap[] = [
        'id_studente' => $sid,
        'github_username' => trim((string)($link['github_username'] ?? '')),
        'roster_identifier' => '',
        'student_repository_url' => trim((string)($link['student_repository_url'] ?? '')),
        'acceptance_code' => trim((string)($link['acceptance_code'] ?? '')),
        'accepted_at' => trim((string)($link['accepted_at'] ?? '')),
    ];
}

// Precarica i voti già registrati per questo assignment: dopo un salvataggio
// la select deve mostrare il voto esistente invece di tornare a "skip".
$existingGradesByStudent = [];
$existingGradeSortByStudent = [];
$gradeLinkMarker = 'github_assignment_review.php?test_id=' . rawurlencode((string)$testId);
$udaForGrades = (string)($test['id_uda'] ?? '');
if ($udaForGrades !== '' && $groupId !== '') {
    foreach ($dbAdapter->findWhere('VOTI', ['id_uda' => $udaForGrades, 'id_gruppo' => $groupId]) as $savedVote) {
        $studentIdForGrade = trim((string)($savedVote['id_studente'] ?? ''));
        if ($studentIdForGrade === '') {
            continue;
        }

        $origin = trim((string)($savedVote['link_origine'] ?? ''));
        $description = (string)($savedVote['descrizione'] ?? '');
        $isAssignmentGrade = $origin !== ''
            ? str_contains($origin, $gradeLinkMarker)
            : str_starts_with(strtolower(ltrim($description)), 'github classroom:');
        if (!$isAssignmentGrade) {
            continue;
        }

        $gradeValue = trim((string)($savedVote['voto'] ?? ''));
        if ($gradeValue === '') {
            $gradeValue = trim((string)($savedVote['giudizio'] ?? ''));
        }
        if ($gradeValue === '') {
            continue;
        }

        $gradeSort = trim((string)($savedVote['data_creazione'] ?? ''))
            ?: trim((string)($savedVote['data_valutazione'] ?? ''));
        if (!isset($existingGradeSortByStudent[$studentIdForGrade])
            || strcmp($gradeSort, $existingGradeSortByStudent[$studentIdForGrade]) >= 0) {
            $existingGradesByStudent[$studentIdForGrade] = normalizeGithubReviewGrade($gradeValue);
            $existingGradeSortByStudent[$studentIdForGrade] = $gradeSort;
        }
    }
}

// Fallback per valutazioni salvate dalla rubrica ma non ancora replicate in VOTI.
$savedRubricGradesByStudent = [];
if ($udaForGrades !== '' && $groupId !== '') {
    foreach ($dbAdapter->findWhere('VALUTAZIONI_RUBRICA', [
        'id_uda' => $udaForGrades,
        'id_rubrica' => (string)$testId,
        'id_gruppo' => $groupId
    ]) as $savedRubricGrade) {
        $note = strtolower(trim((string)($savedRubricGrade['note'] ?? '')));
        $savedJson = (string)($savedRubricGrade['dati_json'] ?? '');
        if ($note !== 'github_rubric' && !str_contains($savedJson, '"kind":"github_rubric"')) {
            continue;
        }
        $studentIdForGrade = trim((string)($savedRubricGrade['id_studente'] ?? ''));
        if ($studentIdForGrade === '') {
            continue;
        }
        $gradeValue = trim((string)($savedRubricGrade['voto_numerico'] ?? ''));
        if ($gradeValue === '') {
            $gradeValue = trim((string)($savedRubricGrade['voto_finale'] ?? ''));
        }
        if ($gradeValue !== '') {
            $savedRubricGradesByStudent[$studentIdForGrade] = normalizeGithubReviewGrade($gradeValue);
        }
    }
}

// Nomi studenti risolti dal resolver centrale del gruppo (mai persistiti).
$runtimeNamesByStudent = [];
if ($groupId !== '') {
    foreach ((new RuntimeStudentNameService($dbAdapter, $userId, $config))->resolveGroupStudents($groupId) as $runtimeStudent) {
        $studentKey = (string)$runtimeStudent['id_studente'];
        $runtimeName = trim((string)$runtimeStudent['nome_completo']);
        $runtimeNamesByStudent[$studentKey] = $runtimeName;
        // Chiave secondaria per il matching col login GitHub (gruppi senza membership interne).
        if (($runtimeStudent['provider'] ?? '') === 'github_classroom' && $studentKey !== '') {
            $runtimeNamesByStudent[strtolower($studentKey)] = $runtimeName;
        }
    }
}

// Commit info (ultimo commit e conteggio base): iteriamo lo studentMap,
// che ora deriva da GITHUB_ASSIGNMENT_STUDENT_LINKS (repo create alla configurazione).
$commitInfo = [];
if ($isAuthenticated && !empty($studentMap)) {
    foreach ($studentMap as $row) {
        $user = strtolower(trim((string)($row['github_username'] ?? '')));
        $repoUrl = (string)($row['student_repository_url'] ?? '');
        if ($user === '' || $repoUrl === '') {
            continue;
        }
        $parsed = parse_url($repoUrl);
        if (empty($parsed['path'])) {
            continue;
        }
        $pathParts = array_values(array_filter(explode('/', $parsed['path'])));
        if (count($pathParts) < 2) {
            continue;
        }
        [$owner, $repo] = [$pathParts[0], $pathParts[1]];
        try {
            $commits = $github->listRepoCommits($owner, $repo, null, null, 20, 1);
            $list = $commits ?? [];
            $last = $list[0] ?? null;
            $lastDate = $last['commit']['author']['date'] ?? null;
            $count = is_array($list) ? count($list) : 0;
            $commitInfo[$user] = [
                'last_commit' => $lastDate,
                'recent_count' => $count,
                'commits' => $list
            ];
        } catch (Exception $e) {
            // Non bloccare la pagina
            $commitInfo[$user] = [
                'last_commit' => null,
                'recent_count' => 0,
                'commits' => []
            ];
        }
    }
}

function gh_review_build_family_note($dbAdapter, array $test, string $testId, string $idUda, string $idGruppo, string $studentId): string
{
    $title = trim((string)($test['nome'] ?? ''));
    $lines = ['Attività di laboratorio con GitHub: ' . ($title !== '' ? $title : 'Progetto GitHub')];
    $lines[] = 'Valutazione:';

    $rubricRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $testId]);
    usort($rubricRows, static function (array $a, array $b): int {
        return (int)($a['ordine'] ?? 0) <=> (int)($b['ordine'] ?? 0);
    });

    $where = [
        'id_uda' => $idUda,
        'id_rubrica' => $testId,
        'id_studente' => $studentId
    ];
    if ($idGruppo !== '') {
        $where['id_gruppo'] = $idGruppo;
    }
    $savedRows = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
    if (empty($savedRows) && $idGruppo !== '') {
        unset($where['id_gruppo']);
        $savedRows = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
    }

    $savedJson = [];
    $savedRow = $savedRows[0] ?? null;
    if ($savedRow && !empty($savedRow['dati_json'])) {
        $decoded = json_decode((string)$savedRow['dati_json'], true);
        if (is_array($decoded)) {
            $savedJson = $decoded;
        }
    }
    $savedItems = is_array($savedJson['items'] ?? null) ? $savedJson['items'] : [];
    $hasDescriptor = false;

    foreach ($rubricRows as $rubricRow) {
        $order = (int)($rubricRow['ordine'] ?? 0);
        $savedItem = null;
        foreach ($savedItems as $item) {
            if (is_array($item) && (int)($item['ordine'] ?? 0) === $order) {
                $savedItem = $item;
                break;
            }
        }
        if (!$savedItem || !($savedItem['enabled'] ?? false)) {
            continue;
        }

        $level = (int)($savedItem['level'] ?? 0);
        if ($level < 1 || $level > 5) {
            continue;
        }
        $descriptor = trim((string)($rubricRow['livello_' . $level . '_desc'] ?? ''));
        if ($descriptor === '') {
            continue;
        }
        $indicator = trim((string)($rubricRow['nome_indicatore'] ?? ''));
        if ($indicator === '') {
            $indicator = 'Indicatore ' . $order;
        }
        $lines[] = $indicator . ': ' . $descriptor;
        $hasDescriptor = true;
    }

    if (!$hasDescriptor) {
        $lines[] = 'Nessun indicatore con descrittore assegnato.';
    }

    return implode("\n", $lines);
}

// Invio link di accettazione per email (riusa NotificationManager).
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_student_email') {
    try {
        $studentId = trim((string)($_POST['student_id'] ?? ''));
        if ($studentId === '' || $groupId === '') {
            throw new Exception('Studente o gruppo mancante.');
        }
        $acceptanceCode = '';
        foreach ($dbAdapter->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId, 'id_studente' => $studentId]) as $link) {
            $acceptanceCode = trim((string)($link['acceptance_code'] ?? ''));
            break;
        }
        if ($acceptanceCode === '') {
            throw new Exception('Codice di accettazione non trovato per lo studente.');
        }
        $email = '';
        $nome = '';
        foreach (gh_review_resolve_students($groupId) as $s) {
            if ((string)($s['id_studente'] ?? '') === $studentId) {
                $email = (string)($s['email'] ?? '');
                $nome = (string)($s['nome'] ?? '');
                break;
            }
        }
        if ($email === '') {
            throw new Exception('Email studente non risolta (verifica i roster Google Classroom/ClasseViva del gruppo).');
        }
        $link = app_url('public/accept_assignment.php') . '?code=' . urlencode($acceptanceCode);
        $body = '<p>Ciao ' . htmlspecialchars($nome !== '' ? $nome : 'studente', ENT_QUOTES, 'UTF-8') . ',</p>'
            . '<p>Ti è stato assegnato un assignment GitHub.</p>'
            . '<p>Accedi con il tuo account GitHub per accettare e ricevere la tua repository:</p>'
            . '<p><a href="' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '">' . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . '</a></p>';
        if (!(new NotificationManager($config))->sendHtmlEmail($email, 'Invito assignment: ' . (string)($test['nome'] ?? ''), $body)) {
            throw new Exception('Invio email non riuscito.');
        }
        $successMessage = 'Email di invito inviata a ' . $email . '.';
    } catch (Exception $e) {
        $errorMessage = 'Errore invio email: ' . $e->getMessage();
    }
}

// Salvataggio voti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    try {
        $tipoVoto = $_POST['tipo_voto'] ?? 'scritto';
        $grades = $_POST['voto'] ?? [];
        $comments = $_POST['commento'] ?? [];
        $studentIds = $_POST['id_studente'] ?? [];
        $usernames = $_POST['github_username'] ?? [];
        $repos = $_POST['repo_url'] ?? [];
        $selectedSaveIndexes = [];
        $selectedRaw = $_POST['salva_voto'] ?? [];
        if (is_array($selectedRaw)) {
            foreach ($selectedRaw as $idx => $flag) {
                if ((string)$flag === '1' && ctype_digit((string)$idx)) {
                    $selectedSaveIndexes[(int)$idx] = true;
                }
            }
        }

        $idGruppo = trim((string)($test['id_gruppo'] ?? ''));
        if ($idGruppo === '') {
            throw new Exception("Assignment non collegato a un gruppo didattico");
        }

        $testDateRaw = $test['data_somministrazione'] ?? ($test['data_creazione'] ?? null);
        $testDate = null;
        if (!empty($testDateRaw)) {
            $ts = strtotime((string)$testDateRaw);
            if ($ts) {
                $testDate = date('Y-m-d', $ts);
            }
        }
        if (!$testDate) {
            $testDate = date('Y-m-d');
        }

        $inserted = 0;
        foreach ($grades as $idx => $grade) {
            if (!isset($selectedSaveIndexes[$idx])) {
                continue;
            }
            $studentId = $studentIds[$idx] ?? '';
            if (!$studentId || ($grade === 'skip')) {
                continue;
            }

            $voto = null;
            $giudizio = '';
            if ($grade === 'i') {
                $giudizio = 'Impreparato';
            } elseif ($grade === 'a') {
                $giudizio = 'Assente';
            } else {
                $voto = floatval($grade);
            }

            $commento = trim($comments[$idx] ?? '');
            $repoUrl = $repos[$idx] ?? '';
            $username = $usernames[$idx] ?? '';

            $familyNote = gh_review_build_family_note(
                $dbAdapter,
                $test,
                (string)$testId,
                (string)($test['id_uda'] ?? ''),
                $idGruppo,
                (string)$studentId
            );
            $internalNote = 'GitHub Classroom: ' . ($test['nome'] ?? '');
            if (!empty($repoUrl)) {
                $internalNote .= "\nRepo: " . $repoUrl;
            }
            if (!empty($test['url_assignment_student'])) {
                $internalNote .= "\nLink Studente: " . $test['url_assignment_student'];
            }
            if (!empty($test['url_assignment_teacher'])) {
                $internalNote .= "\nLink Docente: " . $test['url_assignment_teacher'];
            }
            if ($commento !== '') {
                $internalNote .= "\nCommento: " . $commento;
            }

            $linkOrigine = app_url('public/github_assignment_review.php?test_id=' . urlencode((string)$testId));
            $votoData = [
                'id_voto' => 'VOTO_' . uniqid(),
                'id_uda' => $test['id_uda'],
                'id_gruppo' => $idGruppo,
                'id_studente' => $studentId,
                'tipo_voto' => $tipoVoto,
                'voto' => $voto,
                'giudizio' => $familyNote,
                'descrizione' => $internalNote,
                'data_valutazione' => $testDate,
                'data_creazione' => date('Y-m-d H:i:s'),
                'pubblicato' => 0,
                'provider_pubblicazione' => null,
                'external_publication_id' => null,
                'num_evidenze_positive' => 0,
                'num_evidenze_negative' => 0,
                'num_evidenze_totali' => 0,
                'id_utente' => $userId,
                'link_origine' => $linkOrigine
            ];

            $dbAdapter->insertRow('VOTI', $votoData);
            $inserted++;
        }

        if ($inserted > 0) {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, ['risultati_importati' => 'SI']);
        }
        $successMessage = "Salvati $inserted voti.";
    } catch (Exception $e) {
        $errorMessage = "Errore salvataggio voti: " . $e->getMessage();
    }
}

	$availableGrades = getClasseVivaGrades();
	$idGruppo = $idGruppo ?? '';

	?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Riepilogo Assignment GitHub - <?= htmlspecialchars($test['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
	    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
	    <style>
	        table.github-grades-table { width: 100%; table-layout: fixed; }
	        /* La metà sinistra resta libera quando il pannello rubrica è aperto. */
	        table.github-grades-table col.col-gh { width: 7%; }
	        table.github-grades-table col.col-repo { width: 32%; }
	        table.github-grades-table col.col-vote { width: 11%; }
	        table.github-grades-table td.repo-col a { word-break: break-all; }
	        table.github-grades-table th.vote-col,
	        table.github-grades-table td.vote-col { width: 11%; }
	        table.github-grades-table td.vote-col .student-vote-cell { display: flex; flex-direction: column; gap: .45rem; }
	        table.github-grades-table td.vote-col .student-info { line-height: 1.25; }
	        table.github-grades-table td.vote-col .rubric-open-btn { width: 100%; }
	        .github-review-page { width: 100%; max-width: none !important; margin-left: 0; margin-right: 0; }
	        table.github-grades-table td.comment-col { vertical-align: top; }
	        table.github-grades-table .comment-wrap { display: flex; align-items: stretch; width: 100%; height: 100%; }
	        table.github-grades-table td.comment-col textarea { flex: 1; width: 100%; min-height: 120px; resize: vertical; }
	        table.github-grades-table td.vote-col select { width: 100% !important; }
	        .commit-message-body { white-space: pre-wrap; }
	        .rubric-modal-table th.weight-col,
	        .rubric-modal-table td.weight-col { width: 40px; min-width: 40px; }
	        .rubric-modal-table input.rubric-weight { width: 100%; min-width: 2.5rem; padding: .1rem .1rem; font-size: .85rem; }
	        .rubric-modal-table td:nth-child(2) > .small,
	        .rubric-modal-table td.rubric-level > .small { font-size: .72rem; line-height: 1.2; }

            /* I pannelli di dettaglio restano leggibili anche sulle righe colorate. */
	        table.github-grades-table td .collapse .commit-details,
	        table.github-grades-table td .collapse .commit-details .list-group-item {
	            background-color: transparent !important;
	        }
	        .repo-loc {
	            background-color: #fff1f3 !important;
	            border-color: #f1b9c1 !important;
	            box-shadow: 0 3px 8px rgba(184, 76, 96, .18);
	        }
	        .repo-loc table {
	            font-size: .75rem;
	            --bs-table-bg: transparent;
	        }
	        .github-branches {
	            background-color: #edf8ef;
	            border: 1px solid #b7dfbd;
	            border-radius: .375rem;
	            padding: .45rem .6rem;
	            box-shadow: 0 3px 8px rgba(33, 110, 43, .14);
	        }
	        .github-branches .github-branch-link {
	            background-color: #d9f0dc;
	            border-color: #8fc997 !important;
	            color: #216e2b;
	            box-shadow: 0 2px 4px rgba(33, 110, 43, .28);
	            text-shadow: 0 1px 2px rgba(33, 110, 43, .2);
	        }
	        .github-issues {
	            background-color: #fff4e5;
	            border: 1px solid #f0c27b;
	            border-radius: .375rem;
	            padding: .45rem .6rem;
	            box-shadow: 0 3px 8px rgba(164, 81, 0, .14);
	        }
	        .github-issues .list-group-item {
	            background-color: transparent;
	            border-color: #f0c27b;
	        }
	        .github-issue-link,
	        .issue-reference {
	            color: #a45100 !important;
	            text-shadow: 0 2px 4px rgba(164, 81, 0, .28);
	        }
	        .github-issue-link:hover,
	        .issue-reference:hover {
	            color: #7a3b00 !important;
	        }
	        .github-issue-link .bi,
	        .issue-reference .bi {
	            color: inherit;
	        }
	        .github-tag-badge {
	            background-color: #dff3ff !important;
	            border: 1px solid #9bd7f5;
	            color: #075985 !important;
	            box-shadow: 0 2px 4px rgba(7, 89, 133, .26);
	            text-shadow: 0 1px 2px rgba(7, 89, 133, .18);
	        }
	        .github-branch-reference {
	            color: #216e2b;
	            font-weight: 600;
	            text-shadow: 0 2px 4px rgba(33, 110, 43, .24);
	        }

	        table.github-grades-table tr.rubric-row-active {
	            background-color: #fff3cd !important;
	        }
	        table.github-grades-table tr.rubric-row-active td:first-child {
	            border-left: 4px solid #fd7e14;
	        }
	        @keyframes github-student-row-flash {
	            0%, 100% { background-color: transparent; }
	            35%, 65% { background-color: #ffe08a; }
	        }
	        table.github-grades-table tr.student-row-flash > td {
	            animation: github-student-row-flash 1.2s ease-in-out;
	        }
	        @media (prefers-reduced-motion: reduce) {
	            table.github-grades-table tr.student-row-flash > td { animation: none; }
	        }

	        .rubric-panel {
	            position: fixed;
	            top: 88px;
	            right: 16px;
	            width: calc(50vw - 24px);
	            max-width: 980px;
	            min-width: 420px;
	            height: calc(100vh - 120px);
	            z-index: 1055;
	            display: none;
	        }
	        .rubric-panel.open { display: flex; flex-direction: column; }
	        .rubric-panel .rubric-panel-body { overflow: auto; padding: 12px; }
	        @media (max-width: 992px) {
	            .rubric-panel {
	                left: 16px;
	                right: 16px;
	                width: auto;
	                min-width: 0;
	            }
	        }

	        /* Revisione alla cieca: nasconde nomi/repo finché il toggle non è attivo */
	        body.review-blind .show-name { display: none !important; }
	        body.review-names .show-blind { display: none !important; }
	    </style>
	</head>
<body class="review-names">
        <?php
    $pageTitle = $test['nome'] ?? 'Assignment GitHub';
    $pageSubtitle = 'Assignment GitHub Classroom - UDA: ' . ($test['id_uda'] ?? '');
    $headerContainerClass = 'container-fluid px-3 github-review-header';
    ob_start();
    ?>
    <a class="nav-link" href="uda_tests.php?id=<?= urlencode($test['id_uda']) ?>">
        <i class="bi bi-arrow-left"></i> Torna ai Test
    </a>
    <a class="btn btn-outline-light btn-sm" href="github_rubriche.php?test_id=<?= urlencode($testId) ?>">
        <i class="bi bi-clipboard-data"></i> Rubrica
    </a>
    <a class="btn btn-outline-light btn-sm" href="github_rubriche.php">
        <i class="bi bi-gear"></i>
    </a>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container github-review-page mt-4">
<?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
	            <?php if (!empty($test['id_uda'])): ?>
	                <a class="btn btn-sm btn-success ms-3" href="uda_grades.php?id=<?= urlencode($test['id_uda']) ?>">
	                    <i class="bi bi-upload"></i> Vai alla pagina per la pubblicazione dei voti
	                </a>
	            <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($warningMessage): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($warningMessage) ?>
                <a class="btn btn-sm btn-outline-dark ms-3" href="user_integrations.php#github">Apri integrazioni</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($studentMap)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-info-circle"></i>
                Nessuno studente collegato a questo assignment. Gli studenti vengono aggiunti alla creazione dell'assignment (riga TEST "github") a partire dal gruppo didattico; se mancano, verifica il gruppo in <a href="teaching_groups.php">teaching_groups.php</a>.
            </div>
        <?php else: ?>
            <form method="POST">
                <input type="hidden" name="action" value="save_grades">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label"><strong>Tipo di Valutazione</strong></label>
                        <select name="tipo_voto" class="form-select">
                            <option value="scritto">Scritto</option>
                            <option value="orale">Orale</option>
                            <option value="pratico" selected>Pratico</option>
                        </select>
                    </div>
                    <div class="col-md-8">
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i>
                             I dettagli repository includono commit, issue, branch e tag caricati direttamente da GitHub.
                        </div>
                    </div>
	                </div>
	                <div class="mb-2">
	                    <button type="button" class="btn btn-success open-save-votes-modal" data-bs-toggle="modal" data-bs-target="#saveVotesModal">
	                        <i class="bi bi-save"></i> Salva voti selezionati
	                    </button>
	                </div>
	                <div class="d-flex align-items-center gap-2 mb-3">
	                    <div class="form-check form-switch">
	                        <input class="form-check-input" type="checkbox" role="switch" id="review-show-names" autocomplete="off" checked>
	                        <label class="form-check-label" for="review-show-names">Mostra nomi e repository</label>
	                    </div>
	                    <small class="text-muted">Se disattivato, la revisione è "alla cieca": si vedono solo gli ID.</small>
	                </div>
	                <div class="table-responsive">
	                    <table class="table table-striped align-top github-grades-table">
	                        <colgroup>
	                            <col class="col-gh">
	                            <col class="col-repo">
	                            <col class="col-vote">
	                            <col class="col-comment">
	                        </colgroup>
	                        <thead>
	                            <tr>
	                                <th>GitHub</th>
	                                <th>Repo</th>
                                <th class="vote-col">Studente/Voto</th>
                                <th class="comment-col">Commento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($studentMap as $idx => $row): ?>
                                <?php
                                $uname = (string)($row['github_username'] ?? '');
                                $lowerUser = strtolower(trim($uname));
                                $rid = (string)($row['roster_identifier'] ?? '');
                                $ridKey = strtolower(trim($rid));

                                $repoUrl = (string)($row['student_repository_url'] ?? '');
                                $commitCountTotal = null;
                                $defaultBranch = 'main';
                                $studentId = $row['id_studente'] ?? '';
                                // Nome visualizzato dal resolver centrale del gruppo.
                                $studentName = $runtimeNamesByStudent[(string)$studentId] ?? ($runtimeNamesByStudent[$lowerUser] ?? '');
                                $lastCommit = $commitInfo[$lowerUser]['last_commit'] ?? null;
                                $recentCount = $commitInfo[$lowerUser]['recent_count'] ?? null;
                                $prefill = [];
                                if ($repoUrl) {
                                    $prefill[] = "Repo: {$repoUrl}";
                                }
                                if ($defaultBranch) {
                                    $prefill[] = "Branch: {$defaultBranch}";
                                }
                                if ($commitCountTotal !== null && $commitCountTotal !== '') {
                                    $prefill[] = "Commit totali (Classroom): {$commitCountTotal}";
                                }
                                if ($recentCount !== null) {
                                    $prefill[] = "Ultimi commit caricati: {$recentCount}";
                                }
                                if ($lastCommit) {
                                    $prefill[] = "Ultimo commit: " . date('d/m/Y H:i', strtotime($lastCommit));
                                }
                                $prefillText = implode("\n", $prefill);
                                $prefillFull = $prefillText;
                                $prefillBlind = ($repoUrl !== '') ? str_replace($repoUrl, 'nascosta', $prefillText) : $prefillText;
                                // Identificativo anonimo stabile nella pagina: non espone username o repository.
                                $githubIdDisplay = (string)($idx + 1);
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="github_username[<?= $idx ?>]" value="<?= htmlspecialchars($uname) ?>">
                                        <div class="show-name"><i class="bi bi-github"></i> <?= htmlspecialchars($uname) ?></div>
                                        <div class="show-blind"><i class="bi bi-github"></i> <?= htmlspecialchars($githubIdDisplay) ?></div>
                                        <small class="text-muted show-name"><?= htmlspecialchars($row['roster_identifier'] ?? '') ?></small>
                                    </td>
                                    <td class="repo-col">
                                        <input type="hidden" name="repo_url[<?= $idx ?>]" value="<?= htmlspecialchars($repoUrl) ?>">
                                        <span class="show-blind text-muted">NASCOSTA</span>
                                        <span class="show-name">
                                        <?php if ($repoUrl): ?>
                                            <a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank"><?= htmlspecialchars($repoUrl) ?></a>
                                        <?php else: ?>
                                            <em class="text-muted">N/D</em>
                                        <?php endif; ?>
                                        </span>

                                        <?php
                                        $repoFullRow = '';
                                        if ($repoUrl) {
                                            $parsed = parse_url($repoUrl);
                                            $path = $parsed['path'] ?? '';
                                            $parts = array_values(array_filter(explode('/', $path)));
                                            if (count($parts) >= 2) {
                                                $repoFullRow = $parts[0] . '/' . $parts[1];
                                            }
                                        }
	                                        $locRef = $defaultBranch ?: 'main';
	                                        $locContainerId = 'loc-' . $idx;
	                                        $commitsList = $commitInfo[$lowerUser]['commits'] ?? [];
	                                        ?>

	                                        <?php if ($repoFullRow): ?>
	                                            <button class="btn btn-sm btn-outline-dark mt-2 show-details-btn" type="button">
	                                                <i class="bi bi-eye"></i> Mostra dettagli
	                                            </button>
	                                        <?php endif; ?>

	                                        <?php if ($repoFullRow): ?>
	                                            <div class="collapse mt-2" id="<?= htmlspecialchars($locContainerId) ?>">
	                                                <div class="border rounded p-2 bg-light repo-loc"
                                                     data-loaded="0"
                                                     data-repo="<?= htmlspecialchars($repoFullRow) ?>"
                                                     data-ref="<?= htmlspecialchars($locRef) ?>">
	                                                    <div class="text-muted">La sezione LOC viene calcolata all’apertura dei dettagli.</div>
	                                                </div>
	                                            </div>
	                                        <?php endif; ?>
	                                        <?php if ($repoFullRow): ?>
	                                            <button class="btn btn-sm btn-outline-secondary mt-1 d-none commits-toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#commits-<?= $idx ?>">
	                                                <i class="bi bi-list"></i> Dettaglio commit
	                                            </button>
                                            <div class="collapse mt-2" id="commits-<?= $idx ?>">
                                                <div class="github-review-metadata border rounded p-2 mb-2" data-repo="<?= htmlspecialchars($repoFullRow) ?>" data-loaded="0">
                                                    <div class="text-muted"><i class="bi bi-hourglass-split"></i> Metadati GitHub non ancora caricati.</div>
                                                </div>
                                                <ul class="list-group list-group-flush small github-commits-list">
                                                    <?php foreach ($commitsList as $c): ?>
                                                        <?php
                                                        $msg = (string)($c['commit']['message'] ?? '');
                                                        $msgParts = preg_split("/\\r\\n|\\n|\\r/", $msg, 2);
                                                        $msgTitle = (string)($msgParts[0] ?? '');
                                                        $msgBody = (string)($msgParts[1] ?? '');
                                                        $shaFull = $c['sha'] ?? '';
                                                        $sha = substr($shaFull, 0, 7);
                                                        $dt = $c['commit']['author']['date'] ?? '';
                                                        $url = $c['html_url'] ?? '';
                                                        $repoFull = '';
                                                        if ($repoUrl) {
                                                            $parsed = parse_url($repoUrl);
                                                            $path = $parsed['path'] ?? '';
                                                            $parts = array_values(array_filter(explode('/', $path)));
                                                            if (count($parts) >= 2) {
                                                                $repoFull = $parts[0] . '/' . $parts[1];
                                                            }
                                                        }
                                                        $detailsId = 'commit-details-' . $idx . '-' . preg_replace('~[^0-9a-f]~i', '', substr((string)$shaFull, 0, 12));
                                                        ?>
                                                        <li class="list-group-item px-0">
                                                            <div class="fw-bold"><?= htmlspecialchars($msgTitle) ?></div>
                                                            <?php if (trim($msgBody) !== ''): ?>
                                                                <div class="commit-message-body"><?= htmlspecialchars($msgBody) ?></div>
                                                            <?php endif; ?>
                                                            <div class="text-muted">
                                                                <?= htmlspecialchars($sha) ?> • <?= $dt ? date('d/m/Y H:i', strtotime($dt)) : '' ?>
                                                                <?php if ($url): ?>
                                                                    <a href="<?= htmlspecialchars($url) ?>" target="_blank" class="ms-2">Apri</a>
                                                                <?php endif; ?>
                                                                <?php if ($repoFull && $shaFull): ?>
                                                                    <button class="btn btn-sm btn-outline-primary ms-2 commit-details-btn"
                                                                            type="button"
                                                                            data-repo="<?= htmlspecialchars($repoFull) ?>"
                                                                            data-sha="<?= htmlspecialchars($shaFull) ?>"
                                                                            data-target="#<?= htmlspecialchars($detailsId) ?>"
                                                                            data-bs-toggle="collapse"
                                                                            data-bs-target="#<?= htmlspecialchars($detailsId) ?>">
                                                                        Dettagli
                                                                    </button>
                                                                <?php endif; ?>
                                                            </div>
                                                            <?php if ($repoFull && $shaFull): ?>
                                                                <div class="collapse mt-2" id="<?= htmlspecialchars($detailsId) ?>">
                                                                    <div class="border rounded p-2 bg-light commit-details" data-loaded="0">
                                                                        <div class="text-muted">Clicca “Dettagli” per caricare stats/files.</div>
                                                                    </div>
                                                                </div>
                                                            <?php endif; ?>
                                                        </li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="vote-col">
                                        <input type="hidden" name="id_studente[<?= $idx ?>]" value="<?= htmlspecialchars($studentId) ?>">
                                        <?php if ($studentId): ?>
                                            <?php
                                            $accepted = trim((string)($row['github_username'] ?? '')) !== '' || trim((string)($row['accepted_at'] ?? '')) !== '';
                                            $acceptanceCode = trim((string)($row['acceptance_code'] ?? ''));
                                            $savedGrade = $existingGradesByStudent[$studentId]
                                                ?? ($savedRubricGradesByStudent[$studentId] ?? 'skip');
                                            $defaultVoto = $_POST['voto'][$idx] ?? $savedGrade;
                                            ?>
	                                            <div class="student-vote-cell">
	                                                <div class="student-info">
                                                    <?php if ($accepted): ?>
                                                        <span class="badge bg-success">Accettato</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning text-dark">Assignment non ancora accettato</span>
                                                    <?php endif; ?>
                                                    <?php if ($studentName !== ''): ?><strong class="d-block show-name"><?= htmlspecialchars($studentName) ?></strong><?php endif; ?>
                                                    <small class="text-muted d-block">ID: <?= htmlspecialchars($studentId) ?></small>
                                                    <?php if (!$accepted && $acceptanceCode !== ''): ?>
                                                        <code class="small d-block text-break"><?= htmlspecialchars(app_url('public/accept_assignment.php') . '?code=' . urlencode($acceptanceCode)) ?></code>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Inviare il link di accettazione per email?');">
                                                            <input type="hidden" name="action" value="send_student_email">
                                                            <input type="hidden" name="student_id" value="<?= htmlspecialchars($studentId) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-primary mt-1"><i class="bi bi-envelope"></i> invia per email</button>
                                                        </form>
	                                                    <?php endif; ?>
	                                                </div>
	                                                <div class="form-check save-vote-wrap">
	                                                    <input class="form-check-input save-vote-checkbox"
	                                                           type="checkbox"
	                                                           id="save-vote-<?= $idx ?>"
	                                                           name="salva_voto[<?= $idx ?>]"
	                                                           value="1"
	                                                           data-index="<?= $idx ?>"
	                                                           data-student-name="<?= htmlspecialchars($studentName !== '' ? $studentName : $studentId) ?>"
	                                                           data-github-name="<?= htmlspecialchars($uname !== '' ? $uname : $studentId) ?>"
	                                                           data-blind-student="Studente <?= htmlspecialchars($githubIdDisplay) ?>"
	                                                           data-blind-github="<?= htmlspecialchars($githubIdDisplay) ?>">
	                                                    <label class="form-check-label small" for="save-vote-<?= $idx ?>">Seleziona per salvare</label>
	                                                </div>
	                                                <select name="voto[<?= $idx ?>]" class="form-select form-select-sm voto-select">
                                                    <?php foreach ($availableGrades as $g): ?>
                                                        <?php
                                                        $label = $g;
                                                        if ($g === 'i') $label = 'i (impreparato)';
                                                        elseif ($g === 'a') $label = 'a (assente)';
                                                        elseif ($g === 'skip') $label = '- non importare voto -';
                                                        ?>
                                                        <option value="<?= htmlspecialchars($g) ?>" <?= ($g === $defaultVoto) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-primary rubric-open-btn"
                                                        title="Apri rubrica"
                                                        aria-label="Apri rubrica"
                                                        data-student-id="<?= htmlspecialchars($studentId) ?>"
	                                                        data-student-name="<?= htmlspecialchars($studentName) ?>"
	                                                        data-github-username="<?= htmlspecialchars($uname) ?>"
	                                                        data-blind-github="<?= htmlspecialchars($githubIdDisplay) ?>"
	                                                        data-index="<?= $idx ?>"
                                                        data-repo-url="<?= htmlspecialchars($repoUrl) ?>"
                                                        data-repo-full="<?= htmlspecialchars($repoFullRow) ?>"
                                                        data-ref="<?= htmlspecialchars($locRef) ?>"
                                                        data-commit-total="<?= htmlspecialchars((string)($commitCountTotal ?? '')) ?>"
                                                        data-commit-loaded="<?= htmlspecialchars((string)($recentCount ?? '')) ?>"
                                                        data-last-commit="<?= htmlspecialchars((string)($lastCommit ?? '')) ?>">
                                                    <i class="bi bi-clipboard-check"></i>
                                                </button>
                                            </div>
                                        <?php else: ?>
                                            <em class="text-muted">Associa studente</em>
                                        <?php endif; ?>
                                    </td>
	                                    <td class="comment-col">
	                                        <?php if ($studentId): ?>
	                                            <div class="comment-wrap">
	                                                <textarea name="commento[<?= $idx ?>]" class="form-control form-control-sm review-comment" rows="1"
	                                                          data-blind="<?= htmlspecialchars(json_encode($prefillBlind, JSON_HEX_TAG), ENT_QUOTES) ?>"
	                                                          data-full="<?= htmlspecialchars(json_encode($prefillFull, JSON_HEX_TAG), ENT_QUOTES) ?>"
	                                                          placeholder="Commento docente (opzionale)"><?= htmlspecialchars($prefillBlind) ?></textarea>
	                                            </div>
	                                        <?php else: ?>
	                                            <em class="text-muted">-</em>
	                                        <?php endif; ?>
	                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <button type="button" class="btn btn-success open-save-votes-modal" data-bs-toggle="modal" data-bs-target="#saveVotesModal">
                    <i class="bi bi-save"></i> Salva voti selezionati
                </button>
            </form>

	        <div class="modal fade" id="saveVotesModal" tabindex="-1" aria-labelledby="saveVotesModalLabel" aria-hidden="true">
	            <div class="modal-dialog modal-xl modal-dialog-scrollable">
	                <div class="modal-content">
	                    <div class="modal-header">
	                        <h5 class="modal-title" id="saveVotesModalLabel"><i class="bi bi-save"></i> Seleziona i voti da salvare</h5>
	                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
	                    </div>
	                    <div class="modal-body">
	                        <p class="text-muted small">Sono elencati tutti i voti disponibili, compresi quelli già presenti nel database. Verranno salvate solo le righe selezionate con un voto diverso da “- non importare voto -”.</p>
	                        <div id="saveVotesModalStatus" class="alert alert-warning d-none" role="alert"></div>
	                        <div class="table-responsive">
	                            <table class="table table-sm align-middle" id="saveVotesSummaryTable">
	                                <thead><tr><th></th><th>Studente</th><th>GitHub</th><th>Voto</th></tr></thead>
	                                <tbody id="saveVotesSummaryBody"></tbody>
	                            </table>
	                        </div>
	                    </div>
	                    <div class="modal-footer">
	                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
	                        <button type="button" class="btn btn-success" id="confirmSaveVotesBtn"><i class="bi bi-check2"></i> Conferma salvataggio voti</button>
	                    </div>
	                </div>
	            </div>
	        </div>
	        <?php endif; ?>
	    </div>

		    <!-- Pannello flottante Rubrica GitHub (non modale) -->
	    <div id="rubricPanel" class="rubric-panel shadow border rounded bg-white">
		        <div class="d-flex justify-content-between align-items-start px-3 py-2 border-bottom">
		            <div>
		                <div class="fw-semibold"><i class="bi bi-clipboard-check"></i> Rubrica GitHub</div>
		                <div class="text-muted small" id="rubricModalSubtitle"></div>
		            </div>
		            <div class="d-flex gap-2 align-items-center">
		                <div class="btn-group btn-group-sm" role="group" aria-label="Navigazione studenti">
		                    <button type="button" class="btn btn-outline-primary" id="rubricNavPrev" title="Studente precedente" aria-label="Studente precedente" disabled>
		                        <i class="bi bi-chevron-up"></i>
		                    </button>
		                    <button type="button" class="btn btn-outline-primary" id="rubricNavNext" title="Studente successivo" aria-label="Studente successivo" disabled>
		                        <i class="bi bi-chevron-down"></i>
		                    </button>
		                </div>
	                <span class="text-muted small text-nowrap" id="rubricNavPosition"></span>
		                <a class="btn btn-sm btn-outline-secondary" id="rubricEditLink" target="_blank" title="Modifica rubrica">
		                    <i class="bi bi-pencil-square"></i>
		                </a>
		                <button type="button" class="btn btn-sm btn-outline-secondary" id="rubricCloseBtn" aria-label="Chiudi">
		                    <i class="bi bi-x-lg"></i>
		                </button>
		            </div>
		        </div>
		        <div class="rubric-panel-body">
		            <div class="row g-2">
		                <div class="col-md-9">
		                    <div class="border rounded p-2 bg-light">
		                        <div class="fw-semibold mb-1">Indicatori</div>
		                        <div id="rubricTableHost" class="table-responsive"></div>
		                    </div>
		                </div>
		                <div class="col-md-3">
		                    <div class="border rounded p-2 bg-light h-100">
		                        <div class="fw-semibold mb-1">Dati (dal portale)</div>
	                        <div class="small" id="rubricMetrics"></div>
	                        <hr class="my-2">
	                        <div id="rubricAttributionsStatus" class="alert alert-info py-1 px-2 small mb-2 d-none" role="status" aria-live="polite">
	                            <span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>
	                            Caricamento attribuzioni…
	                        </div>
	                        <div class="d-flex justify-content-between align-items-center">
		                            <div>
		                                <div class="fw-semibold">Voto finale</div>
		                                <div class="text-muted small">Punti (su 24): <span id="rubricPoints">-</span></div>
		                            </div>
		                            <div class="text-end">
	                                <div class="fs-4 fw-bold" id="rubricGrade">-</div>
	                                <div class="text-muted small">Voto (non arrotondato): <span id="rubricGradeRaw">-</span></div>
	                            </div>
	                        </div>
	                        <div class="border-top mt-2 pt-2 small" id="rubricEvaluationSummary"></div>
	                        <div class="mt-2 d-grid gap-2">
		                            <button type="button" class="btn btn-primary" id="rubricApplyBtn">
		                                <i class="bi bi-check2-circle"></i> Applica al voto
		                            </button>
		                        </div>
		                        <div class="small mt-2" id="rubricSaveStatus"></div>
		                    </div>
		                </div>
		            </div>
		        </div>
		    </div>

	    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
	    <script>
        function escapeHtml(str) {
            return String(str ?? '').replace(/[&<>"']/g, function (m) {
                return ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[m]);
            });
        }

        const REVIEW_CSRF_TOKEN = <?= \App\Core\Security\OutputEncoder::json($csrfToken) ?>;
        let githubMetadataCounter = 0;

        function safeGithubAnchor(url, label, className) {
            const value = String(url ?? '');
            if (!/^https:\/\/github\.com\/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+(?:\/|$)/i.test(value)) {
                return escapeHtml(label);
            }
            return '<a href="' + escapeHtml(value) + '" target="_blank" rel="noopener noreferrer"' +
                (className ? ' class="' + escapeHtml(className) + '"' : '') + '>' + label + '</a>';
        }

        function formatGithubDate(value) {
            const raw = String(value ?? '').trim();
            if (!raw) return '—';
            const date = new Date(raw);
            if (Number.isNaN(date.getTime())) return escapeHtml(raw);
            return escapeHtml(date.toLocaleString('it-IT'));
        }

        function renderIssueReferences(refs) {
            if (!Array.isArray(refs) || !refs.length) return '';
            return '<span class="github-issue-references ms-2">' + refs.map(function (ref) {
                const icon = '<i class="' + escapeHtml(ref.icon || 'bi bi-exclamation-circle') + '"></i>';
                return safeGithubAnchor(ref.url, icon + ' ' + escapeHtml(ref.label || ('#' + (ref.number || ''))), 'issue-reference me-1');
            }).join('') + '</span>';
        }

        function renderCommitMetadataItem(commit, repoFull) {
            const sha = String(commit?.sha || '');
            const itemId = 'github-meta-commit-' + (++githubMetadataCounter) + '-' + sha.slice(0, 12);
            const title = escapeHtml(commit?.title || '(messaggio vuoto)');
            const body = String(commit?.body || '').trim();
            const tags = Array.isArray(commit?.tags) ? commit.tags : [];
            const tagHtml = tags.map(function (tag) {
                return '<span class="badge github-tag-badge me-1"><i class="bi bi-tag"></i> ' + escapeHtml(tag) + '</span>';
            }).join('');
            const branch = commit?.branch_origin || {};
            let branchLabel = 'Branch: origine non determinabile';
            if (branch.source === 'pull_request') {
                branchLabel = 'Branch origine (PR): ' + String(branch.label || '');
            } else if (branch.source === 'head_branches') {
                branchLabel = 'Branch attuale (HEAD): ' + String(branch.label || '');
            }
            const detailsButton = sha ? '<button class="btn btn-sm btn-outline-primary ms-2 commit-details-btn" type="button"' +
                ' data-repo="' + escapeHtml(repoFull) + '" data-sha="' + escapeHtml(sha) + '"' +
                ' data-target="#' + escapeHtml(itemId) + '" data-bs-toggle="collapse" data-bs-target="#' + escapeHtml(itemId) + '">Dettagli</button>' : '';
            const details = sha ? '<div class="collapse mt-2" id="' + escapeHtml(itemId) + '">' +
                '<div class="border rounded p-2 bg-light commit-details" data-loaded="0"><div class="text-muted">Clicca “Dettagli” per caricare stats/files.</div></div>' +
                '</div>' : '';
            return '<li class="list-group-item px-0">' +
                '<div class="fw-bold">' + title + renderIssueReferences(commit?.issue_refs) + '</div>' +
                (body ? '<div class="commit-message-body">' + escapeHtml(body) + renderIssueReferences(commit?.issue_refs) + '</div>' : '') +
                '<div class="text-muted">' + escapeHtml(commit?.short_sha || sha.slice(0, 7)) + ' • ' + formatGithubDate(commit?.date) +
                ' <span class="ms-2 github-branch-reference"><i class="bi bi-diagram-3"></i> ' + escapeHtml(branchLabel) + '</span> ' + tagHtml +
                (commit?.url ? safeGithubAnchor(commit.url, 'Apri', 'ms-2') : '') + detailsButton +
                '</div>' + details + '</li>';
        }

        function renderRepoMetadata(container, data, repoFull) {
            const branches = Array.isArray(data.branches) ? data.branches : [];
            const issues = Array.isArray(data.issues) ? data.issues : [];
            const commits = Array.isArray(data.commits) ? data.commits : [];
            const warnings = Array.isArray(data.warnings) ? data.warnings : [];
            let html = '<div class="d-flex flex-wrap gap-2 mb-2"><span class="badge text-bg-secondary">Commit: ' + escapeHtml(commits.length) + '</span>' +
                '<span class="badge text-bg-secondary">Branch: ' + escapeHtml(branches.length) + '</span>' +
                '<span class="badge text-bg-secondary">Issue: ' + escapeHtml(issues.length) + '</span></div>';

            html += '<div class="github-branches mb-2"><strong><i class="bi bi-diagram-3"></i> Branch (' + escapeHtml(branches.length) + ')</strong>: ';
            html += branches.length ? branches.map(function (branch) {
                const url = 'https://github.com/' + repoFull + '/tree/' + encodeURIComponent(branch);
                return safeGithubAnchor(url, '<i class="bi bi-diagram-3"></i> ' + escapeHtml(branch), 'badge github-branch-link me-1');
            }).join('') : '<span class="text-muted">nessun branch disponibile</span>';
            html += '</div>';

            html += '<div class="github-issues"><strong><i class="bi bi-exclamation-circle"></i> Issue</strong>';
            if (!issues.length) {
                html += ': <span class="text-muted">nessuna issue aperta o chiusa disponibile</span>';
            } else {
                html += '<div class="list-group list-group-flush mt-1">';
                issues.forEach(function (issue) {
                    const stateClass = issue.state === 'closed' ? 'text-bg-secondary' : 'text-bg-success';
                    html += '<div class="list-group-item px-0 py-2">' +
                        safeGithubAnchor(issue.url, '<i class="bi bi-exclamation-circle"></i> #' + escapeHtml(issue.number) + ' ' + escapeHtml(issue.title || '(senza titolo)'), 'github-issue-link fw-semibold') +
                        ' <span class="badge ' + stateClass + ' ms-1">' + escapeHtml(issue.state || 'open') + '</span>' +
                        '<div class="small text-muted">Aperta: ' + formatGithubDate(issue.created_at) +
                        ' · Chiusa: ' + formatGithubDate(issue.closed_at) + '</div>' +
                        '<div class="small mt-1">' + escapeHtml(issue.body || 'Nessuna descrizione.') + '</div>';
                    const linkedCommits = Array.isArray(issue.commits) ? issue.commits : [];
                    if (linkedCommits.length) {
                        html += '<div class="small mt-1"><strong>Commit collegati:</strong> ' + linkedCommits.map(function (linked) {
                            return safeGithubAnchor(linked.url, '<i class="bi bi-git"></i> ' + escapeHtml(linked.sha.slice(0, 7)), 'me-1');
                        }).join('') + '</div>';
                    }
                    html += '</div>';
                });
                html += '</div>';
            }
            html += '</div>';
            if (warnings.length) {
                html += '<div class="alert alert-warning py-1 px-2 small mt-2 mb-0">' + warnings.map(escapeHtml).join('<br>') + '</div>';
            }
            container.innerHTML = html;
            container.dataset.loaded = '1';

            const list = container.parentElement ? container.parentElement.querySelector('.github-commits-list') : null;
            if (list) {
                list.innerHTML = commits.length
                    ? commits.map(function (commit) { return renderCommitMetadataItem(commit, repoFull); }).join('')
                    : '<li class="list-group-item px-0 text-muted">Nessun commit disponibile.</li>';
                bindCommitDetailsButtons(list);
            }
        }

        async function loadRepoMetadata(container, repoFull) {
            if (!container || !repoFull || container.dataset.loaded === '1' || container.dataset.loading === '1') return;
            container.dataset.loading = '1';
            container.innerHTML = '<div class="text-muted"><i class="bi bi-hourglass-split"></i> Caricamento issue, branch, tag e commit…</div>';
            try {
                const body = new URLSearchParams({action: 'repo_metadata', repo: repoFull, csrf_token: REVIEW_CSRF_TOKEN});
                const response = await fetch(window.location.href, {method: 'POST', headers: {'Accept': 'application/json'}, body});
                const data = await response.json();
                if (!data.ok) throw new Error(data.error || 'Metadati non disponibili');
                renderRepoMetadata(container, data, repoFull);
            } catch (error) {
                container.innerHTML = '<div class="text-danger"><i class="bi bi-exclamation-triangle"></i> ' + escapeHtml(error.message || 'Errore nel caricamento dei metadati') + '</div>';
                container.dataset.loaded = '0';
            } finally {
                delete container.dataset.loading;
            }
        }

        async function loadCommitDetails(container, repoFull, sha, withComments) {
            if (!container) return;

            container.innerHTML = '<div class="text-muted">Caricamento…</div>';

            const body = new URLSearchParams({action: 'commit_details', repo: repoFull, sha, with_comments: withComments ? '1' : '0'});
            const res = await fetch(window.location.href, {method: 'POST', headers: {'Accept': 'application/json'}, body});
            const data = await res.json();

            if (!data.ok) {
                container.innerHTML = '<div class="text-danger">Errore: ' + escapeHtml(data.error || 'Errore') + '</div>';
                return;
            }

            const commit = data.commit || {};
            const stats = commit.stats || {};
            const files = Array.isArray(commit.files) ? commit.files : [];
            const issueRefs = Array.isArray(data.issue_refs) ? data.issue_refs : [];

            let html = '';
            if (issueRefs.length) {
                html += '<div class="mb-2"><strong>Issue:</strong> ' + renderIssueReferences(issueRefs) + '</div>';
            }
            html += '<div class="mb-2"><strong>Stats</strong>: +' + escapeHtml(stats.additions ?? '-') +
                ' / -' + escapeHtml(stats.deletions ?? '-') +
                ' (tot ' + escapeHtml(stats.total ?? '-') + ')</div>';

            html += '<div class="mb-2"><strong>Files</strong>: ' + escapeHtml(files.length) + (data.files_truncated ? ' (parziale)' : '') + '</div>';
            if (files.length) {
                html += '<ul class="list-group list-group-flush">';
                files.forEach(function (f) {
                    html += '<li class="list-group-item px-0 py-1">'
                        + '<span class="badge bg-secondary me-2">' + escapeHtml(f.status ?? '') + '</span>'
                        + '<span class="font-monospace">' + escapeHtml(f.filename ?? '') + '</span>'
                        + '<span class="text-muted ms-2">+' + escapeHtml(f.additions ?? '-') + ' / -' + escapeHtml(f.deletions ?? '-') + ' (Δ ' + escapeHtml(f.changes ?? '-') + ')</span>'
                        + '</li>';
                });
                html += '</ul>';
                if (data.files_truncated) {
                    html += '<div class="text-muted mt-1">Troppi file: mostrati solo i primi 50.</div>';
                }
            } else {
                html += '<div class="text-muted">Nessun file disponibile.</div>';
            }

            if (!withComments) {
                html += '<button type="button" class="btn btn-sm btn-outline-secondary mt-2 load-commit-comments">Carica commenti commit</button>';
            } else {
                const comments = Array.isArray(data.comments) ? data.comments : [];
                html += '<div class="mt-2"><strong>Commenti</strong>: ' + escapeHtml(comments.length) + '</div>';
                if (comments.length) {
                    html += '<ul class="list-group list-group-flush">';
                    comments.forEach(function (c) {
                        html += '<li class="list-group-item px-0 py-1">'
                            + '<div class="fw-semibold">' + escapeHtml(c.user?.login ?? '') + ' • ' + escapeHtml(c.created_at ?? '') + '</div>'
                            + '<div>' + escapeHtml(c.body ?? '') + '</div>'
                            + (c.html_url ? '<div><a target="_blank" href="' + escapeHtml(c.html_url) + '">Apri su GitHub</a></div>' : '')
                            + '</li>';
                    });
                    html += '</ul>';
                }
            }

            container.innerHTML = html;
            container.dataset.loaded = '1';
            container.dataset.repo = repoFull;
            container.dataset.sha = sha;
            container.dataset.withComments = withComments ? '1' : '0';

            const btn = container.querySelector('.load-commit-comments');
            if (btn) {
                btn.addEventListener('click', function () {
                    loadCommitDetails(container, repoFull, sha, true);
                });
            }
        }

        function bindCommitDetailsButtons(scope) {
            const root = scope || document;
            root.querySelectorAll('.commit-details-btn:not([data-bound])').forEach(function (btn) {
                btn.dataset.bound = '1';
                btn.addEventListener('click', function () {
                const repoFull = btn.dataset.repo;
                const sha = btn.dataset.sha;
                const targetSelector = btn.dataset.target;
                const container = document.querySelector(targetSelector + ' .commit-details');
                if (!container) return;

                if (container.dataset.loaded === '1') {
                    return;
                }

                loadCommitDetails(container, repoFull, sha, false);
                });
            });
        }

        bindCommitDetailsButtons(document);

        async function loadRepoLoc(container, repoFull, ref, force) {
            if (!container) return;

            container.innerHTML = '<div class="text-muted">Calcolo LOC...</div>';

            const body = new URLSearchParams({action: 'repo_loc', repo: repoFull, ref, force: force ? '1' : '0'});
            let res = null;
            let data = null;
            try {
                res = await fetch(window.location.href, {method: 'POST', headers: {'Accept': 'application/json'}, body});
                const responseText = await res.text();
                data = JSON.parse(responseText);
            } catch (error) {
                const status = res && res.status ? ` (HTTP ${res.status})` : '';
                container.innerHTML = '<div class="text-danger">Errore: risposta LOC non valida dal server' + status + '.</div>';
                return;
            }

            if (!data.ok) {
                container.innerHTML = '<div class="text-danger">Errore: ' + escapeHtml(data.error || 'Errore') + '</div>';
                return;
            }

            const totals = data.totals || {};
            const byLang = data.by_language || {};
            const source = data.source || '';
            const when = data.data_creazione || '';
            const cached = data.cached ? ' (cache)' : '';

            let html = '';
            html += '<div class="mb-2"><strong>LOC</strong> ' + cached + ' <span class="text-muted">[' + escapeHtml(source) + ' ' + escapeHtml(when) + ']</span></div>';
            html += '<div class="mb-2">File: ' + escapeHtml(totals.files ?? '-') + ' • Tot: ' + escapeHtml(totals.total ?? '-') +
                ' • Code: ' + escapeHtml(totals.code ?? '-') + ' • Comment: ' + escapeHtml(totals.comment ?? '-') + ' • Blank: ' + escapeHtml(totals.blank ?? '-') + '</div>';

            const entries = Object.entries(byLang || {});
            if (entries.length) {
                html += '<div class="table-responsive"><table class="table table-sm mb-2 loc-table"><thead><tr>' +
                    '<th>Linguaggio</th><th class="text-end">File</th><th class="text-end">Tot</th><th class="text-end">Code</th><th class="text-end">Comm</th><th class="text-end">Blank</th>' +
                    '</tr></thead><tbody>';
                entries.forEach(function ([lang, row]) {
                    html += '<tr>' +
                        '<td>' + escapeHtml(lang) + '</td>' +
                        '<td class="text-end">' + escapeHtml(row.files ?? '-') + '</td>' +
                        '<td class="text-end">' + escapeHtml(row.total ?? '-') + '</td>' +
                        '<td class="text-end">' + escapeHtml(row.code ?? '-') + '</td>' +
                        '<td class="text-end">' + escapeHtml(row.comment ?? '-') + '</td>' +
                        '<td class="text-end">' + escapeHtml(row.blank ?? '-') + '</td>' +
                        '</tr>';
                });
                html += '</tbody></table></div>';
            }

            html += '<button type="button" class="btn btn-sm btn-outline-secondary repo-loc-force">Ricalcola</button>';

            container.innerHTML = html;
            container.dataset.loaded = '1';
            container.dataset.repo = repoFull;
            container.dataset.ref = ref;

            const forceBtn = container.querySelector('.repo-loc-force');
            if (forceBtn) {
                forceBtn.addEventListener('click', function () {
                    loadRepoLoc(container, repoFull, ref, true);
                });
            }
        }

	        function showCollapseElement(el) {
	            if (!el) return;
	            try {
	                bootstrap.Collapse.getOrCreateInstance(el, {toggle: false}).show();
	            } catch (e) {
	                // ignore
	            }
	        }

	        async function runWithConcurrency(items, limit, fn) {
	            const queue = items.slice();
	            const workers = [];
            const worker = async () => {
                while (queue.length) {
                    const item = queue.shift();
                    await fn(item);
                }
            };
            for (let i = 0; i < Math.max(1, limit); i++) {
                workers.push(worker());
            }
	            await Promise.all(workers);
	        }

	        function fitCommentTextareaToRow(row) {
	            if (!row) return;
	            const cell = row.querySelector('td.comment-col');
	            const textarea = cell ? cell.querySelector('textarea') : null;
	            if (!cell || !textarea) return;

	            const rect = cell.getBoundingClientRect();
	            const styles = window.getComputedStyle(cell);
	            const paddingTop = parseFloat(styles.paddingTop || '0') || 0;
	            const paddingBottom = parseFloat(styles.paddingBottom || '0') || 0;
	            const target = Math.max(120, Math.floor(rect.height - paddingTop - paddingBottom));
	            textarea.style.height = target + 'px';
	        }

	        function setCommentTextareaExpanded(row, expanded) {
	            if (!row) return;
	            const cell = row.querySelector('td.comment-col');
	            const textarea = cell ? cell.querySelector('textarea') : null;
	            if (!textarea) return;

	            if (!expanded) {
	                textarea.style.height = '';
	                textarea.style.resize = 'vertical';
	                return;
	            }

	            textarea.style.resize = 'none';
	            fitCommentTextareaToRow(row);
	            requestAnimationFrame(function () { fitCommentTextareaToRow(row); });
	            setTimeout(function () { fitCommentTextareaToRow(row); }, 350);
	            setTimeout(function () { fitCommentTextareaToRow(row); }, 900);
	        }

	        document.querySelectorAll('.show-details-btn').forEach(function (btn) {
	            btn.addEventListener('click', async function () {
	                const cell = btn.closest('td');
	                if (!cell) return;
	                const row = btn.closest('tr');

                // Lista commit
                const commitsToggleBtn = cell.querySelector('.commits-toggle-btn');
                const commitsTarget = commitsToggleBtn ? (commitsToggleBtn.getAttribute('data-bs-target') || commitsToggleBtn.dataset.bsTarget || '') : '';
                const commitsEl = commitsTarget ? document.querySelector(commitsTarget) : null;

	                const isOpen = (commitsEl && commitsEl.classList.contains('show'));
	                if (isOpen) {
	                    setCommentTextareaExpanded(row, false);
                    if (commitsEl) {
                        try { bootstrap.Collapse.getOrCreateInstance(commitsEl, {toggle: false}).hide(); } catch (e) {}
                    }
                    btn.innerHTML = '<i class="bi bi-eye"></i> Mostra dettagli';
                    return;
                }

                // Apri e carica (senza ricaricare se già caricato)
                btn.innerHTML = '<i class="bi bi-eye-slash"></i> Nascondi dettagli';

	                if (commitsEl) {
	                    showCollapseElement(commitsEl);
	                }

                // La LOC è parte dei dettagli: si apre e si calcola per prima,
                // senza un pulsante separato nella riga.
                const locContainer = cell.querySelector('.repo-loc');
                if (locContainer) {
                    const locCollapse = locContainer.closest('.collapse');
                    if (locCollapse) showCollapseElement(locCollapse);
                    if (locContainer.dataset.loaded !== '1') {
                        await loadRepoLoc(locContainer, locContainer.dataset.repo || '', locContainer.dataset.ref || 'main', false);
                    }
                }

	                setCommentTextareaExpanded(row, true);

                const metadataContainer = cell.querySelector('.github-review-metadata');
                const metadataRepo = metadataContainer ? (metadataContainer.dataset.repo || '') : '';
                if (metadataContainer && metadataRepo) {
                    await loadRepoMetadata(metadataContainer, metadataRepo);
                }

	                // I file/stats restano caricati automaticamente solo per i
                // primi 20 commit; gli altri hanno il pulsante "Dettagli" per
                // evitare centinaia di richieste API in una sola apertura.
	                const detailButtons = Array.from(cell.querySelectorAll('.commit-details-btn')).slice(0, 20);
	                for (const detailBtn of detailButtons) {
                        const targetSelector = detailBtn.dataset.target || '';
                        const collapseSelector = detailBtn.getAttribute('data-bs-target') || detailBtn.dataset.bsTarget || targetSelector;
                        const collapseEl = collapseSelector ? document.querySelector(collapseSelector) : null;
                        if (collapseEl) {
                            showCollapseElement(collapseEl);
                        }

                        const container = targetSelector ? document.querySelector(targetSelector + ' .commit-details') : null;
                        if (!container) continue;
                        if (container.dataset.loaded === '1') continue;

                        const repoFull = detailBtn.dataset.repo || '';
                        const sha = detailBtn.dataset.sha || '';
                        if (!repoFull || !sha) continue;

                        try {
                            await loadCommitDetails(container, repoFull, sha, false);
                        } catch (e) {
                            // continua con i successivi
                        }
	                }
 	            });
 	        });

	        const RUBRIC_CTX = {
	            test_id: <?= \App\Core\Security\OutputEncoder::json((string)$testId) ?>,
	            id_uda: <?= \App\Core\Security\OutputEncoder::json((string)($test['id_uda'] ?? '')) ?>,
	            id_gruppo: <?= \App\Core\Security\OutputEncoder::json((string)($idGruppo ?? '')) ?>,
	            rubric_rows: <?= \App\Core\Security\OutputEncoder::json($pageRubricRows) ?>,
	            has_rubric: <?= !empty($pageRubricRows) ? 'true' : 'false' ?>,
	            rubric_editor_url: <?= \App\Core\Security\OutputEncoder::json('github_rubriche.php?test_id=' . urlencode((string)$testId)) ?>
	        };

	        const rubricPanelEl = document.getElementById('rubricPanel');
	        const rubricHost = document.getElementById('rubricTableHost');
	        const rubricMetrics = document.getElementById('rubricMetrics');
	        const rubricAttributionsStatusEl = document.getElementById('rubricAttributionsStatus');
		        const rubricPointsEl = document.getElementById('rubricPoints');
		        const rubricGradeEl = document.getElementById('rubricGrade');
		        const rubricGradeRawEl = document.getElementById('rubricGradeRaw');
		        const rubricEvaluationSummaryEl = document.getElementById('rubricEvaluationSummary');
		        const rubricSubtitleEl = document.getElementById('rubricModalSubtitle');
		        const rubricSaveStatusEl = document.getElementById('rubricSaveStatus');
		        const rubricApplyBtn = document.getElementById('rubricApplyBtn');
		        const rubricEditLink = document.getElementById('rubricEditLink');
		        const rubricCloseBtn = document.getElementById('rubricCloseBtn');
		        const rubricNavPrevEl = document.getElementById('rubricNavPrev');
		        const rubricNavNextEl = document.getElementById('rubricNavNext');
		        const rubricNavPositionEl = document.getElementById('rubricNavPosition');

		        let rubricContext = null;
		        let rubricState = null;
		        let rubricSaveTimer = null;
		        let rubricSaveInFlight = Promise.resolve();
		        let rubricLastSavedAt = 0;
	        let rubricHighlightedRow = null;
	        let rubricCurrentButton = null;
	        let rubricLoadSequence = 0;
	        let rubricLoadController = null;
	        let rubricAttributionsLoaded = false;

		        function getRubricButtons() {
		            return Array.from(document.querySelectorAll('.rubric-open-btn'));
		        }

		        function updateRubricNavigation() {
		            const buttons = getRubricButtons();
		            const currentIndex = rubricCurrentButton ? buttons.indexOf(rubricCurrentButton) : -1;
		            const hasCurrent = currentIndex >= 0;
		            if (rubricNavPrevEl) rubricNavPrevEl.disabled = !hasCurrent || currentIndex <= 0;
		            if (rubricNavNextEl) rubricNavNextEl.disabled = !hasCurrent || currentIndex >= buttons.length - 1;
		            if (rubricNavPositionEl) {
		                rubricNavPositionEl.textContent = hasCurrent ? `${currentIndex + 1}/${buttons.length}` : '';
		            }
		        }

		        function getRowDetailsCollapse(row) {
		            if (!row) return null;
		            const detailsButton = row.querySelector('.show-details-btn');
		            const cell = detailsButton ? detailsButton.closest('td') : null;
		            const commitsToggle = cell ? cell.querySelector('.commits-toggle-btn') : null;
		            const target = commitsToggle ? (commitsToggle.getAttribute('data-bs-target') || commitsToggle.dataset.bsTarget || '') : '';
		            return target ? document.querySelector(target) : null;
		        }

		        function ensureRowDetails(row) {
		            const detailsButton = row ? row.querySelector('.show-details-btn') : null;
		            if (!detailsButton) return Promise.resolve();
		            const detailsCollapse = getRowDetailsCollapse(row);
		            if (!detailsCollapse || detailsCollapse.classList.contains('show')) {
		                return Promise.resolve();
		            }

		            return new Promise(function (resolve) {
		                let settled = false;
		                const finish = function () {
		                    if (settled) return;
		                    settled = true;
		                    resolve();
		                };
		                detailsCollapse.addEventListener('shown.bs.collapse', finish, {once: true});
		                detailsButton.click();
		                window.setTimeout(finish, 450);
		            });
		        }

		        function hideRowDetails(row) {
		            const detailsButton = row ? row.querySelector('.show-details-btn') : null;
		            const detailsCollapse = getRowDetailsCollapse(row);
		            if (!detailsButton || !detailsCollapse || !detailsCollapse.classList.contains('show')) {
		                return Promise.resolve();
		            }

		            return new Promise(function (resolve) {
		                let settled = false;
		                const finish = function () {
		                    if (settled) return;
		                    settled = true;
		                    resolve();
		                };
		                detailsCollapse.addEventListener('hidden.bs.collapse', finish, {once: true});
		                detailsButton.click();
		                window.setTimeout(finish, 450);
		            });
		        }

		        function flashAndScrollToRow(row) {
		            if (!row) return;
		            row.classList.remove('student-row-flash');
		            void row.offsetWidth;
		            row.classList.add('student-row-flash');
		            window.setTimeout(function () {
		                row.classList.remove('student-row-flash');
		            }, 1400);

		            const header = document.querySelector('.uda-app-header');
		            const headerOffset = (header ? header.getBoundingClientRect().height : 0) + 16;
		            const targetTop = Math.max(0, window.scrollY + row.getBoundingClientRect().top - headerOffset);
		            window.scrollTo({top: targetTop, behavior: 'smooth'});
		        }

		        async function navigateRubricStudent(delta) {
		            const buttons = getRubricButtons();
		            if (!buttons.length) return;
		            let currentIndex = rubricCurrentButton ? buttons.indexOf(rubricCurrentButton) : -1;
		            if (currentIndex < 0) currentIndex = delta > 0 ? -1 : buttons.length;
		            const targetIndex = currentIndex + delta;
		            if (targetIndex < 0 || targetIndex >= buttons.length) return;

		            const targetButton = buttons[targetIndex];
		            const targetRow = targetButton.closest('tr');
		            const currentRow = rubricCurrentButton ? rubricCurrentButton.closest('tr') : null;
		            await flushRubricSave();
		            if (currentRow && currentRow !== targetRow) {
		                await hideRowDetails(currentRow);
		            }
		            rubricCurrentButton = targetButton;
		            updateRubricNavigation();
		            await ensureRowDetails(targetRow);
		            flashAndScrollToRow(targetRow);
	            await openRubricModal(targetButton, {focusRow: false});
		        }

		        function setRubricHighlightedRow(rowEl) {
		            if (rubricHighlightedRow && rubricHighlightedRow !== rowEl) {
		                rubricHighlightedRow.classList.remove('rubric-row-active');
		            }
		            rubricHighlightedRow = rowEl || null;
		            if (rubricHighlightedRow) {
		                rubricHighlightedRow.classList.add('rubric-row-active');
		            }
		        }

		        function clearRubricHighlightedRow() {
		            if (rubricHighlightedRow) {
		                rubricHighlightedRow.classList.remove('rubric-row-active');
		            }
		            rubricHighlightedRow = null;
		        }

	        function openRubricPanel() {
	            if (!rubricPanelEl) return;
	            rubricPanelEl.classList.add('open');
	        }

	        async function closeRubricPanel() {
	            if (!rubricPanelEl) return;
	            await flushRubricSave();
	            setRubricLoadingStatus(false);
	            rubricPanelEl.classList.remove('open');
		            clearRubricHighlightedRow();
		        }

		        if (rubricCloseBtn) {
		            rubricCloseBtn.addEventListener('click', closeRubricPanel);
		        }
		        if (rubricNavPrevEl) {
		            rubricNavPrevEl.addEventListener('click', function () { navigateRubricStudent(-1); });
		        }
		        if (rubricNavNextEl) {
		            rubricNavNextEl.addEventListener('click', function () { navigateRubricStudent(1); });
		        }

		        document.addEventListener('keydown', function (e) {
		            if (e.key === 'Escape') {
		                closeRubricPanel();
		            }
		        });

	        function fmtMaybeDate(s) {
	            if (!s) return '';
	            const d = new Date(s);
	            if (isNaN(d.getTime())) return String(s);
	            return d.toLocaleString();
	        }

	        function clamp(n, min, max) {
	            return Math.min(max, Math.max(min, n));
	        }

	        function pointsToGrade(points24) {
	            const p = clamp(Number(points24 || 0), 0, 24);
	            // Conversione punti->voto in decimi (con continuità) basata sulle soglie della rubrica:
	            // 0–12 → 4–5; 13–15 → ~6; 16–18 → ~7; 19–21 → ~8; 22–24 → 9–10
	            // Usiamo una interpolazione lineare tra "nodi" per poter ottenere anche mezzi voti.
	            const knots = [
	                {p: 0, g: 4},
	                {p: 12, g: 5},
	                {p: 15, g: 6},
	                {p: 18, g: 7},
	                {p: 21, g: 8},
	                {p: 22, g: 9},
	                {p: 24, g: 10}
	            ];
	            for (let i = 0; i < knots.length - 1; i++) {
	                const a = knots[i];
	                const b = knots[i + 1];
	                if (p <= b.p) {
	                    const span = (b.p - a.p) || 1;
	                    const t = (p - a.p) / span;
	                    return a.g + t * (b.g - a.g);
	                }
	            }
	            return 10;
	        }

	        function roundToHalf(v) {
	            const n = Number(v);
	            if (!isFinite(n)) return null;
	            return Math.round(n * 2) / 2;
	        }

		        function computeRubric() {
		            if (!rubricState) return {points24: null, gradeRaw: null, gradeRounded: null};
		            let numerator = 0;
		            let denom = 0;
		            rubricState.items.forEach((it) => {
		                if (!it.enabled) return;
		                const w = Number(it.weight || 0);
		                const lvl = Number(it.level || 0);
		                if (!isFinite(w) || w <= 0) return;
		                if (!isFinite(lvl) || lvl <= 0) return;
		                // Scala livelli GitHub: 1->0, 2->1, 3->2, 4->3
		                const lvlScore = Math.max(0, Math.min(3, lvl - 1));
		                numerator += lvlScore * w;
		                denom += 3 * w;
		            });
		            if (denom <= 0) return {points24: 0, gradeRaw: 4, gradeRounded: 4};
		            const points24 = (numerator / denom) * 24;
		            const gradeRaw = pointsToGrade(points24);
		            const gradeRounded = roundToHalf(gradeRaw);
		            return {points24, gradeRaw, gradeRounded};
		        }

	        function setSaveStatus(kind, text) {
	            if (!rubricSaveStatusEl) return;
	            rubricSaveStatusEl.className = 'small mt-2';
	            if (kind === 'ok') rubricSaveStatusEl.classList.add('text-success');
	            if (kind === 'warn') rubricSaveStatusEl.classList.add('text-warning');
	            if (kind === 'err') rubricSaveStatusEl.classList.add('text-danger');
	            if (kind === 'muted') rubricSaveStatusEl.classList.add('text-muted');
	            rubricSaveStatusEl.textContent = text || '';
	        }

	        function setRubricLoadingStatus(loading, message = '') {
	            if (!rubricAttributionsStatusEl) return;
	            if (loading) {
	                rubricAttributionsStatusEl.className = 'alert alert-info py-1 px-2 small mb-2';
	                rubricAttributionsStatusEl.innerHTML = '<span class="spinner-border spinner-border-sm me-1" aria-hidden="true"></span>Caricamento attribuzioni…';

                return;
            }
            if (message) {
                rubricAttributionsStatusEl.className = 'alert alert-warning py-1 px-2 small mb-2';
                rubricAttributionsStatusEl.textContent = message;
                return;
            }
            rubricAttributionsStatusEl.className = 'alert alert-info py-1 px-2 small mb-2 d-none';
            rubricAttributionsStatusEl.textContent = '';
        }

	        function renderRubricTable() {
	            if (!rubricHost || !rubricState) return;

	            const {points24, gradeRaw, gradeRounded} = computeRubric();
	            if (rubricPointsEl) rubricPointsEl.textContent = points24 !== null ? points24.toFixed(1) : '-';
	            if (rubricGradeEl) rubricGradeEl.textContent = gradeRounded !== null ? gradeRounded.toFixed(1) : '-';
	            if (rubricGradeRawEl) rubricGradeRawEl.textContent = gradeRaw !== null ? gradeRaw.toFixed(2) : '-';
	            if (rubricEvaluationSummaryEl) {
	                const selectedItems = rubricState.items.filter((it) => it.enabled && Number(it.level || 0) > 0);
	                let summaryHtml = '<div class="fw-semibold mb-1">Valutazione per indicatore</div>';
	                if (!selectedItems.length) {
	                    summaryHtml += '<div class="text-muted">Nessun livello assegnato.</div>';
	                } else {
	                    selectedItems.forEach((it) => {
	                        const level = Number(it.level || 0);
	                        const description = (it.levels && it.levels[level]) ? it.levels[level] : ('Livello ' + level);
	                        summaryHtml += '<div><strong>' + escapeHtml(it.name || 'Indicatore') + ':</strong> ' + escapeHtml(description) + '</div>';
	                    });
	                }
	                rubricEvaluationSummaryEl.innerHTML = summaryHtml;
	            }

	            let html = '';
	            html += '<table class="table table-sm align-top mb-0 rubric-modal-table">';
	            html += '<thead><tr>' +
	                '<th style="width:40px;"></th>' +
	                '<th style="width:220px;">Indicatore</th>' +
	                '<th class="weight-col">Peso</th>' +
	                '<th>1</th><th>2</th><th>3</th><th>4</th>' +
	                '</tr></thead><tbody>';

	            rubricState.items.forEach((it, idx) => {
	                const rowCls = it.enabled ? '' : 'text-muted opacity-75';
	                html += `<tr class="${rowCls}" data-idx="${idx}">`;
	                html += `<td><input class="form-check-input rubric-enabled" type="checkbox" ${it.enabled ? 'checked' : ''}></td>`;
	                html += `<td><div class="fw-semibold">${escapeHtml(it.name || '')}</div><div class="small text-muted">${escapeHtml(it.description || '')}</div></td>`;
	                html += `<td class="weight-col"><input type="number" step="0.1" min="0" class="form-control form-control-sm rubric-weight" value="${escapeHtml(String(it.weight ?? 1))}"></td>`;
	                for (let lvl = 1; lvl <= 4; lvl++) {
	                    const desc = it.levels && it.levels[lvl] ? it.levels[lvl] : '';
	                    const selected = Number(it.level || 0) === lvl;
	                    const cellCls = selected ? 'table-primary' : '';
	                    html += `<td class="rubric-level ${cellCls}" data-level="${lvl}" style="cursor:pointer;">` +
	                        `<div class="fw-bold">${lvl}</div>` +
	                        `<div class="small text-muted">${escapeHtml(desc)}</div>` +
	                        `</td>`;
	                }
	                html += '</tr>';
	            });

	            html += '</tbody></table>';
	            rubricHost.innerHTML = html;
	        }

	        async function saveRubricNow() {
	            if (!rubricContext || !rubricState) return false;
	            try {
	                const {points24, gradeRaw, gradeRounded} = computeRubric();
	                const now = Date.now();
	                if (now - rubricLastSavedAt < 400) return true;

	                setSaveStatus('muted', 'Salvataggio...');
	                const payload = {
	                    action: 'rubric_save',
	                    student_id: rubricContext.student_id,
	                    nome_studente: rubricContext.nome_studente || '',
	                    id_gruppo: RUBRIC_CTX.id_gruppo || '',
	                    voto_numerico: gradeRounded,
	                    dati_json: {
	                        kind: 'github_rubric',
	                        test_id: RUBRIC_CTX.test_id,
	                        id_uda: RUBRIC_CTX.id_uda,
	                        points24: points24,
	                        grade_raw: gradeRaw,
	                        grade_rounded: gradeRounded,
	                        items: rubricState.items.map((it) => ({
	                            ordine: it.order,
	                            nome: it.name,
	                            enabled: !!it.enabled,
	                            level: it.level ? Number(it.level) : null,
	                            weight: it.weight !== '' ? Number(it.weight) : null
	                        })),
	                        metrics: rubricContext.metrics || {}
	                    }
	                };

	                const res = await fetch(window.location.href, {
	                    method: 'POST',
	                    headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
	                    body: JSON.stringify(payload)
	                });
                const data = await res.json();
                if (!data.ok) {
	                    setSaveStatus('err', 'Errore salvataggio: ' + (data.error || 'Errore'));
	                    return false;
	                }
                rubricLastSavedAt = Date.now();
                setSaveStatus('ok', 'Salvato.');
                return true;
            } catch (e) {
                setSaveStatus('err', 'Errore salvataggio.');
                return false;
            }
        }

        function saveRubricDebounced() {
            if (!rubricContext || !rubricState) return;
            if (rubricSaveTimer) clearTimeout(rubricSaveTimer);
            rubricSaveTimer = setTimeout(function () {
                rubricSaveTimer = null;
                rubricSaveInFlight = saveRubricNow();
            }, 500);
        }

        async function flushRubricSave() {
            if (rubricSaveTimer) {
                clearTimeout(rubricSaveTimer);
                rubricSaveTimer = null;
                rubricSaveInFlight = saveRubricNow();
            }
            await rubricSaveInFlight;
        }

	        function applyGradeToSelect() {
	            if (!rubricContext) return;
	            const select = rubricContext.voteSelect;
	            if (!select) return;
	            const {gradeRounded} = computeRubric();
	            if (gradeRounded === null) return;
	            const val = gradeRounded.toFixed(1);
	            const option = Array.from(select.options).find(o => o.value === val);
	            if (option) {
	                select.value = val;
	                return true;
	            }
	            return false;
	        }

	        function buildRubricState(rows, savedJson) {
	            const rubricRows = Array.isArray(rows) ? rows : [];
	            const savedItems = Array.isArray(savedJson?.items) ? savedJson.items : null;
            return {
	                rubric_id: RUBRIC_CTX.test_id,
	                items: rubricRows.map((r) => {
	                    const order = Number(r.ordine ?? r['ordine'] ?? 0) || 0;
	                    const name = r.nome_indicatore ?? r['nome_indicatore'] ?? '';
	                    const matchSaved = savedItems ? savedItems.find(si => Number(si.ordine || 0) === order) : null;
                    return {
	                        order: order,
	                        name: name,
	                        description: r.descrizione ?? r['descrizione'] ?? '',
	                        enabled: matchSaved ? !!matchSaved.enabled : true,
	                        level: matchSaved && matchSaved.level ? Number(matchSaved.level) : null,
	                        weight: matchSaved && matchSaved.weight !== null && matchSaved.weight !== undefined ? matchSaved.weight : (r.peso ?? r['peso'] ?? 1),
	                        levels: {
	                            1: r.livello_1_desc ?? r['livello_1_desc'] ?? '',
	                            2: r.livello_2_desc ?? r['livello_2_desc'] ?? '',
	                            3: r.livello_3_desc ?? r['livello_3_desc'] ?? '',
	                            4: r.livello_4_desc ?? r['livello_4_desc'] ?? ''
	                        }
	                    };
	                })
	            };
        }

	        async function openRubricModal(btn, options = {}) {
	            if (!rubricPanelEl) return;
	            setRubricLoadingStatus(true);
	            const loadSequence = ++rubricLoadSequence;
	            rubricAttributionsLoaded = false;
            if (rubricApplyBtn) rubricApplyBtn.disabled = true;
	            if (rubricLoadController) {
	                rubricLoadController.abort();
	            }
	            rubricLoadController = new AbortController();
	            const loadSignal = rubricLoadController.signal;
	            await flushRubricSave();
	            if (loadSequence !== rubricLoadSequence) return;
	            const focusRow = !!options.focusRow;
	            const studentId = btn.dataset.studentId || '';
	            const githubUsername = btn.dataset.githubUsername || '';
	            const studentName = btn.dataset.studentName || '';
	            const repoUrl = btn.dataset.repoUrl || '';
	            const repoFull = btn.dataset.repoFull || '';
	            const blindGithub = btn.dataset.blindGithub || 'NASCOSTO';
	            const isBlind = document.body.classList.contains('review-blind');
	            const displayStudentName = isBlind ? 'NASCOSTO' : (studentName || studentId);
	            const displayGithub = isBlind ? blindGithub : (githubUsername || studentId);
	            const displayRepoUrl = isBlind ? '' : repoUrl;
	            const ref = btn.dataset.ref || '';
	            const commitTotal = btn.dataset.commitTotal || '';
	            const commitLoaded = btn.dataset.commitLoaded || '';
	            const lastCommit = btn.dataset.lastCommit || '';

	            const row = btn.closest('tr');
	            rubricCurrentButton = btn;
	            updateRubricNavigation();
	            ensureRowDetails(row);
	            if (focusRow) flashAndScrollToRow(row);
	            setRubricHighlightedRow(row);
	            const voteSelect = row ? row.querySelector('.voto-select') : null;
	            const saveVoteCheckbox = row ? row.querySelector('.save-vote-checkbox') : null;

	            rubricContext = {
	                student_id: studentId,
	                github_username: githubUsername,
	                repo_url: repoUrl,
	                repo_full: repoFull,
	                ref: ref,
	                voteSelect: voteSelect,
	                saveVoteCheckbox: saveVoteCheckbox,
	                index: btn.dataset.index || '',
	                nome_studente: studentName,
	                metrics: {
	                    repo_url: repoUrl,
	                    repo_full: repoFull,
	                    ref: ref,
	                    commit_total_classroom: commitTotal !== '' ? commitTotal : null,
	                    recent_commits_loaded: commitLoaded !== '' ? commitLoaded : null,
	                    last_commit: lastCommit || null
	                }
	            };

	            if (rubricEditLink) rubricEditLink.href = RUBRIC_CTX.rubric_editor_url;
	            if (rubricSubtitleEl) rubricSubtitleEl.textContent = `Studente: ${displayStudentName} - GitHub: ${displayGithub}`;

	            if (rubricMetrics) {
	                rubricMetrics.innerHTML =
	                    `<div><strong>Repo</strong>: ${displayRepoUrl ? `<a target="_blank" href="${escapeHtml(displayRepoUrl)}">${escapeHtml(displayRepoUrl)}</a>` : '<span class="text-muted">NASCOSTA</span>'}</div>` +
	                    `<div><strong>Commit totali (Classroom)</strong>: ${escapeHtml(String(commitTotal || '-'))}</div>` +
	                    `<div><strong>Ultimi commit caricati</strong>: ${escapeHtml(String(commitLoaded || '-'))}</div>` +
	                    `<div><strong>Ultimo commit</strong>: ${escapeHtml(fmtMaybeDate(lastCommit) || '-')}</div>` +
	                    `<div class="mt-2"><strong>LOC</strong>: <span id="rubricLocSummary" class="text-muted">in caricamento...</span></div>`;
	            }

	            if (rubricHost) rubricHost.innerHTML = '';
	            if (rubricEvaluationSummaryEl) rubricEvaluationSummaryEl.innerHTML = '<span class="text-muted">Caricamento attribuzioni...</span>';
	            setSaveStatus('muted', '');
	            openRubricPanel();

	            // La rubrica è già nel contesto HTML: render immediato, senza
	            // attendere una richiesta. Solo le attribuzioni sono dinamiche.
	            if (!RUBRIC_CTX.has_rubric) {
	                setRubricLoadingStatus(false);
	                if (rubricHost) {
	                    rubricHost.innerHTML = '<div class="alert alert-warning mb-0">Nessuna rubrica associata al test. Assegnane una da <a href="' + escapeHtml(RUBRIC_CTX.rubric_editor_url) + '" target="_blank">github_rubriche.php</a>.</div>';
	                }
	                return;
	            }

	            const rubricRows = Array.isArray(RUBRIC_CTX.rubric_rows) ? RUBRIC_CTX.rubric_rows : [];
	            rubricState = buildRubricState(rubricRows, null);
	            renderRubricTable();
	            if (rubricEvaluationSummaryEl) rubricEvaluationSummaryEl.innerHTML = '<span class="text-muted">Caricamento attribuzioni...</span>';
	            openRubricPanel();

	            // Carica solo le attribuzioni del singolo studente, sempre senza cache.
	            const url = new URL(window.location.href);
	            url.searchParams.set('action', 'rubric_saved');
	            url.searchParams.set('student_id', studentId);
	            url.searchParams.set('id_gruppo', RUBRIC_CTX.id_gruppo || '');
	            url.searchParams.set('_rubric_load', String(Date.now()));

            try {
	                const res = await fetch(url.toString(), {
	                    headers: {'Accept': 'application/json'},
	                    cache: 'no-store',
	                    signal: loadSignal
	                });
	                const data = await res.json();
	                if (loadSequence !== rubricLoadSequence) return;

	                if (!data.ok) {
	                    setRubricLoadingStatus(false, 'Attribuzioni non disponibili.');
	                    if (rubricEvaluationSummaryEl) rubricEvaluationSummaryEl.innerHTML = '<span class="text-danger">Errore caricamento attribuzioni: ' + escapeHtml(data.error || 'Errore') + '</span>';
	                    return;
	                }

	                rubricState = buildRubricState(rubricRows, data.saved_json || null);
	                rubricAttributionsLoaded = true;
                if (rubricApplyBtn) rubricApplyBtn.disabled = false;
                setRubricLoadingStatus(false);
                renderRubricTable();
            } catch (e) {
	                if (e && e.name === 'AbortError') return;
	                if (loadSequence !== rubricLoadSequence) return;
                rubricAttributionsLoaded = false;
                setRubricLoadingStatus(false, 'Attribuzioni non disponibili.');
                if (rubricEvaluationSummaryEl) rubricEvaluationSummaryEl.innerHTML = '<span class="text-danger">Errore caricamento attribuzioni.</span>';
	            } finally {
                if (loadSequence === rubricLoadSequence) {
                    rubricLoadController = null;
                }
            }

	            // Carica LOC (usa endpoint esistente, con cache)
	            if (repoFull && !isBlind) {
	                try {
                    const locBody = new URLSearchParams({action: 'repo_loc', repo: repoFull, ref: ref || 'main', force: '0'});
                    const locRes = await fetch(window.location.href, {method: 'POST', headers: {'Accept': 'application/json'}, body: locBody});
                    const locData = await locRes.json();
                    if (loadSequence !== rubricLoadSequence) return;
                    const el = document.getElementById('rubricLocSummary');
	                    if (el) {
	                        if (!locData.ok) {
	                            el.textContent = 'errore';
	                        } else {
	                            const totals = locData.totals || {};
	                            el.textContent = `Tot ${totals.total ?? '-'} · Code ${totals.code ?? '-'} · Comment ${totals.comment ?? '-'} · Blank ${totals.blank ?? '-'}`;
	                            rubricContext.metrics.loc = totals;
	                        }
	                    }
                } catch (e) {
                    if (loadSequence !== rubricLoadSequence) return;
                    const el = document.getElementById('rubricLocSummary');
	                    if (el) el.textContent = 'errore';
	                }
	            } else {
	                const el = document.getElementById('rubricLocSummary');
	                if (el) el.textContent = 'N/D';
	            }
	        }

	        document.addEventListener('click', function (e) {
	            const btn = e.target.closest('.rubric-open-btn');
	            if (!btn) return;
	            openRubricModal(btn);
	        });

	        if (rubricApplyBtn) {
	            rubricApplyBtn.addEventListener('click', function () {
	                const applied = applyGradeToSelect();
	                if (applied) {
	                    const saveVoteCheckbox = rubricContext ? rubricContext.saveVoteCheckbox : null;
                    if (saveVoteCheckbox) {
                        saveVoteCheckbox.checked = true;
                        syncSaveVoteCheckbox(rubricContext.index, true);
                    }
                    setSaveStatus('ok', 'Voto applicato. Il popup resta aperto per eventuali modifiche.');
                }
            });
        }

	        if (rubricHost) {
	            rubricHost.addEventListener('click', function (e) {
	                if (!rubricAttributionsLoaded) return;
	                const cell = e.target.closest('.rubric-level');
	                if (!cell) return;
	                const tr = cell.closest('tr');
	                if (!tr) return;
	                const idx = Number(tr.dataset.idx || '0');
	                const lvl = Number(cell.dataset.level || '0');
	                if (!rubricState || !rubricState.items[idx]) return;
	                if (!rubricState.items[idx].enabled) return;
	                rubricState.items[idx].level = lvl;
	                renderRubricTable();
	                saveRubricDebounced();
	            });

	            rubricHost.addEventListener('change', function (e) {
	                if (!rubricAttributionsLoaded) return;
                const tr = e.target.closest('tr');
	                if (!tr) return;
	                const idx = Number(tr.dataset.idx || '0');
	                if (!rubricState || !rubricState.items[idx]) return;
	                if (e.target.classList.contains('rubric-enabled')) {
	                    rubricState.items[idx].enabled = !!e.target.checked;
	                    renderRubricTable();
	                    saveRubricDebounced();
	                }
	                if (e.target.classList.contains('rubric-weight')) {
	                    rubricState.items[idx].weight = e.target.value;
	                    renderRubricTable();
	                    saveRubricDebounced();
	                }
	            });
	        }

        function getSaveVoteCheckbox(index) {
            return Array.from(document.querySelectorAll('.save-vote-checkbox')).find(function (checkbox) {
                return String(checkbox.dataset.index || '') === String(index);
            }) || null;
        }

        function syncSaveVoteCheckbox(index, checked) {
            const mainCheckbox = getSaveVoteCheckbox(index);
            if (mainCheckbox) mainCheckbox.checked = !!checked;
            document.querySelectorAll('.save-vote-modal-checkbox').forEach(function (modalCheckbox) {
                if (String(modalCheckbox.dataset.index || '') === String(index)) {
                    modalCheckbox.checked = !!checked;
                }
            });
        }

        function renderSaveVotesSummary() {
            const summaryBody = document.getElementById('saveVotesSummaryBody');
            if (!summaryBody) return;
            summaryBody.replaceChildren();

            const showNames = document.getElementById('review-show-names')?.checked !== false;
            const mainCheckboxes = Array.from(document.querySelectorAll('.save-vote-checkbox'));
            if (!mainCheckboxes.length) {
                const emptyRow = document.createElement('tr');
                const emptyCell = document.createElement('td');
                emptyCell.colSpan = 4;
                emptyCell.className = 'text-muted';
                emptyCell.textContent = 'Nessun voto disponibile.';
                emptyRow.appendChild(emptyCell);
                summaryBody.appendChild(emptyRow);
                return;
            }

            mainCheckboxes.forEach(function (mainCheckbox) {
                const index = mainCheckbox.dataset.index || '';
                const row = mainCheckbox.closest('tr');
                const select = row ? row.querySelector('.voto-select') : null;
                const selectedOption = select && select.selectedOptions.length ? select.selectedOptions[0] : null;
                const summaryRow = document.createElement('tr');

                const selectCell = document.createElement('td');
                const modalCheckbox = document.createElement('input');
                modalCheckbox.type = 'checkbox';
                modalCheckbox.className = 'form-check-input save-vote-modal-checkbox';
                modalCheckbox.dataset.index = index;
                modalCheckbox.checked = mainCheckbox.checked;
                modalCheckbox.addEventListener('change', function () {
                    syncSaveVoteCheckbox(index, modalCheckbox.checked);
                });
                selectCell.appendChild(modalCheckbox);

                const studentCell = document.createElement('td');
                studentCell.textContent = showNames
                    ? (mainCheckbox.dataset.studentName || 'Nome non disponibile')
                    : (mainCheckbox.dataset.blindStudent || 'Studente');

                const githubCell = document.createElement('td');
                githubCell.textContent = showNames
                    ? (mainCheckbox.dataset.githubName || 'GitHub non disponibile')
                    : (mainCheckbox.dataset.blindGithub || 'NASCOSTO');

                const gradeCell = document.createElement('td');
                gradeCell.textContent = selectedOption ? selectedOption.textContent.trim() : '- non importare voto -';

                summaryRow.append(selectCell, studentCell, githubCell, gradeCell);
                summaryBody.appendChild(summaryRow);
            });
        }

        (function () {
            const showNamesToggle = document.getElementById('review-show-names');
            const summaryModal = document.getElementById('saveVotesModal');
            const summaryStatus = document.getElementById('saveVotesModalStatus');
            const confirmButton = document.getElementById('confirmSaveVotesBtn');
            const saveForm = document.querySelector('form input[name="action"][value="save_grades"]')?.closest('form');

            document.querySelectorAll('.save-vote-checkbox').forEach(function (checkbox) {
                checkbox.addEventListener('change', function () {
                    syncSaveVoteCheckbox(checkbox.dataset.index || '', checkbox.checked);
                });
            });
            document.querySelectorAll('.voto-select').forEach(function (select) {
                select.addEventListener('change', renderSaveVotesSummary);
            });
            document.querySelectorAll('.open-save-votes-modal').forEach(function (button) {
                button.addEventListener('click', function () {
                    if (summaryStatus) {
                        summaryStatus.classList.add('d-none');
                        summaryStatus.textContent = '';
                    }
                    renderSaveVotesSummary();
                });
            });
            if (confirmButton) {
                confirmButton.addEventListener('click', function () {
                    const selected = Array.from(document.querySelectorAll('.save-vote-checkbox')).filter(function (checkbox) {
                        const row = checkbox.closest('tr');
                        const select = row ? row.querySelector('.voto-select') : null;
                        return checkbox.checked && select && select.value !== 'skip';
                    });
                    if (!selected.length) {
                        if (summaryStatus) {
                            summaryStatus.textContent = 'Seleziona almeno un voto con un valore diverso da “- non importare voto -”.';
                            summaryStatus.classList.remove('d-none');
                        }
                        return;
                    }
                    if (!saveForm) return;
                    if (summaryModal && window.bootstrap) {
                        bootstrap.Modal.getOrCreateInstance(summaryModal).hide();
                    }
                    if (typeof saveForm.requestSubmit === 'function') {
                        saveForm.requestSubmit();
                    } else {
                        saveForm.submit();
                    }
                });
            }
        })();

	    // Toggle "Mostra nomi e repository" (revisione alla cieca)
	    (function () {
	        var toggle = document.getElementById("review-show-names");
	        function apply(show) {
	            document.body.classList.toggle("review-names", show);
	            document.body.classList.toggle("review-blind", !show);
	            document.querySelectorAll(".review-comment").forEach(function (ta) {
	                if (ta.dataset.touched === "1") return;
	                var raw = show ? ta.dataset.full : ta.dataset.blind;
	                try { ta.value = JSON.parse(raw); } catch (e) {}
	            });
	        }
	        if (toggle) {
	            toggle.addEventListener("change", function () { apply(toggle.checked); });
	        }
	        document.querySelectorAll(".review-comment").forEach(function (ta) {
	            ta.addEventListener("input", function () { ta.dataset.touched = "1"; });
	        });
	        apply(true);
	        if (typeof renderSaveVotesSummary === 'function') renderSaveVotesSummary();
	    })();
	    </script>
	</body>
	</html>
