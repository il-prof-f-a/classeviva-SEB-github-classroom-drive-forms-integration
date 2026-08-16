<?php

declare(strict_types=1);

define('REQUIRES_CLASSEVIVA', true);
define('SKIP_CV_TOKEN_POPUP', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ClasseVivaTokenGuard;
use App\Core\GoogleTokenProvider;
use App\Core\TeachingGroupCatalogService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupStudentService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Utils\LocalReturnUrl;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$userId = trim((string)($_SESSION['user_id'] ?? ($config['user_id'] ?? '')));
if ($userId === '') {
    header('Location: login.php');
    exit;
}

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$groupRepository = new TeachingGroupRepository($dbAdapter, $userId);
$integrationRepository = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
$groupService = new TeachingGroupService($groupRepository, $integrationRepository);
$catalogService = new TeachingGroupCatalogService($dbAdapter, $userId);
$studentService = new TeachingGroupStudentService($dbAdapter, $userId);

$rawReturnTo = $_GET['return_to'] ?? $_POST['return_to'] ?? null;
$rawReturnTo = is_scalar($rawReturnTo) ? (string)$rawReturnTo : null;
if (($rawReturnTo === null || trim($rawReturnTo) === '') && (string)($_GET['tab'] ?? '') === 'students') {
    $studentReturnGroup = $_GET['id_gruppo'] ?? ($_GET['id'] ?? '');
    if (is_scalar($studentReturnGroup) && trim((string)$studentReturnGroup) !== '') {
        $rawReturnTo = 'teaching_groups.php?tab=students&id=' . urlencode(trim((string)$studentReturnGroup));
    }
}
$returnTo = LocalReturnUrl::sanitize(
    $rawReturnTo,
    'teaching_groups.php'
);

$escape = static fn(mixed $value): string => htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
$flash = $_SESSION['teaching_groups_flash'] ?? null;
unset($_SESSION['teaching_groups_flash']);
$errorMessage = null;
$successMessage = is_array($flash) ? (string)($flash['success'] ?? '') : '';
$studentSyncFlash = null;
if (is_array($flash) && is_array($flash['student_sync'] ?? null)) {
    $syncCount = max(0, (int)($flash['student_sync']['count'] ?? 0));
    $syncNames = [];
    foreach (($flash['student_sync']['display_names'] ?? []) as $displayName) {
        if (!is_scalar($displayName)) {
            continue;
        }
        $displayName = trim((string)$displayName);
        if ($displayName === '') {
            continue;
        }
        $syncNames[] = function_exists('mb_substr') ? mb_substr($displayName, 0, 120) : substr($displayName, 0, 120);
        if (count($syncNames) >= 20) {
            break;
        }
    }
    $studentSyncFlash = ['count' => $syncCount, 'display_names' => $syncNames];
}
$csrfToken = (string)($_SESSION['teaching_groups_csrf'] ?? '');
if ($csrfToken === '') {
    $csrfToken = bin2hex(random_bytes(32));
    $_SESSION['teaching_groups_csrf'] = $csrfToken;
}
$providerErrors = array_fill_keys(['classeviva', 'google_classroom', 'github_classroom'], null);
$postText = static function (string $key, string $default = ''): string {
    $value = $_POST[$key] ?? $default;
    return is_scalar($value) ? trim((string)$value) : $default;
};

$providerLabels = [
    'classeviva' => 'ClasseViva',
    'google_classroom' => 'Google Classroom',
    'github_classroom' => 'GitHub Classroom',
];
$providerAuthLinks = [
    'classeviva' => 'user_integrations.php#classeviva-section',
    'google_classroom' => 'user_integrations.php#google-section',
    'github_classroom' => 'user_integrations.php#github-section',
];
$providerFields = [
    'classeviva' => 'classeviva_context',
    'google_classroom' => 'google_course_id',
    'github_classroom' => 'github_classroom_id',
];
$providerReady = array_fill_keys(array_keys($providerLabels), false);
$providerOptions = array_fill_keys(array_keys($providerLabels), []);
$assertRosterIdentity = static function (string $groupId, string $provider, string $externalId) use ($studentService, $integrationRepository): void {
    $saved = $integrationRepository->findForGroupProvider($groupId, $provider);
    $context = is_array($saved) ? trim((string)($saved['external_context_id'] ?? '')) : '';
    foreach ($studentService->matrix($groupId) as $row) {
        foreach (($row['identities'] ?? []) as $identity) {
            if (($identity['provider'] ?? '') === $provider && ($identity['external_user_id'] ?? '') === $externalId) {
                $identityContext = trim((string)($identity['external_context_id'] ?? ''));
                foreach (($row['memberships'] ?? []) as $membership) {
                    if (($membership['provider_origine'] ?? '') === $provider
                        && ($membership['external_context_id'] ?? '') === $context
                        && ($identityContext === '' || $identityContext === $context)) {
                        return;
                    }
                }
            }
        }
    }
    throw new RuntimeException('Identità non appartenente al roster del contesto collegato.');
};

try {
    $providerReady['classeviva'] = (bool)(ClasseVivaTokenGuard::getTokenState($config)['ready'] ?? false)
    && filter_var($config['classeviva']['token_valid'] ?? false, FILTER_VALIDATE_BOOLEAN);
    if ($providerReady['classeviva']) {
        $cvApi = new ClasseVivaAPI($config);
        $classes = $cvApi->getClassesWithTeacherSubjects();
        if (!is_array($classes)) {
            throw new RuntimeException('Payload ClasseViva non valido.');
        }
        foreach ($classes as $class) {
            if (!is_array($class)) {
                continue;
            }
            $classId = trim((string)($class['id'] ?? $class['classId'] ?? ''));
            $className = trim((string)($class['name'] ?? $class['className'] ?? ''));
            $subjects = $class['subjects'] ?? [];
            if (!is_array($subjects)) {
                continue;
            }
            foreach ($subjects as $subject) {
                if (!is_array($subject)) {
                    continue;
                }
                $subjectId = trim((string)($subject['id'] ?? $subject['subjectId'] ?? ''));
                $subjectName = trim((string)($subject['name'] ?? $subject['subjectName'] ?? $subject['subjectDesc'] ?? ''));
                if ($classId === '' || $subjectId === '') {
                    continue;
                }
                $value = $classId . '::' . $subjectId;
                $providerOptions['classeviva'][] = ['value' => $value, 'context' => $classId, 'subject' => $subjectId, 'label' => $className . ' · ' . $subjectName];
            }
        }
    }
} catch (Throwable $exception) {
    $providerReady['classeviva'] = false;
    $providerErrors['classeviva'] = 'Servizio ClasseViva non disponibile.';
}

try {
    $googleToken = GoogleTokenProvider::getToken($config, $userId);
    $providerReady['google_classroom'] = is_array($googleToken) && trim((string)($googleToken['access_token'] ?? '')) !== '';
    if ($providerReady['google_classroom']) {
        $courses = (new GoogleClassroomAPI($config))->getCourses();
        if (!is_array($courses)) {
            throw new RuntimeException('Payload Google Classroom non valido.');
        }
        foreach ($courses as $course) {
            if (!is_array($course)) {
                continue;
            }
            $courseId = trim((string)($course['id'] ?? ''));
            if ($courseId !== '') {
                $providerOptions['google_classroom'][] = ['value' => $courseId, 'context' => $courseId, 'subject' => '', 'label' => (string)($course['name'] ?? $courseId)];
            }
        }
    }
} catch (Throwable $exception) {
    $providerReady['google_classroom'] = false;
    $providerErrors['google_classroom'] = 'Servizio Google Classroom non disponibile o scope insufficienti.';
}

try {
    $github = new GitHubIntegration($config);
    $providerReady['github_classroom'] = $github->loadTokenFromSession() && $github->isAuthenticated();
    if ($providerReady['github_classroom']) {
        $classrooms = $github->listClassrooms();
        $classrooms = is_array($classrooms) && isset($classrooms['classrooms']) && is_array($classrooms['classrooms'])
            ? $classrooms['classrooms']
            : (is_array($classrooms) ? $classrooms : throw new RuntimeException('Payload GitHub Classroom non valido.'));
        foreach ($classrooms as $classroom) {
            if (!is_array($classroom)) {
                continue;
            }
            $classroomId = trim((string)($classroom['id'] ?? $classroom['classroom_id'] ?? ''));
            if ($classroomId !== '') {
                $providerOptions['github_classroom'][] = ['value' => $classroomId, 'context' => $classroomId, 'subject' => '', 'label' => (string)($classroom['name'] ?? $classroomId)];
            }
        }
    }
} catch (Throwable $exception) {
    $providerReady['github_classroom'] = false;
    $providerErrors['github_classroom'] = 'Servizio GitHub Classroom non disponibile.';
}

$redirectAfterAction = static function (string $message, array $flashData = []) use ($returnTo): never {
    $_SESSION['teaching_groups_flash'] = ['success' => $message] + $flashData;
    $returnPath = (string)(parse_url($returnTo, PHP_URL_PATH) ?? '');
    if ($returnPath === 'uda_create.php') {
        header('Location: uda_create.php?integration_updated=1#2');
        exit;
    }
    $fragment = (string)(parse_url($returnTo, PHP_URL_FRAGMENT) ?? '');
    $withoutFragment = $fragment === '' ? $returnTo : substr($returnTo, 0, -(strlen($fragment) + 1));
    $separator = str_contains($withoutFragment, '?') ? '&' : '?';
    header('Location: ' . $withoutFragment . $separator . 'updated=1' . ($fragment === '' ? '' : '#' . $fragment));
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        $postedCsrf = $_POST['csrf_token'] ?? null;
        if (!is_string($postedCsrf) || !hash_equals($csrfToken, $postedCsrf)) {
            throw new RuntimeException('Richiesta non valida. Ricarica la pagina.');
        }
        if (!is_scalar($_POST['action'])) {
            throw new RuntimeException('Azione non valida.');
        }
        $action = trim((string)$_POST['action']);
        $groupRaw = $_POST['id_gruppo'] ?? '';
        $groupId = is_scalar($groupRaw) ? trim((string)$groupRaw) : '';
        switch ($action) {
            case 'create_group':
                $groupService->createGroup([
                    'nome_gruppo' => $postText('nome_gruppo'),
                    'nome_classe' => $postText('nome_classe'),
                    'nome_materia' => $postText('nome_materia'),
                    'anno_scolastico' => $postText('anno_scolastico'),
                    'descrizione' => $postText('descrizione'),
                ]);
                $redirectAfterAction('Gruppo didattico creato.');
                break;
            case 'update_group':
                $groupService->updateGroup($groupId, [
                    'nome_gruppo' => $postText('nome_gruppo'),
                    'nome_classe' => $postText('nome_classe'),
                    'nome_materia' => $postText('nome_materia'),
                    'anno_scolastico' => $postText('anno_scolastico'),
                    'descrizione' => $postText('descrizione'),
                    'stato' => $postText('stato', 'attivo'),
                ]);
                $redirectAfterAction('Gruppo didattico aggiornato.');
                break;
            case 'link_provider':
                $providerRaw = $_POST['provider'] ?? '';
                $provider = is_scalar($providerRaw) ? trim((string)$providerRaw) : '';
                $selectedRaw = $_POST[$providerFields[$provider] ?? ''] ?? '';
                $selected = is_scalar($selectedRaw) ? trim((string)$selectedRaw) : '';
                $option = null;
                foreach ($providerOptions[$provider] ?? [] as $candidate) {
                    if ((string)$candidate['value'] === $selected) {
                        $option = $candidate;
                        break;
                    }
                }
                if ($option === null) {
                    throw new RuntimeException('Seleziona un contesto presente nel catalogo autorizzato.');
                }
                $groupService->linkProvider($groupId, [
                    'provider' => $provider,
                    'external_context_id' => (string)$option['context'],
                    'external_subject_id' => (string)$option['subject'],
                    'external_name' => (string)$option['label'],
                    'tipo_risorsa' => $provider === 'classeviva' ? 'classe_materia' : ($provider === 'google_classroom' ? 'course' : 'roster'),
                ]);
                $redirectAfterAction('Collegamento provider salvato.');
                break;
            case 'unlink_provider':
                $unlinkProvider = $_POST['provider'] ?? '';
                if (!is_scalar($unlinkProvider)) {
                    throw new RuntimeException('Provider non valido.');
                }
                $groupService->unlinkProvider($groupId, trim((string)$unlinkProvider));
                $redirectAfterAction('Collegamento provider rimosso.');
                break;
            case 'sync_students':
                $providerRaw = $_POST['provider'] ?? '';
                $provider = is_scalar($providerRaw) ? strtolower(trim((string)$providerRaw)) : '';
                if (!array_key_exists($provider, $providerLabels)) {
                    throw new RuntimeException('Provider non valido.');
                }
                $savedLink = $integrationRepository->findForGroupProvider($groupId, $provider);
                if (!is_array($savedLink)) {
                    throw new RuntimeException('Collega prima il provider al gruppo didattico.');
                }
                // Il contesto viene sempre risolto dal collegamento owner-scoped,
                // mai da un campo hidden o da una label inviata dal browser.
                $provider_context = trim((string)($savedLink['external_context_id'] ?? ''));
                if ($provider_context === '') {
                    throw new RuntimeException('Contesto provider non disponibile.');
                }
                $roster = [];
                if ($provider === 'classeviva') {
                    $students = (new ClasseVivaAPI($config))->getStudents($provider_context);
                    if (!is_array($students)) {
                        throw new RuntimeException('Roster ClasseViva non disponibile.');
                    }
                    foreach ($students as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $externalRaw = $entry['id'] ?? $entry['studentId'] ?? '';
                        if (!is_scalar($externalRaw)) {
                            continue;
                        }
                        $externalId = trim((string)$externalRaw);
                        if ($externalId !== '') {
                            $firstName = is_scalar($entry['nome'] ?? null) ? (string)$entry['nome'] : '';
                            $lastName = is_scalar($entry['cognome'] ?? null) ? (string)$entry['cognome'] : '';
                            $roster[] = ['external_user_id' => $externalId, 'display_name' => trim($firstName . ' ' . $lastName)];
                        }
                    }
                } elseif ($provider === 'google_classroom') {
                    $students = (new GoogleClassroomAPI($config))->getCourseStudents($provider_context);
                    if (!is_array($students)) {
                        throw new RuntimeException('Roster Google Classroom non disponibile.');
                    }
                    foreach ($students as $entry) {
                        if (!is_array($entry)) {
                            continue;
                        }
                        $externalRaw = $entry['id'] ?? $entry['userId'] ?? '';
                        if (!is_scalar($externalRaw)) {
                            continue;
                        }
                        $externalId = trim((string)$externalRaw);
                        if ($externalId !== '') {
                            $displayName = is_scalar($entry['name'] ?? null) ? (string)$entry['name'] : '';
                            $roster[] = ['external_user_id' => $externalId, 'display_name' => $displayName];
                        }
                    }
                } else {
                    $githubRoster = [];
                    $githubRosterSeen = [];
                    $githubApi = new GitHubIntegration($config);
                    $assignments = $githubApi->listAssignments($provider_context);
                    if (!is_array($assignments)) {
                        throw new RuntimeException('Roster GitHub Classroom non disponibile.');
                    }
                    $assignments = isset($assignments['assignments']) && is_array($assignments['assignments']) ? $assignments['assignments'] : $assignments;
                    foreach ($assignments as $assignment) {
                        if (!is_array($assignment)) {
                            continue;
                        }
                        $assignmentId = trim((string)($assignment['id'] ?? $assignment['assignment_id'] ?? ''));
                        if ($assignmentId === '') {
                            continue;
                        }
                        $accepted = $githubApi->listAcceptedAssignments($assignmentId);
                        if (!is_array($accepted)) {
                            throw new RuntimeException('Roster GitHub Classroom non disponibile.');
                        }
                        $accepted = isset($accepted['accepted_assignments']) && is_array($accepted['accepted_assignments']) ? $accepted['accepted_assignments'] : $accepted;
                        foreach ($accepted as $entry) {
                            if (!is_array($entry)) {
                                continue;
                            }
                            $externalRaw = $entry['user_id'] ?? $entry['github_username'] ?? $entry['username'] ?? $entry['roster_identifier'] ?? '';
                            if (!is_scalar($externalRaw)) {
                                continue;
                            }
                            $externalId = trim((string)$externalRaw);
                            if ($externalId !== '' && !isset($githubRosterSeen[$externalId])) {
                                $githubRosterSeen[$externalId] = true;
                                $githubRoster[] = ['external_user_id' => $externalId, 'display_name' => ''];
                            }
                        }
                    }
                    $roster = $githubRoster;
                }
                $syncedRows = $studentService->syncRoster($groupId, $provider, $provider_context, $roster);
                $syncedCount = count($syncedRows);
                $displayNames = [];
                foreach ($syncedRows as $syncedRow) {
                    $displayName = trim((string)($syncedRow['display_name'] ?? ''));
                    if ($displayName !== '' && count($displayNames) < 20) {
                        $displayNames[] = function_exists('mb_substr') ? mb_substr($displayName, 0, 120) : substr($displayName, 0, 120);
                    }
                }
                $redirectAfterAction('Roster studenti sincronizzato: ' . $syncedCount . ' righe.', [
                    'student_sync' => ['count' => $syncedCount, 'display_names' => $displayNames],
                ]);
                break;
            case 'save_student_mapping':
                $anchorProviderRaw = $_POST['anchor_provider'] ?? '';
                $anchorExternalRaw = $_POST['anchor_external_user_id'] ?? '';
                if (!is_scalar($anchorProviderRaw) || !is_scalar($anchorExternalRaw)) {
                    throw new RuntimeException('Identità ancora non valida.');
                }
                $anchorProvider = strtolower(trim((string)$anchorProviderRaw));
                $anchorExternal = trim((string)$anchorExternalRaw);
                $matchesRaw = $_POST['matches'] ?? [];
                if (!is_array($matchesRaw) || $anchorExternal === '') {
                    throw new RuntimeException('Seleziona almeno un’identità da collegare.');
                }
                $matches = [];
                foreach ($matchesRaw as $matchProvider => $externalIds) {
                    if (!is_string($matchProvider) || !is_array($externalIds)) {
                        throw new RuntimeException('Identità candidata non valida.');
                    }
                    foreach ($externalIds as $externalId) {
                        if (!is_scalar($externalId)) {
                            throw new RuntimeException('Identità candidata non valida.');
                        }
                        $externalId = trim((string)$externalId);
                        if ($externalId === '') {
                            continue;
                        }
                        $matches[] = ['provider' => strtolower(trim($matchProvider)), 'external_user_id' => $externalId];
                    }
                }
                if ($matches === []) {
                    $redirectAfterAction('Nessuna selezione: mappatura invariata.');
                    break;
                }
                $assertRosterIdentity($groupId, $anchorProvider, $anchorExternal);
                foreach ($matches as $candidate) {
                    $assertRosterIdentity($groupId, (string)$candidate['provider'], (string)$candidate['external_user_id']);
                }
                // linkIdentities verifica che ogni ID tecnico esista già nel
                // roster del gruppo: label/email arbitrari non vengono accettati.
                $studentService->linkIdentities($groupId, [[
                    'provider' => $anchorProvider,
                    'external_user_id' => $anchorExternal,
                    'matches' => $matches,
                ]]);
                $redirectAfterAction('Identità studenti collegate.');
                break;
            case 'unlink_identity':
                $studentIdRaw = $_POST['student_id'] ?? '';
                $identityProviderRaw = $_POST['provider'] ?? '';
                $identityExternalRaw = $_POST['external_user_id'] ?? '';
                if (!is_scalar($studentIdRaw) || !is_scalar($identityProviderRaw) || !is_scalar($identityExternalRaw)) {
                    throw new RuntimeException('Identità non valida.');
                }
                $studentId = trim((string)$studentIdRaw);
                $identityProvider = strtolower(trim((string)$identityProviderRaw));
                $identityExternal = trim((string)$identityExternalRaw);
                $belongsToGroup = false;
                foreach ($studentService->matrix($groupId) as $matrixRow) {
                    if ((string)($matrixRow['id_studente'] ?? '') !== $studentId) {
                        continue;
                    }
                    foreach (($matrixRow['identities'] ?? []) as $identity) {
                        if (($identity['provider'] ?? '') === $identityProvider
                            && ($identity['external_user_id'] ?? '') === $identityExternal) {
                            $belongsToGroup = true;
                            break 2;
                        }
                    }
                }
                if (!$belongsToGroup || !$studentService->unlinkIdentity($studentId, $identityProvider, $identityExternal)) {
                    throw new RuntimeException('Identità non trovata nel gruppo didattico.');
                }
                $redirectAfterAction('Identità studente scollegata.');
                break;
            default:
                throw new RuntimeException('Azione non riconosciuta.');
        }
    } catch (Throwable $exception) {
        $errorMessage = $exception->getMessage();
    }
}

