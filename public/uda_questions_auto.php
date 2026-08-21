<?php
/**
 * Generazione Automatica Domande con AI (UI semplificata)
 */
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Core\ObiettiviManager;
use App\Core\UserIntegrationManager;
use App\Utils\UdaMetadataHelper;
use App\Core\Security\Csrf;

$db = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$obiettiviManager = new ObiettiviManager($db, $config);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
$csrfSession = &$_SESSION;
$csrfToken = Csrf::token($csrfSession);
$profName = '';
$defaultProvider = '';
if (!empty($_SESSION['user_id'])) {
    try {
        $integrationManager = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
        $profileConf = $integrationManager->getConfig('profile') ?? [];
        $profName = $profileConf['prof_name'] ?? '';
        $aiConf = $integrationManager->getConfig('ai') ?? [];
        $defaultProvider = $aiConf['provider'] ?? '';
    } catch (Exception $e) {
        $profName = '';
        $defaultProvider = '';
    }
}
if (!empty($_SESSION['user_id'])) {
    try {
        $integrationManager = new UserIntegrationManager($db, (string)$_SESSION['user_id']);
        $profileConf = $integrationManager->getConfig('profile') ?? [];
        $profName = $profileConf['prof_name'] ?? '';
    } catch (Exception $e) {
        $profName = '';
    }
}

$udaId = $_GET['id_uda'] ?? $_POST['id_uda'] ?? null;
$error = null;
$uda = null;
$obiettivi = [];
$obiettiviPreview = [];
$obiettiviList = [];
$udaArgomento = '';
$udaDisciplina = '';
$udaDestinatari = '';
$udaMetodologia = '';
$attachmentsList = [];
$attachmentsSeen = [];

if (!$udaId) {
    header('Location: index.php');
    exit;
}

