<?php

declare(strict_types=1);

define('REQUIRES_CLASSEVIVA', true);
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
        $classrooms = is_array($classrooms)
            ? ($classrooms['classrooms'] ?? ($classrooms['data'] ?? $classrooms))
            : [];
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
    $providerErrors['github_classroom'] = 'Servizio GitHub Classroom non disponibile: ' . $exception->getMessage();
}

$githubAuthUrl = null;
if (!($providerReady['github_classroom'] ?? false) && isset($github) && !empty($config['github']['client_id'] ?? '')) {
    try {
        $githubAuthUrl = $github->getAuthorizationUrl(null, (string)($_SERVER['REQUEST_URI'] ?? 'teaching_groups.php'));
    } catch (Throwable $ignored) {
        $githubAuthUrl = null;
    }
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
            case 'save_group':
                $groupService->updateGroup($groupId, [
                    'nome_gruppo' => $postText('nome_gruppo'),
                    'nome_classe' => $postText('nome_classe'),
                    'nome_materia' => $postText('nome_materia'),
                    'anno_scolastico' => $postText('anno_scolastico'),
                    'descrizione' => $postText('descrizione'),
                    'stato' => $postText('stato', 'attivo'),
                ]);
                foreach (array_keys($providerLabels) as $provider) {
                    if (!($providerReady[$provider] ?? false)) {
                        continue;
                    }
                    $selectedRaw = $_POST[$providerFields[$provider]] ?? '';
                    $selected = is_scalar($selectedRaw) ? trim((string)$selectedRaw) : '';
                    $option = null;
                    foreach ($providerOptions[$provider] ?? [] as $candidate) {
                        if ((string)($candidate['value'] ?? '') === $selected) {
                            $option = $candidate;
                            break;
                        }
                    }
                    if ($option === null) {
                        if ($integrationRepository->findForGroupProvider($groupId, $provider) !== null) {
                            $groupService->unlinkProvider($groupId, $provider);
                        }
                        continue;
                    }
                    $groupService->linkProvider($groupId, [
                        'provider' => $provider,
                        'external_context_id' => (string)($option['context'] ?? ''),
                        'external_subject_id' => (string)($option['subject'] ?? ''),
                        'external_name' => (string)($option['label'] ?? ''),
                        'tipo_risorsa' => $provider === 'classeviva' ? 'classe_materia' : ($provider === 'google_classroom' ? 'course' : 'roster'),
                    ]);
                }
                $_SESSION['teaching_groups_flash'] = ['success' => 'Gruppo didattico aggiornato. Ora mappa gli studenti.'];
                header('Location: teaching_groups.php?tab=students&id=' . urlencode($groupId) . '&return_to=' . urlencode($returnTo));
                exit;
            case 'save_all_mappings':
                $mappingsRaw = $_POST['mappings'] ?? [];
                if (!is_array($mappingsRaw)) {
                    throw new RuntimeException('Payload mappature non valido.');
                }
                $linkPayload = [];
                foreach ($mappingsRaw as $mappingRow) {
                    if (!is_array($mappingRow)) {
                        continue;
                    }
                    $anchorProviderRaw = $mappingRow['anchor_provider'] ?? '';
                    $anchorExternalRaw = $mappingRow['anchor_external_user_id'] ?? '';
                    if (!is_scalar($anchorProviderRaw) || !is_scalar($anchorExternalRaw)) {
                        continue;
                    }
                    $anchorProvider = strtolower(trim((string)$anchorProviderRaw));
                    $anchorExternal = trim((string)$anchorExternalRaw);
                    if ($anchorProvider === '' || $anchorExternal === '') {
                        continue;
                    }
                    $matches = [];
                    foreach (($mappingRow['matches'] ?? []) as $matchProvider => $matchExternal) {
                        if (!is_string($matchProvider)) {
                            continue;
                        }
                        $matchExternal = is_scalar($matchExternal) ? trim((string)$matchExternal) : '';
                        if ($matchExternal === '') {
                            continue;
                        }
                        $matches[] = ['provider' => strtolower(trim($matchProvider)), 'external_user_id' => $matchExternal];
                    }
                    if ($matches !== []) {
                        $linkPayload[] = ['provider' => $anchorProvider, 'external_user_id' => $anchorExternal, 'matches' => $matches];
                    }
                }
                $seenTargetKeys = [];
                foreach ($linkPayload as $linkEntry) {
                    $linkAnchorKey = (string)($linkEntry['provider'] ?? '') . ':' . (string)($linkEntry['external_user_id'] ?? '');
                    foreach ($linkEntry['matches'] as $matchEntry) {
                        $matchKey = (string)($matchEntry['provider'] ?? '') . ':' . (string)($matchEntry['external_user_id'] ?? '');
                        if (isset($seenTargetKeys[$matchKey]) && $seenTargetKeys[$matchKey] !== $linkAnchorKey) {
                            throw new RuntimeException('Lo studente "' . (string)($matchEntry['external_user_id'] ?? '') . '" (' . (string)($matchEntry['provider'] ?? '') . ') è stato selezionato per più di una riga. Ogni studente può essere collegato a una sola riga della lista di origine.');
                        }
                        $seenTargetKeys[$matchKey] = $linkAnchorKey;
                    }
                }
                if ($linkPayload !== []) {
                    $studentService->linkIdentities($groupId, $linkPayload);
                }
                $_SESSION['teaching_groups_flash'] = ['success' => 'Mappature studenti salvate.'];
                header('Location: teaching_groups.php?return_to=' . urlencode($returnTo));
                exit;
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
                            $entryStudents = is_array($entry['students'] ?? null) ? $entry['students'] : [];
                            $entryFirstStudent = is_array($entryStudents[0] ?? null) ? $entryStudents[0] : [];
                            $entrySingleStudent = is_array($entry['student'] ?? null) ? $entry['student'] : [];
                            $externalRaw = $entryFirstStudent['login'] ?? $entrySingleStudent['login'] ?? $entry['github_username'] ?? $entry['user_id'] ?? $entry['username'] ?? '';
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

$countUnmapped = static function (string $groupId, array $providers) use ($studentService): array {
    $result = ['unmapped' => 0, 'total' => 0];
    if ($providers === []) {
        return $result;
    }
    $anchorProvider = $providers[0];
    try {
        foreach ($studentService->matrix($groupId) as $matrixRow) {
            $matrixIdentities = is_array($matrixRow['identities'] ?? null) ? $matrixRow['identities'] : [];
            $hasAnchor = false;
            $hasTarget = false;
            foreach ($matrixIdentities as $matrixIdentity) {
                $matrixProvider = (string)($matrixIdentity['provider'] ?? '');
                if ($matrixProvider === $anchorProvider) {
                    $hasAnchor = true;
                } elseif (in_array($matrixProvider, $providers, true)) {
                    $hasTarget = true;
                }
            }
            if ($hasAnchor) {
                $result['total']++;
                if (!$hasTarget) {
                    $result['unmapped']++;
                }
            }
        }
    } catch (Throwable $ignored) {
        return ['unmapped' => 0, 'total' => 0];
    }
    return $result;
};

$groups = [];
foreach ($groupRepository->listAll() as $group) {
    $name = (string)($group['nome_gruppo'] ?? '');
    if ($query !== '' && stripos($name . ' ' . ($group['nome_classe'] ?? '') . ' ' . ($group['nome_materia'] ?? ''), $query) === false) {
        continue;
    }
    $groupId = (string)($group['id_gruppo'] ?? '');
    $catalog = $catalogRows[$groupId] ?? ['providers' => []];
    $providers = is_array($catalog['providers'] ?? null) ? $catalog['providers'] : [];
    $groupProviders = [];
    foreach ($providerLabels as $providerName => $unusedProviderLabel) {
        if (is_array($providers[$providerName] ?? null)) {
            $groupProviders[] = $providerName;
        }
    }
    $mappingCounts = $countUnmapped($groupId, $groupProviders);
    $groups[] = ['row' => $group, 'providers' => $providers, 'unmapped_count' => $mappingCounts['unmapped'], 'total_students' => $mappingCounts['total']];
}

$nameSimilarity = static function (string $a, string $b): float {
    $a = strtolower(trim($a));
    $b = strtolower(trim($b));
    if ($a === $b) {
        return 1.0;
    }
    if ($a === '' || $b === '') {
        return 0.0;
    }
    $maxLength = max(strlen($a), strlen($b));
    return 1 - (levenshtein($a, $b) / $maxLength);
};
$githubFetchDebug = ['assignments' => 0, 'accepted' => 0, 'first_keys' => [], 'assignment_accepted' => -1, 'assignment_submissions' => -1, 'accepted_raw' => ''];
$fetchRoster = static function (string $provider, string $contextId) use ($config, $github, &$githubFetchDebug): array {
    $roster = [];
    if ($provider === 'classeviva') {
        $students = (new ClasseVivaAPI($config))->getStudents($contextId);
        if (is_array($students)) {
            foreach ($students as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $externalId = trim((string)($entry['id'] ?? $entry['studentId'] ?? ''));
                if ($externalId === '') {
                    continue;
                }
                $firstName = is_scalar($entry['nome'] ?? null) ? (string)$entry['nome'] : '';
                $lastName = is_scalar($entry['cognome'] ?? null) ? (string)$entry['cognome'] : '';
                $roster[] = ['external_user_id' => $externalId, 'display_name' => trim($firstName . ' ' . $lastName)];
            }
        }
    } elseif ($provider === 'google_classroom') {
        $students = (new GoogleClassroomAPI($config))->getCourseStudents($contextId);
        if (is_array($students)) {
            foreach ($students as $entry) {
                if (!is_array($entry)) {
                    continue;
                }
                $externalId = trim((string)($entry['id'] ?? $entry['userId'] ?? ''));
                if ($externalId === '') {
                    continue;
                }
                $roster[] = ['external_user_id' => $externalId, 'display_name' => is_scalar($entry['name'] ?? null) ? (string)$entry['name'] : ''];
            }
        }
    } elseif (isset($github)) {
        $githubAssignments = [];
        $githubPage = 1;
        while ($githubPage <= 10) {
            $githubPageAssignments = $github->listAssignments($contextId, $githubPage, 100);
            $githubPageAssignments = is_array($githubPageAssignments)
                ? ($githubPageAssignments['assignments'] ?? ($githubPageAssignments['data'] ?? $githubPageAssignments))
                : [];
            if (!is_array($githubPageAssignments) || $githubPageAssignments === []) {
                break;
            }
            $githubAssignments = array_merge($githubAssignments, $githubPageAssignments);
            if (count($githubPageAssignments) < 100) {
                break;
            }
            $githubPage++;
        }
        $githubFetchDebug['assignments'] = count($githubAssignments);
        $githubSample = $githubAssignments !== [] ? reset($githubAssignments) : null;
        $githubFetchDebug['first_keys'] = is_array($githubSample) ? array_keys($githubSample) : [];
        $githubFetchDebug['assignment_accepted'] = is_array($githubSample) ? (int)($githubSample['accepted'] ?? -1) : -1;
        $githubFetchDebug['assignment_submissions'] = is_array($githubSample) ? (int)($githubSample['submissions'] ?? -1) : -1;
        $githubSeen = [];
        $githubNameCache = $_SESSION['github_display_names'] ?? [];
        if (!is_array($githubNameCache)) {
            $githubNameCache = [];
        }
        foreach ($githubAssignments as $githubAssignment) {
            if (!is_array($githubAssignment)) {
                continue;
            }
            $githubAssignmentId = trim((string)($githubAssignment['id'] ?? $githubAssignment['assignment_id'] ?? ''));
            if ($githubAssignmentId === '') {
                continue;
            }
            $githubGrades = $github->getAssignmentGrades($githubAssignmentId);
            $githubGrades = is_array($githubGrades)
                ? ($githubGrades['grades'] ?? ($githubGrades['data'] ?? $githubGrades))
                : [];
            foreach ($githubGrades as $githubGrade) {
                if (!is_array($githubGrade)) {
                    continue;
                }
                $githubExternal = trim((string)($githubGrade['github_username'] ?? $githubGrade['username'] ?? ''));
                if ($githubExternal === '' || isset($githubSeen[$githubExternal])) {
                    continue;
                }
                $githubSeen[$githubExternal] = true;
                $githubName = trim((string)($githubGrade['roster_identifier'] ?? ''));
                if ($githubName === '') {
                    if (!isset($githubNameCache[$githubExternal])) {
                        $githubNameCache[$githubExternal] = $githubExternal;
                        try {
                            $githubProfile = $github->getUserByLogin($githubExternal);
                            if (is_array($githubProfile) && is_scalar($githubProfile['name'] ?? null) && (string)$githubProfile['name'] !== '') {
                                $githubNameCache[$githubExternal] = (string)$githubProfile['name'];
                            }
                        } catch (Throwable $ignored) {
                        }
                    }
                    $githubName = (string)$githubNameCache[$githubExternal];
                }
                $roster[] = ['external_user_id' => $githubExternal, 'display_name' => $githubName];
            }
            $githubAcceptedRaw = $github->listAcceptedAssignments($githubAssignmentId);
            if ($githubFetchDebug['accepted_raw'] === '') {
                $githubFetchDebug['accepted_raw'] = json_encode($githubAcceptedRaw);
                if (strlen($githubFetchDebug['accepted_raw']) > 400) {
                    $githubFetchDebug['accepted_raw'] = substr($githubFetchDebug['accepted_raw'], 0, 400) . '…';
                }
            }
            $githubAccepted = $githubAcceptedRaw;
            $githubAccepted = is_array($githubAccepted) && isset($githubAccepted['accepted_assignments']) && is_array($githubAccepted['accepted_assignments'])
                ? $githubAccepted['accepted_assignments']
                : (is_array($githubAccepted) && isset($githubAccepted['data']) && is_array($githubAccepted['data'])
                    ? $githubAccepted['data']
                    : (is_array($githubAccepted) ? $githubAccepted : []));
            $githubFetchDebug['accepted'] += count($githubAccepted);
            foreach ($githubAccepted as $githubEntry) {
                if (!is_array($githubEntry)) {
                    continue;
                }
                $githubStudents = is_array($githubEntry['students'] ?? null) ? $githubEntry['students'] : [];
                $githubFirstStudent = is_array($githubStudents[0] ?? null) ? $githubStudents[0] : [];
                $githubSingleStudent = is_array($githubEntry['student'] ?? null) ? $githubEntry['student'] : [];
                $githubExternal = trim((string)($githubFirstStudent['login'] ?? $githubSingleStudent['login'] ?? $githubEntry['github_username'] ?? $githubEntry['user_id'] ?? $githubEntry['username'] ?? ''));
                if ($githubExternal === '' || isset($githubSeen[$githubExternal])) {
                    continue;
                }
                $githubSeen[$githubExternal] = true;
                if (!isset($githubNameCache[$githubExternal])) {
                    $githubName = '';
                    try {
                        $githubProfile = $github->getUserByLogin($githubExternal);
                        if (is_array($githubProfile)) {
                            $githubName = is_scalar($githubProfile['name'] ?? null) ? (string)$githubProfile['name'] : '';
                            if ($githubName === '' && is_scalar($githubProfile['login'] ?? null)) {
                                $githubName = (string)$githubProfile['login'];
                            }
                        }
                    } catch (Throwable $ignored) {
                        $githubName = '';
                    }
                    if ($githubName === '') {
                        $githubName = $githubExternal;
                    }
                    $githubNameCache[$githubExternal] = $githubName;
                }
                $roster[] = ['external_user_id' => $githubExternal, 'display_name' => (string)$githubNameCache[$githubExternal]];
            }
        }
        $_SESSION['github_display_names'] = $githubNameCache;
    }
    return $roster;
};

$configuredProviders = [];
$selectedGroupName = '';
$selectedGroupRecord = $selectedGroupId !== '' ? $groupRepository->findById($selectedGroupId) : null;
$studentMatrixError = null;
$studentGroupOwned = true;
$anchorProvider = null;
$anchorRoster = [];
$targetProviders = [];
$targetRosters = [];
$rosterErrors = [];
$existingMappings = [];
if ($selectedGroupRecord !== null) {
    $selectedGroupName = (string)($selectedGroupRecord['nome_gruppo'] ?? '');
    $selectedCatalog = $catalogRows[$selectedGroupId] ?? ['providers' => []];
    foreach ($providerLabels as $provider => $label) {
        if (is_array($selectedCatalog['providers'][$provider] ?? null)) {
            $configuredProviders[] = $provider;
        }
    }
    $rosters = [];
    foreach ($configuredProviders as $provider) {
        $providerLink = $selectedCatalog['providers'][$provider] ?? [];
        $contextId = trim((string)($providerLink['external_context_id'] ?? ''));
        if ($contextId === '') {
            continue;
        }
        try {
            $roster = $fetchRoster($provider, $contextId);
            if ($roster !== []) {
                $studentService->syncRoster($selectedGroupId, $provider, $contextId, $roster);
            }
            $rosters[$provider] = $roster;
        } catch (Throwable $exception) {
            $rosterErrors[$provider] = 'Roster ' . ($providerLabels[$provider] ?? $provider) . ' non disponibile: ' . $exception->getMessage();
            $rosters[$provider] = [];
        }
        if ($provider === 'github_classroom' && ($rosters[$provider] ?? []) === [] && !isset($rosterErrors[$provider])) {
            $rosterErrors[$provider] = 'Roster GitHub Classroom vuoto per "' . ($providerLink['external_name'] ?? $contextId) . '" (ID ' . $contextId . '): ' . $githubFetchDebug['assignments'] . ' assignment, accepted-field=' . $githubFetchDebug['assignment_accepted'] . ', submissions=' . $githubFetchDebug['assignment_submissions'] . ', listAcceptedAssignments=' . $githubFetchDebug['accepted'] . ' righe. Raw accepted: ' . $githubFetchDebug['accepted_raw'];
        }
    }
    $anchorProvider = $configuredProviders[0] ?? null;
    if ($anchorProvider !== null) {
        $anchorRoster = $rosters[$anchorProvider] ?? [];
        $targetProviders = array_slice($configuredProviders, 1);
        foreach ($targetProviders as $provider) {
            $targetRosters[$provider] = $rosters[$provider] ?? [];
        }
        try {
            foreach ($studentService->matrix($selectedGroupId) as $matrixRow) {
                $anchorExternal = null;
                foreach (($matrixRow['identities'] ?? []) as $matrixIdentity) {
                    if (($matrixIdentity['provider'] ?? '') === $anchorProvider) {
                        $anchorExternal = (string)($matrixIdentity['external_user_id'] ?? '');
                        break;
                    }
                }
                if ($anchorExternal === null || $anchorExternal === '') {
                    continue;
                }
                foreach (($matrixRow['identities'] ?? []) as $matrixIdentity) {
                    $matrixProvider = (string)($matrixIdentity['provider'] ?? '');
                    $matrixExternal = (string)($matrixIdentity['external_user_id'] ?? '');
                    if ($matrixProvider === $anchorProvider || $matrixExternal === '') {
                        continue;
                    }
                    $existingMappings[$anchorExternal][$matrixProvider] = $matrixExternal;
                }
            }
        } catch (Throwable $ignored) {
            $existingMappings = [];
        }
        $preselected = [];
        foreach ($targetProviders as $targetProvider) {
            $targetRoster = $targetRosters[$targetProvider] ?? [];
            $preCandidates = [];
            foreach ($anchorRoster as $anchorEntry) {
                $preAnchorExternal = (string)($anchorEntry['external_user_id'] ?? '');
                if ($preAnchorExternal === '') {
                    continue;
                }
                if (isset($existingMappings[$preAnchorExternal][$targetProvider])) {
                    $preCandidates[] = [$preAnchorExternal, (string)$existingMappings[$preAnchorExternal][$targetProvider], 2.0];
                    continue;
                }
                $preAnchorName = (string)($anchorEntry['display_name'] ?? '');
                $preBestScore = 0.0;
                $preBestTarget = '';
                foreach ($targetRoster as $targetEntry) {
                    $targetEntryName = (string)($targetEntry['display_name'] ?? '');
                    $targetEntryScore = $nameSimilarity($preAnchorName, $targetEntryName);
                    if ($targetEntryScore > 0.75 && $targetEntryScore > $preBestScore) {
                        $preBestScore = $targetEntryScore;
                        $preBestTarget = (string)($targetEntry['external_user_id'] ?? '');
                    }
                }
                if ($preBestTarget !== '') {
                    $preCandidates[] = [$preAnchorExternal, $preBestTarget, $preBestScore];
                }
            }
            usort($preCandidates, static fn(array $a, array $b): int => $b[2] <=> $a[2]);
            $usedTargets = [];
            foreach ($preCandidates as [$preAnchorExternal, $preTargetExternal]) {
                if (isset($usedTargets[$preTargetExternal])) {
                    continue;
                }
                $usedTargets[$preTargetExternal] = true;
                $preselected[$targetProvider][$preAnchorExternal] = $preTargetExternal;
            }
        }
    }
} elseif ($selectedGroupId !== '') {
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

    <?php if ($githubAuthUrl !== null): ?><div class="d-flex justify-content-end mb-3"><a href="<?= $escape($githubAuthUrl) ?>" class="btn btn-dark">Autorizza GitHub</a></div><?php endif; ?>


    <?php if ($tab === 'students'): ?>
        <section class="card shadow-sm mb-4">
            <div class="card-body">
                <h2 class="h5">Mappatura studenti<?= $selectedGroupName !== '' ? ' — ' . $escape($selectedGroupName) : '' ?></h2>
                <p class="text-muted"><a href="teaching_groups.php?return_to=<?= urlencode($returnTo) ?>">&larr; Torna ai gruppi didattici</a></p>
                <?php if ($studentMatrixError !== null): ?><div class="alert alert-danger" role="alert"><?= $escape($studentMatrixError) ?></div><?php endif; ?>
                <?php foreach ($rosterErrors as $rosterError): ?><div class="alert alert-warning" role="alert"><?= $escape($rosterError) ?></div><?php endforeach; ?>
                <?php if ($anchorProvider === null): ?>
                    <div class="alert alert-warning mt-3 mb-0" role="alert">Collega almeno un provider al gruppo per mappare gli studenti.</div>
                <?php elseif ($anchorRoster === []): ?>
                    <div class="alert alert-info mt-3 mb-0" role="alert">Nessuno studente trovato nel roster di <?= $escape($providerLabels[$anchorProvider] ?? $anchorProvider) ?>.</div>
                <?php else: ?>
                    <form method="post" id="mapping-form">
                        <input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>">
                        <input type="hidden" name="action" value="save_all_mappings">
                        <input type="hidden" name="id_gruppo" value="<?= $escape($selectedGroupId) ?>">
                        <div class="table-responsive mt-3">
                            <table class="table table-sm align-middle">
                                <thead><tr><th scope="col"><?= $escape($providerLabels[$anchorProvider] ?? $anchorProvider) ?></th><?php foreach ($targetProviders as $targetProvider): ?><th scope="col"><?= $escape($providerLabels[$targetProvider] ?? $targetProvider) ?></th><?php endforeach; ?></tr></thead>
                                <tbody>
                                <?php foreach ($anchorRoster as $anchorIndex => $anchorEntry): $anchorExternal = (string)($anchorEntry['external_user_id'] ?? ''); $anchorName = (string)($anchorEntry['display_name'] ?? ''); ?>
                                    <tr>
                                        <td><strong><?= $anchorName !== '' ? $escape($anchorName) : '' ?></strong><?php if ($anchorName !== ''): ?> <code class="small text-muted"><?= $escape($anchorExternal) ?></code><?php else: ?><code><?= $escape($anchorExternal) ?></code><?php endif; ?></td>
                                        <input type="hidden" name="mappings[<?= $anchorIndex ?>][anchor_provider]" value="<?= $escape($anchorProvider) ?>">
                                        <input type="hidden" name="mappings[<?= $anchorIndex ?>][anchor_external_user_id]" value="<?= $escape($anchorExternal) ?>">
                                        <?php foreach ($targetProviders as $targetProvider): $targetSelected = $preselected[$targetProvider][$anchorExternal] ?? ''; ?>
                                        <td><select class="form-select form-select-sm" name="mappings[<?= $anchorIndex ?>][matches][<?= $escape($targetProvider) ?>]"><option value="">Non mappare</option><?php foreach ($targetRosters[$targetProvider] ?? [] as $targetEntry): $targetExternal = (string)($targetEntry['external_user_id'] ?? ''); $targetName = (string)($targetEntry['display_name'] ?? ''); ?><option value="<?= $escape($targetExternal) ?>" <?= $targetSelected === $targetExternal ? 'selected' : '' ?>><?= $targetName !== '' ? $escape($targetName) . ' (' . $escape($targetExternal) . ')' : $escape($targetExternal) ?></option><?php endforeach; ?></select></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-grid gap-2 d-md-flex justify-content-md-end mt-3">
                            <button type="submit" class="btn btn-success">Salva mappature</button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </section>
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
        <?php foreach ($groups as $entry): $group = $entry['row']; $groupId = (string)$group['id_gruppo']; $unmappedCount = (int)($entry['unmapped_count'] ?? 0); $totalStudents = (int)($entry['total_students'] ?? 0); $mapBtnClass = $unmappedCount === 0 ? 'btn-success' : ($unmappedCount < 3 ? 'btn-warning' : ''); $mapBtnStyle = $unmappedCount >= 3 ? 'background-color:#fd7e14;border-color:#fd7e14;color:#fff' : ''; ?>
            <div class="col-12"><article class="card shadow-sm"><div class="card-body">
                <div class="d-flex flex-wrap justify-content-between gap-2"><div><h2 class="h5 mb-1"><?= $escape($group['nome_gruppo'] ?? '') ?></h2><div class="small text-muted"><?= $escape(trim(($group['nome_classe'] ?? '') . ' · ' . ($group['nome_materia'] ?? '') . ' · ' . ($group['anno_scolastico'] ?? ''), ' ·')) ?></div></div><span class="badge <?= ($group['stato'] ?? 'attivo') === 'attivo' ? 'text-bg-success' : 'text-bg-secondary' ?>"><?= $escape($group['stato'] ?? 'attivo') ?></span></div>
                <form method="post" class="mt-2"><input type="hidden" name="csrf_token" value="<?= $escape($csrfToken) ?>"><input type="hidden" name="action" value="save_group"><input type="hidden" name="id_gruppo" value="<?= $escape($groupId) ?>"><input type="hidden" name="return_to" value="<?= $escape($returnTo) ?>">
                    <div class="row gy-2 gx-2">
                        <div class="col-md-3"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-name">Nome gruppo</label><input id="group-<?= $escape($groupId) ?>-name" class="form-control" name="nome_gruppo" value="<?= $escape($group['nome_gruppo'] ?? '') ?>" required></div>
                        <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-class">Classe</label><input id="group-<?= $escape($groupId) ?>-class" class="form-control" name="nome_classe" value="<?= $escape($group['nome_classe'] ?? '') ?>"></div>
                        <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-subject">Materia</label><input id="group-<?= $escape($groupId) ?>-subject" class="form-control" name="nome_materia" value="<?= $escape($group['nome_materia'] ?? '') ?>"></div>
                        <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-year">Anno scolastico</label><input id="group-<?= $escape($groupId) ?>-year" class="form-control" name="anno_scolastico" value="<?= $escape($group['anno_scolastico'] ?? '') ?>"></div>
                        <div class="col-md-2"><label class="form-label visually-hidden" for="group-<?= $escape($groupId) ?>-status">Stato</label><select id="group-<?= $escape($groupId) ?>-status" class="form-select" name="stato"><option value="attivo" <?= ($group['stato'] ?? '') === 'attivo' ? 'selected' : '' ?>>Attivo</option><option value="disattivo" <?= ($group['stato'] ?? '') === 'disattivo' ? 'selected' : '' ?>>Disattivo</option></select></div>
                        <div class="col-12"><label class="form-label small text-muted" for="group-<?= $escape($groupId) ?>-description">Descrizione</label><textarea id="group-<?= $escape($groupId) ?>-description" class="form-control form-control-sm" name="descrizione" rows="1"><?= $escape($group['descrizione'] ?? '') ?></textarea></div>
                    </div>
                    <div class="row g-2 mt-2">
                    <?php foreach ($providerLabels as $provider => $label): $linked = $entry['providers'][$provider] ?? null; ?>
                        <div class="col-lg-4"><div class="border rounded p-2 h-100"><div class="d-flex justify-content-between"><strong><?= $escape($label) ?></strong><span class="small <?= $providerReady[$provider] ? 'text-success' : 'text-muted' ?>"><?= $providerReady[$provider] ? 'Autorizzato' : 'Autorizzazione richiesta' ?></span></div>
                            <?php if ($providerErrors[$provider] !== null): ?><div class="small text-danger" role="alert"><?= $escape($providerErrors[$provider]) ?></div><?php endif; ?>
                            <?php if (!$providerReady[$provider] && !is_array($linked)): ?>
                                <p class="small text-muted mb-1">Autorizza il provider per caricare i contesti disponibili.</p>
                                <a class="btn btn-sm btn-outline-secondary" href="<?= $escape($providerAuthLinks[$provider]) ?>">Apri autorizzazione</a>
                            <?php elseif (!$providerReady[$provider] && is_array($linked)): ?>
                                <div class="small text-muted mb-1">Collegato: <?= $escape($linked['external_name'] ?: $linked['external_context_id']) ?> (autorizzazione richiesta per modificare).</div>
                            <?php else: ?>
                                <?php if (is_array($linked)): ?><div class="small text-muted mb-1">Collegato: <?= $escape($linked['external_name'] ?: $linked['external_context_id']) ?></div><?php endif; ?>
                                <label class="form-label small" for="provider-<?= $escape($groupId . '-' . $provider) ?>">Contesto disponibile</label>
                                <select id="provider-<?= $escape($groupId . '-' . $provider) ?>" class="form-select form-select-sm mb-1" name="<?= $escape($providerFields[$provider]) ?>">
                                    <option value=""><?= is_array($linked) ? 'Scollega' : 'Seleziona…' ?></option>
                                    <?php foreach ($providerOptions[$provider] as $option): $optionSelected = is_array($linked) && (string)($option['context'] ?? '') === (string)($linked['external_context_id'] ?? '') && (string)($option['subject'] ?? '') === (string)($linked['external_subject_id'] ?? ''); ?><option value="<?= $escape($option['value']) ?>" <?= $optionSelected ? 'selected' : '' ?>><?= $escape($option['label']) ?></option><?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div></div>
                    <?php endforeach; ?></div>
                    <div class="mt-3 d-flex justify-content-between align-items-center"><button class="btn btn-primary" type="submit">Salva</button><a href="?tab=students&amp;id=<?= urlencode($groupId) ?>&amp;return_to=<?= urlencode($returnTo) ?>" class="btn btn-sm <?= $mapBtnClass ?>" style="<?= $mapBtnStyle ?>"><?= $unmappedCount ?> di <?= $totalStudents ?> studenti da mappare</a></div>
                </form>
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