$queryRaw = $_GET['q'] ?? '';
$query = is_scalar($queryRaw) ? trim((string)$queryRaw) : '';
$tabRaw = $_GET['tab'] ?? 'groups';
$tab = is_scalar($tabRaw) && (string)$tabRaw === 'students' ? 'students' : 'groups';
$selectedGroupRaw = $_GET['id_gruppo'] ?? ($_GET['id'] ?? '');
$selectedGroupId = is_scalar($selectedGroupRaw) ? trim((string)$selectedGroupRaw) : '';
$studentGroupOwned = true;

// CatalogService normalizza i collegamenti per il wizard; la pagina mantiene
// anche i campi descrittivi completi del repository per l’editing.
$catalogRows = [];
$catalogError = null;
try {
    foreach ($catalogService->listForWizard(false) as $row) {
        $catalogRows[(string)$row['id_gruppo']] = $row;
    }
} catch (Throwable $exception) {
    $catalogRows = [];
    $catalogError = 'Catalogo gruppi temporaneamente non disponibile.';
}

$groups = [];
foreach ($groupRepository->listAll() as $group) {
    $name = (string)($group['nome_gruppo'] ?? '');
    if ($query !== '' && stripos($name . ' ' . ($group['nome_classe'] ?? '') . ' ' . ($group['nome_materia'] ?? ''), $query) === false) {
        continue;
    }
    $groupId = (string)($group['id_gruppo'] ?? '');
    $catalog = $catalogRows[$groupId] ?? ['providers' => []];
    $providers = is_array($catalog['providers'] ?? null) ? $catalog['providers'] : [];
    $groups[] = ['row' => $group, 'providers' => $providers];
}

