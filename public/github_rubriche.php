<?php

declare(strict_types=1);

/** Gestione, selezione e modifica delle rubriche di valutazione GitHub. */

require_once '../bootstrap.php';

if (PHP_SAPI !== 'cli') {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

use App\Core\Database\DatabaseFactory;
use App\Core\GitHubRubricTemplateService;
use App\Core\Security\Csrf;
use App\Integration\GoogleDriveAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$templateService = new GitHubRubricTemplateService();
$csrfToken = Csrf::token($_SESSION);

function githubRubricH(mixed $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function distinctGithubRubriche(array $allRows, array $githubTestIdSet): array
{
    $rubriche = [];
    foreach ($allRows as $row) {
        $id = trim((string)($row['id_rubrica'] ?? ''));
        if ($id === '') continue;
        $note = trim((string)($row['note'] ?? ''));
        if (!isset($githubTestIdSet[$id]) && $note !== 'github_rubric') continue;
        if (!isset($rubriche[$id])) {
            $rubriche[$id] = [
                'id_rubrica' => $id,
                'id_uda' => (string)($row['id_uda'] ?? ''),
                'count' => 0,
                'note' => $note !== '' ? $note : 'github_rubric',
            ];
        }
        $rubriche[$id]['count']++;
    }
    ksort($rubriche, SORT_NATURAL | SORT_FLAG_CASE);
    return array_values($rubriche);
}

function githubRubricBase64UrlEncode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function githubRubricBase64UrlDecode(string $value): ?string
{
    if ($value === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $value)) return null;
    $decoded = base64_decode(strtr($value, '-_', '+/') . str_repeat('=', (4 - strlen($value) % 4) % 4), true);
    return $decoded === false ? null : $decoded;
}

/** @param list<array<string,string>> $rows */
function encodeGithubRubricTemporaryRows(array $rows, string $csrfToken): array
{
    $payload = githubRubricBase64UrlEncode((string)json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    return [$payload, hash_hmac('sha256', $payload, $csrfToken)];
}

/** @return list<array<string,string>> */
function decodeGithubRubricTemporaryRows(mixed $payload, mixed $signature, string $csrfToken, GitHubRubricTemplateService $service): array
{
    if (!is_string($payload) || !is_string($signature) || $payload === '' || !hash_equals(hash_hmac('sha256', $payload, $csrfToken), $signature)) {
        throw new RuntimeException('Template temporaneo non valido o scaduto.');
    }
    $decoded = githubRubricBase64UrlDecode($payload);
    if ($decoded === null) throw new RuntimeException('Template temporaneo non leggibile.');
    try {
        $rows = json_decode($decoded, true, 32, JSON_THROW_ON_ERROR);
    } catch (Throwable $exception) {
        throw new RuntimeException('Template temporaneo non leggibile.', 0, $exception);
    }
    return $service->validateRows($rows);
}

$message = trim((string)($_GET['saved'] ?? '')) !== '' ? 'Rubrica salvata.' : '';
$error = '';
$selectedTestId = trim((string)($_GET['test_id'] ?? $_POST['test_id'] ?? ''));
$selectedRubricId = trim((string)($_GET['id_rubrica'] ?? $_POST['id_rubrica'] ?? ''));
$selectedSource = trim((string)($_GET['source'] ?? $_POST['source'] ?? ''));
$selectedUdaId = '';
$selectedTest = null;
$editingRows = [];
$temporaryRows = [];
$temporaryTemplateName = trim((string)($_POST['temporary_template_name'] ?? ''));
$temporaryPayload = trim((string)($_POST['temporary_template_payload'] ?? ''));
$temporarySignature = trim((string)($_POST['temporary_template_signature'] ?? ''));

if ($selectedTestId !== '') {
    $selectedTest = $dbAdapter->findOne('TEST', 'id_test', $selectedTestId);
    if ($selectedTest) {
        $selectedUdaId = (string)($selectedTest['id_uda'] ?? '');
        if ($selectedRubricId === '') $selectedRubricId = $selectedTestId;
    }
}
if ($selectedTestId === '' && $selectedRubricId !== '') {
    $candidate = $dbAdapter->findOne('TEST', 'id_test', $selectedRubricId);
    if ($candidate && strtolower((string)($candidate['piattaforma'] ?? '')) === 'github') {
        $selectedTestId = $selectedRubricId;
        $selectedTest = $candidate;
        $selectedUdaId = (string)($candidate['id_uda'] ?? '');
    }
}

$action = trim((string)($_POST['action'] ?? ''));
try {
    if ($action !== '') Csrf::assertValid($_SESSION, Csrf::providedToken($_POST, $_SERVER));

    if ($action === 'delete') {
        $idRubrica = trim((string)($_POST['id_rubrica'] ?? ''));
        if ($idRubrica === '') throw new RuntimeException('Rubrica non specificata.');
        if ($dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $idRubrica]) === []) throw new RuntimeException('Rubrica non trovata.');
        $dbAdapter->deleteRow('RUBRICA', $idRubrica, 'id_rubrica');
        $message = 'Rubrica eliminata.';
        if ($selectedRubricId === $idRubrica) { $selectedRubricId = ''; $selectedSource = ''; $editingRows = []; }
    }

    if ($action === 'load_template') {
        $selectedSource = trim((string)($_POST['source'] ?? ''));
        if ($selectedSource === '') throw new RuntimeException('Seleziona una rubrica o un template.');
    }

    if ($action === 'load_custom_template') {
        $sheetUrl = trim((string)($_POST['rubrica_sheet_url'] ?? ''));
        $temporaryPath = null;
        $temporaryOriginalName = null;
        try {
            if ($sheetUrl !== '') {
                $driveFileId = null;
                if (preg_match('~/d/([a-zA-Z0-9_-]+)~', $sheetUrl, $match)) $driveFileId = $match[1];
                else {
                    $parts = parse_url($sheetUrl);
                    if (is_array($parts) && !empty($parts['query'])) { parse_str($parts['query'], $query); $driveFileId = isset($query['id']) ? trim((string)$query['id']) : null; }
                }
                if (!$driveFileId || !preg_match('/^[a-zA-Z0-9_-]{10,}$/', $driveFileId)) throw new RuntimeException('Link Google Sheet non valido.');
                $temporaryPath = tempnam(sys_get_temp_dir(), 'github-rubric-');
                if ($temporaryPath === false) throw new RuntimeException('Impossibile creare il file temporaneo.');
                $temporaryXlsxPath = $temporaryPath . '.xlsx';
                @rename($temporaryPath, $temporaryXlsxPath);
                $temporaryPath = $temporaryXlsxPath;
                $meta = (new GoogleDriveAPI($config))->downloadFileAsXlsx($driveFileId, $temporaryPath);
                $allowedMime = ['application/vnd.google-apps.spreadsheet', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/vnd.ms-excel'];
                if (!in_array((string)($meta['mimeType'] ?? ''), $allowedMime, true)) throw new RuntimeException('Il file Google non è un foglio Excel compatibile.');
                $temporaryOriginalName = 'Rubrica GitHub Google Sheet.xlsx';
            } elseif (isset($_FILES['rubrica_file']) && (int)($_FILES['rubrica_file']['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $temporaryPath = (string)($_FILES['rubrica_file']['tmp_name'] ?? '');
                $temporaryOriginalName = (string)($_FILES['rubrica_file']['name'] ?? 'rubrica.xlsx');
                if (!is_file($temporaryPath)) throw new RuntimeException('Upload non leggibile.');
            } else throw new RuntimeException('Seleziona un file Excel oppure inserisci un link Google Sheet.');

            $temporaryRows = $templateService->parseFile((string)$temporaryPath, '', '', $temporaryOriginalName);
            $temporaryTemplateName = $temporaryOriginalName ?: 'Rubrica GitHub temporanea';
            [$temporaryPayload, $temporarySignature] = encodeGithubRubricTemporaryRows($temporaryRows, $csrfToken);
            $selectedSource = 'temporary';
            $message = 'Template personalizzato validato e caricato temporaneamente.';
        } finally {
            if ($temporaryPath !== null && is_file($temporaryPath)) @unlink($temporaryPath);
        }
    }

    if ($action === 'save') {
        $targetRubricId = $selectedTestId !== '' ? $selectedTestId : ($selectedSource !== '' && str_starts_with($selectedSource, 'db:') ? substr($selectedSource, 3) : $selectedRubricId);
        if ($targetRubricId === '') throw new RuntimeException('Seleziona un Test GitHub prima di salvare la rubrica.');
        $toInsert = $templateService->validateRows($_POST['rows'] ?? [], $targetRubricId, $selectedUdaId);
        $dbAdapter->deleteRow('RUBRICA', $targetRubricId, 'id_rubrica');
        foreach ($toInsert as $row) $dbAdapter->insertRow('RUBRICA', $row);
        header('Location: github_rubriche.php?test_id=' . urlencode($selectedTestId ?: $targetRubricId) . '&id_rubrica=' . urlencode($targetRubricId) . '&source=db:' . urlencode($targetRubricId) . '&saved=1');
        exit;
    }
} catch (Throwable $exception) { $error = $exception->getMessage(); }

$allTests = $dbAdapter->findAll('TEST');
$githubTests = array_values(array_filter($allTests, static fn(array $test): bool => strtolower((string)($test['piattaforma'] ?? '')) === 'github'));
usort($githubTests, static fn(array $a, array $b): int => strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? '')));
$githubTestIdSet = [];
foreach ($githubTests as $testRow) { $testId = trim((string)($testRow['id_test'] ?? '')); if ($testId !== '') $githubTestIdSet[$testId] = true; }
$rubricheList = distinctGithubRubriche($dbAdapter->findAll('RUBRICA'), $githubTestIdSet);
$persistentTemplates = $templateService->listPersistentTemplates();

if ($selectedSource === '' && $selectedRubricId !== '' && $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $selectedRubricId]) !== []) $selectedSource = 'db:' . $selectedRubricId;
if ($selectedSource === 'temporary') {
    try { $temporaryRows = decodeGithubRubricTemporaryRows($temporaryPayload, $temporarySignature, $csrfToken, $templateService); }
    catch (Throwable $exception) { $error = $error !== '' ? $error : $exception->getMessage(); $selectedSource = ''; $temporaryRows = []; }
}
if ($selectedSource !== '' && str_starts_with($selectedSource, 'db:')) {
    $selectedRubricId = substr($selectedSource, 3);
    $editingRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $selectedRubricId]);
    if ($selectedUdaId === '' && $editingRows !== []) $selectedUdaId = (string)($editingRows[0]['id_uda'] ?? '');
} elseif ($selectedSource !== '' && str_starts_with($selectedSource, 'file:')) {
    foreach ($persistentTemplates as $template) if ($template['id'] === $selectedSource) { $editingRows = $template['rows']; $temporaryTemplateName = $template['name']; break; }
    if ($editingRows === []) $error = $error !== '' ? $error : 'Template persistente non trovato o non conforme.';
} elseif ($selectedSource === 'temporary') $editingRows = $temporaryRows;
if ($selectedTestId !== '' && $selectedSource === '' && $selectedRubricId !== '') { $candidateRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $selectedRubricId]); if ($candidateRows !== []) { $selectedSource = 'db:' . $selectedRubricId; $editingRows = $candidateRows; } }
if ($temporaryRows !== [] && $selectedSource === 'temporary') [$temporaryPayload, $temporarySignature] = encodeGithubRubricTemporaryRows($temporaryRows, $csrfToken);
$templateSelection = $selectedSource !== '';
$targetRubricId = $selectedTestId !== '' ? $selectedTestId : ($selectedSource !== '' && str_starts_with($selectedSource, 'db:') ? substr($selectedSource, 3) : '');
$noRubricForSelectedTest = $selectedTestId !== '' && $selectedSource === '' && $editingRows === [];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>Rubriche GitHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"><link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .rubric-editor-table { min-width: 2000px; }.rubric-editor-table td,.rubric-editor-table th{min-height:140px;vertical-align:top;padding:.25rem}.rubric-editor-table textarea.form-control{min-height:140px;resize:vertical}.rubric-editor-table th.level-col,.rubric-editor-table td.level-col{width:200px}.rubric-editor-table th.level5-col,.rubric-editor-table td.level5-col{width:160px}.rubric-editor-table input.form-control-sm,.rubric-editor-table select.form-select-sm{padding:.2rem .35rem}.rubric-list-table td{white-space:nowrap}.rubric-list-table td.note-cell{white-space:normal}.template-source-file{background:#f8fbff}.template-source-temporary{background:#fff8e1}.template-source-selected{background-color:#dff3ff !important}
    </style>
