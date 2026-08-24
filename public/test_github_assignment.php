<?php
/**
 * Pagina di TEST: assignment GitHub via REST API (senza GitHub Classroom).
 *
 * Flusso:
 *  1. Seleziona un gruppo didattico.
 *  2. Recupera le email degli studenti:
 *     - Google Classroom (email reali), oppure
 *     - per gruppi con SOLO ClasseViva, genera l'email dal "Template email studenti"
 *       (nome + cognome + dominio).
 *  3. Seleziona template repo e org GitHub.
 *  4. Crea repo private per studente + invita l'email all'org (admin:org).
 *  5. Risultati in un DB SQLite temporaneo; due pulsanti di pulizia.
 */

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\StudentEmailResolver;
use App\Core\NotificationManager;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

Authorization::assertAdmin(
    $_SESSION,
    array_filter(array_map('trim', explode(',', (string)env('ADMIN_EMAILS', ''))))
);

$db = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? '');
$csrfToken = Csrf::token($_SESSION);

$github = new GitHubIntegration($config);
$github->loadTokenFromSession();
$ghAuthed = $github->isAuthenticated();
$studentLinkBase = app_url('public/accept_assignment.php');
$ghScopes = $ghAuthed ? $github->getTokenScopes() : [];
$hasAdminOrg = in_array('admin:org', $ghScopes, true);