$studentRows = [];
$studentMatrixError = null;
$studentFilterRaw = $_GET['student_status'] ?? 'tutti';
$studentFilter = is_scalar($studentFilterRaw) ? strtolower(trim((string)$studentFilterRaw)) : 'tutti';
// filter_students uses only server-derived matrix rows; provider_context is
// always read from the saved owner-scoped integration during sync_students.
if (!in_array($studentFilter, ['tutti', 'mappati', 'non_mappati', 'conflitti'], true)) {
    $studentFilter = 'tutti';
}
$studentReturnTo = 'teaching_groups.php?tab=students';
if ($selectedGroupId !== '') {
    $studentReturnTo .= '&id=' . urlencode($selectedGroupId);
}
if ($studentFilter !== 'tutti') {
    $studentReturnTo .= '&student_status=' . urlencode($studentFilter);
}
$configuredProviders = [];
if ($selectedGroupId !== '' && $groupRepository->findById($selectedGroupId) !== null) {
    $selectedCatalog = $catalogRows[$selectedGroupId] ?? ['providers' => []];
    foreach ($providerLabels as $provider => $label) {
        if (is_array($selectedCatalog['providers'][$provider] ?? null)) {
            $configuredProviders[] = $provider;
        }
    }
    try {
        foreach ($studentService->matrix($selectedGroupId) as $row) {
            $identities = is_array($row['identities'] ?? null) ? $row['identities'] : [];
            $identityCount = count($identities);
            $providerCounts = [];
            foreach ($identities as $identity) {
                $provider = (string)($identity['provider'] ?? '');
                if ($provider !== '') {
                    $providerCounts[$provider] = ($providerCounts[$provider] ?? 0) + 1;
                }
            }
            $hasConflict = count(array_filter($providerCounts, static fn(int $count): bool => $count > 1)) > 0;
            $configuredIdentityCount = count(array_intersect(array_keys($providerCounts), $configuredProviders));
            $isComplete = $configuredProviders === [] || $configuredIdentityCount >= count($configuredProviders);
            $status = $hasConflict ? 'conflitti' : ($isComplete ? 'mappati' : 'non_mappati');
            if ($studentFilter !== 'tutti' && $studentFilter !== $status) {
                continue;
            }
            $row['status'] = $status;
            $row['provider_counts'] = $providerCounts;
            $studentRows[] = $row;
        }
    } catch (Throwable $exception) {
        $studentMatrixError = 'Matrice studenti temporaneamente non disponibile.';
    }
} elseif ($selectedGroupId !== '') {
    // Repository e servizio sono entrambi user-scoped: non rivelare se un ID
    // appartiene a un altro utente.
    $studentMatrixError = 'Gruppo didattico non trovato.';
    $studentGroupOwned = false;
    if ($tab === 'students') {
        http_response_code(404);
    }
}

