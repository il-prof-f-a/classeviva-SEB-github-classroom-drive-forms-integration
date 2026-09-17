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
use App\Core\GitHubAssignmentTeamService;
use App\Core\GitHubAssignmentNameService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Core\ClasseVivaCapability;
use App\Core\ClasseVivaTokenGuard;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Core\Security\PublicError;
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
$teamService = new GitHubAssignmentTeamService();
$nameService = new GitHubAssignmentNameService();

$orgs = [];
if ($ghAuthed) {
    try { $orgs = $github->listOrganizations(); } catch (Throwable $e) {}
}
$formData = $_SESSION['github_assignment_form'] ?? [];

// Valori iniziali ergonomici: quando non esiste una configurazione precedente
// preseleziona la prima voce effettivamente disponibile in ogni catalogo.
$availableGroupIds = array_values(array_filter(array_map(
    static fn(array $group): string => (string)($group['id_gruppo'] ?? ''),
    $groups
), static fn(string $id): bool => $id !== ''));
$selectedGroupIds = normalize_group_selection($formData['group_ids'] ?? ($formData['group_id'] ?? []));
$selectedGroupIds = array_values(array_filter(
    $selectedGroupIds,
    static fn(string $id): bool => in_array($id, $availableGroupIds, true)
));
if ($selectedGroupIds === [] && $availableGroupIds !== []) {
    $selectedGroupIds = [$availableGroupIds[0]];
}

$selectedTemplateId = trim((string)($formData['template_id'] ?? ''));
$availableTemplateIds = array_values(array_filter(array_map(
    static fn(array $template): string => (string)($template['id_template'] ?? ''),
    $templates
), static fn(string $id): bool => $id !== ''));
if (!in_array($selectedTemplateId, $availableTemplateIds, true)) {
    $selectedTemplateId = $availableTemplateIds[0] ?? '';
}

$selectedOrg = trim((string)($formData['org'] ?? ''));
$availableOrgLogins = array_values(array_filter(array_map(
    static fn(array $org): string => trim((string)($org['login'] ?? '')),
    $orgs
), static fn(string $login): bool => $login !== ''));
if (!in_array($selectedOrg, $availableOrgLogins, true)) {
    $selectedOrg = $availableOrgLogins[0] ?? '';
}

