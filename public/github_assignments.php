<?php
/**
 * Gestione Assignment GitHub (metodologia REST, senza GitHub Classroom).
 *
 * Elenca gli assignment (righe TEST con piattaforma='github') e permette di:
 *  - aprire il riepilogo (github_assignment_review.php);
 *  - consultare le repository create per prefisso;
 *  - inviare nuovamente gli inviti via email;
 *  - eliminare un assignment (riga TEST + link studente + repository).
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentService;
use App\Core\NotificationManager;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAuthenticated($_SESSION);

$pageTitle = "Gestione Assignment GitHub";

$db = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? '');
$csrfToken = Csrf::token($_SESSION);

$github = new GitHubIntegration($config);
$github->loadTokenFromSession();
$ghAuthed = $github->isAuthenticated();

$filterUda = trim((string)($_GET['id_uda'] ?? ''));
$repoViewId = trim((string)($_GET['repo'] ?? ''));

$error = null;
$success = null;

function gh_assignments_h(string $v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/** Estrae owner/repo da una URL GitHub (https://github.com/{owner}/{repo}). */
function gh_assignments_owner_repo(string $url): array
{
    if (preg_match('#^https?://github[.]com/([^/]+)/([^/]+?)(?:/.*)?$#i', trim($url), $m)) {
        return [strtolower($m[1]), strtolower($m[2])];
    }
    return ['', ''];
}

/** Risolve email/nome studenti del gruppo (Google Classroom primario, ClasseViva fallback). */
function gh_assignments_resolve_students(array $config, $db, string $userId, string $groupId, string $emailTemplate, string $emailDomain): array
{
    $svc = new TeachingGroupStudentService($db, $userId);
    $integrations = new TeachingGroupIntegrationRepository($db, $userId);
    $gcCourse = '';
    $cvClass = '';
    foreach ($integrations->listForGroup($groupId) as $it) {
        if (($it['stato'] ?? 'attivo') === 'disattivo') {
            continue;
        }
        $p = (string)($it['provider'] ?? '');
        if ($p === 'google_classroom') {
            $gcCourse = (string)($it['external_context_id'] ?? '');
        } elseif ($p === 'classeviva') {
            $cvClass = (string)($it['external_context_id'] ?? '');
        }
    }
    $rosters = [];
    if ($gcCourse !== '') {
        try {
            $rosters['google_classroom'] = (new GoogleClassroomAPI($config))->getCourseStudents($gcCourse);
        } catch (Throwable $e) {
            $rosters['google_classroom'] = [];
        }
    }
    if ($cvClass !== '') {
        try {
            $rosters['classeviva'] = (new ClasseVivaAPI($config))->getStudentiClasse($cvClass);
        } catch (Throwable $e) {
            $rosters['classeviva'] = [];
        }
    }
    $matrix = $svc->matrix($groupId);
    $as = new GitHubAssignmentService($emailTemplate, $emailDomain);
    return $as->resolveStudents($matrix, $rosters, 'google_classroom');
}