$pageTitle = 'Gruppi didattici';
$pageSubtitle = 'Gestisci gruppi, collegamenti ai provider e roster studenti';
$headerActions = '<a class="nav-link" href="index.php">Dashboard</a>';
$pageActions = '<a class="btn btn-primary btn-sm" href="uda_create.php?integration_updated=1#2">Apri wizard UDA</a>';
$skipOnboardingBanner = true;
$studentIdentityChoices = [];
$studentStatusLabels = ['mappati' => 'Mappata', 'non_mappati' => 'Riga incompleta', 'conflitti' => 'Conflitto'];
foreach ($studentRows as $studentRow) {
    foreach (($studentRow['identities'] ?? []) as $identity) {
        $provider = (string)($identity['provider'] ?? '');
        $externalId = (string)($identity['external_user_id'] ?? '');
        if ($provider !== '' && $externalId !== '') {
            $studentIdentityChoices[] = ['provider' => $provider, 'external_user_id' => $externalId, 'student_id' => (string)($studentRow['id_studente'] ?? '')];
        }
    }
}
?>
<!doctype html>
<html lang="it">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $escape($pageTitle) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<?php include __DIR__ . '/partials/app_header.php'; ?>
<main class="container py-4">
    <?php if ($successMessage !== ''): ?><div class="alert alert-success"><?= $escape($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="alert alert-danger"><?= $escape($errorMessage) ?></div><?php endif; ?>
    <?php if ($catalogError !== null): ?><div class="alert alert-warning" role="alert"><?= $escape($catalogError) ?></div><?php endif; ?>
    <?php if (is_array($studentSyncFlash)): ?><div class="alert alert-info" role="alert"><strong>Roster sincronizzato</strong>: <?= $escape($studentSyncFlash['count']) ?> righe.
        <?php if ($studentSyncFlash['display_names'] !== []): ?><ul class="mb-0"><?php foreach ($studentSyncFlash['display_names'] as $displayName): ?><li><?= $escape($displayName) ?></li><?php endforeach; ?></ul><?php endif; ?>
    </div><?php endif; ?>

    <ul class="nav nav-tabs mb-3" role="tablist">
        <li class="nav-item" role="presentation"><a role="tab" aria-selected="<?= $tab === 'groups' ? 'true' : 'false' ?>" class="nav-link <?= $tab === 'groups' ? 'active' : '' ?>" href="?tab=groups&amp;return_to=<?= urlencode($returnTo) ?>">Gruppi</a></li>
        <li class="nav-item" role="presentation"><a role="tab" aria-selected="<?= $tab === 'students' ? 'true' : 'false' ?>" class="nav-link <?= $tab === 'students' ? 'active' : '' ?>" href="?tab=students&amp;return_to=<?= urlencode($returnTo) ?>">Studenti</a></li>
    </ul>

    <?php if ($tab === 'students'): ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Mappatura studenti</h2>
                <p class="text-muted">Seleziona un gruppo per visualizzare o sincronizzare il roster dal collegamento provider salvato.</p>
                <?php if ($selectedGroupId === '' || $studentGroupOwned): ?><form method="get" class="row gy-2 gx-2 align-items-end" aria-label="Selezione gruppo studenti">
                    <input type="hidden" name="tab" value="students"><input type="hidden" name="return_to" value="<?= $escape($studentReturnTo) ?>">
                    <div class="col-md-6"><label class="form-label" for="students-group">Gruppo</label>
                        <select id="students-group" name="id_gruppo" class="form-select" required><option value="">Seleziona un gruppo</option>
                            <?php foreach ($groupRepository->listAll() as $group): ?><option value="<?= $escape($group['id_gruppo'] ?? '') ?>" <?= $selectedGroupId === (string)($group['id_gruppo'] ?? '') ? 'selected' : '' ?>><?= $escape($group['nome_gruppo'] ?? '') ?></option><?php endforeach; ?>
                        </select>
                    </div><div class="col-md-auto"><button class="btn btn-outline-primary" type="submit">Visualizza roster</button></div>
                </form><?php endif; ?>
                <?php if ($selectedGroupId !== '' && $studentMatrixError === null): ?>
                    <?php if ($configuredProviders === []): ?><div class="alert alert-warning mt-3 mb-0" role="alert">Collega almeno un provider per sincronizzare gli studenti.</div><?php endif; ?>
                    <?php foreach ($configuredProviders as $provider): $providerLink = $selectedCatalog['providers'][$provider] ?? []; ?>
                        <div class="border rounded p-2 mt-3 d-flex flex-wrap align-items-center justify-content-between gap-2"><span><strong><?= $escape($providerLabels[$provider]) ?></strong><span class="small text-muted ms-2">Contesto: <?= $escape($providerLink['external_name'] ?? $providerLink['external_context_id'] ?? '') ?></span></span>
                            <form method="post" class="m-0"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="sync_students"><input type="hidden" name="id_gruppo" value="<?= $escape($selectedGroupId) ?>"><input type="hidden" name="provider" value="<?= $escape($provider) ?>"><input type="hidden" name="return_to" value="<?= $escape($studentReturnTo) ?>"><button class="btn btn-sm btn-outline-primary" type="submit">Sincronizza studenti</button></form>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
        <?php if ($selectedGroupId !== ''): ?>
            <?php if ($studentMatrixError !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($studentMatrixError) ?></div><?php endif; ?>
            <?php if ($studentMatrixError === null && $configuredProviders !== []): ?>
                <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Filtri studenti">
                    <?php foreach (['tutti' => 'Tutti', 'mappati' => 'Mappati', 'non_mappati' => 'Non mappati', 'conflitti' => 'Conflitti'] as $filterKey => $filterLabel): ?><a class="btn btn-sm <?= $studentFilter === $filterKey ? 'btn-primary' : 'btn-outline-secondary' ?>" href="?tab=students&amp;id_gruppo=<?= urlencode($selectedGroupId) ?>&amp;student_status=<?= urlencode($filterKey) ?>&amp;return_to=<?= urlencode($returnTo) ?>" aria-current="<?= $studentFilter === $filterKey ? 'page' : 'false' ?>"><?= $escape($filterLabel) ?></a><?php endforeach; ?>
                </nav>
                <div class="table-responsive"><table class="table table-sm align-middle" aria-describedby="student-matrix-help"><caption id="student-matrix-help" class="text-muted">Gli identificativi esterni sono mostrati solo per il collegamento; nomi ed email non vengono salvati.</caption>
                    <thead><tr><th scope="col">ID interno</th><th scope="col">Stato</th><?php foreach ($configuredProviders as $provider): ?><th scope="col"><?= $escape($providerLabels[$provider]) ?></th><?php endforeach; ?><th scope="col">Mappatura</th><th scope="col">Azioni</th></tr></thead><tbody>
                    <?php foreach ($studentRows as $studentRow): $identities = is_array($studentRow['identities'] ?? null) ? $studentRow['identities'] : []; ?><tr><th scope="row"><code><?= $escape($studentRow['id_studente'] ?? '') ?></code></th><td><span class="badge <?= $studentRow['status'] === 'conflitti' ? 'text-bg-danger' : ($studentRow['status'] === 'mappati' ? 'text-bg-success' : 'text-bg-secondary') ?>"><?= $escape($studentStatusLabels[$studentRow['status']] ?? $studentRow['status']) ?></span></td>
                        <?php foreach ($configuredProviders as $provider): ?><td><?php $providerIdentity = null; foreach ($identities as $identity) { if (($identity['provider'] ?? '') === $provider) { $providerIdentity = $identity; break; } } ?><?php if (is_array($providerIdentity)): ?><code><?= $escape($providerIdentity['external_user_id'] ?? '') ?></code><?php else: ?><span class="text-muted">Non mappato</span><?php endif; ?></td><?php endforeach; ?>
                        <td><?php
                            $anchorIdentity = null;
                            foreach ($identities as $identity) {
                                $identityProvider = trim((string)($identity['provider'] ?? ''));
                                $identityExternalId = trim((string)($identity['external_user_id'] ?? ''));
                                if ($identityProvider !== '' && $identityExternalId !== '') {
                                    $anchorIdentity = ['provider' => $identityProvider, 'external_user_id' => $identityExternalId];
                                    break;
                                }
                            }
                            $missingProviders = [];
                            foreach ($configuredProviders as $provider) {
                                $hasProviderIdentity = false;
                                foreach ($identities as $identity) {
                                    if ((string)($identity['provider'] ?? '') === $provider
                                        && trim((string)($identity['external_user_id'] ?? '')) !== '') {
                                        $hasProviderIdentity = true;
                                        break;
                                    }
                                }
                                if (!$hasProviderIdentity) {
                                    $missingProviders[] = $provider;
                                }
                            }
                        ?><?php if ($anchorIdentity !== null && $missingProviders !== []): ?>
                            <form method="post" class="p-2 border rounded" aria-label="Collega identita studente">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_student_mapping"><input type="hidden" name="id_gruppo" value="<?= $escape($selectedGroupId) ?>"><input type="hidden" name="student_id" value="<?= $escape($studentRow['id_studente'] ?? '') ?>"><input type="hidden" name="anchor_provider" value="<?= $escape($anchorIdentity['provider']) ?>"><input type="hidden" name="anchor_external_user_id" value="<?= $escape($anchorIdentity['external_user_id']) ?>"><input type="hidden" name="return_to" value="<?= $escape($studentReturnTo) ?>">
                                <div class="small text-muted mb-2">Seleziona le identita del roster da collegare; puoi lasciare tutti i campi vuoti.</div>
                                <?php foreach ($missingProviders as $missingProvider):
                                    $providerChoices = [];
                                    $seenChoices = [];
                                    foreach ($studentIdentityChoices as $choice) {
                                        if ((string)($choice['provider'] ?? '') !== $missingProvider) {
                                            continue;
                                        }
                                        $choiceExternalId = trim((string)($choice['external_user_id'] ?? ''));
                                        if ($choiceExternalId === '' || isset($seenChoices[$choiceExternalId])) {
                                            continue;
                                        }
                                        $seenChoices[$choiceExternalId] = true;
                                        $providerChoices[] = $choice;
                                    }
                                    $providerFieldId = 'student-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)($studentRow['id_studente'] ?? '')) . '-match-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $missingProvider);
                                ?>
                                    <div class="mb-2"><label class="form-label small" for="<?= $escape($providerFieldId) ?>"><?= $escape($providerLabels[$missingProvider] ?? $missingProvider) ?> da collegare</label><select id="<?= $escape($providerFieldId) ?>" class="form-select form-select-sm" name="matches[<?= $escape($missingProvider) ?>][]" multiple size="<?= $providerChoices === [] ? 1 : min(4, count($providerChoices)) ?>" aria-describedby="<?= $escape($providerFieldId) ?>-help" <?= $providerChoices === [] ? 'disabled' : '' ?>><?php foreach ($providerChoices as $choice): ?><option value="<?= $escape($choice['external_user_id']) ?>"><?= $escape($choice['external_user_id']) ?></option><?php endforeach; ?></select><?php if ($providerChoices === []): ?><span id="<?= $escape($providerFieldId) ?>-help" class="form-text text-warning">Nessuna identita disponibile nel roster.</span><?php else: ?><span id="<?= $escape($providerFieldId) ?>-help" class="form-text">Nessuna selezione lascia invariata questa riga.</span><?php endif; ?></div>
                                <?php endforeach; ?>
                                <button class="btn btn-sm btn-primary" type="submit">Collega</button>
                            </form>
                        <?php endif; ?></td>
                        <td><?php foreach ($identities as $identity): ?><form method="post" class="d-inline-block me-1 mb-1"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="unlink_identity"><input type="hidden" name="id_gruppo" value="<?= $escape($selectedGroupId) ?>"><input type="hidden" name="student_id" value="<?= $escape($studentRow['id_studente'] ?? '') ?>"><input type="hidden" name="provider" value="<?= $escape($identity['provider'] ?? '') ?>"><input type="hidden" name="external_user_id" value="<?= $escape($identity['external_user_id'] ?? '') ?>"><input type="hidden" name="return_to" value="<?= $escape($studentReturnTo) ?>"><button class="btn btn-sm btn-outline-danger" type="submit" aria-label="Scollega identità">Scollega</button></form><?php endforeach; ?></td></tr><?php endforeach; ?>
                    <?php if ($studentRows === []): ?><tr><td colspan="<?= count($configuredProviders) + 4 ?>" class="text-muted">Nessuna riga per questo filtro.</td></tr><?php endif; ?></tbody></table></div>
                <?php if (count($studentIdentityChoices) >= 2): $anchorChoice = $studentIdentityChoices[0]; $candidateChoice = $studentIdentityChoices[1]; ?><section class="card border-primary mt-3" aria-labelledby="student-link-heading"><div class="card-body"><h3 id="student-link-heading" class="h6">Suggerimenti di collegamento</h3><p class="small text-muted">Seleziona solo identità già presenti nel roster. Il server verifica gruppo, provider e ID tecnico.</p><form method="post" class="row gy-2 gx-2 align-items-end"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_student_mapping"><input type="hidden" name="id_gruppo" value="<?= $escape($selectedGroupId) ?>"><input type="hidden" name="student_id" value="<?= $escape($anchorChoice['student_id'] ?? '') ?>"><input type="hidden" name="return_to" value="<?= $escape($studentReturnTo) ?>"><div class="col-md-4"><label class="form-label" for="anchor-provider">Identità ancora</label><input id="anchor-provider" class="form-control" name="anchor_provider" value="<?= $escape($anchorChoice['provider']) ?>" readonly></div><div class="col-md-4"><label class="form-label" for="anchor-id">ID esterno ancora</label><input id="anchor-id" class="form-control" name="anchor_external_user_id" value="<?= $escape($anchorChoice['external_user_id']) ?>" readonly></div><div class="col-md-4"><label class="form-label" for="match-id">Identità da collegare</label><input id="match-id" class="form-control" name="matches[<?= $escape($candidateChoice['provider']) ?>][]" value="<?= $escape($candidateChoice['external_user_id']) ?>" readonly></div><div class="col-12"><button class="btn btn-sm btn-primary" type="submit">Collega identità suggerite</button></div></form></div></section><?php endif; ?>
            <?php endif; ?>
        <?php endif; ?>
    <?php else: ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Nuovo gruppo</h2>
                <form method="post" class="row gy-2 gx-2">
                    <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="create_group"><input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
                    <div class="col-md-4"><label class="form-label" for="new-nome-gruppo">Nome gruppo</label><input id="new-nome-gruppo" class="form-control" name="nome_gruppo" required></div>
                    <div class="col-md-2"><label class="form-label" for="new-nome-classe">Classe</label><input id="new-nome-classe" class="form-control" name="nome_classe"></div>
                    <div class="col-md-3"><label class="form-label" for="new-nome-materia">Materia</label><input id="new-nome-materia" class="form-control" name="nome_materia"></div>
                    <div class="col-md-3"><label class="form-label" for="new-anno">Anno scolastico</label><input id="new-anno" class="form-control" name="anno_scolastico"></div>
                    <div class="col-12"><label class="form-label" for="new-descrizione">Descrizione</label><textarea id="new-descrizione" class="form-control" name="descrizione" rows="2"></textarea></div>
                    <div class="col-12"><button class="btn btn-primary" type="submit">Crea gruppo</button></div>
                </form>
            </div>
        </section>

        <form method="get" class="row gy-2 gx-2 align-items-end mb-3">
            <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
            <div class="col-md-8"><label class="form-label" for="group-filter">Filtra gruppi</label><input id="group-filter" class="form-control" name="q" value="<?= $escape($query) ?>" placeholder="Nome, classe o materia"></div>
            <div class="col-md-auto"><button class="btn btn-outline-secondary" type="submit">Filtra</button></div>
        </form>

        <div class="row g-3">
        <?php foreach ($groups as $entry): $group = $entry['row']; $groupId = (string)$group['id_gruppo']; ?>
            <div class="col-12"><article class="card shadow-sm"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1"><?= $escape($group['nome_gruppo'] ?? '') ?></h2><div class="small text-muted"><?= $escape(trim(($group['nome_classe'] ?? '') . ' · ' . ($group['nome_materia'] ?? '') . ' · ' . ($group['anno_scolastico'] ?? ''), ' ·')) ?></div></div><span class="badge <?= ($group['stato'] ?? 'attivo') === 'attivo' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $escape($group['stato'] ?? 'attivo') ?></span></div>
                <form method="post" class="row gy-2 gx-2 mt-2"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="update_group"><input type="hidden" name="id_gruppo" value="<?= $escape($groupId) ?>"><input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
                    <div class="col-md-3"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-name">Nome gruppo</label><input id="group-<?= $escape($groupId) ?>-name" class="form-control" name="nome_gruppo" value="<?= $escape($group['nome_gruppo'] ?? '') ?>" required></div>
                    <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-class">Classe</label><input id="group-<?= $escape($groupId) ?>-class" class="form-control" name="nome_classe" value="<?= $escape($group['nome_classe'] ?? '') ?>"></div>
                    <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-subject">Materia</label><input id="group-<?= $escape($groupId) ?>-subject" class="form-control" name="nome_materia" value="<?= $escape($group['nome_materia'] ?? '') ?>"></div>
                    <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-year">Anno scolastico</label><input id="group-<?= $escape($groupId) ?>-year" class="form-control" name="anno_scolastico" value="<?= $escape($group['anno_scolastico'] ?? '') ?>"></div>
                    <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-status">Stato</label><select id="group-<?= $escape($groupId) ?>-status" class="form-select" name="stato"><option value="attivo" <?= ($group['stato'] ?? '') === 'attivo' ? 'selected' : '' ?>>Attivo</option><option value="disattivo" <?= ($group['stato'] ?? '') === 'disattivo' ? 'selected' : '' ?>>Disattivo</option></select></div><div class="col-md-1"><button class="btn btn-outline-primary w-100" type="submit">Salva</button></div>
                    <div class="col-12"><label class="form-label small text-muted" for="group-<?= $escape($groupId) ?>-description">Descrizione</label><textarea id="group-<?= $escape($groupId) ?>-description" class="form-control form-control-sm" name="descrizione" rows="1"><?= $escape($group['descrizione'] ?? '') ?></textarea></div>
                </form>
                <div class="row g-2 mt-2">
                <?php foreach ($providerLabels as $provider => $label): $linked = $entry['providers'][$provider] ?? null; ?>
                    <div class="col-lg-4"><div class="border rounded p-2 h-100"><div class="d-flex justify-content-between"><strong><?= $escape($label) ?></strong><span class="small <?= $providerReady[$provider] ? 'text-success' : 'text-muted' ?>"><?= $providerReady[$provider] ? 'Autorizzato' : 'Autorizzazione richiesta' ?></span></div>
                        <?php if ($providerErrors[$provider] !== null): ?><div class="small text-danger" role="alert"><?= $escape($providerErrors[$provider]) ?></div><?php endif; ?>
                        <?php if (is_array($linked)): ?>
                            <div class="small text-muted mb-2">Collegato: <?= $escape($linked['external_name'] ?: $linked['external_context_id']) ?></div>
                            <form method="post">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="unlink_provider">
                                <input type="hidden" name="id_gruppo" value="<?= $escape($groupId) ?>">
                                <input type="hidden" name="provider" value="<?= $escape($provider) ?>">
                                <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
                                <button class="btn btn-sm btn-outline-danger" type="submit">Scollega</button>
                            </form>
                        <?php elseif (!$providerReady[$provider]): ?>
                            <p class="small text-muted mb-1">Autorizza il provider per caricare i contesti disponibili.</p>
                            <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($providerAuthLinks[$provider]) ?>">Apri autorizzazione</a>
                        <?php else: ?>
                            <form method="post" class="mt-2">
                                <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                                <input type="hidden" name="action" value="link_provider">
                                <input type="hidden" name="id_gruppo" value="<?= $escape($groupId) ?>">
                                <input type="hidden" name="provider" value="<?= $escape($provider) ?>">
                                <input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
                                <label class="form-label small" for="provider-<?= $escape($groupId . '-' . $provider) ?>">Contesto disponibile</label>
                                <select id="provider-<?= $escape($groupId . '-' . $provider) ?>" class="form-select form-select-sm mb-1" name="<?= $escape($providerFields[$provider]) ?>" required>
                                    <option value="">Seleziona…</option>
                                    <?php foreach ($providerOptions[$provider] as $option): ?><option value="<?= $escape($option['value']) ?>"><?= $escape($option['label']) ?></option><?php endforeach; ?>
                                </select>
                                <button class="btn btn-sm btn-outline-primary" type="submit">Collega</button>
                            </form>
                        <?php endif; ?>
                    </div></div>
                <?php endforeach; ?></div>
            </div></article></div>
        <?php endforeach; ?>
        <?php if ($groups === [] && $catalogError === null): ?><div class="col-12"><div class="alert alert-info">Nessun gruppo trovato. Puoi crearne uno senza collegare subito un provider.</div></div><?php endif; ?>
        </div>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('form[method="post"]').forEach(function (form) {
    if (form.querySelector('input[name="csrf_token"]')) return;
    const input = document.createElement('input');
    input.type = 'hidden';
    input.name = 'csrf_token';
    input.value = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    form.appendChild(input);
});
</script>
</body>
</html>
