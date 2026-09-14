<?php
/**
 * Creazione Assignment GitHub (metodologia REST, senza GitHub Classroom).
 *
 * Flow:
 *  Step 1: uno o più gruppi didattici + template repo + org GitHub + nome + tipo + deadline.
 *  Step 2: anteprima studenti (email risolte via API, solo id_studente persistiti),
 *          scelta modalità invito (email con link personale / pubblicazione Classroom
 *          con link generico), quindi creazione repo + TEST + link studente.
 */

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentGroupRepository;
use App\Core\GitHubAssignmentService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Core\ClasseVivaCapability;
use App\Core\ClasseVivaTokenGuard;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;
use App\Core\NotificationManager;
use App\Utils\EncryptionHelper;

$idUda = trim((string)($_GET['id_uda'] ?? ''));
if ($idUda !== '' && !defined('REQUIRES_CLASSEVIVA_FOR_UDA')) {
    define('REQUIRES_CLASSEVIVA_FOR_UDA', $idUda);
}

require_once '../bootstrap.php';

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
// Verifica che il token sia ancora valido (fresco): se getUser fallisce, il token è scaduto/revocato.
if ($ghAuthed) {
    try {
        $github->getUser();
    } catch (Throwable $e) {
        $ghAuthed = false;
    }
}
$githubAuthUrl = '';
if (!$ghAuthed && !empty($config['github']['client_id'] ?? '')) {
    $githubAuthUrl = $github->getAuthorizationUrl(null, (string)($_SERVER['REQUEST_URI'] ?? 'github_assignment_create.php'));
}

if ($idUda === '') {
    header('Location: index.php');
    exit;
}
$uda = $db->findUDAById($idUda);
if (!$uda) {
    header('Location: index.php');
    exit;
}

// Gruppi collegati alla UDA. Deduplicazione difensiva nel caso di vecchi
// collegamenti duplicati presenti nello storico.
$groupsById = [];
foreach ($db->findWhere('UDA_GRUPPI', ['id_uda' => $idUda, 'id_utente' => $userId]) as $link) {
    $g = $db->findOne('GRUPPI_DIDATTICI', 'id_gruppo', (string)($link['id_gruppo'] ?? ''));
    if (is_array($g) && !empty($g['id_gruppo'])) {
        $groupsById[(string)$g['id_gruppo']] = $g;
    }
}
$groups = array_values($groupsById);
$requiresClasseVivaForGroups = ClasseVivaCapability::hasMappedUda($db, $userId, $idUda);

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
    return $as->resolveStudents($matrix, $rosters, 'google_classroom');
}

/** @return list<string> */
function normalize_group_selection(mixed $raw): array {
    return GitHubAssignmentGroupRepository::normalizeGroupIds(
        is_array($raw) ? $raw : [$raw]
    );
}

/** @param list<string> $groupIds @param list<array<string,mixed>> $groups */
function assert_groups_belong_to_uda(array $groupIds, array $groups): void {
    if ($groupIds === []) {
        throw new Exception('Seleziona almeno un gruppo didattico.');
    }
    $allowed = [];
    foreach ($groups as $group) {
        $id = trim((string)($group['id_gruppo'] ?? ''));
        if ($id !== '') {
            $allowed[$id] = true;
        }
    }
    foreach ($groupIds as $groupId) {
        if (!isset($allowed[$groupId])) {
            throw new Exception('Uno dei gruppi selezionati non è assegnato a questa UDA.');
        }
    }
}