// ---- Azioni POST ----
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $action = (string)($_POST['action'] ?? '');
        $testId = (string)($_POST['test_id'] ?? '');

        $test = $testId !== '' ? $db->findOne('TEST', 'id_test', $testId) : null;
        if (!$test || strtolower((string)($test['piattaforma'] ?? '')) !== 'github') {
            throw new Exception('Assignment GitHub non trovato.');
        }
        $cfg = json_decode((string)($test['github_config_json'] ?? '{}'), true);
        $cfg = is_array($cfg) ? $cfg : [];
        $org = (string)($cfg['org'] ?? '');
        $prefix = (string)($cfg['repo_prefix'] ?? GitHubAssignmentService::repoPrefix((string)($test['nome'] ?? '')));

        if ($action === 'delete_assignment') {
            // 1) Elimina le repository create per questo assignment (per prefisso).
            $deletedRepos = 0;
            if ($ghAuthed && $org !== '' && $prefix !== '') {
                try {
                    foreach ($github->listOrgRepositories($org) as $repo) {
                        $name = (string)($repo['name'] ?? '');
                        if ($name === '' || strpos($name, $prefix) !== 0) {
                            continue;
                        }
                        try {
                            $github->deleteRepository($org, $name);
                            $deletedRepos++;
                        } catch (Throwable $e) {
                            // continua
                        }
                    }
                } catch (Throwable $e) {
                    // continua: la cancellazione delle righe procede comunque
                }
            }
            // 2) Elimina i link studente.
            foreach ($db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $link) {
                $db->deleteRow('GITHUB_ASSIGNMENT_STUDENT_LINKS', (string)($link['id_map'] ?? ''), 'id_map');
            }
            // 3) Elimina la riga TEST.
            $db->deleteRow('TEST', $testId, 'id_test');
            $success = 'Assignment eliminato' . ($deletedRepos > 0 ? " ({$deletedRepos} repository rimosse)." : '.');
            header('Location: github_assignments.php' . ($filterUda !== '' ? '?id_uda=' . urlencode($filterUda) : ''));
            exit;
        }

        if ($action === 'send_invites') {
            if (!$ghAuthed) {
                throw new Exception('Autorizza GitHub prima di inviare gli inviti.');
            }
            $groupId = (string)($test['id_gruppo'] ?? '');
            if ($groupId === '') {
                throw new Exception('Questo assignment non è collegato a un gruppo didattico.');
            }
            $profile = $config['user_profile'] ?? [];
            $emailTemplate = (string)($profile['school_student_email_template'] ?? '');
            $emailDomain = (string)($profile['school_email_domain'] ?? '');

            $codeByStudent = [];
            foreach ($db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $link) {
                if ((string)($link['github_username'] ?? '') !== '') {
                    continue; // già accettato
                }
                $codeByStudent[(string)($link['id_studente'] ?? '')] = (string)($link['acceptance_code'] ?? '');
            }

            $students = gh_assignments_resolve_students($config, $db, $userId, $groupId, $emailTemplate, $emailDomain);
            $nm = new NotificationManager($config);
            $sent = 0;
            $failed = 0;
            foreach ($students as $s) {
                $code = $codeByStudent[(string)$s['id_studente']] ?? '';
                $email = (string)($s['email'] ?? '');
                if ($code === '' || $email === '') {
                    $failed++;
                    continue;
                }
                $link = app_url('public/accept_assignment.php') . '?code=' . urlencode($code);
                $body = '<p>Ciao ' . gh_assignments_h((string)($s['nome'] ?? 'studente')) . ',</p>'
                    . '<p>Ti ricordiamo di accettare il tuo assignment GitHub.</p>'
                    . '<p>Accedi con il tuo account GitHub per ricevere la tua repository:</p>'
                    . '<p><a href="' . gh_assignments_h($link) . '">' . gh_assignments_h($link) . '</a></p>';
                if ($nm->sendHtmlEmail($email, 'Invito assignment: ' . (string)($test['nome'] ?? ''), $body)) {
                    $sent++;
                } else {
                    $failed++;
                }
            }
            $success = "Inviti inviati: {$sent}" . ($failed > 0 ? " (non inviati: {$failed})." : '.');
            header('Location: github_assignments.php' . ($filterUda !== '' ? '?id_uda=' . urlencode($filterUda) : ''));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

// ---- Caricamento dati ----
$tests = $db->findWhere('TEST', ['piattaforma' => 'github']);
if ($filterUda !== '') {
    $tests = array_values(array_filter($tests, static fn($t) => (string)($t['id_uda'] ?? '') === $filterUda));
}
usort($tests, static fn($a, $b) => strcmp((string)($b['data_creazione'] ?? ''), (string)($a['data_creazione'] ?? '')));

// Arricchisce ogni test con nome gruppo, UDA, config e conteggio link.
$rows = [];
foreach ($tests as $t) {
    $idTest = (string)($t['id_test'] ?? '');
    $cfg = json_decode((string)($t['github_config_json'] ?? '{}'), true);
    $cfg = is_array($cfg) ? $cfg : [];

    $groupName = '';
    $groupId = (string)($t['id_gruppo'] ?? '');
    if ($groupId !== '') {
        $g = $db->findOne('GRUPPI_DIDATTICI', 'id_gruppo', $groupId);
        if (is_array($g)) {
            $groupName = (string)($g['nome_gruppo'] ?? '');
        }
    }
    $udaTitle = '';
    if ((string)($t['id_uda'] ?? '') !== '') {
        $u = $db->findUDAById((string)$t['id_uda']);
        if (is_array($u)) {
            $udaTitle = (string)($u['titolo'] ?? '');
        }
    }
    $links = $db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $idTest]);
    $accepted = 0;
    foreach ($links as $l) {
        if ((string)($l['github_username'] ?? '') !== '') {
            $accepted++;
        }
    }

    $rows[] = [
        'test' => $t,
        'group_name' => $groupName,
        'uda_title' => $udaTitle,
        'org' => (string)($cfg['org'] ?? ''),
        'slug' => (string)($cfg['slug'] ?? ''),
        'prefix' => (string)($cfg['repo_prefix'] ?? GitHubAssignmentService::repoPrefix((string)($t['nome'] ?? ''))),
        'link_count' => count($links),
        'accepted' => $accepted,
    ];
}