</head>
<body>
<?php $pageTitle = '<i class="bi bi-github"></i> Rubriche GitHub'; $pageSubtitle = 'Seleziona un test e carica una rubrica esistente o un template conforme.'; ob_start(); if ($selectedTestId !== ''): ?><a class="btn btn-outline-light btn-sm" href="github_assignment_review.php?test_id=<?= githubRubricH($selectedTestId) ?>"><i class="bi bi-arrow-left"></i> Torna al test</a><?php endif; ?><?php $headerActions = ob_get_clean(); include __DIR__ . '/partials/app_header.php'; ?>
<div class="container mt-4">
    <?php if ($message !== ''): ?><div class="alert alert-success"><?= githubRubricH($message) ?></div><?php endif; ?><?php if ($error !== ''): ?><div class="alert alert-danger"><?= githubRubricH($error) ?></div><?php endif; ?>
    <div class="card mb-4" id="githubTestSelectionPanel">
        <div class="card-header bg-light"><strong>Selezione Test GitHub</strong></div>
        <div class="card-body">
            <form method="GET" id="githubTestSelectionForm" class="row g-2 align-items-end">
                <div class="col-lg-9">
                    <label class="form-label" for="selectTest">Test GitHub</label>
                    <select class="form-select" name="test_id" id="selectTest">
                        <option value="">-- Seleziona test GitHub --</option>
                        <?php foreach ($githubTests as $testRow): $tid=(string)($testRow['id_test']??'');$tname=(string)($testRow['nome']??$tid);$tuda=(string)($testRow['id_uda']??''); ?>
                            <option value="<?= githubRubricH($tid) ?>" <?= $tid === $selectedTestId ? 'selected' : '' ?>><?= githubRubricH($tname) ?> — <?= githubRubricH($tid) ?> — UDA <?= githubRubricH($tuda) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-lg-3">
                    <button class="btn btn-outline-primary w-100" type="submit"><i class="bi bi-arrow-repeat"></i> Ricarica rubrica associata al test</button>
                </div>
            </form>
        </div>
    </div>
    <?php if ($noRubricForSelectedTest): ?>
        <div class="alert alert-warning d-flex align-items-start gap-2" role="status">
            <i class="bi bi-info-circle-fill mt-1"></i>
            <div>Questo test GitHub non ha ancora una rubrica associata. Seleziona una rubrica disponibile oppure caricane una con il pulsante qui sotto.</div>
        </div>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <strong>Rubriche esistenti e template disponibili</strong>
            <button type="button" class="btn btn-outline-primary btn-sm" data-bs-toggle="modal" data-bs-target="#github_rubric_template_modal">
                <i class="bi bi-upload"></i> Carica Rubrica Personalizzata
            </button>
        </div>
        <div class="card-body">
            <form method="POST" id="templateSelectionForm">
                <input type="hidden" name="action" value="load_template">
                <input type="hidden" name="_csrf_token" value="<?= githubRubricH($csrfToken) ?>">
                <input type="hidden" name="test_id" value="<?= githubRubricH($selectedTestId) ?>">
                <?php if ($temporaryRows !== []): ?>
                    <input type="hidden" name="temporary_template_payload" value="<?= githubRubricH($temporaryPayload) ?>">
                    <input type="hidden" name="temporary_template_signature" value="<?= githubRubricH($temporarySignature) ?>">
                    <input type="hidden" name="temporary_template_name" value="<?= githubRubricH($temporaryTemplateName) ?>">
                <?php endif; ?>
                <p class="form-text mb-3">Seleziona una riga per caricare la rubrica o il template nell’editor. La riga selezionata viene evidenziata in azzurro.</p>
            </form>
            <?php if ($rubricheList === [] && $persistentTemplates === [] && $temporaryRows === []): ?>
                <div class="text-muted">Nessuna rubrica o template conforme trovato.</div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-striped align-middle rubric-list-table mb-0">
                        <thead><tr><th scope="col">Seleziona</th><th scope="col">Origine</th><th scope="col">Nome / id</th><th scope="col"># indicatori</th><th scope="col">Note</th><th scope="col" class="text-end">Azioni</th></tr></thead>
                        <tbody>
                        <?php foreach ($rubricheList as $rubric): ?>
                            <?php $source = 'db:' . $rubric['id_rubrica']; ?>
                            <tr class="<?= $selectedSource === $source ? 'template-source-selected' : '' ?>">
                                <td>
                                    <input class="form-check-input template-source-radio" form="templateSelectionForm" type="radio" name="source" value="<?= githubRubricH($source) ?>" <?= $selectedSource === $source ? 'checked' : '' ?> aria-label="Seleziona rubrica <?= githubRubricH($rubric['id_rubrica']) ?>">
                                </td>
                                <td><span class="badge text-bg-secondary">Database</span></td>
                                <td class="font-monospace"><?= githubRubricH($rubric['id_rubrica']) ?></td>
                                <td><?= (int)$rubric['count'] ?></td>
                                <td class="note-cell text-muted small"><?= githubRubricH($rubric['note']) ?></td>
                                <td class="text-end">
                                    <button type="button" class="btn btn-sm btn-outline-danger delete-rubric-button" data-rubric-id="<?= githubRubricH($rubric['id_rubrica']) ?>" data-csrf-token="<?= githubRubricH($csrfToken) ?>" title="Elimina rubrica"><i class="bi bi-trash"></i></button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php foreach ($persistentTemplates as $template): ?>
                            <?php $source = $template['id']; ?>
                            <tr class="template-source-file <?= $selectedSource === $source ? 'template-source-selected' : '' ?>">
                                <td>
                                    <input class="form-check-input template-source-radio" form="templateSelectionForm" type="radio" name="source" value="<?= githubRubricH($source) ?>" <?= $selectedSource === $source ? 'checked' : '' ?> aria-label="Seleziona template <?= githubRubricH($template['name']) ?>">
                                </td>
                                <td><span class="badge text-bg-info">Materiale</span></td>
                                <td><?= githubRubricH($template['name']) ?></td>
                                <td><?= (int)$template['count'] ?></td>
                                <td class="note-cell text-muted small">Template Excel conforme</td>
                                <td class="text-end">
                                    <a class="btn btn-sm btn-outline-primary" href="<?= githubRubricH(app_url('Materiale/' . rawurlencode((string)$template['file_name']))) ?>" download title="Scarica il template Excel conforme">
                                        <i class="bi bi-download"></i> Scarica
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($temporaryRows !== []): ?>
                            <tr class="template-source-temporary <?= $selectedSource === 'temporary' ? 'template-source-selected' : '' ?>">
                                <td>
                                    <input class="form-check-input template-source-radio" form="templateSelectionForm" type="radio" name="source" value="temporary" <?= $selectedSource === 'temporary' ? 'checked' : '' ?> aria-label="Seleziona template temporaneo">
                                </td>
                                <td><span class="badge text-bg-warning">Temporanea</span></td>
                                <td><?= githubRubricH($temporaryTemplateName ?: 'Rubrica personalizzata') ?></td>
                                <td><?= count($temporaryRows) ?></td>
                                <td class="note-cell text-muted small">Scompare al ricaricamento della pagina</td>
                                <td class="text-end">—</td>
                            </tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="card mb-4"><div class="card-header bg-light"><strong>Editor rubrica</strong><?php if ($selectedSource !== ''): ?><span class="text-muted small ms-2">Sorgente: <?= githubRubricH($selectedSource) ?></span><?php endif; ?></div><div class="card-body">
        <?php if ($editingRows === []): ?><div class="text-muted">Seleziona una rubrica o un template dal pannello superiore per caricarlo nell’editor.</div><?php else: ?><?php if ($targetRubricId === '' && !str_starts_with($selectedSource, 'db:')): ?><div class="alert alert-warning py-2">Seleziona un Test GitHub per poter salvare questa rubrica.</div><?php endif; ?><form method="POST"><input type="hidden" name="action" value="save"><input type="hidden" name="_csrf_token" value="<?= githubRubricH($csrfToken) ?>"><input type="hidden" name="test_id" value="<?= githubRubricH($selectedTestId) ?>"><input type="hidden" name="id_rubrica" value="<?= githubRubricH($targetRubricId) ?>"><input type="hidden" name="source" value="<?= githubRubricH($selectedSource) ?>"><?php if ($temporaryRows !== [] && $selectedSource === 'temporary'): ?><input type="hidden" name="temporary_template_payload" value="<?= githubRubricH($temporaryPayload) ?>"><input type="hidden" name="temporary_template_signature" value="<?= githubRubricH($temporarySignature) ?>"><input type="hidden" name="temporary_template_name" value="<?= githubRubricH($temporaryTemplateName) ?>"><?php endif; ?><div class="table-responsive"><table class="table table-striped align-top rubric-editor-table"><thead><tr><th style="width:180px">Indicatore</th><th style="width:220px">Descrizione</th><th class="level-col">Livello 1</th><th class="level-col">Livello 2</th><th class="level-col">Livello 3</th><th class="level-col">Livello 4</th><th class="level5-col">Livello 5</th><th style="width:80px">Peso</th><th style="width:80px">Ordine</th><th style="width:140px">Pubblicato</th><th style="width:220px">Note</th><th style="width:180px">Annotazione CV</th><th style="width:180px">Data pubbl.</th><th style="width:60px"></th></tr></thead><tbody id="rowsBody">
            <?php foreach (array_values($editingRows) as $index => $row): ?><tr><td><textarea class="form-control indicatore-text" name="rows[<?= $index ?>][nome_indicatore]"><?= githubRubricH($row['nome_indicatore']??'') ?></textarea></td><td><textarea class="form-control" name="rows[<?= $index ?>][descrizione]"><?= githubRubricH($row['descrizione']??'') ?></textarea></td><td class="level-col"><textarea class="form-control" name="rows[<?= $index ?>][livello_1_desc]"><?= githubRubricH($row['livello_1_desc']??'') ?></textarea></td><td class="level-col"><textarea class="form-control" name="rows[<?= $index ?>][livello_2_desc]"><?= githubRubricH($row['livello_2_desc']??'') ?></textarea></td><td class="level-col"><textarea class="form-control" name="rows[<?= $index ?>][livello_3_desc]"><?= githubRubricH($row['livello_3_desc']??'') ?></textarea></td><td class="level-col"><textarea class="form-control" name="rows[<?= $index ?>][livello_4_desc]"><?= githubRubricH($row['livello_4_desc']??'') ?></textarea></td><td class="level5-col"><textarea class="form-control" name="rows[<?= $index ?>][livello_5_desc]"><?= githubRubricH($row['livello_5_desc']??'') ?></textarea></td><td><input class="form-control form-control-sm" name="rows[<?= $index ?>][peso]" value="<?= githubRubricH($row['peso']??'1') ?>"></td><td><input class="form-control form-control-sm" name="rows[<?= $index ?>][ordine]" value="<?= githubRubricH($row['ordine']??(string)($index+1)) ?>"></td><td><select class="form-select form-select-sm" name="rows[<?= $index ?>][pubblicato]"><option value="0" selected>0</option><option value="1" <?= ((string)($row['pubblicato']??'0')==='1')?'selected':'' ?>>1</option></select></td><td><input class="form-control form-control-sm" name="rows[<?= $index ?>][note]" value="<?= githubRubricH($row['note']??'github_rubric') ?>"></td><td><input class="form-control form-control-sm" name="rows[<?= $index ?>][id_annotazione_cv]" value="<?= githubRubricH($row['id_annotazione_cv']??'') ?>"></td><td><input class="form-control form-control-sm" name="rows[<?= $index ?>][data_pubblicazione]" value="<?= githubRubricH($row['data_pubblicazione']??'') ?>"></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x-lg"></i></button></td></tr><?php endforeach; ?>
        </tbody></table></div><div class="d-flex gap-2"><button type="button" class="btn btn-outline-primary" id="addRowBtn"><i class="bi bi-plus-lg"></i> Aggiungi indicatore</button><button type="submit" class="btn btn-success" <?= $targetRubricId === '' ? 'disabled' : '' ?>><i class="bi bi-save"></i> Salva rubrica</button></div></form><?php endif; ?></div></div>