$error = null;
$students = [];
$step = 'form';

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
    $action = (string)($_POST['action'] ?? '');
    $isJsonAction = $action === 'load_roster';
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);

        if ($action === 'prepare' || $action === 'load_roster') {
            $groupIds = normalize_group_selection($_POST['group_ids'] ?? ($_POST['group_id'] ?? []));
            $templateId = trim((string)($_POST['template_id'] ?? ''));
            $org = trim((string)($_POST['org'] ?? ''));
            $name = trim((string)($_POST['name'] ?? ''));
            $visibility = strtolower(trim((string)($_POST['visibility'] ?? 'private')));
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
            $unknownPlaceholders = $nameService->unknownPlaceholders($name);
            if ($unknownPlaceholders !== []) {
                throw new Exception('Placeholder non supportati nel nome assignment: ' . implode(', ', $unknownPlaceholders) . '.');
            }
            if (!in_array($visibility, ['private', 'public'], true)) {
                $visibility = 'private';
            }

            $_SESSION['github_assignment_form'] = [
                'group_ids' => $groupIds,
                // Compatibilità con pagine e test che leggono ancora il primo gruppo.
                'group_id' => $groupIds[0],
                'template_id' => $templateId,
                'org' => $org,
                'name' => $name,
                'name_template' => $name,
                'visibility' => $visibility,
                'tipo_test' => trim((string)($_POST['tipo_test'] ?? 'altro')),
                'deadline' => trim((string)($_POST['deadline'] ?? '')),
                'note' => trim((string)($_POST['note'] ?? '')),
                'assignment_mode' => 'single',
            ];
            $formData = $_SESSION['github_assignment_form'];
            $students = resolve_students_for_groups($config, $db, $userId, $groupIds, $emailTemplate, $emailDomain);
            if ($action === 'load_roster') {
                $firstGroup = $teamService->defaultGroups($students)[0] ?? ['name' => 'Gruppo 1'];
                header('Content-Type: application/json; charset=utf-8');
                echo json_encode([
                    'ok' => true,
                    'students' => array_values($students),
                    'firstStudent' => trim((string)($students[0]['nome'] ?? $students[0]['name'] ?? '')),
                    'firstTeam' => (string)($firstGroup['name'] ?? 'Gruppo 1'),
                    'firstGroup' => $gname,
                    'template' => $tname,
                    'date' => date('Y-m-d'),
                    'org' => $org,
                    'visibility' => $nameService->effectiveVisibility($visibility, $nameService->containsStudentPlaceholder($name)),
                    'privacy_visibility_forced' => $nameService->containsStudentPlaceholder($name),
                    'team_groups' => $teamService->defaultGroups($students),
                ], JSON_THROW_ON_ERROR);
                exit;
            }
            $step = 'confirm';
        }

        if ($action === 'create') {
            if (empty($formData)) throw new Exception('Dati mancanti. Ricomincia dalla selezione.');
            if (!$ghAuthed) throw new Exception('Autorizza GitHub prima di creare gli assignment.');
            if ($requiresClasseVivaForGroups) {
                ClasseVivaTokenGuard::requireToken($config);
            }

            $org = trim((string)($_POST['org'] ?? $formData['org'] ?? ''));
            $namePosted = trim((string)($_POST['name'] ?? ''));
            $name = $namePosted !== '' ? $namePosted : (string)($formData['name_template'] ?? $formData['name'] ?? '');
            $groupIds = normalize_group_selection($formData['group_ids'] ?? ($formData['group_id'] ?? []));
            assert_groups_belong_to_uda($groupIds, $groups);
            $groupId = $groupIds[0];
            $templateId = trim((string)($_POST['template_id'] ?? $formData['template_id'] ?? ''));
            if ($templateId === '' || $org === '') {
                throw new Exception('Template e org sono obbligatori.');
            }
            $formData['name'] = $name;
            $formData['name_template'] = $name;
            $formData['org'] = $org;
            $formData['template_id'] = $templateId;
            $formData['visibility'] = strtolower(trim((string)($_POST['visibility'] ?? $formData['visibility'] ?? 'private')));
            $formData['tipo_test'] = trim((string)($_POST['tipo_test'] ?? $formData['tipo_test'] ?? 'altro'));
            $formData['deadline'] = trim((string)($_POST['deadline'] ?? $formData['deadline'] ?? ''));
            $formData['note'] = trim((string)($_POST['note'] ?? $formData['note'] ?? ''));
            $modes = (array)($_POST['modes'] ?? []);
            $modeEmail = in_array('email', $modes, true);
            $modeClassroom = in_array('classroom', $modes, true);
            $assignmentMode = $teamService->normalizeMode(
                $_POST['assignment_mode'] ?? ($formData['assignment_mode'] ?? 'single')
            );
            $incompatiblePlaceholders = $nameService->incompatiblePlaceholders($name, $assignmentMode);
            if ($incompatiblePlaceholders !== []) {
                throw new Exception(
                    'I placeholder ' . implode(', ', $incompatiblePlaceholders)
                    . ' non sono disponibili per una assegnazione ' . ($assignmentMode === 'group' ? 'di gruppo' : 'singola') . '.'
                );
            }
            $students = resolve_students_for_groups($config, $db, $userId, $groupIds, $emailTemplate, $emailDomain);
            if (empty($students)) throw new Exception('Nessuno studente risolto nel gruppo.');
            $teamGroups = [];
            if ($assignmentMode === 'group') {
                $rawTeamGroups = json_decode((string)($_POST['team_groups_json'] ?? ''), true);
                if (!is_array($rawTeamGroups)) {
                    throw new Exception('Composizione dei gruppi di lavoro mancante o non valida.');
                }
                $teamGroups = $teamService->normalizeGroups($rawTeamGroups, $students);
            }

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

            $groupName = '';
            foreach ($groups as $group) {
                if ((string)($group['id_gruppo'] ?? '') === $groupId) {
                    $groupName = trim((string)($group['nome_gruppo'] ?? $groupId));
                    break;
                }
            }
            $templateName = trim((string)($template['nome'] ?? $templateId));
            $nameUnknownPlaceholders = $nameService->unknownPlaceholders($name);
            if ($nameUnknownPlaceholders !== []) {
                throw new Exception('Placeholder non supportati nel nome assignment: ' . implode(', ', $nameUnknownPlaceholders) . '.');
            }
            $privacyVisibilityForced = $nameService->containsStudentPlaceholder($name);
            $repositoryVisibility = $nameService->effectiveVisibility(
                (string)($formData['visibility'] ?? 'private'),
                $privacyVisibilityForced
            );
            $private = $repositoryVisibility === 'private';
            $creationDate = date('Y-m-d');
            $baseNameContext = [
                'gruppo' => $groupName,
                'template' => $templateName,
                'data' => $creationDate,
                'anno' => substr($creationDate, 0, 4),
                'org' => $org,
            ];
            // Nome condiviso per link, test UDA, ricerca docente e Classroom:
            // il pattern completo resta invece disponibile per le repository.
            $sharedName = $nameService->expandSharedName($name, $baseNameContext);

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

            $slug = GitHubAssignmentService::assignmentSlug($sharedName);
            $prefix = GitHubAssignmentService::repoPrefix($sharedName);
            $idTest = 'TEST_' . uniqid();
            $genericLink = app_url('public/accept_assignment.php') . '?assignment=' . urlencode($slug);

            $usedCodes = [];
            $links = [];
            $createdRepos = [];
            $repoCreationErrors = [];
            $studentsById = [];
            foreach ($students as $student) {
                $studentId = trim((string)($student['id_studente'] ?? ''));
                if ($studentId !== '') {
                    $studentsById[$studentId] = $student;
                }
            }
            if ($assignmentMode === 'group') {
                foreach ($teamGroups as $teamIndex => $team) {
                    $firstStudentName = '';
                    foreach (($team['student_ids'] ?? []) as $teamStudentId) {
                        $candidateStudent = $studentsById[(string)$teamStudentId] ?? null;
                        if (is_array($candidateStudent)) {
                            $firstStudentName = trim((string)($candidateStudent['nome'] ?? ''));
                            if ($firstStudentName !== '') break;
                        }
                    }
                    $expandedName = $nameService->expand($name, array_merge($baseNameContext, [
                        'team' => trim((string)($team['name'] ?? ('Gruppo ' . ($teamIndex + 1)))),
                        'studente' => $firstStudentName,
                    ]));
                    if (trim($expandedName) === '') {
                        $repoCreationErrors[] = 'Gruppo ' . ($teamIndex + 1) . ': il pattern del nome repository produce un nome vuoto.';
                        break;
                    }
                    $repoName = GitHubAssignmentService::teamRepoName($expandedName, $teamIndex + 1, $usedCodes);
                    try {
                        $created = $github->createRepositoryFromTemplate($tOwner, $tRepo, $repoName, $org, $expandedName, $private);
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
                    if ($repoCreationErrors !== []) {
                        break;
                    }
                    foreach ($team['student_ids'] as $studentId) {
                        $student = $studentsById[$studentId] ?? null;
                        if (!is_array($student)) {
                            throw new Exception('Studente non presente nel roster durante la creazione del gruppo.');
                        }
                        $links[] = [
                            'student' => $student,
                            'repo_url' => $repoUrl,
                            'code' => GitHubAssignmentService::generateAcceptanceCode(),
                        ];
                    }
                }
            } else {
                foreach ($students as $s) {
                    $expandedName = $nameService->expand($name, array_merge($baseNameContext, [
                        'team' => '',
                        'studente' => trim((string)($s['nome'] ?? '')),
                    ]));
                    if (trim($expandedName) === '') {
                        $repoCreationErrors[] = 'Il pattern del nome repository produce un nome vuoto per uno studente.';
                        break;
                    }
                    $repoName = GitHubAssignmentService::repoName($expandedName, $usedCodes);
                    $acceptanceCode = GitHubAssignmentService::generateAcceptanceCode();
                    try {
                        $created = $github->createRepositoryFromTemplate($tOwner, $tRepo, $repoName, $org, $expandedName, $private);
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
                'nome' => $sharedName,
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
                    'name_template' => $name,
                    'name_context' => $sharedName,
                    'repository_visibility' => $repositoryVisibility,
                    'privacy_visibility_forced' => $privacyVisibilityForced,
                    'template_id' => $templateId,
                    'assignment_mode' => $assignmentMode,
                    'team_groups' => $teamGroups,
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
                    if ($nm->sendHtmlEmail($email, 'Invito assignment: ' . $sharedName, $body)) { $emailSent++; } else { $emailFailed++; }
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
                                'title' => $sharedName,
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
        if ($isJsonAction) {
            http_response_code(422);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => PublicError::message($e, 'github assignment roster')], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = $e->getMessage();
    }
}
if ($step === 'confirm') {
    $selectedGroupIds = normalize_group_selection($formData['group_ids'] ?? ($formData['group_id'] ?? []));
}
$confirmNameTemplate = (string)($formData['name_template'] ?? $formData['name'] ?? '');
$confirmStudentName = $nameService->containsStudentPlaceholder($confirmNameTemplate);
$confirmVisibility = $nameService->effectiveVisibility((string)($formData['visibility'] ?? 'private'), $confirmStudentName);
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
        <div class="card-header bg-white fw-semibold"><?= $step === 'confirm' ? 'Conferma e modalità invito' : 'Configurazione assignment e roster' ?></div>
        <div class="card-body">
            <?php if ($step === 'confirm'): ?>
                <form method="post" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="create">
                    <input type="hidden" name="assignment_mode" id="assignment-mode" value="single">
                    <input type="hidden" name="team_groups_json" id="team-groups-json" value="">
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
                        <div class="alert <?= $confirmStudentName ? 'alert-warning' : 'alert-secondary' ?> mb-0">
                            <i class="bi bi-tag"></i>
                            <strong>Pattern nome repository:</strong> <code><?= h($confirmNameTemplate) ?></code>
                            <span class="ms-2"><strong>Visibilità effettiva:</strong> <?= $confirmVisibility === 'private' ? 'Privata' : 'Pubblica' ?></span>
                            <?php if ($confirmStudentName): ?>
                                <div class="small mt-1"><i class="bi bi-shield-lock"></i> La presenza di <code>{studente}</code> obbliga la visibilità privata.</div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="col-12">
                        <label for="assignment-mode-select" class="form-label fw-semibold">Assegnazione singola/in team</label>
                        <select class="form-select" id="assignment-mode-select">
                            <option value="single" selected>Assegnazione singola</option>
                            <option value="group">Assegnazione in team</option>
                        </select>
                        <div class="form-text">In modalità gruppo ogni gruppo riceverà una repository condivisa.</div>
                    </div>
                    <div class="col-12 d-none" id="team-groups-panel">
                        <div class="card border-primary-subtle">
                            <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
                                <strong><i class="bi bi-people-fill"></i> Gruppi di lavoro</strong>
                                <button type="button" class="btn btn-sm btn-primary" id="add-team-group"><i class="bi bi-plus-circle"></i> Aggiungi gruppo</button>
                            </div>
                            <div class="card-body">
                                <div id="team-groups" class="d-flex flex-wrap gap-2"></div>
                                <div class="form-text mt-2">Seleziona un gruppo e poi clicca sul nome di uno studente per assegnarlo. Ogni studente deve rimanere in un gruppo.</div>
                            </div>
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
                                        <td><span class="team-student-name" data-student-id="<?= h((string)($s['id_studente'] ?? '')) ?>"><?= h((string)($s['nome'] ?? $s['id_studente'])) ?></span></td>
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
                <form method="post" id="assignment-config-form" class="row g-3">
                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                    <input type="hidden" name="action" value="load_roster">
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
                        <select name="template_id" id="template-id" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($templates as $t): ?>
                                <option value="<?= h((string)$t['id_template']) ?>"<?= $selectedTemplateId === (string)$t['id_template'] ? ' selected' : '' ?>><?= h((string)($t['nome'] ?? $t['id_template'])) ?><?= ($t['categoria'] ?? '') !== '' ? ' — ' . h((string)$t['categoria']) : '' ?></option>
                            <?php endforeach; ?>
                        </select>
                        <a href="github_repo_templates.php" target="_blank" class="btn btn-sm btn-outline-secondary mt-1">
                            <i class="bi bi-pencil-square"></i> Gestisci template
                        </a>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Org GitHub</label>
                        <select name="org" id="github-org" class="form-select" required>
                            <option value="">— seleziona —</option>
                            <?php foreach ($orgs as $o): ?>
                                <option value="<?= h((string)($o['login'] ?? '')) ?>"<?= $selectedOrg === (string)($o['login'] ?? '') ? ' selected' : '' ?>><?= h((string)($o['login'] ?? '')) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="assignment-name" class="form-label">Nome assignment / Nome repository (auto {gruppo}-{template})</label>
                        <input type="text" name="name" id="assignment-name" class="form-control" placeholder="{gruppo}-{template}" value="<?= h((string)($formData['name_template'] ?? $formData['name'] ?? '')) ?>">
                        <div class="mt-2 d-flex flex-wrap gap-1 align-items-center">
                            <span class="small text-muted me-1">Inserisci placeholder:</span>
                            <?php foreach ([
                                '{gruppo}' => ['gruppo didattico', 'common'],
                                '{template}' => ['template scelto', 'common'],
                                '{studente}' => ['primo studente del roster', 'single'],
                                '{team}' => ['primo team', 'group'],
                                '{data}' => ['data di creazione', 'common'],
                                '{anno}' => ['anno di creazione', 'common'],
                                '{org}' => ['organizzazione GitHub', 'common'],
                            ] as $token => [$description, $placeholderMode]): ?>
                                <button type="button" class="btn btn-sm btn-outline-secondary placeholder-token" data-placeholder="<?= h($token) ?>" data-placeholder-mode="<?= h($placeholderMode) ?>" title="<?= h($description) ?>"><?= h($token) ?></button>
                            <?php endforeach; ?>
                        </div>
                        <div class="form-text" id="name-token-legend">
                            <strong>Legenda:</strong> {gruppo} = <span id="first-group-example">{gruppo}</span>;
                            {template} = <span id="template-example">{template}</span>;
                            {studente} = <span id="first-student-example">{studente}</span>;
                            {team} = <span id="first-team-example">{team}</span>;
                            {data}/{anno} = data/anno di creazione; {org} = <span id="org-example">{org}</span>.
                        </div>
                        <div id="name-preview-row" class="alert alert-light border py-2 mt-2 mb-0">
                            <strong>Esempio nome repository:</strong> <code id="name-preview">{gruppo}-{template}</code>
                        </div>
                        <div id="name-placeholder-error" class="alert alert-danger py-2 mt-2 mb-0 d-none" role="alert"></div>
                        <div id="student-name-privacy-warning" class="alert alert-warning py-2 mt-2 mb-0 d-none">
                            <i class="bi bi-shield-lock"></i>
                            Il placeholder <code>{studente}</code> contiene dati personali: la visibilità verrà vincolata a <strong>Privata</strong>.
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label for="repository-visibility" class="form-label">Visibilità repository</label>
                        <select name="visibility" id="repository-visibility" class="form-select" required>
                            <?php $selectedVisibility = strtolower((string)($formData['visibility'] ?? 'private')); ?>
                            <option value="private"<?= $selectedVisibility !== 'public' ? ' selected' : '' ?>>Privata (consigliata)</option>
                            <option value="public"<?= $selectedVisibility === 'public' ? ' selected' : '' ?>>Pubblica</option>
                        </select>
                        <div class="form-text">Le repository pubbliche sono visibili a chiunque. Se usi {studente}, la scelta pubblica viene disabilitata e il server forza Privata.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Tipo</label>
                        <select name="tipo_test" id="tipo-test" class="form-select">
                            <option value="prerequisiti">Prerequisiti</option>
                            <option value="intermedio">Intermedio</option>
                            <option value="finale">Finale</option>
                            <option value="altro" selected>Altro</option>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Deadline</label>
                        <input type="datetime-local" name="deadline" id="deadline" class="form-control">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Note</label>
                        <input type="text" name="note" id="note" class="form-control">
                    </div>
                    <div class="col-12">
                        <button type="submit" class="btn btn-outline-primary" id="load-roster-button"><i class="bi bi-people"></i> Carica studenti e continua</button>
                    </div>
                </form>
                <section id="roster-panel" class="mt-4 d-none" aria-live="polite">
                    <div id="roster-load-status" class="alert alert-info py-2">Configura l&#39;assignment e carica il roster.</div>
                    <div class="mb-3">
                        <label for="assignment-mode-select" class="form-label fw-semibold">Assegnazione singola/in team</label>
                        <select class="form-select" id="assignment-mode-select">
                            <option value="single" selected>Assegnazione singola</option>
                            <option value="group">Assegnazione in team</option>
                        </select>
                        <div class="form-text">In modalità gruppo ogni team riceverà una repository condivisa. I placeholder incompatibili vengono disabilitati.</div>
                    </div>
                    <div class="d-none" id="team-groups-panel">
                        <div class="card border-primary-subtle mb-3">
                            <div class="card-header bg-primary-subtle d-flex justify-content-between align-items-center">
                                <strong><i class="bi bi-people-fill"></i> Team di lavoro</strong>
                                <button type="button" class="btn btn-sm btn-primary" id="add-team-group"><i class="bi bi-plus-circle"></i> Aggiungi team</button>
                            </div>
                            <div class="card-body">
                                <div id="team-groups" class="d-flex flex-wrap gap-2"></div>
                                <div class="form-text mt-2">Seleziona un team e poi clicca sul nome di uno studente per assegnarlo. Ogni studente deve rimanere in un team.</div>
                            </div>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-striped align-middle">
                            <thead><tr><th>#</th><th>Studente</th><th>Email</th></tr></thead>
                            <tbody id="roster-students"><tr><td colspan="3" class="text-muted">Nessun roster caricato.</td></tr></tbody>
                        </table>
                    </div>
                    <form method="post" id="assignment-create-form" class="row g-3 mt-2">
                        <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                        <input type="hidden" name="action" value="create">
                        <input type="hidden" name="assignment_mode" id="assignment-mode" value="single">
                        <input type="hidden" name="team_groups_json" id="team-groups-json" value="">
                        <input type="hidden" name="name" id="create-name" value="">
                        <input type="hidden" name="template_id" id="create-template-id" value="">
                        <input type="hidden" name="org" id="create-org" value="">
                        <input type="hidden" name="visibility" id="create-visibility" value="private">
                        <input type="hidden" name="tipo_test" id="create-tipo-test" value="altro">
                        <input type="hidden" name="deadline" id="create-deadline" value="">
                        <input type="hidden" name="note" id="create-note" value="">
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
                            <button type="submit" class="btn btn-primary d-none" id="create-assignment-button" disabled><i class="bi bi-git"></i> Crea assignment</button>
                            <a href="github_assignment_create.php?id_uda=<?= h($idUda) ?>" class="btn btn-outline-secondary">Annulla</a>
                        </div>
                    </form>
                </section>
            <?php endif; ?>
            <div class="modal fade" id="team-rename-modal" tabindex="-1" aria-labelledby="team-rename-title" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title" id="team-rename-title">Rinomina gruppo di lavoro</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                        </div>
                        <div class="modal-body">
                            <label for="team-rename-input" class="form-label">Nuovo nome</label>
                            <input type="text" class="form-control" id="team-rename-input" maxlength="120" autocomplete="off">
                            <div id="team-rename-error" class="invalid-feedback">Inserisci un nome non vuoto.</div>
                            <div class="form-text">Il nome viene usato anche per comporre il nome della repository quando è presente {team}.</div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="button" class="btn btn-primary" id="team-rename-save">Salva nome</button>
                        </div>
                    </div>
                </div>
            </div>
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

// Gestione client-side dei gruppi di lavoro dell'assignment. Il payload viene
// sempre ricontrollato dal server prima di creare le repository.
(() => {
    const modeSelect = document.getElementById('assignment-mode-select');
    const modeInput = document.getElementById('assignment-mode');
    const payloadInput = document.getElementById('team-groups-json');
    const panel = document.getElementById('team-groups-panel');
    const groupsContainer = document.getElementById('team-groups');
    const addButton = document.getElementById('add-team-group');
    if (!modeSelect || !modeInput || !payloadInput || !panel || !groupsContainer || !addButton) return;

    const serverStudents = <?= \App\Core\Security\OutputEncoder::json(array_values(array_map(static function (array $student): array {
        return ['id' => (string)($student['id_studente'] ?? '')];
    }, $students))) ?>;
    const serverGroups = <?= \App\Core\Security\OutputEncoder::json($teamService->defaultGroups($students)) ?>;
    let students = serverStudents;
    let initialGroups = serverGroups;
    const palette = ['#dbeafe', '#dcfce7', '#fef3c7', '#fce7f3', '#ede9fe', '#cffafe', '#ffedd5', '#e0e7ff'];
    let groups = initialGroups.map((group) => ({
        id: String(group.id),
        name: String(group.name),
        color: String(group.color),
        student_ids: [...group.student_ids],
    }));
    let activeGroupId = groups[0]?.id || '';
    let nextGroupNumber = groups.length + 1;
    const renameModalElement = document.getElementById('team-rename-modal');
    const renameInput = document.getElementById('team-rename-input');
    const renameSave = document.getElementById('team-rename-save');
    const renameError = document.getElementById('team-rename-error');
    const renameModal = renameModalElement && window.bootstrap
        ? window.bootstrap.Modal.getOrCreateInstance(renameModalElement)
        : null;
    let renameGroupId = '';
    let renameTimer = null;

    const colorForNewGroup = () => {
        const used = new Set(groups.map((group) => group.color.toLowerCase()));
        const available = palette.filter((color) => !used.has(color));
        return available[Math.floor(Math.random() * Math.max(1, available.length))] || palette[groups.length % palette.length];
    };

    const groupForStudent = (studentId) => groups.find((group) => group.student_ids.includes(studentId));

    const syncPayload = () => {
        payloadInput.value = JSON.stringify(groups);
    };

    const clearRenameTimer = () => {
        if (renameTimer !== null) {
            window.clearTimeout(renameTimer);
            renameTimer = null;
        }
    };

    const openTeamRename = (group) => {
        if (!renameModal || !renameInput) return;
        renameGroupId = group.id;
        renameInput.value = group.name;
        renameInput.classList.remove('is-invalid');
        renameError?.classList.remove('d-block');
        renameModal.show();
        window.setTimeout(() => {
            renameInput.focus();
            renameInput.select();
        }, 150);
    };

    const renderStudentColors = () => {
        document.querySelectorAll('.team-student-name').forEach((node) => {
            const group = modeSelect.value === 'group' ? groupForStudent(node.dataset.studentId || '') : null;
            node.style.backgroundColor = group ? group.color : '';
            node.style.borderRadius = group ? '0.25rem' : '';
            node.style.padding = group ? '0.1rem 0.35rem' : '';
            node.style.display = group ? 'inline-block' : '';
        });
    };

    const renderGroups = () => {
        const isGroupMode = modeSelect.value === 'group';
        modeInput.value = isGroupMode ? 'group' : 'single';
        panel.classList.toggle('d-none', !isGroupMode);
        groupsContainer.replaceChildren();
        if (!isGroupMode) {
            renderStudentColors();
            return;
        }
        groups.forEach((group) => {
            const card = document.createElement('div');
            card.className = 'border rounded p-2 d-flex align-items-center gap-2';
            card.style.borderLeft = '0.5rem solid ' + group.color;
            card.style.backgroundColor = group.color;
            card.style.cursor = 'pointer';
            card.style.outline = group.id === activeGroupId ? '2px solid #0d6efd' : '';
            card.title = 'Seleziona questo gruppo per assegnare studenti';
            card.addEventListener('click', () => {
                activeGroupId = group.id;
                renderGroups();
            });

            const label = document.createElement('span');
            label.className = 'fw-semibold';
            label.textContent = group.name + ' (' + group.student_ids.length + ')';
            label.title = 'Doppio clic o pressione prolungata per rinominare';
            label.style.userSelect = 'none';
            label.addEventListener('dblclick', (event) => {
                event.preventDefault();
                event.stopPropagation();
                clearRenameTimer();
                openTeamRename(group);
            });
            label.addEventListener('pointerdown', (event) => {
                if (event.pointerType === 'mouse' && event.button !== 0) return;
                clearRenameTimer();
                renameTimer = window.setTimeout(() => {
                    renameTimer = null;
                    openTeamRename(group);
                }, 600);
            });
            ['pointerup', 'pointercancel', 'pointerleave', 'pointermove'].forEach((eventName) => {
                label.addEventListener(eventName, clearRenameTimer);
            });
            card.appendChild(label);

            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'btn btn-sm btn-outline-danger';
            remove.innerHTML = '<i class="bi bi-trash"></i>';
            remove.title = group.student_ids.length > 0 ? 'Sposta prima gli studenti per cancellare il gruppo' : 'Cancella gruppo vuoto';
            remove.disabled = group.student_ids.length > 0 || groups.length === 1;
            remove.addEventListener('click', (event) => {
                event.stopPropagation();
                if (remove.disabled) return;
                groups = groups.filter((candidate) => candidate.id !== group.id);
                activeGroupId = groups[0]?.id || '';
                if (window.githubRosterContext) window.githubRosterContext.team = groups[0]?.name || '{team}';
                syncPayload();
                renderGroups();
            });
            card.appendChild(remove);
            groupsContainer.appendChild(card);
        });
        syncPayload();
        renderStudentColors();
    };

    const moveStudentToActiveGroup = (studentId) => {
        if (modeSelect.value !== 'group' || !activeGroupId || !studentId) return;
        const target = groups.find((group) => group.id === activeGroupId);
        if (!target) return;
        groups.forEach((group) => {
            group.student_ids = group.student_ids.filter((id) => id !== studentId);
        });
        target.student_ids.push(studentId);
        syncPayload();
        renderGroups();
    };

    const rosterContainer = document.getElementById('roster-students');
    rosterContainer?.addEventListener('click', (event) => {
        const node = event.target.closest('.team-student-name');
        if (!node) return;
        moveStudentToActiveGroup(node.dataset.studentId || '');
    });
    window.initializeGithubTeamGroups = (rosterStudents, rosterGroups) => {
        students = Array.isArray(rosterStudents) ? rosterStudents : [];
        initialGroups = Array.isArray(rosterGroups) ? rosterGroups : [];
        groups = initialGroups.map((group) => ({
            id: String(group.id || ''),
            name: String(group.name || 'Gruppo'),
            color: String(group.color || '#dbeafe'),
            student_ids: Array.isArray(group.student_ids) ? group.student_ids.map(String) : [],
        }));
        activeGroupId = groups[0]?.id || '';
        nextGroupNumber = groups.length + 1;
        renderGroups();
    };
    modeSelect.addEventListener('change', renderGroups);
    addButton.addEventListener('click', () => {
        const id = 'team-' + nextGroupNumber;
        groups.push({
            id,
            name: 'Gruppo ' + nextGroupNumber,
            color: colorForNewGroup(),
            student_ids: [],
        });
        nextGroupNumber += 1;
        activeGroupId = id;
        renderGroups();
    });
    renameSave?.addEventListener('click', () => {
        const target = groups.find((group) => group.id === renameGroupId);
        const newName = (renameInput?.value || '').trim();
        if (!target || !newName) {
            renameInput?.classList.add('is-invalid');
            renameError?.classList.add('d-block');
            return;
        }
        target.name = newName.slice(0, 120);
        if (groups[0]?.id === target.id && window.githubRosterContext) {
            window.githubRosterContext.team = target.name;
        }
        syncPayload();
        renderGroups();
        window.githubAssignmentNamePreview?.();
        renameModal?.hide();
    });
    renderGroups();
})();

// Placeholder, anteprima concreta e vincoli tra modalità di assegnazione.
(() => {
    const nameInput = document.getElementById('assignment-name');
    if (!nameInput) return;
    const modeSelect = document.getElementById('assignment-mode-select');
    const visibilitySelect = document.getElementById('repository-visibility');
    const warning = document.getElementById('student-name-privacy-warning');
    const errorBox = document.getElementById('name-placeholder-error');
    const createButton = document.getElementById('create-assignment-button');
    const context = {
        gruppo: '{gruppo}', template: '{template}', studente: '{studente}', team: '{team}',
        data: new Date().toISOString().slice(0, 10), anno: new Date().getFullYear().toString(), org: '{org}',
    };
    window.githubRosterContext = context;

    const mode = () => modeSelect?.value === 'group' ? 'group' : 'single';
    const selectedGroupName = () => {
        const selected = document.querySelector('#group-selectors select.group-select:not(:last-child), #group-selectors select.group-select');
        return selected?.value ? (selected.selectedOptions?.[0]?.textContent?.trim() || '{gruppo}') : '{gruppo}';
    };
    const selectedTemplateName = () => {
        const option = document.getElementById('template-id')?.selectedOptions?.[0];
        return option?.value ? (option.dataset?.templateName || option.textContent?.split(' — ')[0]?.trim() || '{template}') : '{template}';
    };
    const currentContext = () => ({
        ...(window.githubRosterContext || context),
        gruppo: selectedGroupName(),
        template: selectedTemplateName(),
        org: document.getElementById('github-org')?.value?.trim() || '{org}',
    });
    const incompatible = () => {
        const tokens = [];
        const pattern = nameInput.value || '';
        if (mode() === 'group' && /\{studente\}/i.test(pattern)) tokens.push('{studente}');
        if (mode() === 'single' && /\{team\}/i.test(pattern)) tokens.push('{team}');
        return tokens;
    };
    const updatePreview = () => {
        const values = currentContext();
        const pattern = nameInput.value.trim() || '{gruppo}-{template}';
        const preview = pattern.replace(/\{([a-z0-9_]+)\}/gi, (literal, key) =>
            Object.prototype.hasOwnProperty.call(values, key.toLowerCase()) ? values[key.toLowerCase()] : literal
        );
        const previewNode = document.getElementById('name-preview');
        if (previewNode) previewNode.textContent = preview;
        [['first-group-example', values.gruppo], ['template-example', values.template],
            ['first-student-example', values.studente], ['first-team-example', values.team],
            ['org-example', values.org]].forEach(([id, value]) => {
            const node = document.getElementById(id);
            if (node) node.textContent = value;
        });
        return preview;
    };
    const updateValidation = () => {
        const bad = incompatible();
        if (errorBox) {
            errorBox.textContent = bad.length
                ? bad.join(', ') + ' non è disponibile per una assegnazione ' + (mode() === 'group' ? 'di gruppo.' : 'singola.')
                : '';
            errorBox.classList.toggle('d-none', bad.length === 0);
        }
        document.querySelectorAll('.placeholder-token').forEach((button) => {
            const tokenMode = button.dataset.placeholderMode || 'common';
            button.disabled = tokenMode !== 'common' && tokenMode !== mode();
            button.classList.toggle('disabled', button.disabled);
        });
        const hasStudent = /\{studente\}/i.test(nameInput.value || '');
        const publicOption = visibilitySelect?.querySelector('option[value="public"]');
        if (publicOption) publicOption.disabled = hasStudent;
        if (hasStudent && visibilitySelect) visibilitySelect.value = 'private';
        warning?.classList.toggle('d-none', !hasStudent);
        if (createButton && !createButton.classList.contains('d-none')) createButton.disabled = bad.length > 0;
        updatePreview();
        return bad.length === 0;
    };
    window.validateGithubAssignmentName = updateValidation;
    window.githubAssignmentNamePreview = updatePreview;

    document.querySelectorAll('.placeholder-token').forEach((button) => {
        button.addEventListener('click', () => {
            if (button.disabled) return;
            const token = button.dataset.placeholder || '';
            if (!token) return;
            const start = nameInput.selectionStart ?? nameInput.value.length;
            const end = nameInput.selectionEnd ?? start;
            nameInput.value = nameInput.value.slice(0, start) + token + nameInput.value.slice(end);
            nameInput.focus();
            const caret = start + token.length;
            nameInput.setSelectionRange(caret, caret);
            updateValidation();
        });
    });
    nameInput.addEventListener('input', updateValidation);
    modeSelect?.addEventListener('change', updateValidation);
    document.getElementById('template-id')?.addEventListener('change', updateValidation);
    document.getElementById('github-org')?.addEventListener('change', updateValidation);
    document.getElementById('group-selectors')?.addEventListener('change', updateValidation);
    updateValidation();
})();

// Caricamento asincrono del roster: la configurazione resta sulla stessa pagina
// e il form di creazione viene abilitato solo dopo una risposta valida.
(() => {
    const configForm = document.getElementById('assignment-config-form');
    const rosterPanel = document.getElementById('roster-panel');
    const rosterBody = document.getElementById('roster-students');
    const status = document.getElementById('roster-load-status');
    const loadButton = document.getElementById('load-roster-button');
    const createForm = document.getElementById('assignment-create-form');
    const createButton = document.getElementById('create-assignment-button');
    const modeSelect = document.getElementById('assignment-mode-select');
    if (!configForm || !rosterPanel || !rosterBody || !status || !loadButton || !createForm || !createButton) return;

    let rosterLoaded = false;
    const invalidateRoster = () => {
        if (!rosterLoaded) return;
        rosterLoaded = false;
        createButton.classList.add('d-none');
        createButton.disabled = true;
        setStatus('I gruppi didattici sono cambiati: ricarica il roster prima di creare l\'assignment.', 'warning');
    };
    const setStatus = (message, type = 'info') => {
        status.className = 'alert alert-' + type + ' py-2';
        status.textContent = message;
    };
    const syncCreateConfig = () => {
        const fields = [
            ['assignment-name', 'create-name'], ['template-id', 'create-template-id'],
            ['github-org', 'create-org'], ['repository-visibility', 'create-visibility'],
            ['tipo-test', 'create-tipo-test'], ['deadline', 'create-deadline'], ['note', 'create-note'],
        ];
        fields.forEach(([sourceId, targetId]) => {
            const source = document.getElementById(sourceId);
            const target = document.getElementById(targetId);
            if (source && target) target.value = source.value || '';
        });
    };
    const renderRoster = (students) => {
        rosterBody.replaceChildren();
        if (!students.length) {
            const row = document.createElement('tr');
            const cell = document.createElement('td');
            cell.colSpan = 3;
            cell.className = 'text-muted';
            cell.textContent = 'Nessuno studente risolto (verifica le mappature del gruppo).';
            row.appendChild(cell);
            rosterBody.appendChild(row);
            return;
        }
        students.forEach((student, index) => {
            const row = document.createElement('tr');
            const number = document.createElement('td');
            number.textContent = String(index + 1);
            const nameCell = document.createElement('td');
            const name = document.createElement('span');
            name.className = 'team-student-name';
            name.dataset.studentId = String(student.id_studente || student.id || '');
            name.textContent = String(student.nome || student.name || student.id_studente || student.id || 'Studente');
            name.style.cursor = 'pointer';
            nameCell.appendChild(name);
            const emailCell = document.createElement('td');
            const email = document.createElement('code');
            email.textContent = String(student.email || '(non risolta)');
            emailCell.appendChild(email);
            row.append(number, nameCell, emailCell);
            rosterBody.appendChild(row);
        });
    };
    configForm.addEventListener('submit', async (event) => {
        event.preventDefault();
        if (!configForm.reportValidity()) return;
        loadButton.disabled = true;
        loadButton.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status" aria-hidden="true"></span> Caricamento roster...';
        setStatus('Caricamento roster in corso...', 'info');
        try {
            const data = new FormData(configForm);
            data.set('action', 'load_roster');
            const response = await fetch(configForm.getAttribute('action') || window.location.href, {
                method: 'POST', body: data, credentials: 'same-origin',
                headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
            });
            const rawResponse = await response.text();
            let payload;
            try {
                payload = JSON.parse(rawResponse);
            } catch (parseError) {
                if (response.redirected || /^\s*<!doctype\s+html/i.test(rawResponse)) {
                    throw new Error('La sessione docente non è più valida. Ricarica la pagina ed effettua nuovamente l\'accesso.');
                }
                throw new Error('Il server ha restituito una risposta non valida durante il caricamento del roster.');
            }
            if (!response.ok || !payload.ok) throw new Error(payload.error || 'Impossibile caricare il roster.');
            const students = Array.isArray(payload.students) ? payload.students : [];
            renderRoster(students);
            rosterLoaded = students.length > 0;
            rosterPanel.classList.remove('d-none');
            setStatus(rosterLoaded ? 'Roster caricato: ' + students.length + ' studenti.' : 'Roster vuoto: verifica le mappature del gruppo.', rosterLoaded ? 'success' : 'warning');
            window.githubRosterContext = {
                ...(window.githubRosterContext || {}),
                gruppo: payload.firstGroup || '{gruppo}',
                template: payload.template || '{template}',
                studente: payload.firstStudent || '{studente}',
                team: payload.firstTeam || '{team}',
                data: payload.date || (window.githubRosterContext?.data || '{data}'),
                org: payload.org || '{org}',
            };
            window.initializeGithubTeamGroups?.(students, payload.team_groups || []);
            syncCreateConfig();
            createButton.classList.toggle('d-none', !rosterLoaded);
            createButton.disabled = !rosterLoaded || !(window.validateGithubAssignmentName?.() ?? true);
            window.githubAssignmentNamePreview?.();
            rosterPanel.scrollIntoView({behavior: 'smooth', block: 'start'});
        } catch (error) {
            rosterPanel.classList.remove('d-none');
            setStatus(error instanceof Error ? error.message : 'Errore inatteso durante il caricamento del roster.', 'danger');
            createButton.classList.add('d-none');
        } finally {
            loadButton.disabled = false;
            loadButton.innerHTML = '<i class="bi bi-people"></i> Carica studenti e continua';
        }
    });
    createForm.addEventListener('submit', (event) => {
        if (!rosterLoaded || !(window.validateGithubAssignmentName?.() ?? true)) {
            event.preventDefault();
            return;
        }
        syncCreateConfig();
        if (modeSelect?.value === 'single') {
            const payload = document.getElementById('team-groups-json');
            if (payload) payload.value = '';
        }
    });
    document.getElementById('group-selectors')?.addEventListener('change', invalidateRoster);
})();
</script>
</body>
</html>