// --- DB temporaneo SQLite ---
$tempDb = ROOT_PATH . '/storage/temp/github_assignment_test.db';
$pdo = new PDO('sqlite:' . $tempDb, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec('CREATE TABLE IF NOT EXISTS test_assignment (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, gruppo_id TEXT, gruppo_nome TEXT, template_id TEXT, template_url TEXT, org TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS test_students (id INTEGER PRIMARY KEY AUTOINCREMENT, nome TEXT, email TEXT, source TEXT, repo_name TEXT, repo_url TEXT, invite_status TEXT, error TEXT, created_at TEXT)');
$pdo->exec('CREATE TABLE IF NOT EXISTS test_config (k TEXT PRIMARY KEY, v TEXT)');
try { $pdo->exec('ALTER TABLE test_students ADD COLUMN github_username TEXT'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE test_students ADD COLUMN code TEXT'); } catch (Throwable $e) {}

// --- helper ---
function h(?string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

function gh_parse_repo(string $url): array {
    $url = trim($url);
    if (preg_match('#^https?://github[.]com/([^/]+)/([^/]+?)(?:[.]git)?/?$#i', $url, $m)) {
        return [strtolower($m[1]), strtolower($m[2])];
    }
    return ['', ''];
}

function gh_slug(string $s): string {
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/', '-', $s);
    return trim($s === null ? '' : $s, '-');
}

function gh_local(string $email): string {
    $p = strpos($email, '@');
    return gh_slug($p === false ? $email : substr($email, 0, $p));
}

function gh_repo_name(string $name, array &$usedCodes): string {
    $base = substr(gh_slug($name), 0, 50);
    do {
        $code = (string) random_int(100000, 999999);
    } while (isset($usedCodes[$code]));
    $usedCodes[$code] = true;
    return $base . '-stud-' . $code;
}

// --- dati ---
$groups = array_values(array_filter($db->findAll('GRUPPI_DIDATTICI'), function ($g) use ($userId) {
    return (string)($g['id_utente'] ?? '') === $userId;
}));
$templates = array_values(array_filter($db->findAll('GITHUB_REPO_TEMPLATES'), function ($t) {
    return ($t['attivo'] ?? '') === 'si';
}));
$profile = $config['user_profile'] ?? [];
$emailDomain = (string)($profile['school_email_domain'] ?? '');
$emailTemplate = (string)($profile['school_student_email_template'] ?? '');

$orgs = [];
$orgsError = null;
if ($ghAuthed) {
    try { $orgs = $github->listOrganizations(); } catch (Throwable $e) { $orgsError = $e->getMessage(); }
}

// --- POST ---
$error = null;
$notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';

        if ($action === 'load_students' || $action === 'create') {
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            if ($groupId === '') throw new Exception('Seleziona un gruppo didattico.');
            $group = null;
            foreach ($groups as $g) { if ((string)$g['id_gruppo'] === $groupId) { $group = $g; break; } }
            if ($group === null) throw new Exception('Gruppo non trovato.');

            $integrations = $db->findWhere('GRUPPI_INTEGRAZIONI', ['id_gruppo' => $groupId, 'id_utente' => $userId]);
            $gcCourse = ''; $cvClass = ''; $cvSubject = '';
            foreach ($integrations as $it) {
                if (($it['stato'] ?? 'attivo') === 'disattivo') continue;
                $provider = (string)($it['provider'] ?? '');
                if ($provider === 'google_classroom') {
                    $gcCourse = (string)($it['external_context_id'] ?? '');
                } elseif ($provider === 'classeviva') {
                    $cvClass = (string)($it['external_context_id'] ?? '');
                    $cvSubject = (string)($it['external_subject_id'] ?? '');
                }
            }

            if ($action === 'load_students') {
                $pdo->exec('DELETE FROM test_students');
                $pdo->exec('DELETE FROM test_assignment');

                $students = [];
                if ($gcCourse !== '') {
                    $gc = new GoogleClassroomAPI($config);
                    foreach ($gc->getCourseStudents($gcCourse) as $s) {
                        $students[] = [
                            'nome' => trim((string)($s['name'] ?? '')),
                            'email' => trim((string)($s['email'] ?? '')),
                            'source' => 'google',
                        ];
                    }
                } elseif ($cvClass !== '') {
                    if ($emailDomain === '' || $emailTemplate === '') {
                        throw new Exception('Manca "Template email studenti" o "Dominio email studenti" nel Profilo (Integrazioni -> Profilo).');
                    }
                    $cv = new ClasseVivaAPI($config);
                    foreach ($cv->getStudentiClasse($cvClass) as $s) {
                        $nome = (string)($s['nome'] ?? '');
                        $cognome = (string)($s['cognome'] ?? '');
                        $students[] = [
                            'nome' => trim($cognome . ' ' . $nome),
                            'email' => StudentEmailResolver::generate($emailTemplate, $emailDomain, $nome, $cognome),
                            'source' => 'generata',
                        ];
                    }
                } else {
                    throw new Exception('Il gruppo non ha una mappatura Google Classroom né ClasseViva.');
                }
                if (empty($students)) throw new Exception('Nessuno studente trovato nel roster.');

                $stmt = $pdo->prepare('INSERT INTO test_students (nome, email, source, repo_name, repo_url, invite_status, error, created_at) VALUES (?,?,?,?,?,?,?,?)');
                $now = date('c');
                foreach ($students as $s) {
                    $stmt->execute([$s['nome'], $s['email'], $s['source'], '', '', 'pending', '', $now]);
                }

                $name = trim((string)($_POST['name'] ?? ''));
                if ($name === '') {
                    $tname = '';
                    foreach ($templates as $t) { if ((string)$t['id_template'] === (string)($_POST['template_id'] ?? '')) { $tname = (string)($t['nome'] ?? ''); break; } }
                    $name = gh_slug((string)($group['nome_gruppo'] ?? 'gruppo') . '-' . $tname);
                }
                $pdo->prepare('INSERT INTO test_assignment (nome, gruppo_id, gruppo_nome, template_id, template_url, org, created_at) VALUES (?,?,?,?,?,?,?)')
                    ->execute([$name, $groupId, (string)($group['nome_gruppo'] ?? ''), trim((string)($_POST['template_id'] ?? '')), '', trim((string)($_POST['org'] ?? '')), $now]);

                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
            }

            // --- action === create ---
            if (!$ghAuthed) throw new Exception('Autorizza GitHub prima di creare gli assignment.');
            if (!$hasAdminOrg) throw new Exception('Manca lo scope admin:org. Riautorizza GitHub (pulsante in alto).');

            $org = trim((string)($_POST['org'] ?? ''));
            if ($org === '') throw new Exception('Seleziona un org GitHub.');

            $templateId = trim((string)($_POST['template_id'] ?? ''));
            $template = null;
            foreach ($templates as $t) { if ((string)$t['id_template'] === $templateId) { $template = $t; break; } }
            if ($template === null) throw new Exception('Seleziona un template repo.');
            [$tOwner, $tRepo] = gh_parse_repo((string)($template['url_repository'] ?? ''));
            if ($tOwner === '' || $tRepo === '') throw new Exception('URL template non valido: ' . (string)($template['url_repository'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new Exception('Nome assignment mancante.');

            $rows = $pdo->query('SELECT * FROM test_students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
            if (empty($rows)) throw new Exception('Carica prima gli studenti (pulsante "Carica studenti").');

            $upd = $pdo->prepare('UPDATE test_students SET repo_name=?, repo_url=?, invite_status=?, error=?, code=? WHERE id=?');
            $usedCodes = [];
            foreach ($rows as $row) {
                $repoName = gh_repo_name($name, $usedCodes);
                $repoUrl = '';
                $invite = 'pending';
                $err = '';
                try {
                    $created = $github->createRepositoryFromTemplate($tOwner, $tRepo, $repoName, $org, $name, true);
                    $repoUrl = (string)($created['html_url'] ?? '');
                    if ($repoUrl === '') $err = 'repo creata senza html_url';
                } catch (Throwable $e) {
                    $err = 'repo: ' . $e->getMessage();
                }
                if ((string)$row['email'] !== '') {
                    try {
                        $github->inviteUserByEmail($org, (string)$row['email']);
                        $invite = 'ok';
                    } catch (Throwable $e) {
                        $invite = 'error';
                        $err = ($err === '' ? '' : $err . ' | ') . 'invito: ' . $e->getMessage();
                    }
                } else {
                    $invite = 'skipped';
                }
                $upd->execute([$repoName, $repoUrl, $invite, $err, bin2hex(random_bytes(16)), (int)$row['id']]);
            }

            $pdo->prepare('UPDATE test_assignment SET template_url=?, org=? WHERE id=(SELECT MAX(id) FROM test_assignment)')
                ->execute([(string)($template['url_repository'] ?? ''), $org]);

            $teacherToken = (string)($_SESSION['github_access_token'] ?? '');
            if ($teacherToken !== '') {
                $pdo->prepare('INSERT OR REPLACE INTO test_config (k, v) VALUES (?, ?)')->execute(['teacher_token', $teacherToken]);
            $pdo->prepare('INSERT OR REPLACE INTO test_config (k, v) VALUES (?, ?)')->execute(['teacher_id', $userId]);
            $repoPrefix = substr(gh_slug($name), 0, 50);
            $pdo->prepare('INSERT OR REPLACE INTO test_config (k, v) VALUES (?, ?)')->execute(['repo_prefix', $repoPrefix]);
            $pdo->prepare('INSERT OR REPLACE INTO test_config (k, v) VALUES (?, ?)')->execute(['assignment_slug', $repoPrefix . '-' . bin2hex(random_bytes(4))]);
            }

            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($action === 'invite_one' || $action === 'invite_all') {
            $assignmentName = (string)($pdo->query('SELECT nome FROM test_assignment ORDER BY id DESC LIMIT 1')->fetchColumn() ?: 'Assignment');
            $nm = new NotificationManager($config);
            if ($action === 'invite_all') {
                $targets = $pdo->query("SELECT * FROM test_students WHERE code IS NOT NULL AND code != '' AND email != ''")->fetchAll(PDO::FETCH_ASSOC);
            } else {
                $sid = (int)($_POST['student_id'] ?? 0);
                $row = $sid > 0 ? $pdo->query('SELECT * FROM test_students WHERE id = ' . $sid)->fetch(PDO::FETCH_ASSOC) : null;
                $targets = $row ? [$row] : [];
            }
            if (empty($targets)) throw new Exception('Nessuno studente con link disponibile.');
            $sent = 0; $failed = 0;
            foreach ($targets as $t) {
                $email = (string)($t['email'] ?? '');
                $code = (string)($t['code'] ?? '');
                if ($email === '' || $code === '') { $failed++; continue; }
                $link = $studentLinkBase . '?code=' . urlencode($code);
                $nome = h((string)($t['nome'] ?? 'studente'));
                $body = '<p>Ciao ' . $nome . ',</p>'
                    . '<p>Ti e stato assegnato un assignment (<strong>' . h($assignmentName) . '</strong>).</p>'
                    . '<p>Accedi con il tuo account GitHub (la stessa email istituzionale) per ottenere accesso alla tua repository:</p>'
                    . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>';
                if ($nm->sendHtmlEmail($email, 'Invito assignment: ' . $assignmentName, $body)) { $sent++; } else { $failed++; }
            }
            $notice = 'Email inviate: ' . $sent . ', fallite: ' . $failed . '.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?notice=' . urlencode($notice));
            exit;
        }

        if ($action === 'clear_local') {
            $pdo->exec('DELETE FROM test_students');
            $pdo->exec('DELETE FROM test_assignment');
            header('Location: ' . $_SERVER['PHP_SELF']);
            exit;
        }

        if ($action === 'delete_repos') {
            if (!$ghAuthed) throw new Exception('Autorizza GitHub prima di eliminare i repo.');
            $rows = $pdo->query("SELECT * FROM test_students WHERE repo_url != ''")->fetchAll(PDO::FETCH_ASSOC);
            $deleted = 0; $failed = 0;
            foreach ($rows as $row) {
                [$o, $r] = gh_parse_repo((string)$row['repo_url']);
                if ($o === '' || $r === '') { $failed++; continue; }
                try { $github->deleteRepository($o, $r); $deleted++; }
                catch (Throwable $e) { $failed++; }
            }
            $notice = 'Repo eliminati: ' . $deleted . ', falliti: ' . $failed . '.';
            header('Location: ' . $_SERVER['PHP_SELF'] . '?notice=' . urlencode($notice));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

if (isset($_GET['notice'])) $notice = (string)$_GET['notice'];

// --- risultati ---
$draft = $pdo->query('SELECT * FROM test_assignment ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC);
$resultRows = $pdo->query('SELECT * FROM test_students ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$draftName = (string)($draft['nome'] ?? '');
$draftGroup = (string)($draft['gruppo_id'] ?? '');
$draftTemplate = (string)($draft['template_id'] ?? '');
$draftOrg = (string)($draft['org'] ?? '');
$assignmentOrg = (string)($draft['org'] ?? '');
$repoPrefix = (string)($pdo->query("SELECT v FROM test_config WHERE k = 'repo_prefix'")->fetchColumn() ?: '');
$assignmentSlug = (string)($pdo->query("SELECT v FROM test_config WHERE k = 'assignment_slug'")->fetchColumn() ?: '');
$assignmentRepos = [];
if ($ghAuthed && $assignmentOrg !== '' && $repoPrefix !== '') {
    try {
        $assignmentRepos = array_values(array_filter($github->listOrgRepositories($assignmentOrg), static fn($r) => str_starts_with((string)($r['name'] ?? ''), $repoPrefix)));
    } catch (Throwable $e) {
        $assignmentRepos = [];
    }
}

$teacherReposUrl = ($assignmentOrg !== '' && $repoPrefix !== '')
    ? 'https://github.com/search?q=' . urlencode("user:{$assignmentOrg} {$repoPrefix} in:name") . '&type=repositories'
    : '';

$ghAuthUrl = $github->getAuthorizationUrl(null, 'test_github_assignment.php');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test assignment GitHub (REST)</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body class="bg-light">
<div class="container py-4" style="max-width: 1100px;">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <h1 class="h4 mb-0"><i class="bi bi-github"></i> Test assignment GitHub — REST API</h1>
        <a href="index.php" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-left"></i> Dashboard</a>
    </div>

    <?php if ($error !== null): ?>
        <div class="alert alert-danger"><?= h($error) ?></div>
    <?php endif; ?>
    <?php if ($notice !== null): ?>
        <div class="alert alert-info"><?= h($notice) ?></div>
    <?php endif; ?>

    <!-- Stato GitHub -->
    <div class="card shadow-sm mb-3">
        <div class="card-body">
            <?php if (!$ghAuthed): ?>
                <span class="badge text-bg-danger">GitHub non autorizzato</span>
                <a href="<?= h($ghAuthUrl) ?>" class="btn btn-dark btn-sm ms-2"><i class="bi bi-github"></i> Autorizza GitHub</a>
            <?php else: ?>
                <span class="badge text-bg-success me-2"><i class="bi bi-check-circle"></i> GitHub autorizzato</span>
                <?php foreach ($ghScopes as $s): ?>
                    <span class="badge text-bg-secondary"><?= h($s) ?></span>
                <?php endforeach; ?>
                <?php if (!$hasAdminOrg): ?>
                    <div class="alert alert-warning mt-2 mb-0 py-2">
                        <i class="bi bi-exclamation-triangle"></i> Manca lo scope <code>admin:org</code> (serve per gli inviti).
                        <a href="<?= h($ghAuthUrl) ?>" class="btn btn-sm btn-dark ms-2"><i class="bi bi-arrow-repeat"></i> Riautorizza GitHub</a>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ($orgsError !== null): ?><div class="text-danger small mt-1"><?= h($orgsError) ?></div><?php endif; ?>
        </div>
    </div>

    <!-- Form -->
    <form method="post" class="card shadow-sm mb-3">
        <div class="card-header bg-white fw-semibold">Configurazione assignment</div>
        <div class="card-body row g-3">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">

            <div class="col-md-6">
                <label class="form-label">Gruppo didattico</label>
                <select name="group_id" class="form-select" required>
                    <option value="">— seleziona —</option>
                    <?php foreach ($groups as $g): ?>
                        <option value="<?= h((string)$g['id_gruppo']) ?>" <?= $draftGroup === (string)$g['id_gruppo'] ? 'selected' : '' ?>>
                            <?= h((string)($g['nome_gruppo'] ?? $g['id_gruppo'])) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Template repo</label>
                <select name="template_id" class="form-select" required>
                    <option value="">— seleziona —</option>
                    <?php foreach ($templates as $t): ?>
                        <option value="<?= h((string)$t['id_template']) ?>" <?= $draftTemplate === (string)$t['id_template'] ? 'selected' : '' ?>>
                            <?= h((string)($t['nome'] ?? $t['id_template'])) ?><?= ($t['categoria'] ?? '') !== '' ? ' — ' . h((string)$t['categoria']) : '' ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Org GitHub</label>
                <select name="org" class="form-select" required>
                    <option value="">— seleziona —</option>
                    <?php foreach ($orgs as $o): ?>
                        <option value="<?= h((string)($o['login'] ?? '')) ?>" <?= $draftOrg === (string)($o['login'] ?? '') ? 'selected' : '' ?>>
                            <?= h((string)($o['login'] ?? '')) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="col-md-6">
                <label class="form-label">Nome assignment (auto {gruppo}-{template})</label>
                <input type="text" name="name" class="form-control" value="<?= h($draftName) ?>" placeholder="{gruppo}-{template}">
            </div>

            <div class="col-12 d-flex gap-2">
                <button type="submit" name="action" value="load_students" class="btn btn-outline-primary"><i class="bi bi-people"></i> Carica studenti</button>
                <button type="submit" name="action" value="create" class="btn btn-primary"><i class="bi bi-git"></i> Crea assignment</button>
            </div>
        </div>
    </form>

    <!-- Risultati -->
    <div class="card shadow-sm">
        <div class="card-header bg-white d-flex justify-content-between align-items-center">
            <span class="fw-semibold">Studenti e risultati (DB temporaneo)</span>
            <span>
                <form method="post" class="d-inline" onsubmit="return confirm('Inviare email a tutti gli studenti?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" name="action" value="invite_all" class="btn btn-sm btn-primary"><i class="bi bi-envelope"></i> Invita tutti</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Svuotare i dati locali?');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" name="action" value="clear_local" class="btn btn-sm btn-outline-secondary"><i class="bi bi-trash"></i> Svuota dati locali</button>
                </form>
                <form method="post" class="d-inline" onsubmit="return confirm('Eliminare i repo su GitHub? (irreversibile)');">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <button type="submit" name="action" value="delete_repos" class="btn btn-sm btn-outline-danger"><i class="bi bi-github"></i> Elimina repo su GitHub</button>
                </form>
            </span>
        </div>
        <div class="card-body p-0">
            <table class="table table-sm table-striped mb-0">
                <thead>
                    <tr>
                        <th>#</th><th>Studente</th><th>Email</th><th>Fonte</th><th>Repo</th><th>Invito</th><th>Link studente</th><th>Invita email</th><th>Errore</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($resultRows)): ?>
                        <tr><td colspan="9" class="text-muted p-3">Nessun dato. Carica gli studenti.</td></tr>
                    <?php else: ?>
                        <?php foreach ($resultRows as $i => $r): ?>
                            <tr>
                                <td><?= (int)$i + 1 ?></td>
                                <td><?= h((string)($r['nome'] ?? '')) ?></td>
                                <td><code><?= h((string)($r['email'] ?? '')) ?></code></td>
                                <td>
                                    <?php if (($r['source'] ?? '') === 'generata'): ?><span class="badge text-bg-warning">generata</span>
                                    <?php else: ?><span class="badge text-bg-info">google</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($r['repo_url'] ?? '') !== ''): ?>
                                        <a href="<?= h((string)$r['repo_url']) ?>" target="_blank" rel="noopener"><?= h((string)($r['repo_name'] ?? $r['repo_url'])) ?></a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($r['invite_status'] ?? '') === 'ok'): ?><span class="badge text-bg-success">ok</span>
                                    <?php elseif (($r['invite_status'] ?? '') === 'error'): ?><span class="badge text-bg-danger">errore</span>
                                    <?php elseif (($r['invite_status'] ?? '') === 'skipped'): ?><span class="badge text-bg-secondary">n/d</span>
                                    <?php else: ?><span class="badge text-bg-light">pending</span><?php endif; ?>
                                </td>
                                <td class="small text-break">
                                    <?php if (($r['code'] ?? '') !== ''): ?>
                                        <a href="<?= h($studentLinkBase . '?code=' . (string)$r['code']) ?>" target="_blank" rel="noopener"><?= h($studentLinkBase . '?code=' . (string)$r['code']) ?></a>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td>
                                    <?php if (($r['code'] ?? '') !== '' && ($r['email'] ?? '') !== ''): ?>
                                        <form method="post" class="d-inline">
                                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                            <input type="hidden" name="action" value="invite_one">
                                            <input type="hidden" name="student_id" value="<?= (int)$r['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-primary" title="Invia email con il link"><i class="bi bi-envelope"></i> Invita</button>
                                        </form>
                                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                                </td>
                                <td class="text-danger small"><?= h((string)($r['error'] ?? '')) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <div class="card shadow-sm mt-3">
        <div class="card-header bg-white fw-semibold">Link e repository su GitHub</div>
        <div class="card-body">
            <div class="mb-2 text-break">
                <strong>Link generico assignment (studente):</strong>
                <?php if ($assignmentSlug !== ''): ?>
                    <a href="<?= h($studentLinkBase . '?assignment=' . urlencode($assignmentSlug)) ?>" target="_blank" rel="noopener"><?= h($studentLinkBase . '?assignment=' . urlencode($assignmentSlug)) ?></a>
                <?php else: ?>
                    <span class="text-muted">Esegui "Crea assignment" per generarlo.</span>
                <?php endif; ?>
            </div>
            <div class="mb-2 text-break">
                <strong>Link docente (repo su GitHub):</strong>
                <?php if ($teacherReposUrl !== ''): ?>
                    <a href="<?= h($teacherReposUrl) ?>" target="_blank" rel="noopener"><?= h($teacherReposUrl) ?></a>
                <?php else: ?>
                    <span class="text-muted">Esegui "Crea assignment" per generarlo.</span>
                <?php endif; ?>
            </div>
            <div>
                <strong>Repo su GitHub (<?= count($assignmentRepos) ?>)</strong> <span class="text-muted small">prefisso "<?= h($repoPrefix) ?>"</span>
                <?php if (empty($assignmentRepos)): ?>
                    <div class="text-muted small mt-1">Nessuna repo trovata.</div>
                <?php else: ?>
                    <ul class="small mb-0 mt-1">
                        <?php foreach ($assignmentRepos as $rep): ?>
                            <li><a href="<?= h((string)($rep['html_url'] ?? '')) ?>" target="_blank" rel="noopener"><?= h((string)($rep['full_name'] ?? $rep['name'])) ?></a></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <p class="text-muted small mt-3">
        Nota: i risultati vivono in <code>storage/temp/github_assignment_test.db</code>. Le email generate da ClasseViva
        usano il "Template email studenti" del Profilo (nome + cognome + dominio).
    </p>
</div>
</body>
</html>