/** @param list<string> $groupIds @return list<array<string,mixed>> */
function resolve_students_for_groups(array $config, $db, string $userId, array $groupIds, string $emailTemplate, string $emailDomain): array {
    $lists = [];
    foreach ($groupIds as $groupId) {
        $lists[] = resolve_students($config, $db, $userId, $groupId, $emailTemplate, $emailDomain);
    }
    $service = new GitHubAssignmentService($emailTemplate, $emailDomain);
    return $service->mergeResolvedStudents($lists);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $action = $_POST['action'] ?? '';

        if ($action === 'prepare') {
            $groupIds = normalize_group_selection($_POST['group_ids'] ?? ($_POST['group_id'] ?? []));
            $templateId = trim((string)($_POST['template_id'] ?? ''));
            $org = trim((string)($_POST['org'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            assert_groups_belong_to_uda($groupIds, $groups);
            if ($requiresClasseVivaForGroups) {
                ClasseVivaTokenGuard::requireToken($config);
            }
            if ($templateId === '' || $org === '') {
                throw new Exception('Gruppo, template e org sono obbligatori.');
            }
            $tname = '';
            foreach ($templates as $t) { if ((string)$t['id_template'] === $templateId) { $tname = (string)($t['nome'] ?? ''); break; } }
            $gname = '';
            foreach ($groups as $g) {
                if ((string)$g['id_gruppo'] === $groupIds[0]) {
                    $gname = (string)($g['nome_gruppo'] ?? '');
                    break;
                }
            }
            if ($name === '') $name = GitHubAssignmentService::repoPrefix($gname . '-' . $tname);

            $_SESSION['github_assignment_form'] = [
                'group_ids' => $groupIds,
                // Compatibilità con pagine e test che leggono ancora il primo gruppo.
                'group_id' => $groupIds[0],
                'template_id' => $templateId,
                'org' => $org,
                'name' => $name,
                'tipo_test' => trim((string)($_POST['tipo_test'] ?? 'altro')),
                'deadline' => trim((string)($_POST['deadline'] ?? '')),
                'note' => trim((string)($_POST['note'] ?? '')),
            ];
            $formData = $_SESSION['github_assignment_form'];
            $students = resolve_students_for_groups($config, $db, $userId, $groupIds, $emailTemplate, $emailDomain);
            $step = 'confirm';
        }

        if ($action === 'create') {
            if (empty($formData)) throw new Exception('Dati mancanti. Ricomincia dalla selezione.');
            if (!$ghAuthed) throw new Exception('Autorizza GitHub prima di creare gli assignment.');
            if ($requiresClasseVivaForGroups) {
                ClasseVivaTokenGuard::requireToken($config);
            }

            $org = (string)$formData['org'];
            $name = (string)$formData['name'];
            $groupIds = normalize_group_selection($formData['group_ids'] ?? ($formData['group_id'] ?? []));
            assert_groups_belong_to_uda($groupIds, $groups);
            $groupId = $groupIds[0];
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

            // GitHub consente POST /generate solo per repository marcati
            // esplicitamente come "Template repository". Verifichiamo prima
            // del batch per non creare assignment con link studenti vuoti.
            $sourceRepository = $github->getRepository($tOwner, $tRepo);
            if (empty($sourceRepository['is_template'])) {
                throw new Exception(
                    'Il repository sorgente non è configurato come template GitHub. '
                    . 'Apri https://github.com/' . $tOwner . '/' . $tRepo
                    . '/settings e abilita "Template repository", quindi riprova.'
                );
            }

            $students = resolve_students_for_groups($config, $db, $userId, $groupIds, $emailTemplate, $emailDomain);
            if (empty($students)) throw new Exception('Nessuno studente risolto nel gruppo.');

            $slug = GitHubAssignmentService::assignmentSlug($name);
            $prefix = GitHubAssignmentService::repoPrefix($name);
            $idTest = 'TEST_' . uniqid();
            $genericLink = app_url('public/accept_assignment.php') . '?assignment=' . urlencode($slug);

            $usedCodes = [];
            $links = [];
            $createdRepos = [];
            $repoCreationErrors = [];
            foreach ($students as $s) {
                $repoName = GitHubAssignmentService::repoName($name, $usedCodes);
                $acceptanceCode = GitHubAssignmentService::generateAcceptanceCode();
                try {
                    $created = $github->createRepositoryFromTemplate($tOwner, $tRepo, $repoName, $org, $name, true);
                    // Registriamo subito il nome per poter tentare la pulizia
                    // anche se la risposta non contiene html_url.
                    $createdRepos[] = ['owner' => $org, 'repo' => $repoName];
                    $repoUrl = (string)($created['html_url'] ?? '');
                    if ($repoUrl === '') {
                        throw new Exception('GitHub non ha restituito il collegamento alla repository.');
                    }
                } catch (Throwable $e) {
                    $repoCreationErrors[] = $repoName . ': ' . $e->getMessage();
                    break;
                }
                $links[] = ['student' => $s, 'repo_url' => $repoUrl, 'code' => $acceptanceCode];
            }

            if ($repoCreationErrors !== []) {
                // Il batch non deve lasciare repository orfane se una delle
                // creazioni fallisce prima del salvataggio nel database.
                foreach ($createdRepos as $createdRepo) {
                    try {
                        $github->deleteRepository($createdRepo['owner'], $createdRepo['repo']);
                    } catch (Throwable $ignored) {
                        // La causa originale è più utile all'utente; la pulizia
                        // viene comunque tentata per ogni repository riuscita.
                    }
                }
                throw new Exception('Creazione repository studenti fallita: ' . implode('; ', $repoCreationErrors));
            }

            foreach ($links as $link) {
                $db->insertRow('GITHUB_ASSIGNMENT_STUDENT_LINKS', [
                    'id_map' => 'GHMAP_' . bin2hex(random_bytes(10)),
                    'id_assignment' => $idTest,
                    'id_studente' => (string)$link['student']['id_studente'],
                    'student_repository_url' => (string)$link['repo_url'],
                    'acceptance_code' => (string)$link['code'],
                    'github_username' => '',
                    'accepted_at' => null,
                    'note' => '',
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'id_utente' => $userId,
                ]);
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
            (new GitHubAssignmentGroupRepository($db, $userId))->replaceForTest($idTest, $groupIds);

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
            $classroomPublicationErrors = 0;
            if ($modeClassroom) {
                $integrations = new TeachingGroupIntegrationRepository($db, $userId);
                // Un solo materiale per corso Classroom, anche se più gruppi
                // interni puntano allo stesso corso esterno.
                $courseGroups = [];
                foreach ($groupIds as $selectedGroupId) {
                    foreach ($integrations->listForGroup($selectedGroupId) as $it) {
                        if ((string)($it['provider'] ?? '') !== 'google_classroom'
                            || ($it['stato'] ?? 'attivo') === 'disattivo') {
                            continue;
                        }
                        $courseId = trim((string)($it['external_context_id'] ?? ''));
                        if ($courseId !== '' && !isset($courseGroups[$courseId])) {
                            $courseGroups[$courseId] = $selectedGroupId;
                        }
                    }
                }
                if ($courseGroups !== []) {
                    $gc = new GoogleClassroomAPI($config);
                    // Argomento Classroom = argomento dell'UDA (fallback sul titolo).
                    $topicName = !empty((string)($uda['argomento'] ?? '')) ? (string)$uda['argomento'] : (string)($uda['titolo'] ?? '');
                    $legacyClassroomUpdated = false;
                    foreach ($courseGroups as $gcCourse => $courseGroupId) {
                        try {
                            $materialData = [
                                'title' => $name,
                                'description' => 'Assignment GitHub — accedi con il tuo account GitHub per ricevere la repository.',
                                'link' => $genericLink,
                                'state' => 'DRAFT',
                            ];
                            if ($topicName !== '') {
                                try {
                                    $topic = $gc->findOrCreateTopic($gcCourse, $topicName);
                                    $materialData['topicId'] = (string)($topic['id'] ?? '');
                                } catch (Throwable $ignored) {
                                    // argomento non impostabile: prosegue senza topic
                                }
                            }
                            $created = $gc->createMaterial($gcCourse, $materialData);
                            $assignmentId = (string)($created['id'] ?? '');
                            $classroomUrl = trim((string)($created['link'] ?? ''));
                            $db->insertRow('TEST_CLASSROOM_PUBBLICAZIONI', [
                                'id_pubblicazione' => 'TCLPUB_' . bin2hex(random_bytes(10)),
                                'id_test' => $idTest,
                                'id_gruppo' => $courseGroupId,
                                'course_id' => $gcCourse,
                                'classroom_assignment_id' => $assignmentId,
                                'classroom_url' => $classroomUrl,
                                'data_pubblicazione' => date('Y-m-d H:i:s'),
                                'id_utente' => $userId,
                            ]);
                            if (!$legacyClassroomUpdated) {
                                $db->updateRow('TEST', 'id_test', $idTest, [
                                    'classroom_course_id' => $gcCourse,
                                    'classroom_assignment_id' => $assignmentId,
                                    'classroom_url' => $classroomUrl,
                                    'pubblicato' => 'NO',
                                ]);
                                $legacyClassroomUpdated = true;
                            }
                            $classroomPublished = true;
                        } catch (Throwable $ignored) {
                            // Un corso non disponibile non deve annullare le
                            // pubblicazioni già riuscite sugli altri corsi.
                            $classroomPublicationErrors++;
                        }
                    }
                }
            }

            unset($_SESSION['github_assignment_form']);
            $classroomMessage = $classroomPublished ? '. Bozze Classroom create.' : '';
            if ($classroomPublicationErrors > 0) {
                $classroomMessage .= ' Pubblicazioni Classroom non riuscite: ' . $classroomPublicationErrors . '.';
            }
            $_SESSION['github_assignment_success'] = 'Assignment creato (' . $idTest . '). Email inviate: ' . $emailSent . '/' . ($emailSent + $emailFailed) . $classroomMessage;
            header('Location: uda_tests.php?id=' . urlencode($idUda));
            exit;
        }
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
$selectedGroupIds = normalize_group_selection($formData['group_ids'] ?? ($formData['group_id'] ?? []));
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

    <?php if (!$ghAuthed): ?>
        <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
            <div><i class="bi bi-github"></i> <strong>Autenticazione GitHub richiesta</strong> — il token non è presente o non è più valido.</div>
            <?php if ($githubAuthUrl !== ''): ?>
                <a href="<?= h($githubAuthUrl) ?>" class="btn btn-dark"><i class="bi bi-github"></i> Autorizza GitHub</a>
            <?php else: ?>
                <a href="user_integrations.php#github-section" class="btn btn-outline-dark"><i class="bi bi-gear"></i> Configura le credenziali GitHub</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

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
                        <div class="alert alert-info mb-0">
                            <i class="bi bi-people"></i>
                            <strong>Gruppi destinatari:</strong>
                            <?php
                            $confirmGroupNames = [];
                            foreach ($selectedGroupIds as $selectedGroupId) {
                                foreach ($groups as $group) {
                                    if ((string)($group['id_gruppo'] ?? '') === $selectedGroupId) {
                                        $confirmGroupNames[] = (string)($group['nome_gruppo'] ?? $selectedGroupId);
                                        break;
                                    }
                                }
                            }
                            echo h(implode(', ', $confirmGroupNames));
                            ?>
                        </div>
                    </div>
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
                            <input class="form-check-input" type="checkbox" name="modes[]" value="email" id="modeEmail">
                            <label class="form-check-label" for="modeEmail">Invito via email (link personale per studente)</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="modes[]" value="classroom" id="modeClassroom" checked>
                            <label class="form-check-label" for="modeClassroom">Pubblica come bozza su Google Classroom (link generico di classe)</label>
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
                        <label class="form-label">Gruppi didattici di destinazione</label>
                        <div id="group-selectors" class="vstack gap-2">
                            <?php
                            $groupRows = $selectedGroupIds;
                            if ($groupRows === [] || end($groupRows) !== '') {
                                $groupRows[] = '';
                            }
                            foreach ($groupRows as $selectedGroupId):
                            ?>
                                <select name="group_ids[]" class="form-select group-select">
                                    <option value="">— seleziona —</option>
                                    <?php foreach ($groups as $g): ?>
                                        <?php $groupOptionId = (string)$g['id_gruppo']; ?>
                                        <option value="<?= h($groupOptionId) ?>"<?= $selectedGroupId === $groupOptionId ? ' selected' : '' ?>><?= h((string)($g['nome_gruppo'] ?? $groupOptionId)) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text">Selezionando un gruppo compare automaticamente una nuova lista. Lo stesso gruppo non può essere scelto due volte.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Template repo</label>
                        <select name="template_id" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= h((string)$t['id_template']) ?>"><?= h((string)($t['nome'] ?? $t['id_template'])) ?><?= ($t['categoria'] ?? '') !== '' ? ' — ' . h((string)$t['categoria']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="github_repo_templates.php" target="_blank" class="btn btn-sm btn-outline-secondary mt-1">
                            <i class="bi bi-pencil-square"></i> Gestisci template
                        </a>
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
<script>
(() => {
    const container = document.getElementById('group-selectors');
    if (!container) return;
    const form = container.closest('form');
    const options = <?= \App\Core\Security\OutputEncoder::json(array_map(static function (array $group): array {
        return [
            'id' => (string)($group['id_gruppo'] ?? ''),
            'name' => (string)($group['nome_gruppo'] ?? ($group['id_gruppo'] ?? '')),
        ];
    }, $groups)) ?>;

    const addSelector = () => {
        const select = document.createElement('select');
        select.name = 'group_ids[]';
        select.className = 'form-select group-select';
        const empty = document.createElement('option');
        empty.value = '';
        empty.textContent = '— seleziona —';
        select.appendChild(empty);
        options.forEach((option) => {
            const item = document.createElement('option');
            item.value = option.id;
            item.textContent = option.name;
            select.appendChild(item);
        });
        container.appendChild(select);
    };

    const syncSelectors = () => {
        const selects = [...container.querySelectorAll('select.group-select')];
        // Mantieni una sola lista vuota finale e rimuovi eventuali buchi lasciati
        // dalla deselezione di un gruppo intermedio.
        selects.forEach((select, index) => {
            if (select.value === '' && index < selects.length - 1) select.remove();
        });
        let current = [...container.querySelectorAll('select.group-select')];
        if (current.length === 0 || current[current.length - 1].value !== '') addSelector();
        current = [...container.querySelectorAll('select.group-select')];
        const selected = new Set(current.map((select) => select.value).filter(Boolean));
        current.forEach((select) => {
            [...select.options].forEach((option) => {
                option.disabled = option.value !== '' && selected.has(option.value) && option.value !== select.value;
            });
        });
    };

    container.addEventListener('change', syncSelectors);
    if (form) {
        form.addEventListener('submit', (event) => {
            const selected = [...container.querySelectorAll('select.group-select')]
                .map((select) => select.value).filter(Boolean);
            if (selected.length === 0) {
                event.preventDefault();
                alert('Seleziona almeno un gruppo didattico.');
            }
        });
    }
    syncSelectors();
})();
</script>
</body>
</html>