// Vista repository (opzionale, una alla volta).
$reposForTest = null;
if ($repoViewId !== '') {
    foreach ($rows as $row) {
        if ((string)($row['test']['id_test'] ?? '') === $repoViewId) {
            $reposForTest = $row;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= gh_assignments_h($pageTitle) ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .gh-assignment-card { border-left: 4px solid #663399; }
        .gh-assignment-card:hover { box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
    </style>
</head>
<body>
<?php
$pageSubtitle = 'Assignment GitHub (metodologia REST)';
$headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-arrow-left"></i> Dashboard</a>'
    . ($filterUda !== '' ? '<a class="nav-link" href="github_assignment_create.php?id_uda=' . urlencode($filterUda) . '"><i class="bi bi-plus-circle"></i> Nuovo assignment</a>' : '');
$pageActions = '';
include __DIR__ . '/partials/app_header.php';
?>
<div class="container mt-4">
    <?php if ($error !== null): ?><div class="alert alert-danger"><?= gh_assignments_h($error) ?></div><?php endif; ?>
    <?php if ($success !== null): ?><div class="alert alert-success"><?= gh_assignments_h($success) ?></div><?php endif; ?>

    <?php if ($filterUda === ''): ?>
        <div class="alert alert-info">
            <i class="bi bi-info-circle"></i>
            Qui trovi gli assignment GitHub creati dai gruppi didattici. Per creare un nuovo assignment apri una UDA e usa
            <strong>Test &amp; attività &rarr; Crea assegnazione GitHub</strong>.
        </div>
    <?php endif; ?>

    <?php if (empty($rows)): ?>
        <div class="alert alert-secondary">
            <i class="bi bi-github"></i>
            <?= $filterUda !== '' ? 'Nessun assignment GitHub per questa UDA.' : 'Nessun assignment GitHub configurato.' ?>
        </div>
    <?php else: ?>
        <div class="row g-3">
            <?php foreach ($rows as $row): $t = $row['test']; ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100 gh-assignment-card">
                        <div class="card-header bg-white d-flex justify-content-between align-items-start">
                            <h6 class="mb-0 text-truncate" title="<?= gh_assignments_h($t['nome'] ?? '') ?>">
                                <i class="bi bi-github"></i> <?= gh_assignments_h($t['nome'] ?? '') ?>
                            </h6>
                            <span class="badge bg-github" style="background:#663399">GitHub</span>
                        </div>
                        <div class="card-body">
                            <?php if ($row['uda_title'] !== ''): ?>
                                <p class="mb-1"><strong>UDA:</strong> <small class="text-muted"><?= gh_assignments_h($row['uda_title']) ?></small></p>
                            <?php endif; ?>
                            <?php if ($row['group_name'] !== ''): ?>
                                <p class="mb-1"><strong>Gruppo:</strong> <small class="text-muted"><?= gh_assignments_h($row['group_name']) ?></small></p>
                            <?php endif; ?>
                            <?php if ($row['org'] !== ''): ?>
                                <p class="mb-1"><strong>Org:</strong> <code><?= gh_assignments_h($row['org']) ?></code></p>
                            <?php endif; ?>
                            <p class="mb-1">
                                <strong>Studenti:</strong>
                                <span class="badge bg-success"><?= (int)$row['accepted'] ?> accettati</span>
                                <span class="badge bg-warning text-dark"><?= max(0, (int)$row['link_count'] - (int)$row['accepted']) ?> in attesa</span>
                            </p>
                            <p class="mb-1 small text-muted">
                                <i class="bi bi-clock"></i> Creato: <?= gh_assignments_h($t['data_creazione'] ?? '') ?>
                            </p>
                        </div>
                        <div class="card-footer bg-white">
                            <div class="d-flex flex-wrap gap-1">
                                <a href="github_assignment_review.php?test_id=<?= urlencode((string)$t['id_test']) ?>" class="btn btn-sm btn-outline-dark">
                                    <i class="bi bi-list-check"></i> Riepilogo
                                </a>
                                <a href="github_assignments.php?repo=<?= urlencode((string)$t['id_test']) ?><?= $filterUda !== '' ? '&id_uda=' . urlencode($filterUda) : '' ?>" class="btn btn-sm btn-outline-secondary">
                                    <i class="bi bi-collection"></i> Repo
                                </a>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Inviare nuovamente gli inviti agli studenti non ancora accettati?');">
                                    <input type="hidden" name="csrf_token" value="<?= gh_assignments_h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="send_invites">
                                    <input type="hidden" name="test_id" value="<?= gh_assignments_h($t['id_test']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-primary" title="Invia inviti"><i class="bi bi-envelope"></i></button>
                                </form>
                                <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare questo assignment? Verranno rimosse anche le repository e i link studente.');">
                                    <input type="hidden" name="csrf_token" value="<?= gh_assignments_h($csrfToken) ?>">
                                    <input type="hidden" name="action" value="delete_assignment">
                                    <input type="hidden" name="test_id" value="<?= gh_assignments_h($t['id_test']) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                </form>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if ($reposForTest !== null): ?>
        <div class="card mt-4">
            <div class="card-header bg-dark text-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="bi bi-collection"></i> Repository di "<?= gh_assignments_h($reposForTest['test']['nome'] ?? '') ?>"</h6>
                <a href="github_assignments.php<?= $filterUda !== '' ? '?id_uda=' . urlencode($filterUda) : '' ?>" class="btn btn-sm btn-outline-light">Chiudi</a>
            </div>
            <div class="card-body">
                <?php
                $org = $reposForTest['org'];
                $prefix = $reposForTest['prefix'];
                if (!$ghAuthed || $org === '' || $prefix === ''):
                ?>
                    <div class="alert alert-warning mb-0">Autorizza GitHub e assicurati che l'org sia configurata per vedere le repository.</div>
                <?php else:
                    $orgRepos = [];
                    try { $orgRepos = $github->listOrgRepositories($org); } catch (Throwable $e) { $orgRepos = []; }
                    $matching = array_values(array_filter($orgRepos, static fn($r) => strpos((string)($r['name'] ?? ''), $prefix) === 0));
                    if (empty($matching)):
                ?>
                    <div class="alert alert-secondary mb-0">Nessuna repository trovata per il prefisso <code><?= gh_assignments_h($prefix) ?></code>.</div>
                <?php else: ?>
                    <table class="table table-sm table-striped mb-0">
                        <thead><tr><th>Repository</th><th>Visibilità</th><th></th></tr></thead>
                        <tbody>
                        <?php foreach ($matching as $repo): ?>
                            <tr>
                                <td><code><?= gh_assignments_h($repo['name'] ?? '') ?></code></td>
                                <td><?= (string)($repo['private'] ?? '') === '' ? '' : (((bool)($repo['private'] ?? false)) ? 'Privata' : 'Pubblica') ?></td>
                                <td class="text-end">
                                    <?php if (!empty($repo['html_url'])): ?>
                                        <a href="<?= gh_assignments_h($repo['html_url']) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php endif; endif; ?>
            </div>
        </div>
    <?php endif; ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