</div>
<div class="modal fade" id="github_rubric_template_modal" tabindex="-1" aria-labelledby="githubRubricTemplateModalLabel" aria-hidden="true"><div class="modal-dialog"><div class="modal-content"><div class="modal-header"><h5 class="modal-title" id="githubRubricTemplateModalLabel">Carica Rubrica Personalizzata</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button></div><form method="POST" enctype="multipart/form-data"><div class="modal-body"><input type="hidden" name="action" value="load_custom_template"><input type="hidden" name="_csrf_token" value="<?= githubRubricH($csrfToken) ?>"><input type="hidden" name="test_id" value="<?= githubRubricH($selectedTestId) ?>"><div class="mb-3"><label class="form-label" for="rubrica_sheet_url">Link Google Sheet</label><input class="form-control" type="url" id="rubrica_sheet_url" name="rubrica_sheet_url" placeholder="https://docs.google.com/spreadsheets/d/..."><div class="form-text">In alternativa al file Excel.</div></div><div class="text-center text-muted small mb-3">oppure</div><div class="mb-3"><label class="form-label" for="rubrica_file">File Excel (.xlsx)</label><input class="form-control" type="file" id="rubrica_file" name="rubrica_file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"></div><div class="small text-muted">Il formato viene verificato prima dell’uso. <a href="<?= githubRubricH($templateService->defaultTemplateUrl()) ?>" download>Scarica il template vuoto</a>.</div></div><div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button><button type="submit" class="btn btn-primary"><i class="bi bi-check2"></i> Valida e carica</button></div></form></div></div></div>
<template id="rowTemplate"><tr><td><textarea class="form-control indicatore-text" name=""></textarea></td><td><textarea class="form-control" name=""></textarea></td><td class="level-col"><textarea class="form-control" name=""></textarea></td><td class="level-col"><textarea class="form-control" name=""></textarea></td><td class="level-col"><textarea class="form-control" name=""></textarea></td><td class="level-col"><textarea class="form-control" name=""></textarea></td><td class="level5-col"><textarea class="form-control" name=""></textarea></td><td><input class="form-control form-control-sm" name="" value="1"></td><td><input class="form-control form-control-sm" name="" value=""></td><td><select class="form-select form-select-sm" name=""><option value="0" selected>0</option><option value="1">1</option></select></td><td><input class="form-control form-control-sm" name="" value="github_rubric"></td><td><input class="form-control form-control-sm" name="" value=""></td><td><input class="form-control form-control-sm" name="" value=""></td><td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x-lg"></i></button></td></tr></template>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script><script>
(()=>{const fields=['nome_indicatore','descrizione','livello_1_desc','livello_2_desc','livello_3_desc','livello_4_desc','livello_5_desc','peso','ordine','pubblicato','note','id_annotazione_cv','data_pubblicazione'];const form=document.getElementById('templateSelectionForm'),load=document.getElementById('loadTemplateButton');const refresh=()=>{if(form&&load)load.disabled=!form.querySelector('input[name="source"]:checked')};document.querySelectorAll('.template-source-radio').forEach(r=>r.addEventListener('change',refresh));refresh();const reindex=()=>{const body=document.getElementById('rowsBody');if(!body)return;body.querySelectorAll('tr').forEach((tr,i)=>tr.querySelectorAll('input,textarea,select').forEach((el,j)=>{if(fields[j]){el.name=`rows[${i}][${fields[j]}]`;if(fields[j]==='ordine'&&!el.value)el.value=String(i+1)}}))};const add=document.getElementById('addRowBtn');if(add)add.addEventListener('click',()=>{const t=document.getElementById('rowTemplate'),b=document.getElementById('rowsBody');if(t&&b){b.appendChild(t.content.cloneNode(true));reindex()}});const body=document.getElementById('rowsBody');if(body){body.addEventListener('click',e=>{const b=e.target.closest('.remove-row');if(b){b.closest('tr')?.remove();reindex()}});reindex()}})();
</script><script>
(() => {
    const testForm = document.getElementById('githubTestSelectionForm');
    const testSelect = document.getElementById('selectTest');
    if (testForm && testSelect) testSelect.addEventListener('change', () => testForm.submit());
    const sourceForm = document.getElementById('templateSelectionForm');
    const sourceRadios = sourceForm ? document.querySelectorAll('.template-source-radio') : [];
    const refreshSelectedRow = () => sourceRadios.forEach((radio) => {
        const row = radio.closest('tr');
        if (row) row.classList.toggle('template-source-selected', radio.checked);
    });
    sourceRadios.forEach((radio) => radio.addEventListener('change', () => {
        refreshSelectedRow();
        sourceForm.submit();
    }));
    refreshSelectedRow();

    document.querySelectorAll('.delete-rubric-button').forEach((button) => button.addEventListener('click', () => {
        if (!window.confirm('Eliminare la rubrica?')) return;
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = window.location.href.split('?')[0];
        [['action', 'delete'], ['_csrf_token', button.dataset.csrfToken || ''], ['id_rubrica', button.dataset.rubricId || '']].forEach(([name, value]) => {
            const input = document.createElement('input');
            input.type = 'hidden';
            input.name = name;
            input.value = value;
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
    }));
})();
</script></body></html>
