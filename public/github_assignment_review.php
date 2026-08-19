<?php
/**
 * Riepilogo assignment GitHub Classroom e assegnazione voti manuali
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentRosterService;
use App\Core\ProviderNeutralMappingService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\UdaGroupRepository;
use App\Integration\GitHubIntegration;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$github = new GitHubIntegration($config);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$mappingService = new ProviderNeutralMappingService($dbAdapter, $userId);
$github->loadTokenFromSession();
$isAuthenticated = $github->isAuthenticated();

function jsonResponse($data, $status = 200)
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data);
    exit;
}

$action = $_GET['action'] ?? null;
if ($action === 'commit_details') {
    try {
        if (!$isAuthenticated) {
            throw new Exception('Non autenticato su GitHub');
        }

        $repoFull = trim((string)($_GET['repo'] ?? ''));
        $sha = trim((string)($_GET['sha'] ?? ''));
        $withComments = (string)($_GET['with_comments'] ?? '0') === '1';

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
            'comments' => $comments
        ]);
    } catch (Exception $e) {
        jsonResponse([
            'ok' => false,
            'error' => $e->getMessage()
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

// Carica test
$test = $dbAdapter->findOne('TEST', 'id_test', $testId);
if (!$test) {
    die("Test non trovato");
}
if (strtolower($test['piattaforma'] ?? '') !== 'github') {
    die("Questo test non è un assignment GitHub");
}

function ghDefaultGitRubricDefinition(): array
{
    return [
        [
            'nome_indicatore' => 'Frequenza e qualità dei commit',
            'descrizione' => 'Valuta se lo studente usa Git come strumento di lavoro e non come “salvataggio finale”.',
            'livello_1_desc' => 'Pochissimi commit, spesso enormi (“tutto il progetto”), difficili da capire',
            'livello_2_desc' => 'Commit presenti ma irregolari o troppo grandi',
            'livello_3_desc' => 'Commit frequenti, legati a step logici dello sviluppo',
            'livello_4_desc' => 'Commit ben distribuiti, ciascuno rappresenta un avanzamento chiaro',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '1'
        ],
        [
            'nome_indicatore' => 'Correttezza tecnica dei commit (compilazione)',
            'descrizione' => 'Questa voce è fondamentale per far capire che Git non è il cestino degli errori.',
            'livello_1_desc' => 'Commit con codice che non compila o non funziona',
            'livello_2_desc' => 'Alcuni commit instabili o chiaramente incompleti',
            'livello_3_desc' => 'Commit generalmente funzionanti',
            'livello_4_desc' => 'Tutti i commit compilano/eseguono correttamente',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '2'
        ],
        [
            'nome_indicatore' => 'Uso dei branch',
            'descrizione' => 'Premia l’uso consapevole dei branch (feature/fix) invece di sviluppare tutto su main.',
            'livello_1_desc' => 'Tutto sviluppato su main/master, nessun branch',
            'livello_2_desc' => 'Branch usati in modo occasionale o confuso',
            'livello_3_desc' => 'Branch per funzionalità principali',
            'livello_4_desc' => 'Branch chiari (uno per funzionalità), ben nominati e gestiti',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '3'
        ],
        [
            'nome_indicatore' => 'Messaggi di commit (titolo + descrizione)',
            'descrizione' => 'Premia chi spiega il ragionamento: problema, soluzione, contesto.',
            'livello_1_desc' => 'Messaggi vaghi o inutili (fix, modifica, aggiornamento)',
            'livello_2_desc' => 'Titolo comprensibile ma descrizione assente o personale (“ho fatto…”)',
            'livello_3_desc' => 'Titolo chiaro + descrizione tecnica sintetica',
            'livello_4_desc' => 'Titolo chiaro + descrizione strutturata (problema, soluzione, fonti)',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '4'
        ],
        [
            'nome_indicatore' => 'Uso corretto di push e merge (team)',
            'descrizione' => 'Nei lavori di gruppo pesa molto: responsabilità verso il team.',
            'livello_1_desc' => 'Push frequenti di codice non funzionante',
            'livello_2_desc' => 'Push disordinati o merge prematuri',
            'livello_3_desc' => 'Push solo di codice funzionante',
            'livello_4_desc' => 'Push e merge consapevoli, merge solo a funzionalità completata',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '5'
        ],
        [
            'nome_indicatore' => 'Tracciabilità e leggibilità della cronologia',
            'descrizione' => 'Il criterio “da ingegnere del software”: la cronologia racconta lo sviluppo.',
            'livello_1_desc' => 'Cronologia confusa, difficile capire cosa è stato fatto',
            'livello_2_desc' => 'Cronologia leggibile solo in parte',
            'livello_3_desc' => 'Cronologia chiara e ricostruibile',
            'livello_4_desc' => 'Cronologia che racconta lo sviluppo del progetto passo‑passo',
            'livello_5_desc' => '',
            'peso' => '1',
            'ordine' => '6'
        ],
    ];
}

if ($action === 'rubric_load') {
    try {
        $studentId = trim((string)($_GET['student_id'] ?? ''));
        if ($studentId === '') {
            throw new Exception('student_id mancante');
        }

        $rubricId = (string)$testId;
        $rubricaRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $rubricId]);
        if (empty($rubricaRows)) {
            $rubricaRows = ghDefaultGitRubricDefinition();
        }

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
            'rubric_rows' => $rubricaRows,
            'saved' => $savedRow,
            'saved_json' => $savedJson
        ]);
    } catch (Exception $e) {
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
    }
}

if ($action === 'rubric_save') {
    try {
        $raw = file_get_contents('php://input');
        $payload = json_decode($raw, true);
        if (!is_array($payload)) {
            $payload = $_POST;
        }

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
        jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
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
    $where = @shell_exec('where cloc 2>NUL');
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

if ($action === 'repo_loc') {
    try {
        if (!$isAuthenticated) {
            throw new Exception('Non autenticato su GitHub');
        }

        set_time_limit(180);

        $repoFull = trim((string)($_GET['repo'] ?? ''));
        $ref = trim((string)($_GET['ref'] ?? ''));
        $force = (string)($_GET['force'] ?? '0') === '1';

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
        // Preferisci il token GitHub Classroom (PAT) configurato nelle integrazioni (più affidabile per Classroom),
        // poi fallback al token OAuth in sessione.
        $token = $config['github']['classroom_token']
            ?? ($config['github']['pat'] ?? ($config['github']['token'] ?? null))
            ?? ($_SESSION['github_access_token'] ?? null);
        if (!$token) {
            throw new Exception('Token GitHub non disponibile (configurazione o sessione)');
        }

        $tmpZip = tempnam(sys_get_temp_dir(), 'uda-ghzip-');
        if (!$tmpZip) {
            throw new Exception('Impossibile creare file temporaneo');
        }

        $url = "https://api.github.com/repos/{$owner}/{$repo}/zipball/{$ref}";
        $fh = fopen($tmpZip, 'wb');
        if (!$fh) {
            throw new Exception('Impossibile scrivere zip temporaneo');
        }

        $headers = [
            'Authorization: Bearer ' . $token,
            'Accept: application/vnd.github+json',
            'X-GitHub-Api-Version: 2022-11-28',
            'User-Agent: Sistema-UDA-PHP'
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_FILE, $fh);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($ch, CURLOPT_UNRESTRICTED_AUTH, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, 120);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 20);
        $ok = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);
        fclose($fh);

        if (!$ok || $httpCode < 200 || $httpCode >= 300) {
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

        $zip = new ZipArchive();
        if ($zip->open($tmpZip) !== true) {
            @unlink($tmpZip);
            ghRemoveDirRecursive($extractDir);
            throw new Exception('Impossibile aprire zip');
        }
        $zip->extractTo($extractDir);
        $zip->close();
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
    } catch (Exception $e) {
        jsonResponse([
            'ok' => false,
            'error' => $e->getMessage()
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

// Normalizza/deriva gli ID GitHub dal link docente se mancanti (utile per test creati via "collega link")
$githubClassroomId = trim((string)($test['github_classroom_id'] ?? ''));
$githubAssignmentId = trim((string)($test['github_assignment_id'] ?? ''));
$teacherUrl = trim((string)($test['url_assignment_teacher'] ?? ($test['url_docente'] ?? ($test['url_gestione'] ?? ''))));

$classroomIdCandidateFromUrl = '';
$assignmentIdCandidateFromUrl = '';
$derivedAssignmentSlug = '';
if ($teacherUrl) {
    $parsed = parse_url($teacherUrl);
    $path = $parsed['path'] ?? '';
    $parts = array_values(array_filter(explode('/', (string)$path)));
    $classroomsIdx = array_search('classrooms', $parts, true);
    $assignmentsIdx = array_search('assignments', $parts, true);

    if ($classroomsIdx !== false && isset($parts[$classroomsIdx + 1])) {
        $classroomSegment = (string)$parts[$classroomsIdx + 1];
        if (preg_match('/^(\\d+)(?:-|$)/', $classroomSegment, $m)) {
            $classroomIdCandidateFromUrl = $m[1];
        }
    }

    if ($assignmentsIdx !== false && isset($parts[$assignmentsIdx + 1])) {
        $assignmentSegment = (string)$parts[$assignmentsIdx + 1];
        if (preg_match('/^(\\d+)(?:-|$)/', $assignmentSegment, $m)) {
            $assignmentIdCandidateFromUrl = $m[1];
        } else {
            $derivedAssignmentSlug = $assignmentSegment;
        }
    }
}

// Determina la GitHub Classroom "API id" da usare:
// - prioritÃ : valore giÃ  salvato nel TEST
// - fallback: integrazione github_classroom del gruppo didattico derivata dalla UDA
// - solo come ultima risorsa: candidato dal link docente (che in alcune UI Ã¨ un ID diverso dall'API)
$mappingRow = null;
$allMaps = [];
try {
    $allMaps = $mappingService->listGithubClassroomMappings();
} catch (Exception $e) {
    $allMaps = [];
}

$findMapByClassroomId = function (string $cid) use ($allMaps) {
    foreach ($allMaps as $m) {
        if ((string)($m['github_classroom_id'] ?? '') === (string)$cid) {
            return $m;
        }
    }
    return null;
};

if ($githubClassroomId !== '') {
    $mappingRow = $findMapByClassroomId($githubClassroomId);
}

if (!$mappingRow && $classroomIdCandidateFromUrl !== '') {
    $candidateMap = $findMapByClassroomId($classroomIdCandidateFromUrl);
    if ($candidateMap) {
        $mappingRow = $candidateMap;
        $githubClassroomId = (string)($candidateMap['github_classroom_id'] ?? '');
    }
}

if (!$mappingRow && !empty($test['id_uda'])) {
    try {
        // Gruppi didattici dell'UDA con integrazione github_classroom.
        $groupRepo = new UdaGroupRepository($dbAdapter, $userId);
        $integrationRepo = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
        $candidates = [];
        foreach ($groupRepo->listForUda((string)$test['id_uda']) as $assignment) {
            $gid = (string)($assignment['id_gruppo'] ?? '');
            if ($gid === '') {
                continue;
            }
            $integration = $integrationRepo->findForGroupProvider($gid, 'github_classroom');
            if ($integration === null) {
                continue;
            }
            $cid = (string)($integration['external_context_id'] ?? '');
            if ($githubClassroomId === '' || $cid === $githubClassroomId) {
                $candidates[$gid] = $integration;
            }
        }

        if (count($candidates) === 1) {
            $only = array_values($candidates)[0];
            $githubClassroomId = (string)($only['external_context_id'] ?? '');
            foreach ($allMaps as $m) {
                if ((string)($m['github_classroom_id'] ?? '') === $githubClassroomId) {
                    $mappingRow = $m;
                    break;
                }
            }
        }
    } catch (Exception $e) {
        // non bloccare
    }
}

// Se non ho ancora l'ID assignment, prova a validare/riusare l'eventuale candidato numerico dal link
if ($githubAssignmentId === '' && $assignmentIdCandidateFromUrl !== '') {
    try {
        $existing = $dbAdapter->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
            'id_assignment' => $assignmentIdCandidateFromUrl
        ]);
        if (!empty($existing)) {
            $githubAssignmentId = $assignmentIdCandidateFromUrl;
        } elseif ($isAuthenticated) {
            // Prova a verificare via API (se fallisce, ignora)
            $github->getAssignment($assignmentIdCandidateFromUrl);
            $githubAssignmentId = $assignmentIdCandidateFromUrl;
        }
    } catch (Exception $e) {
        // ignora
    }
}

// Risoluzione assignment_id via API listAssignments (match per invite link o per slug calcolato dal titolo)
if ($githubAssignmentId === '' && $githubClassroomId !== '' && $isAuthenticated) {
    try {
        $studentInviteUrl = trim((string)($test['url_assignment_student'] ?? ($test['url_studenti'] ?? ($test['url'] ?? ''))));
        $studentInviteUrlNorm = $studentInviteUrl !== '' ? rtrim($studentInviteUrl, '/') : '';
        $slugFromTestName = ghSlugify(trim((string)($test['nome'] ?? '')));
        $targetSlug = $derivedAssignmentSlug !== '' ? $derivedAssignmentSlug : $slugFromTestName;

        $page = 1;
        $perPage = 100;
        $candidateAssignmentIds = [];
        while ($page <= 10) {
            $resp = $github->listAssignments($githubClassroomId, $page, $perPage);
            $items = $resp['assignments'] ?? ($resp['data'] ?? $resp ?? []);
            if (empty($items) || !is_array($items)) {
                break;
            }

            foreach ($items as $a) {
                $candidateId = trim((string)($a['id'] ?? ''));
                if ($candidateId === '') {
                    continue;
                }
                $candidateAssignmentIds[] = $candidateId;

                // 1) Match per invite link (più affidabile)
                $inviteLink = (string)($a['invite_link'] ?? ($a['invitation_link'] ?? ($a['invite_url'] ?? ($a['invitation_url'] ?? ''))));
                $inviteLinkNorm = $inviteLink !== '' ? rtrim($inviteLink, '/') : '';
                if ($studentInviteUrlNorm !== '' && $inviteLinkNorm !== '' && strcasecmp($studentInviteUrlNorm, $inviteLinkNorm) === 0) {
                    $githubAssignmentId = $candidateId;
                    break 2;
                }

                // 2) Match slug esplicito (se l'API lo restituisce)
                $slug = (string)($a['slug'] ?? '');
                if ($targetSlug !== '' && $slug !== '' && strcasecmp($slug, $targetSlug) === 0) {
                    $githubAssignmentId = $candidateId;
                    break 2;
                }

                // 3) Match slug calcolato dal titolo (fallback)
                $title = (string)($a['title'] ?? ($a['name'] ?? ($a['assignment_title'] ?? '')));
                $titleSlug = ghSlugify($title);
                if ($targetSlug !== '' && $titleSlug !== '' && strcasecmp($titleSlug, $targetSlug) === 0) {
                    $githubAssignmentId = $candidateId;
                    break 2;
                }

                // 4) Match sul nome test (fallback ulteriore)
                if ($slugFromTestName !== '' && $titleSlug !== '' && strcasecmp($titleSlug, $slugFromTestName) === 0) {
                    $githubAssignmentId = $candidateId;
                    break 2;
                }

                // 5) Match su eventuali url restituiti dall'API
                $html = (string)($a['html_url'] ?? ($a['url'] ?? ($a['teacher_url'] ?? '')));
                if ($html) {
                    $p = parse_url($html);
                    $pp = array_values(array_filter(explode('/', (string)($p['path'] ?? ''))));
                    $aIdx = array_search('assignments', $pp, true);
                    if ($targetSlug !== '' && $aIdx !== false && isset($pp[$aIdx + 1]) && strcasecmp((string)$pp[$aIdx + 1], $targetSlug) === 0) {
                        $githubAssignmentId = $candidateId;
                        break 2;
                    }
                }
            }

            if (count($items) < $perPage) {
                break;
            }
            $page++;
        }

        // Fallback finale: se listAssignments non espone invite_link, prova a risolvere via getAssignment(ID) su un numero limitato di assignment.
        if ($githubAssignmentId === '' && $studentInviteUrlNorm !== '' && !empty($candidateAssignmentIds)) {
            $candidateAssignmentIds = array_values(array_unique($candidateAssignmentIds));
            $maxChecks = 50;
            $checked = 0;
            foreach ($candidateAssignmentIds as $candidateId) {
                if ($checked >= $maxChecks) {
                    break;
                }
                $checked++;
                try {
                    $assignment = $github->getAssignment($candidateId);
                    $inviteLink = (string)($assignment['invite_link'] ?? ($assignment['invitation_link'] ?? ($assignment['invite_url'] ?? ($assignment['invitation_url'] ?? ''))));
                    $inviteLinkNorm = $inviteLink !== '' ? rtrim($inviteLink, '/') : '';
                    if ($inviteLinkNorm !== '' && strcasecmp($studentInviteUrlNorm, $inviteLinkNorm) === 0) {
                        $githubAssignmentId = $candidateId;
                        break;
                    }
                } catch (Exception $e) {
                    // continua
                }
            }
        }
    } catch (Exception $e) {
        // non bloccare
    }
}

if (($githubClassroomId && $githubClassroomId !== ($test['github_classroom_id'] ?? ''))
    || ($githubAssignmentId && $githubAssignmentId !== ($test['github_assignment_id'] ?? ''))
) {
    try {
        $update = [];
        if ($githubClassroomId) $update['github_classroom_id'] = $githubClassroomId;
        if ($githubAssignmentId) $update['github_assignment_id'] = $githubAssignmentId;
        if (!empty($update)) {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, $update);
            $test = array_merge($test, $update);
        }
    } catch (Exception $e) {
        // non bloccare
    }
}

// Carica roster/accepted assignments dalle API GitHub Classroom: è la fonte
// primaria per l'elenco studenti (provider-neutral, indipendente da ClasseViva).
$acceptedAssignments = [];
$assignmentGrades = [];
if ($isAuthenticated && !empty($githubAssignmentId)) {
    try {
        $accepted = $github->listAcceptedAssignments($githubAssignmentId);
        $acceptedAssignments = $accepted['accepted_assignments'] ?? ($accepted['data'] ?? $accepted ?? []);
    } catch (Exception $e) {
        $errorMessage = $errorMessage ?: "Errore nel caricamento accepted assignments: " . $e->getMessage();
    }
    // getAssignmentGrades restituisce le repository anche quando accepted_assignments
    // è vuoto (endpoint Classroom in chiusura): è la fonte affidabile per repo/roster.
    try {
        $grades = $github->getAssignmentGrades($githubAssignmentId);
        $assignmentGrades = is_array($grades) ? $grades : [];
    } catch (Exception $e) {
        // non bloccare: accepted_assignments resta il fallback
    }
}
if (!is_array($acceptedAssignments)) {
    $acceptedAssignments = [];
}
if (!is_array($assignmentGrades)) {
    $assignmentGrades = [];
}

// Costruisce l'elenco studenti: roster API risolto verso id_studente interno
// tramite le identità; GITHUB_ASSIGNMENT_STUDENT_LINKS resta come overlay per
// associazioni manuali e repository sovrascritte.
$rosterService = new GitHubAssignmentRosterService($dbAdapter, $userId);
$studentMap = $rosterService->buildStudentMap(
    $acceptedAssignments,
    (string)$githubAssignmentId,
    (string)($mappingRow['id_gruppo'] ?? '')
);
// Arricchisce repo/roster dai grades (che includono student_repository_url).
$studentMap = $rosterService->enrichRepositories($studentMap, $assignmentGrades);
$acceptedByUser = [];
$acceptedByRoster = [];
$acceptedReposFound = 0;
foreach ($acceptedAssignments as $item) {
    $user = '';
    if (!empty($item['students'][0]['login'])) {
        $user = (string)$item['students'][0]['login'];
    } elseif (!empty($item['student']['login'])) {
        $user = (string)$item['student']['login'];
    } elseif (!empty($item['github_username'])) {
        $user = (string)$item['github_username'];
    }
    $user = strtolower(trim($user));

    $rid = strtolower(trim((string)($item['roster_identifier'] ?? '')));
    if ($rid !== '') {
        $acceptedByRoster[$rid] = $item;
    }

    $repoUrl = (string)($item['repository']['html_url'] ?? ($item['repository_url'] ?? ''));
    if ($repoUrl !== '') {
        $acceptedReposFound++;
    }

    if ($user !== '') {
        $acceptedByUser[$user] = $item;
    }
}

if (!empty($githubAssignmentId) && !empty($studentMap)) {
    $hasAnyRepoStored = false;
    foreach ($studentMap as $row) {
        if (!empty($row['student_repository_url'])) {
            $hasAnyRepoStored = true;
            break;
        }
    }

    if (!$hasAnyRepoStored) {
        if (empty($acceptedAssignments) && empty($assignmentGrades)) {
            $warningMessage = "Repo studenti non disponibili (N/D). Possibili cause: nessuno studente ha ancora accettato l'assignment (repo non create) oppure il token GitHub non ha accesso alle API GitHub Classroom. Verifica in Integrazioni → GitHub (Token GitHub Classroom) e riprova.";
        } else {
            $warningMessage = "Repo studenti non disponibili (N/D) anche se esistono accepted assignments/grades: controlla i permessi del token GitHub Classroom oppure ripeti l'autenticazione.";
        }
    }
}

// Commit info (ultimo commit e conteggio base)
$commitInfo = [];
if ($isAuthenticated && !empty($acceptedByUser)) {
    foreach ($acceptedByUser as $user => $item) {
        $repoUrl = (string)($item['repository']['html_url'] ?? ($item['repository_url'] ?? ''));
        if (!$repoUrl) {
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

// Salvataggio voti
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_grades') {
    try {
        $tipoVoto = $_POST['tipo_voto'] ?? 'scritto';
        $grades = $_POST['voto'] ?? [];
        $comments = $_POST['commento'] ?? [];
        $studentIds = $_POST['id_studente'] ?? [];
        $usernames = $_POST['github_username'] ?? [];
        $repos = $_POST['repo_url'] ?? [];

        if (!$mappingRow) {
            throw new Exception("Mappatura classe/materia GitHub non trovata");
        }

        $idGruppo = (string)($mappingRow['id_gruppo'] ?? '');

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

            $descrizione = 'GitHub Classroom: ' . ($test['nome'] ?? '');
            if (!empty($repoUrl)) {
                $descrizione .= "\nRepo: " . $repoUrl;
            }
            if (!empty($test['url_assignment_student'])) {
                $descrizione .= "\nLink Studente: " . $test['url_assignment_student'];
            }
            if (!empty($test['url_assignment_teacher'])) {
                $descrizione .= "\nLink Docente: " . $test['url_assignment_teacher'];
            }
            if ($commento !== '') {
                $descrizione .= "\nCommento: " . $commento;
            }

            $linkOrigine = app_url('public/github_assignment_review.php?test_id=' . urlencode((string)$testId));
            $votoData = [
                'id_voto' => 'VOTO_' . uniqid(),
                'id_uda' => $test['id_uda'],
                'id_gruppo' => $idGruppo,
                'id_studente' => $studentId,
                'tipo_voto' => $tipoVoto,
                'voto' => $voto,
                'giudizio' => $giudizio,
                'descrizione' => $descrizione,
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
	        table.github-grades-table col.col-gh { width: 180px; }
	        table.github-grades-table col.col-repo { width: 440px; }
	        table.github-grades-table col.col-student { width: 100px; }
	        table.github-grades-table col.col-vote { width: 90px; }
	        table.github-grades-table td.repo-col a { word-break: break-all; }
	        table.github-grades-table th.vote-col,
	        table.github-grades-table td.vote-col { width: 90px; }
	        table.github-grades-table td.comment-col { vertical-align: top; }
	        table.github-grades-table .comment-wrap { display: flex; align-items: stretch; width: 100%; height: 100%; }
	        table.github-grades-table td.comment-col textarea { flex: 1; width: 100%; min-height: 120px; resize: vertical; }
	        table.github-grades-table td.vote-col select { width: 100% !important; }
	        .commit-message-body { white-space: pre-wrap; }
	        .rubric-modal-table th.weight-col,
	        .rubric-modal-table td.weight-col { width: 110px; }
	        .rubric-modal-table input.rubric-weight { width: 100%; min-width: 0; padding: .1rem .25rem; font-size: .85rem; }

            /* Mantieni il colore di sfondo della riga anche dentro i dettagli (commit/LOC) */
            table.github-grades-table td .collapse .repo-loc,
            table.github-grades-table td .collapse .commit-details,
            table.github-grades-table td .collapse .list-group-item {
                background-color: transparent !important;
            }

	        table.github-grades-table tr.rubric-row-active {
	            background-color: #fff3cd !important;
	        }
	        table.github-grades-table tr.rubric-row-active td:first-child {
	            border-left: 4px solid #fd7e14;
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
	    </style>
	</head>
<body>
        <?php
    $pageTitle = $test['nome'] ?? 'Assignment GitHub';
    $pageSubtitle = 'Assignment GitHub Classroom - UDA: ' . ($test['id_uda'] ?? '');
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


    <div class="container mt-4">
<?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
	            <?php if (!empty($test['id_uda'])): ?>
	                <a class="btn btn-sm btn-success ms-3" href="uda_grades.php?id=<?= urlencode($test['id_uda']) ?>">
	                    <i class="bi bi-upload"></i> Pubblica voti sul registro elettronico
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
                <?php
                $mapLink = 'github_classroom_mapping.php';
                $params = [];
                if (!empty($githubClassroomId)) {
                    $params['github_classroom_id'] = $githubClassroomId;
                }
                if (!empty($mappingRow['id_mapping'])) {
                    $params['mapping_id'] = $mappingRow['id_mapping'];
                }
                if (!empty($githubAssignmentId)) {
                    $params['assignment_id'] = $githubAssignmentId;
                }
                if (empty($githubAssignmentId) && !empty($derivedAssignmentSlug)) {
                    $params['assignment_slug'] = $derivedAssignmentSlug;
                }
                if (!empty($test['id_uda'])) {
                    $params['id_uda'] = $test['id_uda'];
                }
                if (!empty($testId)) {
                    $params['test_id'] = $testId;
                }
                $params['action'] = 'map_students';
                if (!empty($params)) {
                    $mapLink .= '?' . http_build_query($params);
                }
                ?>
                Nessuna associazione studenti trovata per questo assignment. Completa la mappatura in <a href="<?= htmlspecialchars($mapLink) ?>#student-map">github_classroom_mapping.php</a>.
                <?php if (empty($githubAssignmentId)): ?>
                    <div class="small text-muted mt-1">
                        Nota: non riesco a ricavare l'ID numerico dell'assignment da questo test.
                        <?php if (!$isAuthenticated): ?>
                            Autenticati su GitHub e riprova (serve una chiamata API per risolvere lo slug).
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
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
                            Sono mostrati gli ultimi commit (max 20) per repo; per il dettaglio completo vai su GitHub.
                        </div>
                    </div>
	                </div>
	                <div class="table-responsive">
	                    <table class="table table-striped align-top github-grades-table">
	                        <colgroup>
	                            <col class="col-gh">
	                            <col class="col-repo">
	                            <col class="col-student">
	                            <col class="col-vote">
	                            <col class="col-comment">
	                        </colgroup>
	                        <thead>
	                            <tr>
	                                <th>GitHub</th>
	                                <th>Repo</th>
                                <th>Studente ClasseViva</th>
                                <th class="vote-col">Voto</th>
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

                                $acceptedItem = $acceptedByUser[$lowerUser] ?? null;
                                if (!$acceptedItem && $ridKey !== '') {
                                    $acceptedItem = $acceptedByRoster[$ridKey] ?? null;
                                }

                                $repoUrl = (string)($row['student_repository_url'] ?? '');
                                if ($repoUrl === '' && $acceptedItem) {
                                    $repoUrl = (string)($acceptedItem['repository']['html_url'] ?? ($acceptedItem['repository_url'] ?? ''));
                                }

                                $commitCountTotal = $acceptedItem ? ($acceptedItem['commit_count'] ?? null) : null;
                                $defaultBranch = $acceptedItem ? (string)($acceptedItem['repository']['default_branch'] ?? ($acceptedItem['default_branch'] ?? '')) : null;
                                $studentId = $row['id_studente'] ?? '';
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
                                ?>
                                <tr>
                                    <td>
                                        <input type="hidden" name="github_username[<?= $idx ?>]" value="<?= htmlspecialchars($uname) ?>">
                                        <div><i class="bi bi-github"></i> <?= htmlspecialchars($uname) ?></div>
                                        <small class="text-muted"><?= htmlspecialchars($row['roster_identifier'] ?? '') ?></small>
                                    </td>
                                    <td class="repo-col">
                                        <input type="hidden" name="repo_url[<?= $idx ?>]" value="<?= htmlspecialchars($repoUrl) ?>">
                                        <?php if ($repoUrl): ?>
                                            <a href="<?= htmlspecialchars($repoUrl) ?>" target="_blank"><?= htmlspecialchars($repoUrl) ?></a>
                                        <?php else: ?>
                                            <em class="text-muted">N/D</em>
                                        <?php endif; ?>

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

	                                        <?php if (!empty($commitsList)): ?>
	                                            <button class="btn btn-sm btn-outline-dark mt-2 show-details-btn" type="button">
	                                                <i class="bi bi-eye"></i> Mostra dettagli
	                                            </button>
	                                        <?php endif; ?>

	                                        <?php if ($repoFullRow): ?>
	                                            <button class="btn btn-sm btn-outline-success mt-1 ms-1 repo-loc-btn"
	                                                    type="button"
	                                                    data-repo="<?= htmlspecialchars($repoFullRow) ?>"
                                                     data-ref="<?= htmlspecialchars($locRef) ?>"
                                                    data-bs-target="#<?= htmlspecialchars($locContainerId) ?>">
	                                                LOC
	                                            </button>
	                                            <div class="collapse mt-2" id="<?= htmlspecialchars($locContainerId) ?>">
	                                                <div class="border rounded p-2 bg-light repo-loc" data-loaded="0">
	                                                    <div class="text-muted">Clicca “LOC” per calcolare le linee di codice.</div>
	                                                </div>
	                                            </div>
	                                        <?php endif; ?>
	                                        <?php if (!empty($commitsList)): ?>
	                                            <button class="btn btn-sm btn-outline-secondary mt-1 d-none commits-toggle-btn" type="button" data-bs-toggle="collapse" data-bs-target="#commits-<?= $idx ?>">
	                                                <i class="bi bi-list"></i> Dettaglio commit
	                                            </button>
                                            <div class="collapse mt-2" id="commits-<?= $idx ?>">
                                                <ul class="list-group list-group-flush small">
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
                                    <td>
                                        <input type="hidden" name="id_studente[<?= $idx ?>]" value="<?= htmlspecialchars($studentId) ?>">
                                        <?php if ($studentId): ?>
                                            <span class="badge bg-success">Associato</span>
                                            <small class="text-muted d-block">ID: <?= htmlspecialchars($studentId) ?></small>
                                        <?php else: ?>
                                            <span class="badge bg-warning text-dark">Non associato</span>
                                        <?php endif; ?>
                                    </td>
	                                    <td class="vote-col">
	                                        <?php if ($studentId): ?>
	                                            <?php $defaultVoto = $_POST['voto'][$idx] ?? 'skip'; ?>
	                                            <div class="d-flex flex-column gap-1">
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
	                                                        data-student-id="<?= htmlspecialchars($studentId) ?>"
	                                                        data-github-username="<?= htmlspecialchars($uname) ?>"
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
	                                                <textarea name="commento[<?= $idx ?>]" class="form-control form-control-sm" rows="1"
	                                                          placeholder="Commento docente (opzionale)"><?= htmlspecialchars($prefillText) ?></textarea>
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
                <button type="submit" class="btn btn-success">
                    <i class="bi bi-save"></i> Salva voti
                </button>
            </form>
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

        async function loadCommitDetails(container, repoFull, sha, withComments) {
            if (!container) return;

            container.innerHTML = '<div class="text-muted">Caricamento…</div>';

            const url = new URL(window.location.href);
            url.searchParams.set('action', 'commit_details');
            url.searchParams.set('repo', repoFull);
            url.searchParams.set('sha', sha);
            url.searchParams.set('with_comments', withComments ? '1' : '0');

            const res = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
            const data = await res.json();

            if (!data.ok) {
                container.innerHTML = '<div class="text-danger">Errore: ' + escapeHtml(data.error || 'Errore') + '</div>';
                return;
            }

            const commit = data.commit || {};
            const stats = commit.stats || {};
            const files = Array.isArray(commit.files) ? commit.files : [];

            let html = '';
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

        document.querySelectorAll('.commit-details-btn').forEach(function (btn) {
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

        async function loadRepoLoc(container, repoFull, ref, force) {
            if (!container) return;

            container.innerHTML = '<div class="text-muted">Calcolo LOC...</div>';

            const url = new URL(window.location.href);
            url.searchParams.set('action', 'repo_loc');
            url.searchParams.set('repo', repoFull);
            url.searchParams.set('ref', ref);
            url.searchParams.set('force', force ? '1' : '0');

            const res = await fetch(url.toString(), {headers: {'Accept': 'application/json'}});
            const data = await res.json();

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
                html += '<div class="table-responsive"><table class="table table-sm mb-2"><thead><tr>' +
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

        document.querySelectorAll('.repo-loc-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const repoFull = btn.dataset.repo;
                const ref = btn.dataset.ref;
                const targetSelector = btn.dataset.target;
                const container = document.querySelector(targetSelector + ' .repo-loc');
                if (!container) return;

                if (container.dataset.loaded === '1') {
                    return;
                }

                loadRepoLoc(container, repoFull, ref, false);
            });
        });

	        function showCollapseElement(el) {
	            if (!el) return;
	            try {
	                bootstrap.Collapse.getOrCreateInstance(el, {toggle: false}).show();
	            } catch (e) {
	                // ignore
	            }
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
 
 	                setCommentTextareaExpanded(row, true);

	                // Dettagli commit (stats/files) per tutti i commit visibili (in sequenza)
	                const detailButtons = Array.from(cell.querySelectorAll('.commit-details-btn'));
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

            // ==== Gestore calcolo LOC (separato da "Mostra dettagli") ====
            document.querySelectorAll('.repo-loc-btn').forEach(function (btn) {
                btn.addEventListener('click', async function (e) {
                    e.preventDefault();

                    const target = btn.getAttribute('data-bs-target') || btn.dataset.bsTarget || '';
                    const collapseEl = target ? document.querySelector(target) : null;
                    if (!collapseEl) return;

                    const isOpen = collapseEl.classList.contains('show');
                    if (isOpen) {
                        try { bootstrap.Collapse.getOrCreateInstance(collapseEl, {toggle: false}).hide(); } catch (err) {}
                        return;
                    }

                    showCollapseElement(collapseEl);
                    const container = collapseEl.querySelector('.repo-loc');
                    if (container && container.dataset.loaded !== '1') {
                        await loadRepoLoc(container, btn.dataset.repo || '', btn.dataset.ref || 'main', false);
                    }
                });
            });

	        const RUBRIC_CTX = {
	            test_id: <?= json_encode((string)$testId) ?>,
	            id_uda: <?= json_encode((string)($test['id_uda'] ?? '')) ?>,
	            id_gruppo: <?= json_encode((string)($idGruppo ?? '')) ?>,
	            rubric_editor_url: <?= json_encode('github_rubriche.php?test_id=' . urlencode((string)$testId)) ?>
	        };

		        const rubricPanelEl = document.getElementById('rubricPanel');
		        const rubricHost = document.getElementById('rubricTableHost');
		        const rubricMetrics = document.getElementById('rubricMetrics');
		        const rubricPointsEl = document.getElementById('rubricPoints');
		        const rubricGradeEl = document.getElementById('rubricGrade');
		        const rubricGradeRawEl = document.getElementById('rubricGradeRaw');
		        const rubricSubtitleEl = document.getElementById('rubricModalSubtitle');
		        const rubricSaveStatusEl = document.getElementById('rubricSaveStatus');
		        const rubricApplyBtn = document.getElementById('rubricApplyBtn');
		        const rubricEditLink = document.getElementById('rubricEditLink');
		        const rubricCloseBtn = document.getElementById('rubricCloseBtn');

		        let rubricContext = null;
		        let rubricState = null;
		        let rubricSaveTimer = null;
		        let rubricLastSavedAt = 0;
		        let rubricHighlightedRow = null;

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

		        function closeRubricPanel() {
		            if (!rubricPanelEl) return;
		            rubricPanelEl.classList.remove('open');
		            clearRubricHighlightedRow();
		        }

		        if (rubricCloseBtn) {
		            rubricCloseBtn.addEventListener('click', closeRubricPanel);
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

	        function renderRubricTable() {
	            if (!rubricHost || !rubricState) return;

	            const {points24, gradeRaw, gradeRounded} = computeRubric();
	            if (rubricPointsEl) rubricPointsEl.textContent = points24 !== null ? points24.toFixed(1) : '-';
	            if (rubricGradeEl) rubricGradeEl.textContent = gradeRounded !== null ? gradeRounded.toFixed(1) : '-';
	            if (rubricGradeRawEl) rubricGradeRawEl.textContent = gradeRaw !== null ? gradeRaw.toFixed(2) : '-';

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

	        async function saveRubricDebounced() {
	            if (!rubricContext || !rubricState) return;
	            if (rubricSaveTimer) clearTimeout(rubricSaveTimer);
	            rubricSaveTimer = setTimeout(async function () {
	                try {
	                    const {points24, gradeRaw, gradeRounded} = computeRubric();
	                    const now = Date.now();
	                    if (now - rubricLastSavedAt < 400) return;

	                    setSaveStatus('muted', 'Salvataggio...');
	                    const url = new URL(window.location.href);
	                    url.searchParams.set('action', 'rubric_save');
	                    url.searchParams.set('test_id', RUBRIC_CTX.test_id);

	                    const payload = {
	                        student_id: rubricContext.student_id,
	                        nome_studente: rubricContext.nome_studente || '',
	                        id_gruppo: RUBRIC_CTX.id_gruppo || '',
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

	                    const res = await fetch(url.toString(), {
	                        method: 'POST',
	                        headers: {'Content-Type': 'application/json', 'Accept': 'application/json'},
	                        body: JSON.stringify(payload)
	                    });
	                    const data = await res.json();
	                    if (!data.ok) {
	                        setSaveStatus('err', 'Errore salvataggio: ' + (data.error || 'Errore'));
	                        return;
	                    }
	                    rubricLastSavedAt = Date.now();
	                    setSaveStatus('ok', 'Salvato.');
	                } catch (e) {
	                    setSaveStatus('err', 'Errore salvataggio.');
	                }
	            }, 500);
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

		        async function openRubricModal(btn) {
		            if (!rubricPanelEl) return;
	            const studentId = btn.dataset.studentId || '';
	            const githubUsername = btn.dataset.githubUsername || '';
	            const repoUrl = btn.dataset.repoUrl || '';
	            const repoFull = btn.dataset.repoFull || '';
	            const ref = btn.dataset.ref || '';
	            const commitTotal = btn.dataset.commitTotal || '';
	            const commitLoaded = btn.dataset.commitLoaded || '';
	            const lastCommit = btn.dataset.lastCommit || '';

	            const row = btn.closest('tr');
	            setRubricHighlightedRow(row);
	            const voteSelect = row ? row.querySelector('.voto-select') : null;

	            rubricContext = {
	                student_id: studentId,
	                github_username: githubUsername,
	                repo_url: repoUrl,
	                repo_full: repoFull,
	                ref: ref,
	                voteSelect: voteSelect,
	                nome_studente: '',
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
	            if (rubricSubtitleEl) rubricSubtitleEl.textContent = `Studente: ${studentId} · GitHub: ${githubUsername}`;

	            if (rubricMetrics) {
	                rubricMetrics.innerHTML =
	                    `<div><strong>Repo</strong>: ${repoUrl ? `<a target="_blank" href="${escapeHtml(repoUrl)}">${escapeHtml(repoUrl)}</a>` : '<span class="text-muted">N/D</span>'}</div>` +
	                    `<div><strong>Commit totali (Classroom)</strong>: ${escapeHtml(String(commitTotal || '-'))}</div>` +
	                    `<div><strong>Ultimi commit caricati</strong>: ${escapeHtml(String(commitLoaded || '-'))}</div>` +
	                    `<div><strong>Ultimo commit</strong>: ${escapeHtml(fmtMaybeDate(lastCommit) || '-')}</div>` +
	                    `<div class="mt-2"><strong>LOC</strong>: <span id="rubricLocSummary" class="text-muted">in caricamento...</span></div>`;
	            }

	            if (rubricHost) rubricHost.innerHTML = '<div class="text-muted">Caricamento rubrica...</div>';
	            setSaveStatus('muted', '');
	            openRubricPanel();

	            // Carica rubric + eventuale salvataggio
	            const url = new URL(window.location.href);
	            url.searchParams.set('action', 'rubric_load');
	            url.searchParams.set('student_id', studentId);
	            url.searchParams.set('id_gruppo', RUBRIC_CTX.id_gruppo || '');
	            if (!data.ok) {
	                if (rubricHost) rubricHost.innerHTML = '<div class="text-danger">Errore: ' + escapeHtml(data.error || 'Errore') + '</div>';
	                return;
	            }

	            const rows = Array.isArray(data.rubric_rows) ? data.rubric_rows : [];
	            const saved = data.saved_json || null;
	            const savedItems = Array.isArray(saved?.items) ? saved.items : null;

	            rubricState = {
	                rubric_id: data.rubric_id || RUBRIC_CTX.test_id,
	                items: rows.map((r) => {
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

		            renderRubricTable();
		            openRubricPanel();

	            // Carica LOC (usa endpoint esistente, con cache)
	            if (repoFull) {
	                try {
	                    const locUrl = new URL(window.location.href);
	                    locUrl.searchParams.set('action', 'repo_loc');
	                    locUrl.searchParams.set('repo', repoFull);
	                    locUrl.searchParams.set('ref', ref || 'main');
	                    locUrl.searchParams.set('force', '0');
	                    const locRes = await fetch(locUrl.toString(), {headers: {'Accept': 'application/json'}});
	                    const locData = await locRes.json();
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
		                if (applied) closeRubricPanel();
		            });
		        }

	        if (rubricHost) {
	            rubricHost.addEventListener('click', function (e) {
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
	    </script>
	</body>
	</html>
