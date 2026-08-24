<?php
/**
 * Creazione Assignment GitHub (metodologia REST, senza GitHub Classroom).
 *
 * Flow:
 *  Step 1: gruppo didattico + template repo + org GitHub + nome + tipo + deadline.
 *  Step 2: anteprima studenti (email risolte via API, solo id_studente persistiti),
 *          scelta modalità invito (email con link personale / pubblicazione Classroom
 *          con link generico), quindi creazione repo + TEST + link studente.
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;
use App\Core\NotificationManager;
use App\Utils\EncryptionHelper;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAuthenticated($_SESSION);

$pageTitle = "Crea Assignment GitHub";

$db = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? '');
$csrfToken = Csrf::token($_SESSION);

$github = new GitHubIntegration($config);
$github->loadTokenFromSession();
$ghAuthed = $github->isAuthenticated();

$idUda = trim((string)($_GET['id_uda'] ?? ''));
if ($idUda === '') {
    header('Location: index.php');
    exit;
}
$uda = $db->findUDAById($idUda);
if (!$uda) {
    header('Location: index.php');
    exit;
}

// Gruppi collegati alla UDA
$groups = [];
foreach ($db->findWhere('UDA_GRUPPI', ['id_uda' => $idUda, 'id_utente' => $userId]) as $link) {
    $g = $db->findOne('GRUPPI_DIDATTICI', 'id_gruppo', (string)($link['id_gruppo'] ?? ''));
    if (is_array($g) && !empty($g['id_gruppo'])) {
        $groups[] = $g;
    }
}

// Template repo attivi
$templates = array_values(array_filter($db->findAll('GITHUB_REPO_TEMPLATES'), static fn($t) => ($t['attivo'] ?? '') === 'si'));

// Profilo (template email studenti)
$profile = $config['user_profile'] ?? [];
$emailDomain = (string)($profile['school_email_domain'] ?? '');
$emailTemplate = (string)($profile['school_student_email_template'] ?? '');

$orgs = [];
if ($ghAuthed) {
    try { $orgs = $github->listOrganizations(); } catch (Throwable $e) {}
}

$error = null;
$students = [];
$step = 'form';
$formData = $_SESSION['github_assignment_form'] ?? [];

function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

function resolve_students(array $config, $db, string $userId, string $groupId, string $emailTemplate, string $emailDomain): array {
    $svc = new TeachingGroupStudentService($db, $userId);
    $integrations = new TeachingGroupIntegrationRepository($db, $userId);
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
        try { $rosters['google_classroom'] = (new GoogleClassroomAPI($config))->getCourseStudents($gcCourse); } catch (Throwable $e) { $rosters['google_classroom'] = []; }
    }
    if ($cvClass !== '') {
        try { $rosters['classeviva'] = (new ClasseVivaAPI($config))->getStudentiClasse($cvClass); } catch (Throwable $e) { $rosters['classeviva'] = []; }
    }
    $matrix = $svc->matrix($groupId);
    $as = new GitHubAssignmentService($emailTemplate, $emailDomain);
    return $as->resolveStudents($matrix, $rosters);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';

        if ($action === 'prepare') {
            $groupId = trim((string)($_POST['group_id'] ?? ''));
            $templateId = trim((string)($_POST['template_id'] ?? ''));
            $org = trim((string)($_POST['org'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            if ($groupId === '' || $templateId === '' || $org === '') {
                throw new Exception('Gruppo, template e org sono obbligatori.');
            }
            $tname = '';
            foreach ($templates as $t) { if ((string)$t['id_template'] === $templateId) { $tname = (string)($t['nome'] ?? ''); break; } }
            $gname = '';
            foreach ($groups as $g) { if ((string)$g['id_gruppo'] === $groupId) { $gname = (string)($g['nome_gruppo'] ?? ''); break; } }
            if ($name === '') $name = GitHubAssignmentService::repoPrefix($gname . '-' . $tname);

            $_SESSION['github_assignment_form'] = [
                'group_id' => $groupId,
                'template_id' => $templateId,
                'org' => $org,
                'name' => $name,
                'tipo_test' => trim((string)($_POST['tipo_test'] ?? 'altro')),
                'deadline' => trim((string)($_POST['deadline'] ?? '')),
                'note' => trim((string)($_POST['note'] ?? '')),
            ];
            $formData = $_SESSION['github_assignment_form'];
            $students = resolve_students($config, $db, $userId, $groupId, $emailTemplate, $emailDomain);
            $step = 'confirm';
        }

        if ($action === 'create') {
            if (empty($formData)) throw new Exception('Dati mancanti. Ricomincia dalla selezione.');
            if (!$ghAuthed) throw new Exception('Autorizza GitHub prima di creare gli assignment.');

            $org = (string)$formData['org'];
            $name = (string)$formData['name'];
            $groupId = (string)$formData['group_id'];
            $templateId = (string)$formData['template_id'];
            $modes = (array)($_POST['modes'] ?? []);
            $modeEmail = in_array('email', $modes, true);
            $modeClassroom = in_array('classroom', $modes, true);

            $template = null;
            foreach ($templates as $t) { if ((string)$t['id_template'] === $templateId) { $template = $t; break; } }
            if (!$template) throw new Exception('Template non trovato.');
            [$tOwner, $tRepo] = (static function(string $url): array {
                if (preg_match('#^https?://github[.]com/([^/]+)/([^/]+?)(?:[.]git)?/?$#i', trim($url), $m)) {
                    return [strtolower($m[1]), strtolower($m[2])];
                }
                return ['', ''];
            })((string)($template['url_repository'] ?? ''));
            if ($tOwner === '' || $tRepo === '') throw new Exception('URL template non valido.');

            $students = resolve_students($config, $db, $userId, $groupId, $emailTemplate, $emailDomain);
            if (empty($students)) throw new Exception('Nessuno studente risolto nel gruppo.');

            $slug = GitHubAssignmentService::assignmentSlug($name);
            $prefix = GitHubAssignmentService::repoPrefix($name);
            $idTest = 'TEST_' . uniqid();
            $genericLink = app_url('public/accept_assignment.php') . '?assignment=' . urlencode($slug);

            $usedCodes = [];
            $links = [];
            foreach ($students as $s) {
                $repoName = GitHubAssignmentService::repoName($name, $usedCodes);
                $acceptanceCode = GitHubAssignmentService::generateAcceptanceCode();
                $repoUrl = '';
                try {
                    $created = $github->createRepositoryFromTemplate($tOwner, $tRepo, $repoName, $org, $name, true);
                    $repoUrl = (string)($created['html_url'] ?? '');
                } catch (Throwable $e) {
                }
                $db->insertRow('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
                    'id_assignment' => $idTest,
                    'id_studente' => (string)$s['id_studente'],
                    'student_repository_url' => $repoUrl,
                    'acceptance_code' => $acceptanceCode,
                    'github_username' => '',
                    'accepted_at' => null,
                    'note' => '',
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'id_utente' => $userId,
                ]);
                $links[] = ['student' => $s, 'repo_url' => $repoUrl, 'code' => $acceptanceCode];
            }

            $teacherReposUrl = ($org !== '' && $prefix !== '')
                ? 'https://github.com/search?q=' . urlencode("user:{$org} {$prefix} in:name") . '&type=repositories'
                : '';

            $db->insertRow('TEST', [
                'id_test' => $idTest,
                'id_uda' => $idUda,
                'id_gruppo' => $groupId,
                'tipo_test' => (string)$formData['tipo_test'],
                'nome' => $name,
                'descrizione' => (string)$formData['note'],
                'piattaforma' => 'github',
                'url' => '',
                'id_esterno' => '',
                'pubblicato' => 'NO',
                'data_creazione' => date('Y-m-d H:i:s'),
                'note' => (string)$formData['note'],
                'id_utente' => $userId,
                'url_studenti' => $genericLink,
                'url_docente' => $teacherReposUrl,
                'url_assignment_student' => $genericLink,
                'url_assignment_teacher' => app_url('public/github_assignment_review.php') . '?test_id=' . urlencode($idTest),
                'github_config_json' => json_encode([
                    'org' => $org,
                    'slug' => $slug,
                    'repo_prefix' => $prefix,
                    'template_id' => $templateId,
                    'modalita_invito' => $modes,
                    'teacher_token' => EncryptionHelper::encrypt((string)($_SESSION['github_access_token'] ?? '')),
                ], JSON_THROW_ON_ERROR),
            ]);

            $emailSent = 0; $emailFailed = 0;
            if ($modeEmail) {
                $nm = new NotificationManager($config);
                foreach ($links as $l) {
                    $email = (string)($l['student']['email'] ?? '');
                    if ($email === '') { $emailFailed++; continue; }
                    $link = app_url('public/accept_assignment.php') . '?code=' . urlencode((string)$l['code']);
                    $body = '<p>Ciao ' . h((string)($l['student']['nome'] ?? 'studente')) . ',</p>'
                        . '<p>Ti e stato assegnato un assignment GitHub.</p>'
                        . '<p>Accedi con il tuo account GitHub per accettare e ricevere la tua repository:</p>'
                        . '<p><a href="' . h($link) . '">' . h($link) . '</a></p>';
                    if ($nm->sendHtmlEmail($email, 'Invito assignment: ' . $name, $body)) { $emailSent++; } else { $emailFailed++; }
                }
            }

            $classroomPublished = false;
            if ($modeClassroom) {
                $integrations = new TeachingGroupIntegrationRepository($db, $userId);
                $gcCourse = '';
                foreach ($integrations->listForGroup($groupId) as $it) {
                    if ((string)($it['provider'] ?? '') === 'google_classroom' && ($it['stato'] ?? 'attivo') !== 'disattivo') {
                        $gcCourse = (string)($it['external_context_id'] ?? '');
                    }
                }
                if ($gcCourse !== '') {
                    $gc = new GoogleClassroomAPI($config);
                    $created = $gc->createMaterial($gcCourse, [
                        'title' => $name,
                        'description' => 'Assignment GitHub — accedi con il tuo account GitHub per ricevere la repository.',
                        'link' => $genericLink,
                        'state' => 'PUBLISHED',
                    ]);
                    $db->updateRow('TEST', 'id_test', $idTest, [
                        'classroom_course_id' => $gcCourse,
                        'classroom_assignment_id' => (string)($created['id'] ?? ''),
                        'pubblicato' => 'SI',
                    ]);
                    $classroomPublished = true;
                }
            }

            unset($_SESSION['github_assignment_form']);
            $_SESSION['github_assignment_success'] = 'Assignment creato (' . $idTest . '). Email inviate: ' . $emailSent . '/' . ($emailSent + $emailFailed) . ($classroomPublished ? '. Pubblicato su Classroom.' : '.');
            header('Location: github_assignments.php?id_uda=' . urlencode($idUda));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= h($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
<?php
$pageTitle = '<i class="bi bi-github"></i> Crea Assignment GitHub';
$pageSubtitle = 'UDA: ' . ($uda['titolo'] ?? '');
$headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($idUda) . '"><i class="bi bi-arrow-left"></i> Torna ai test</a>';
$pageActions = '';
include __DIR__ . '/partials/app_header.php';
?>
<div class="container mt-4">

    <?php if ($error !== null): ?><div class="alert alert-danger"><?= h($error) ?></div><?php endif; ?>
    <?php if (!empty($_SESSION['github_assignment_success'])): ?><div class="alert alert-success"><?= h((string)$_SESSION['github_assignment_success']) ?></div><?php unset($_SESSION['github_assignment_success']); endif; ?>

    <div class="card shadow-sm">
        <div class="card-header bg-white fw-semibold"><?= $step === 'confirm' ? 'Conferma e modalità invito' : 'Configurazione assignment' ?></div>
        <div class="card-body">
            <?php if ($step === 'confirm'): ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <div class="col-12">
                        <table class="table table-sm table-striped">
                            <thead><tr><th>#</th><th>Studente</th><th>Email</th></tr></thead>
                            <tbody>
                                <?php if (empty($students)): ?><tr><td colspan="3" class="text-muted">Nessuno studente risolto (verifica le mappature del gruppo).</td></tr>
                                <?php else: foreach ($students as $i => $s): ?>
                                    <tr>
                                        <td><?= (int)$i + 1 ?></td>
                                        <td><?= h((string)($s['nome'] ?? $s['id_studente'])) ?></td>
                                        <td><code><?= h((string)($s['email'] ?? '(non risolta)')) ?></code></td>
                                    </tr>
                                <?php endforeach; endif; ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="modes[]" value="email" id="modeEmail" checked>
                            <label class="form-check-label" for="modeEmail">Invito via email (link personale per studente)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="modes[]" value="classroom" id="modeClassroom">
                            <label class="form-check-label" for="modeClassroom">Pubblica su Google Classroom (link generico di classe)</label>
                        </div>
                    </div>
                    <div class="col-12 d-flex gap-2">
                        <button type="submit" class="btn btn-primary"><i class="bi bi-git"></i> Crea assignment</button>
                        <a href="github_assignment_create.php?id_uda=<?= h($idUda) ?>" class="btn btn-outline-secondary">Annulla</a>
                    </div>
                </form>
            <?php else: ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="prepare">
                    <div class="col-md-6">
                        <label class="form-label">Gruppo didattico</label>
                        <select name="group_id" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($groups as $g): ?>
                                <option value="<?= h((string)$g['id_gruppo']) ?>"><?= h((string)($g['nome_gruppo'] ?? $g['id_gruppo'])) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Template repo</label>
                        <select name="template_id" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= h((string)$t['id_template']) ?>"><?= h((string)($t['nome'] ?? $t['id_template'])) ?><?= ($t['categoria'] ?? '') !== '' ? ' — ' . h((string)$t['categoria']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Org GitHub</label>
                        <select name="org" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?= h((string)($o['login'] ?? '')) ?>"><?= h((string)($o['login'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Nome assignment (auto {gruppo}-{template})</label>
                        <input type="text" name="name" class="form-control" placeholder="{gruppo}-{template}">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select name="tipo_test" class="form-select">
                            <option value="prerequisiti">Prerequisiti</option>
                            <option value="intermedio">Intermedio</option>
                            <option value="finale">Finale</option>
                            <option value="altro" selected>Altro</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Deadline</label>
                        <input type="datetime-local" name="deadline" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" class="form-control">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary"><i class="bi bi-people"></i> Carica studenti e continua</button>
                    </div>
                </form>
            <?php endif; ?>
        </div>
    </div>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
