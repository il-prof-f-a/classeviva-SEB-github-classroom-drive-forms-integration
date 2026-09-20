<?php

declare(strict_types=1);

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubAssignmentEditorService;
use App\Core\GitHubAssignmentGroupRepository;
use App\Core\GitHubAssignmentService;
use App\Core\RuntimeStudentNameService;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GitHubIntegration;
use App\Integration\GoogleClassroomAPI;
use App\Utils\EncryptionHelper;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAuthenticated($_SESSION);

$db = DatabaseFactory::createWithInitialization($config, true);
$dbAdapter = $db;
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$csrfToken = Csrf::token($_SESSION);
$testId = trim((string)($_GET['test_id'] ?? $_POST['test_id'] ?? ''));

function github_assignment_edit_h(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function github_assignment_edit_redirect(string $testId, string $anchor = ''): never
{
    $url = 'github_assignment_edit.php?test_id=' . urlencode($testId);
    if ($anchor !== '') {
        $url .= '#' . ltrim($anchor, '#');
    }
    header('Location: ' . $url);
    exit;
}

/** @return array{owner:string,repo:string}|null */
function github_assignment_edit_repo(string $url): ?array
{
    return GitHubAssignmentEditorService::parseRepositoryUrl($url);
}

/** @return string */
function github_assignment_edit_token(array $test, array $config): string
{
    $sessionToken = trim((string)($_SESSION['github_access_token'] ?? ''));
    if ($sessionToken !== '') {
        return $sessionToken;
    }
    $cfg = json_decode((string)($test['github_config_json'] ?? '{}'), true);
    $encrypted = is_array($cfg) ? trim((string)($cfg['teacher_token'] ?? '')) : '';
    if ($encrypted === '') {
        return '';
    }
    try {
        return trim((string)EncryptionHelper::decrypt($encrypted));
    } catch (Throwable $e) {
        return '';
    }
}

/** @return list<string> */
function github_assignment_edit_group_ids($db, string $testId, string $userId, array $test): array
{
    $ids = (new GitHubAssignmentGroupRepository($db, $userId))->listGroupIdsForTest($testId);
    $legacy = trim((string)($test['id_gruppo'] ?? ''));
    if ($legacy !== '' && !in_array($legacy, $ids, true)) {
        $ids[] = $legacy;
    }
    return $ids;
}

/** @return array{names:array<string,string>,emails:array<string,string>} */
function github_assignment_edit_runtime_roster($db, array $config, string $userId, array $groupIds): array
{
    $names = [];
    $emails = [];
    $nameService = new RuntimeStudentNameService($db, $userId, $config);
    $profile = $config['user_profile'] ?? [];
    $emailTemplate = (string)($profile['school_student_email_template'] ?? '');
    $emailDomain = (string)($profile['school_email_domain'] ?? '');
    $groupStudents = new TeachingGroupStudentService($db, $userId);
    $integrations = new TeachingGroupIntegrationRepository($db, $userId);

    foreach ($groupIds as $groupId) {
        foreach ($nameService->resolveGroupStudents($groupId) as $student) {
            $id = trim((string)($student['id_studente'] ?? ''));
            $name = trim((string)($student['nome_completo'] ?? $student['nome'] ?? ''));
            if ($id !== '' && $name !== '') {
                $names[$id] = $name;
                $names[strtolower($id)] = $name;
            }
            foreach ((array)($student['external_ids'] ?? []) as $externalIds) {
                foreach ((array)$externalIds as $externalId) {
                    $externalId = trim((string)$externalId);
                    if ($externalId !== '' && $name !== '') {
                        $names[$externalId] = $name;
                        $names[strtolower($externalId)] = $name;
                    }
                }
            }
        }

        $matrix = $groupStudents->matrix($groupId);
        $rosters = [];
        foreach ($integrations->listForGroup($groupId) as $integration) {
            if (($integration['stato'] ?? 'attivo') === 'disattivo') {
                continue;
            }
            $provider = trim((string)($integration['provider'] ?? ''));
            $contextId = trim((string)($integration['external_context_id'] ?? ''));
            if ($provider === '' || $contextId === '') {
                continue;
            }
            try {
                if ($provider === 'google_classroom') {
                    $rosters[$provider] = array_merge(
                        $rosters[$provider] ?? [],
                        (new GoogleClassroomAPI($config))->getCourseStudents($contextId)
                    );
                } elseif ($provider === 'classeviva') {
                    $rosters[$provider] = array_merge(
                        $rosters[$provider] ?? [],
                        (new ClasseVivaAPI($config))->getStudentiClasse($contextId)
                    );
                }
            } catch (Throwable $e) {
                error_log('Errore roster editor assignment ' . $provider . ': ' . $e->getMessage());
            }
        }
        $resolved = (new GitHubAssignmentService($emailTemplate, $emailDomain))
            ->resolveStudents($matrix, $rosters, 'google_classroom');
        foreach ($resolved as $student) {
            $id = trim((string)($student['id_studente'] ?? ''));
            $email = trim((string)($student['email'] ?? ''));
            $name = trim((string)($student['nome'] ?? ''));
            if ($id !== '' && $email !== '') {
                $emails[$id] = $email;
                $emails[strtolower($id)] = $email;
            }
            if ($id !== '' && $name !== '') {
                $names[$id] ??= $name;
                $names[strtolower($id)] ??= $name;
            }
        }
    }

    return ['names' => $names, 'emails' => $emails];
}

if ($testId === '') {
    http_response_code(400);
    exit('test_id mancante');
}

$test = $db->findOne('TEST', 'id_test', $testId);
if (!is_array($test) || strtolower(trim((string)($test['piattaforma'] ?? ''))) !== 'github') {
    http_response_code(404);
    exit('Assignment GitHub non trovato.');
}

$udaId = trim((string)($test['id_uda'] ?? ''));
$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $action = trim((string)($_POST['action'] ?? ''));
        if ((string)($_POST['test_id'] ?? '') !== $testId) {
            throw new RuntimeException('Test non valido.');
        }

        if ($action === 'update_github_test') {
            $punteggio = max(0, (float)($_POST['punteggio_max'] ?? 0));
            $soglia = min(100, max(0, (float)($_POST['soglia_sufficienza'] ?? 0)));
            $updates = [
                'nome' => trim((string)($_POST['nome'] ?? '')),
                'descrizione' => trim((string)($_POST['descrizione'] ?? '')),
                'num_domande' => max(0, (int)($_POST['num_domande'] ?? 0)),
                'durata_minuti' => max(0, (int)($_POST['durata_minuti'] ?? 0)),
                'punteggio_max' => (string)$punteggio,
                'soglia_sufficienza' => (string)$soglia,
                'data_somministrazione' => trim((string)($_POST['data_somministrazione'] ?? '')),
                'ora_consegna' => trim((string)($_POST['ora_consegna'] ?? '')),
                'note' => trim((string)($_POST['note'] ?? '')),
            ];
            if ($updates['nome'] === '') {
                throw new RuntimeException('Il nome del test è obbligatorio.');
            }
            $db->updateRow('TEST', 'id_test', $testId, $updates);
            $test = $db->findOne('TEST', 'id_test', $testId) ?? $test;
            $successMessage = 'Dati generali del test aggiornati.';
        } elseif ($action === 'update_github_links') {
            $idMaps = is_array($_POST['id_map'] ?? null) ? $_POST['id_map'] : [];
            $repositories = is_array($_POST['student_repository_url'] ?? null) ? $_POST['student_repository_url'] : [];
            $emails = is_array($_POST['email_studente'] ?? null) ? $_POST['email_studente'] : [];
            $usernames = is_array($_POST['github_username'] ?? null) ? $_POST['github_username'] : [];
            $existing = [];
            foreach ($db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]) as $link) {
                $idMap = trim((string)($link['id_map'] ?? ''));
                if ($idMap !== '') {
                    $existing[$idMap] = $link;
                }
            }
            $prepared = [];
            $collaboratorRequests = [];
            $seen = [];
            foreach ($idMaps as $index => $rawIdMap) {
                $idMap = trim((string)$rawIdMap);
                if ($idMap === '' || isset($seen[$idMap]) || !isset($existing[$idMap])) {
                    throw new RuntimeException('Associazione studente non valida.');
                }
                $seen[$idMap] = true;
                $old = $existing[$idMap];
                // I campi della tabella usano id_map come chiave per evitare
                // che un riordinamento delle righe possa incrociare studenti
                // e repository/email diversi.
                $repoInput = trim((string)($repositories[$idMap] ?? $repositories[$index] ?? ''));
                $repoParts = $repoInput === '' ? null : github_assignment_edit_repo($repoInput);
                if ($repoInput !== '' && $repoParts === null) {
                    throw new RuntimeException('La repository della riga ' . ($index + 1) . ' non è un URL GitHub valido.');
                }
                $repoUrl = $repoParts === null ? '' : 'https://github.com/' . $repoParts['owner'] . '/' . $repoParts['repo'];
                $emailInput = trim((string)($emails[$idMap] ?? $emails[$index] ?? ''));
                $email = $emailInput === '' ? '' : GitHubAssignmentEditorService::normalizeEmail($emailInput);
                if ($emailInput !== '' && $email === null) {
                    throw new RuntimeException('L’email della riga ' . ($index + 1) . ' non è valida.');
                }
                $usernameInput = trim((string)($usernames[$idMap] ?? $usernames[$index] ?? ''));
                $username = $usernameInput === '' ? '' : GitHubAssignmentEditorService::normalizeUsername($usernameInput);
                if ($usernameInput !== '' && $username === null) {
                    throw new RuntimeException('Lo username GitHub della riga ' . ($index + 1) . ' non è valido.');
                }
                $oldUsername = trim((string)($old['github_username'] ?? ''));
                $oldRepo = trim((string)($old['student_repository_url'] ?? ''));
                $oldAcceptedAt = trim((string)($old['accepted_at'] ?? ''));
                $needsCollaborator = $username !== '' && $repoParts !== null
                    && ($username !== $oldUsername || $repoUrl !== $oldRepo || $oldAcceptedAt === '');
                if ($needsCollaborator) {
                    $collaboratorRequests[] = [
                        'id_map' => $idMap,
                        'owner' => $repoParts['owner'],
                        'repo' => $repoParts['repo'],
                        'username' => $username,
                    ];
                }
                $prepared[] = [
                    'id_map' => $idMap,
                    'student_repository_url' => $repoUrl,
                    'email_studente' => $email ?? '',
                    'github_username' => $username ?? '',
                    'accepted_at' => $username !== '' && $repoParts !== null
                        ? ($needsCollaborator ? date('Y-m-d H:i:s') : ($old['accepted_at'] ?? date('Y-m-d H:i:s')))
                        : null,
                ];
            }

            if ($collaboratorRequests !== []) {
                $token = github_assignment_edit_token($test, $config);
                if ($token === '') {
                    throw new RuntimeException('Token GitHub non disponibile: autorizza GitHub prima di sincronizzare un collaboratore.');
                }
                $githubApi = new GitHubIntegration($config);
                $githubApi->setAccessToken($token);
                foreach ($collaboratorRequests as $request) {
                    try {
                        $githubApi->addCollaborator($request['owner'], $request['repo'], $request['username']);
                    } catch (Throwable $e) {
                        throw new RuntimeException(
                            'Impossibile aggiungere ' . $request['username'] . ' come collaboratore a '
                            . $request['owner'] . '/' . $request['repo'] . ': ' . $e->getMessage(),
                            0,
                            $e
                        );
                    }
                }
            }

            $connection = $db->getConnection();
            $transactional = $connection instanceof PDO && !$connection->inTransaction();
            if ($transactional) {
                $connection->beginTransaction();
            }
            try {
                foreach ($prepared as $row) {
                    $db->updateRow('GITHUB_ASSIGNMENT_STUDENT_LINKS', 'id_map', $row['id_map'], [
                        'student_repository_url' => $row['student_repository_url'],
                        'email_studente' => $row['email_studente'],
                        'github_username' => $row['github_username'],
                        'accepted_at' => $row['accepted_at'],
                    ]);
                }
                if ($transactional) {
                    $connection->commit();
                }
            } catch (Throwable $e) {
                if ($transactional && $connection->inTransaction()) {
                    $connection->rollBack();
                }
                throw $e;
            }
            $successMessage = 'Associazioni studente, repository e account GitHub aggiornate.';
        } else {
            throw new RuntimeException('Azione non riconosciuta.');
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
    }
}