try {
    $uda = $udaManager->getUDAById($udaId);
    if (!$uda) {
        throw new Exception('UDA non trovata');
    }

    $obiettivi = $obiettiviManager->getObiettiviPerUDA($udaId);
    $obiettiviPreview = [];
    foreach ($obiettivi as $obj) {
        $desc = trim($obj->descrizione ?? '');
        if ($desc === '') {
            continue;
        }
        $id = $obj->id ?? spl_object_hash($obj);
        $short = mb_substr($desc, 0, 160) . (mb_strlen($desc) > 160 ? '…' : '');
        $obiettiviPreview[] = $short;
        $obiettiviList[] = ['id' => $id, 'text' => $short];
    }

    $udaArgomento   = $uda->argomento ?? '';
    $udaDisciplina  = $uda->disciplina ?? ($uda->materia ?? '');
    $udaDestinatari = $uda->classi_target ?? ($uda->destinatari ?? ($uda->classi ?? ''));
    $udaMetodologia = $uda->metodologia ?? '';

    // Materiali UDA inclusi automaticamente (DB + file storage) con deduplica
    $attachmentsList = [];
    $attachmentsSeen = [];

    $udaComplete = $udaManager->getUDAComplete($udaId);
    $assignedTarget = UdaMetadataHelper::classTargetFromAssignments($udaComplete['classi_assegnate'] ?? []);
    if ($assignedTarget !== '') {
        $udaDestinatari = $assignedTarget;
    }
    if (!empty($udaComplete['materiali'])) {
        foreach ($udaComplete['materiali'] as $mat) {
            $name = $mat['nome'] ?? 'Allegato';
            if (!empty($mat['file_id_drive'])) {
                $key = 'drive:' . $mat['file_id_drive'];
                if (!isset($attachmentsSeen[$key])) {
                    $attachmentsSeen[$key] = true;
                    $attachmentsList[] = ['material_id' => (string)($mat['id_materiale'] ?? ''), 'drive_file_id' => $mat['file_id_drive'], 'name' => $name];
                }
            } elseif (!empty($mat['url_drive']) && preg_match('#^https?://#', $mat['url_drive'])) {
                $key = 'url:' . $mat['url_drive'];
                if (!isset($attachmentsSeen[$key])) {
                    $attachmentsSeen[$key] = true;
                    $attachmentsList[] = ['material_id' => (string)($mat['id_materiale'] ?? ''), 'url' => $mat['url_drive'], 'name' => $name];
                }
            } elseif (!empty($mat['local_path'])) {
                $key = 'local:' . $mat['local_path'];
                if (!isset($attachmentsSeen[$key])) {
                    $attachmentsSeen[$key] = true;
                    $attachmentsList[] = ['material_id' => (string)($mat['id_materiale'] ?? ''), 'relative_path' => $mat['local_path'], 'name' => $name];
                }
            }
        }
    }
    $udaFiles = $udaManager->getUDAFiles($udaId, null);
    foreach ($udaFiles as $file) {
        $path = $file['relative_path'] ?? '';
        $name = $file['name'] ?? ($path ? basename($path) : 'Allegato');
        $key = 'local:' . $path;
        if (!isset($attachmentsSeen[$key])) {
            $attachmentsSeen[$key] = true;
            $attachmentsList[] = ['relative_path' => $path, 'name' => $name];
        }
    }

    $aiProviders = [
        'gemini'     => 'Gemini AI',
        'openai'     => 'OpenAI',
        'claude'     => 'Claude AI',
        'openrouter' => 'OpenRouter'
    ];
    $aiModels = [
        'gemini'     => ['gemini-2.5-flash', 'gemini-2.5-pro'],
        'openai'     => ['gpt-4o', 'gpt-4o-mini'],
        'claude'     => ['claude-3-5-sonnet', 'claude-3-haiku'],
        'openrouter' => ['openrouter/gpt-4o-mini', 'openrouter/claude-3-haiku']
    ];
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genera domande con AI - <?= htmlspecialchars($uda->titolo ?? 'UDA') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .summary-badge { font-size: 0.95rem; }
        .truncate-2 {
            display: -webkit-box;
            -webkit-line-clamp: 2;
            -webkit-box-orient: vertical;
            overflow: hidden;
        }
        .list-dot {
            list-style: disc;
            margin-left: 1.2rem;
        }
    </style>
    </head>
<body>
<?php
$pageTitle = '<i class="bi bi-robot"></i> Generazione Automatica Domande (AI)';
$pageSubtitle = $uda->titolo ?? '';
$headerActions = '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '"><i class="bi bi-arrow-left"></i> Torna all\'UDA</a>'
    . '<a class="nav-link" href="uda_questions.php?id=' . urlencode($udaId) . '"><i class="bi bi-question-circle"></i> Gestione Domande</a>'
    . '<a href="uda_view.php?id=' . urlencode($udaId) . '" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Torna all\'UDA</a>'
    . '<a href="uda_questions.php?id=' . urlencode($udaId) . '" class="btn btn-secondary btn-sm"><i class="bi bi-question-circle"></i> Gestione Domande</a>';
include __DIR__ . '/partials/app_header.php';
?>

<div class="container mt-4">
    <?php if ($error): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
    <?php endif; ?>

<div class="card shadow-sm mb-4">
        <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
            <span><i class="bi bi-cpu"></i> Genera con AI (preview)</span>
            <span class="badge bg-light text-dark summary-badge"><?= count($attachmentsList) ?> allegati UDA inclusi</span>
        </div>
        <div class="card-body">
            <div class="row g-4">
                <div class="col-lg-6">
                    <h6 class="mb-2 text-uppercase text-muted">Contenuti nel prompt</h6>
                    <div class="mb-3">
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="ai_inc_argomento" <?= $udaArgomento ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ai_inc_argomento">Argomento: "<strong><?= htmlspecialchars($udaArgomento ?: '-') ?></strong>"</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="ai_inc_prof" <?= $profName ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ai_inc_prof">Prof: "<strong><?= htmlspecialchars($profName ?: '-') ?></strong>"</label>
                        </div>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="ai_inc_disciplina" <?= $udaDisciplina ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ai_inc_disciplina">Disciplina/Materia: "<strong><?= htmlspecialchars($udaDisciplina ?: '-') ?></strong>"</label>
                        </div>
                        <?php if ($udaMetodologia): ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="ai_inc_metodologia" checked>
                            <label class="form-check-label" for="ai_inc_metodologia">Metodologia: "<strong><?= htmlspecialchars($udaMetodologia) ?></strong>"</label>
                        </div>
                        <?php endif; ?>
                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" id="ai_inc_destinatari" <?= $udaDestinatari ? 'checked' : '' ?>>
                            <label class="form-check-label" for="ai_inc_destinatari">Destinatari/Classi: "<strong><?= htmlspecialchars($udaDestinatari ?: '-') ?></strong>"</label>
                        </div>
                        <div class="d-flex align-items-center mb-2">
                            <div class="form-check me-2">
                                <input class="form-check-input" type="checkbox" id="ai_inc_obiettivi" <?= !empty($obiettiviList) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ai_inc_obiettivi">Obiettivi (<?= count($obiettiviList) ?>)</label>
                            </div>
                            <?php if (!empty($obiettiviList)): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="toggle_all_objs">Seleziona tutti</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($obiettiviList)): ?>
                            <div class="border rounded mb-3 ps-2" style="max-height: 160px; overflow:auto;">
                                <?php foreach ($obiettiviList as $idx => $row): ?>
                                    <div class="form-check small py-1 ms-2">
                                        <input class="form-check-input ai-obj" type="checkbox" id="obj_<?= $idx ?>" data-text="<?= htmlspecialchars($row['text'], ENT_QUOTES) ?>" checked>
                                        <label class="form-check-label truncate-2 ms-1" for="obj_<?= $idx ?>"><?= htmlspecialchars($row['text']) ?></label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="text-muted small mb-3">Nessun obiettivo disponibile.</p>
                        <?php endif; ?>
                        <div class="d-flex align-items-center mb-2">
                            <div class="form-check me-2">
                                <input class="form-check-input" type="checkbox" id="ai_inc_allegati" <?= count($attachmentsList) ? 'checked' : '' ?>>
                                <label class="form-check-label" for="ai_inc_allegati">Allegati UDA (<?= count($attachmentsList) ?>)</label>
                            </div>
                            <?php if (!empty($attachmentsList)): ?>
                            <button type="button" class="btn btn-sm btn-outline-secondary ms-auto" id="toggle_all_att">Seleziona tutti</button>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($attachmentsList)): ?>
                            <div class="form-check form-switch mb-2 ms-2">
                                <input class="form-check-input" type="checkbox" id="ai_use_public" checked>
                                <label class="form-check-label" for="ai_use_public">
                                    Usa link pubblici temporanei; in alternativa scarica e allega in automatico.
                                </label>
                            </div>
                            <div class="border rounded mb-3 ps-2" style="max-height: 160px; overflow:auto;">
                                <?php foreach ($attachmentsList as $idx => $att): ?>
                                    <div class="form-check small py-1 ms-2">
                                        <input class="form-check-input ai-att" type="checkbox" id="att_<?= $idx ?>" data-idx="<?= $idx ?>" checked>
                                        <label class="form-check-label truncate-2 ms-1" for="att_<?= $idx ?>">
                                            <i class="bi bi-paperclip"></i>
                                            <?= htmlspecialchars($att['name'] ?? ($att['url'] ?? $att['drive_file_id'] ?? 'allegato')) ?>
                                            <?php if (!empty($att['drive_file_id'])): ?>
                                                <span class="text-muted">(Drive)</span>
                                            <?php elseif (!empty($att['url'])): ?>
                                                <span class="text-muted">(Link)</span>
                                            <?php elseif (!empty($att['relative_path'])): ?>
                                                <span class="text-muted">(Locale)</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <p class="small text-muted">Nessun materiale allegato all'UDA.</p>
                        <?php endif; ?>
                    </div>

                    <h6 class="mb-2 text-uppercase text-muted">Provider & modello</h6>
                    <div class="alert alert-warning py-2 px-3 d-none" id="ai_key_warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Nessuna API key configurata per il provider selezionato: saranno usati solo i modelli fallback e la chiamata potrebbe fallire.
                    </div>
                    <div class="row g-2 mb-3">
                        <div class="col-md-6">
                            <label class="form-label">Provider AI</label>
                            <div class="input-group">
                                <select id="ai_provider" class="form-select">
                                    <?php foreach ($aiProviders as $key => $label): ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= ($defaultProvider === $key) ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <a href="user_integrations.php#ai-section" class="btn btn-outline-secondary">
                                    <i class="bi bi-gear"></i>
                                </a>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Modello</label>
                            <select id="ai_model" class="form-select">
                                <?php foreach ($aiModels['gemini'] as $model): ?>
                                    <option value="<?= htmlspecialchars($model) ?>"><?= htmlspecialchars($model) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <h6 class="mb-2 text-uppercase text-muted">Quantità domande</h6>
                    <div class="row g-2">
                        <div class="col-6 col-md-3">
                            <label class="form-label"># aperte</label>
                            <input type="number" min="0" max="30" value="0" class="form-control" id="ai_num_open">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label"># chiuse (media)</label>
                            <input type="number" min="0" max="30" value="5" class="form-control" id="ai_num_closed_medium">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label"># chiuse (difficile)</label>
                            <input type="number" min="0" max="30" value="5" class="form-control" id="ai_num_closed_hard">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="form-label"># chiuse (molto diff.)</label>
                            <input type="number" min="0" max="30" value="5" class="form-control" id="ai_num_closed_expert">
                        </div>
                    </div>
                </div>

                <div class="col-lg-6">
                    <div class="mb-3">
                        <label class="form-label">Prompt (preview, modificabile)</label>
                        <textarea id="ai_prompt_preview" class="form-control" rows="8"></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Risultato (raw)</label>
                        <textarea id="ai_response_preview" class="form-control" rows="10" readonly></textarea>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-primary w-50" id="ai_generate_btn">
                            <i class="bi bi-magic"></i> Genera bozza con AI
                        </button>
                        <button type="button" class="btn btn-success w-50" id="ai_import_btn" disabled>
                            <i class="bi bi-upload"></i> Importa in anteprima
                        </button>
                        <button type="button" class="btn btn-outline-secondary" id="ai_copy_btn">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                    <p class="text-muted small mt-2 mb-0">
                        Il JSON generato sarà passato direttamente all'anteprima di importazione domande.
                    </p>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const modelsFallback = <?= json_encode($aiModels) ?>;
    const providerSelect = document.getElementById('ai_provider');
    const modelSelect = document.getElementById('ai_model');
    const promptPreview = document.getElementById('ai_prompt_preview');
    const responsePreview = document.getElementById('ai_response_preview');
    const importBtn = document.getElementById('ai_import_btn');
    const copyBtn = document.getElementById('ai_copy_btn');
    const csrfToken = <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const attachmentsList = <?= json_encode($attachmentsList, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    const obiettiviList = <?= json_encode($obiettiviList, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    const profName = "<?= addslashes($profName) ?>";
    const alertPlaceholder = document.createElement('div');
    alertPlaceholder.className = 'mt-2';
    // inserisci l'area avvisi subito sotto l'header della card principale
    document.querySelector('.card.shadow-sm .card-body').prepend(alertPlaceholder);

    let lastResponse = null;
    let lastAutoPrompt = '';
    let userEditedPrompt = false;

    function extractJsonBlock(text) {
        if (!text || typeof text !== 'string') return text;
        const match = text.match(/```json\s*([\s\S]*?)\s*```/i);
        if (match && match[1]) {
            return match[1].trim();
        }
        return text;
    }

    function buildPayload(includeOverride = true) {
        const usePublicEl = document.getElementById('ai_use_public');
        const incAttEl = document.getElementById('ai_inc_allegati');
        const usePublic = usePublicEl ? usePublicEl.checked : false;
        const includeAttachments = incAttEl ? incAttEl.checked : false;
        const hasAttachments = Array.isArray(attachmentsList) && attachmentsList.length > 0;
        const dlCheckbox = document.getElementById('ai_download_allegati');
        const downloadAttachments = dlCheckbox ? dlCheckbox.checked : (hasAttachments && includeAttachments && !usePublic);
        return {
            provider: providerSelect.value,
            uda_id: <?= json_encode($udaId, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>,
            model: modelSelect.value,
            use_public_links: usePublic,
            download_attachments: downloadAttachments,
            material_ids: (includeAttachments && hasAttachments
                ? Array.from(document.querySelectorAll('.ai-att:checked')).map(cb => attachmentsList[parseInt(cb.dataset.idx, 10)]?.material_id).filter(Boolean)
                : []),
            attachments: (includeAttachments && hasAttachments
                ? Array.from(document.querySelectorAll('.ai-att:checked')).map(cb => {
                    const idx = parseInt(cb.dataset.idx, 10);
                    return attachmentsList[idx] ?? null;
                }).filter(Boolean)
                : []),
            num_open: parseInt(document.getElementById('ai_num_open').value || '0', 10),
            num_closed_medium: parseInt(document.getElementById('ai_num_closed_medium').value || '0', 10),
            num_closed_hard: parseInt(document.getElementById('ai_num_closed_hard').value || '0', 10),
            num_closed_expert: parseInt(document.getElementById('ai_num_closed_expert').value || '0', 10),
            params: {
                include_prof: document.getElementById('ai_inc_prof').checked,
                prof_name: "<?= addslashes($profName) ?>",
                include_argomento: document.getElementById('ai_inc_argomento').checked,
                include_disciplina: document.getElementById('ai_inc_disciplina').checked,
                include_destinatari: document.getElementById('ai_inc_destinatari').checked,
                include_metodologia: document.getElementById('ai_inc_metodologia') ? document.getElementById('ai_inc_metodologia').checked : false,
                include_obiettivi: document.getElementById('ai_inc_obiettivi').checked,
                include_allegati: includeAttachments,
                argomento_text: "<?= addslashes($udaArgomento) ?>",
                disciplina_text: "<?= addslashes($udaDisciplina) ?>",
                destinatari_text: "<?= addslashes($udaDestinatari) ?>",
                metodologia_text: "<?= addslashes($udaMetodologia) ?>",
                obiettivi_selected: Array.from(document.querySelectorAll('.ai-obj:checked')).map(cb => cb.dataset.text),
                attachments_selected: (includeAttachments && hasAttachments
                    ? Array.from(document.querySelectorAll('.ai-att:checked')).map(cb => {
                        const idx = parseInt(cb.dataset.idx, 10);
                        return attachmentsList[idx] ?? null;
                    }).filter(Boolean)
                    : [])
            },
            override_prompt: includeOverride ? promptPreview.value : ''
        };
    }

    function showAlert(message, type = 'danger') {
        alertPlaceholder.innerHTML = `
            <div class="alert alert-${type} alert-dismissible fade show" role="alert">
                ${message}
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>`;
    }

    function populateModels(list = []) {
        const prov = providerSelect.value;
        const opts = list.length ? list : (modelsFallback[prov] || []);
        modelSelect.innerHTML = '';
        opts.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m;
            opt.textContent = m;
            modelSelect.appendChild(opt);
        });
    }

    async function refreshModels() {
        const prov = providerSelect.value;
        const warn = document.getElementById('ai_key_warning');
        try {
            const res = await fetch('api_generate_questions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify({action: 'list_models', provider: prov})
            });
            const data = await res.json();
            if (data.models && Array.isArray(data.models) && data.models.length) {
                populateModels(data.models);
                if (warn) {
                    warn.classList.toggle('d-none', !!data.has_key);
                }
                return;
            }
        } catch (e) {
            // fallback
        }
        if (warn) {
            warn.classList.add('d-none');
        }
        populateModels();
    }

    function applyUsePublicDefault() {
        const prov = providerSelect.value;
        const usePublicEl = document.getElementById('ai_use_public');
        if (!usePublicEl) return;
        if (prov === 'gemini') {
            usePublicEl.checked = true;
        } else {
            usePublicEl.checked = false;
        }
    }

    providerSelect.addEventListener('change', () => {
        applyUsePublicDefault();
        refreshModels();
        updatePromptPreview();
    });
    applyUsePublicDefault();
    refreshModels();

    // preview generato lato server: il prompt effettivo arriva dalla risposta del provider

    async function updatePromptPreview() {
        if (userEditedPrompt && promptPreview.value !== lastAutoPrompt && lastAutoPrompt) {
            const ok = confirm('Rigenerare il prompt farà perdere le modifiche manuali. Vuoi procedere?');
            if (!ok) return;
        }
        const payload = buildPayload(false);
        payload.action = 'preview_prompt';
        promptPreview.value = 'Generazione anteprima prompt...';
        try {
            const res = await fetch('api_generate_questions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify(payload)
            });
            const data = await res.json();
            if (data && data.prompt) {
                promptPreview.value = data.prompt;
                lastAutoPrompt = data.prompt;
                userEditedPrompt = false;
            } else if (data.error) {
                promptPreview.value = 'Errore anteprima: ' + data.error;
            } else {
                promptPreview.value = 'Anteprima non disponibile.';
            }
        } catch (e) {
            promptPreview.value = 'Errore anteprima prompt: ' + e;
        }
    }

    ['ai_inc_argomento','ai_inc_prof','ai_inc_disciplina','ai_inc_destinatari','ai_inc_metodologia','ai_inc_obiettivi','ai_inc_allegati','ai_use_public','ai_num_open','ai_num_closed_medium','ai_num_closed_hard','ai_num_closed_expert']
        .forEach(id => {
            const el = document.getElementById(id);
            if (el) {
                el.addEventListener('change', updatePromptPreview);
                el.addEventListener('input', updatePromptPreview);
            }
        });
    document.querySelectorAll('.ai-obj, .ai-att').forEach(el => {
        el.addEventListener('change', updatePromptPreview);
    });
    const toggleObjs = document.getElementById('toggle_all_objs');
    if (toggleObjs) {
        toggleObjs.addEventListener('click', () => {
            const checks = Array.from(document.querySelectorAll('.ai-obj'));
            const allChecked = checks.every(cb => cb.checked);
            checks.forEach(cb => cb.checked = !allChecked);
            updatePromptPreview();
        });
    }
    const toggleAtt = document.getElementById('toggle_all_att');
    if (toggleAtt) {
        toggleAtt.addEventListener('click', () => {
            const checks = Array.from(document.querySelectorAll('.ai-att'));
            const allChecked = checks.every(cb => cb.checked);
            checks.forEach(cb => cb.checked = !allChecked);
            updatePromptPreview();
        });
    }
    providerSelect.addEventListener('change', updatePromptPreview);
    modelSelect.addEventListener('change', updatePromptPreview);
    updatePromptPreview();
    promptPreview.addEventListener('input', () => {
        userEditedPrompt = (promptPreview.value !== lastAutoPrompt);
    });

    document.getElementById('ai_generate_btn').addEventListener('click', async () => {
        const prov = providerSelect.value;
        let urlPreview = '';
        if (prov === 'openai') {
            urlPreview = 'POST https://api.openai.com/v1/chat/completions (model/messages)';
        } else if (prov === 'claude') {
            urlPreview = 'POST https://api.anthropic.com/v1/messages (model/messages)';
        } else if (prov === 'openrouter') {
            urlPreview = 'POST https://openrouter.ai/api/v1/chat/completions (model/messages)';
        } else if (prov === 'gemini') {
            urlPreview = 'POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent (contents.parts.text)';
        }
        const usePublicEl = document.getElementById('ai_use_public');
        const usePublic = usePublicEl ? usePublicEl.checked : false;
        const attachmentsSelected = document.getElementById('ai_inc_allegati').checked
            ? Array.from(document.querySelectorAll('.ai-att:checked')).length
            : 0;
        const downloading = !usePublic && attachmentsSelected > 0;
        const loadingMsg = downloading ? `Carico ${attachmentsSelected} file sul provider...` : 'Invio richiesta al provider...';
        responsePreview.value = loadingMsg + '\n' + (urlPreview ? 'Formato atteso: ' + urlPreview + '\n\n' : '');
        alertPlaceholder.innerHTML = '';
        importBtn.disabled = true;
        lastResponse = null;

        const payload = buildPayload(true);

        try {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 120000); // timeout 120s
            const res = await fetch('api_generate_questions.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken},
                body: JSON.stringify(payload),
                signal: controller.signal
            });
            clearTimeout(timer);
            const data = await res.json();
            lastResponse = data;
            const lines = [];
            if (data.prompt) {
                lines.push('Prompt inviato:\n' + data.prompt);
            }
            lines.push('\nRisposta raw:\n' + JSON.stringify(data, null, 2));
            responsePreview.value = lines.join('\n');
            if (data.prompt) {
                promptPreview.value = data.prompt;
                lastAutoPrompt = data.prompt;
                userEditedPrompt = false;
            }
            if (data.permissions_info && data.permissions_info.made_public) {
                const pub = data.permissions_info.made_public;
                const rev = data.permissions_info.reverted ?? 0;
                showAlert(`Resi pubblici temporaneamente ${pub} file Drive e ripristinati ${rev}.`, 'info');
            }
            if (data.ai_raw && data.ai_raw.upload_info) {
                const up = data.ai_raw.upload_info;
                if (up.file_ids && up.file_ids.length) {
                    showAlert(`Caricati ${up.file_ids.length} file su OpenAI.`, 'info');
                } else if (up.errors && up.errors.length) {
                    showAlert(`Errore caricamento file: ${up.errors.join('; ')}`, 'danger');
                }
            }
            if (!data.error && data.ai_raw && data.ai_raw.content) {
                importBtn.disabled = false;
            } else if (data.error) {
                showAlert('Errore dal provider: ' + data.error, 'danger');
            } else {
                showAlert('Risposta non valida dal provider. Controlla la chiave API e i parametri.', 'warning');
            }
        } catch (err) {
            const msg = err.name === 'AbortError' ? 'Timeout: nessuna risposta dal provider entro 2 minuti.' : ('Errore chiamata API: ' + err);
            responsePreview.value = msg;
            showAlert(msg, 'danger');
        }
    });

    copyBtn.addEventListener('click', () => {
        if (!lastResponse) return;
        navigator.clipboard.writeText(JSON.stringify(lastResponse, null, 2)).catch(() => {});
    });

    importBtn.addEventListener('click', () => {
        if (!lastResponse || !lastResponse.ai_raw || !lastResponse.ai_raw.content) {
            return;
        }
        const cleaned = extractJsonBlock(lastResponse.ai_raw.content);
        const form = document.createElement('form');
        form.method = 'POST';
        form.action = 'import_questions.php?id=<?= htmlspecialchars($udaId) ?>';
        form.target = '_blank';

        const inputs = [
            {name: 'action', value: 'preview_ai'},
            {name: 'id_uda', value: '<?= htmlspecialchars($udaId) ?>'},
            {name: 'id', value: '<?= htmlspecialchars($udaId) ?>'}
        ];
        inputs.forEach(obj => {
            const inp = document.createElement('input');
            inp.type = 'hidden';
            inp.name = obj.name;
            inp.value = obj.value;
            form.appendChild(inp);
        });
        const ta = document.createElement('textarea');
        ta.name = 'ai_json';
        ta.style.display = 'none';
        ta.value = cleaned;
        form.appendChild(ta);

        document.body.appendChild(form);
        form.submit();
        document.body.removeChild(form);
    });
</script>
</body>
</html>