$groupIds = github_assignment_edit_group_ids($db, $testId, $userId, $test);
$runtime = github_assignment_edit_runtime_roster($db, $config, $userId, $groupIds);
$runtimeNames = $runtime['names'];
$runtimeEmails = $runtime['emails'];
$links = $db->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => $testId]);
$rows = [];
foreach ($links as $link) {
    $studentId = trim((string)($link['id_studente'] ?? ''));
    if ($studentId === '') {
        continue;
    }
    $name = $runtimeNames[$studentId] ?? $runtimeNames[strtolower($studentId)] ?? 'Nome non disponibile';
    $overrideEmail = trim((string)($link['email_studente'] ?? ''));
    $runtimeEmail = $runtimeEmails[$studentId] ?? $runtimeEmails[strtolower($studentId)] ?? '';
    $rows[] = [
        'id_map' => trim((string)($link['id_map'] ?? '')),
        'id_studente' => $studentId,
        'nome' => $name,
        'email' => $overrideEmail !== '' ? $overrideEmail : $runtimeEmail,
        'email_override' => $overrideEmail,
        'github_username' => trim((string)($link['github_username'] ?? '')),
        'student_repository_url' => trim((string)($link['student_repository_url'] ?? '')),
        'accepted_at' => trim((string)($link['accepted_at'] ?? '')),
    ];
}

$pageTitle = '<i class="bi bi-github"></i> Modifica assignment GitHub';
$pageSubtitle = 'Test: ' . (string)($test['nome'] ?? $testId);
$headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($udaId) . '#test-' . urlencode($testId) . '"><i class="bi bi-arrow-left"></i> Torna ai test</a>'
    . '<a class="nav-link" href="github_assignment_review.php?test_id=' . urlencode($testId) . '"><i class="bi bi-github"></i> Apri review</a>';
$pageActions = '';
include __DIR__ . '/partials/app_header.php';
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= github_assignment_edit_h(strip_tags($pageTitle)) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .technical-id { font-size: .78rem; color: #6c757d; word-break: break-all; }
        .repo-input { min-width: 18rem; }
        @media (max-width: 768px) { .repo-input { min-width: 12rem; } }
    </style>
</head>
<body>
<div class="container-fluid px-3 px-lg-4 mt-4">
    <?php if ($successMessage !== null): ?><div class="alert alert-success"><i class="bi bi-check-circle"></i> <?= github_assignment_edit_h($successMessage) ?></div><?php endif; ?>
    <?php if ($errorMessage !== null): ?><div class="alert alert-danger"><i class="bi bi-exclamation-triangle"></i> <?= github_assignment_edit_h($errorMessage) ?></div><?php endif; ?>

    <section class="card shadow-sm mb-4" id="test-data">
        <div class="card-header bg-primary text-white"><i class="bi bi-pencil-square"></i> Dati generali del test</div>
        <div class="card-body">
            <form method="post" class="row g-3">
                <input type="hidden" name="csrf_token" value="<?= github_assignment_edit_h($csrfToken) ?>">
                <input type="hidden" name="action" value="update_github_test">
                <input type="hidden" name="test_id" value="<?= github_assignment_edit_h($testId) ?>">
                <div class="col-12">
                    <label for="edit_nome" class="form-label">Nome Test *</label>
                    <input type="text" class="form-control" id="edit_nome" name="nome" value="<?= github_assignment_edit_h($test['nome'] ?? '') ?>" required>
                </div>
                <div class="col-12">
                    <label for="edit_descrizione" class="form-label">Descrizione</label>
                    <textarea class="form-control" id="edit_descrizione" name="descrizione" rows="3"><?= github_assignment_edit_h($test['descrizione'] ?? '') ?></textarea>
                </div>
                <div class="col-md-3"><label for="edit_num_domande" class="form-label">Numero Domande</label><input type="number" min="0" class="form-control" id="edit_num_domande" name="num_domande" value="<?= github_assignment_edit_h($test['num_domande'] ?? 0) ?>"></div>
                <div class="col-md-3"><label for="edit_durata_minuti" class="form-label">Durata (minuti)</label><input type="number" min="0" class="form-control" id="edit_durata_minuti" name="durata_minuti" value="<?= github_assignment_edit_h($test['durata_minuti'] ?? 0) ?>"></div>
                <div class="col-md-3"><label for="edit_punteggio_max" class="form-label">Punteggio Max</label><input type="number" min="0" step="0.1" class="form-control" id="edit_punteggio_max" name="punteggio_max" value="<?= github_assignment_edit_h($test['punteggio_max'] ?? 0) ?>"></div>
                <div class="col-md-3"><label for="edit_soglia_sufficienza" class="form-label">Soglia Sufficienza (%)</label><input type="number" min="0" max="100" step="0.1" class="form-control" id="edit_soglia_sufficienza" name="soglia_sufficienza" value="<?= github_assignment_edit_h($test['soglia_sufficienza'] ?? 0) ?>"></div>
                <div class="col-md-4"><label for="edit_data_somministrazione" class="form-label">Data Somministrazione</label><input type="date" class="form-control" id="edit_data_somministrazione" name="data_somministrazione" value="<?= github_assignment_edit_h($test['data_somministrazione'] ?? '') ?>"></div>
                <div class="col-md-4"><label for="edit_ora_consegna" class="form-label">Ora Consegna</label><input type="time" class="form-control" id="edit_ora_consegna" name="ora_consegna" value="<?= github_assignment_edit_h($test['ora_consegna'] ?? '') ?>"></div>
                <div class="col-md-4"><label class="form-label">Piattaforma</label><input type="text" class="form-control" value="GITHUB" disabled></div>
                <div class="col-12"><label for="edit_note" class="form-label">Note</label><textarea class="form-control" id="edit_note" name="note" rows="2"><?= github_assignment_edit_h($test['note'] ?? '') ?></textarea></div>
                <div class="col-12"><button type="submit" class="btn btn-primary"><i class="bi bi-save"></i> Salva dati test</button></div>
            </form>
        </div>
    </section>

    <section class="card shadow-sm" id="student-links">
        <div class="card-header bg-dark text-white"><i class="bi bi-people"></i> Associazioni studenti e repository</div>
        <div class="card-body">
            <div class="alert alert-info small"><i class="bi bi-info-circle"></i> Il nome dello studente e il suo ID sono ricavati dal roster e non sono modificabili. L’email inserita qui diventa l’email attesa dal link di accettazione. Se username e repository sono valorizzati, il salvataggio aggiunge lo username come collaboratore GitHub.</div>
            <form method="post">
                <input type="hidden" name="csrf_token" value="<?= github_assignment_edit_h($csrfToken) ?>">
                <input type="hidden" name="action" value="update_github_links">
                <input type="hidden" name="test_id" value="<?= github_assignment_edit_h($testId) ?>">
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle">
                        <thead><tr><th>Studente</th><th>ID tecnico</th><th>Email assignment</th><th>Nome GitHub</th><th>Repository</th><th>Stato</th></tr></thead>
                        <tbody>
                        <?php if ($rows === []): ?>
                            <tr><td colspan="6" class="text-muted">Nessuna associazione studente presente.</td></tr>
                        <?php else: foreach ($rows as $index => $row): ?>
                            <tr>
                                <td>
                                    <input type="hidden" name="id_map[]" value="<?= github_assignment_edit_h($row['id_map']) ?>">
                                    <strong><?= github_assignment_edit_h($row['nome']) ?></strong>
                                    <div class="small text-muted">riga <?= (int)$index + 1 ?></div>
                                </td>
                                <td><code class="technical-id"><?= github_assignment_edit_h($row['id_studente']) ?></code></td>
                                <td><input type="email" class="form-control" name="email_studente[<?= github_assignment_edit_h($row['id_map']) ?>]" value="<?= github_assignment_edit_h($row['email']) ?>" placeholder="email@email.it"></td>
                                <td><input type="text" class="form-control" name="github_username[<?= github_assignment_edit_h($row['id_map']) ?>]" value="<?= github_assignment_edit_h($row['github_username']) ?>" pattern="[A-Za-z0-9_.-]{1,39}" maxlength="39" placeholder="username"></td>
                                <td><input type="url" class="form-control repo-input" name="student_repository_url[<?= github_assignment_edit_h($row['id_map']) ?>]" value="<?= github_assignment_edit_h($row['student_repository_url']) ?>" placeholder="https://github.com/org/repo"></td>
                                <td><?php if ($row['github_username'] !== '' && $row['accepted_at'] !== ''): ?><span class="badge text-bg-success">Associato</span><?php else: ?><span class="badge text-bg-secondary">Da accettare</span><?php endif; ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
                <button type="submit" class="btn btn-dark"><i class="bi bi-save"></i> Salva associazioni</button>
            </form>
        </div>
    </section>
</div>
</body>
</html>
