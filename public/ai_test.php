<?php
/**
 * Pagina di test per chiamata ai provider AI
 * Usa l'endpoint api_generate_questions.php con un prompt libero.
 */
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

$providers = [
    'gemini' => 'Gemini',
    'openai' => 'OpenAI',
    'claude' => 'Claude',
    'openrouter' => 'OpenRouter',
];
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>AI Provider Test</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body>
<div class="container my-4">
    <h1 class="h3 mb-3">Test chiamata AI provider</h1>
    <p class="text-muted">Inserisci provider, modello e prompt. Viene chiamato l'endpoint interno <code>api_generate_questions.php</code> usando le API key dell'utente.</p>

    <div id="alert-area"></div>

    <div class="row g-3">
        <div class="col-md-4">
            <label class="form-label">Provider</label>
            <div class="input-group">
                <select id="prov" class="form-select">
                    <?php foreach ($providers as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>"><?= htmlspecialchars($label) ?></option>
                    <?php endforeach; ?>
                </select>
                <a href="user_integrations.php#ai-section" class="btn btn-outline-secondary">
                    <i class="bi bi-gear"></i>
                </a>
            </div>
        </div>
        <div class="col-md-4">
            <label class="form-label">Modello</label>
            <select id="model" class="form-select">
                <option value="">Seleziona un modello</option>
            </select>
            <div class="form-text text-muted">La lista viene caricata dal provider (se la key è configurata), altrimenti usa il fallback manuale.</div>
        </div>
        <div class="col-md-4 d-flex align-items-end">
            <div class="form-check form-switch">
                <input class="form-check-input" type="checkbox" id="use_public" checked>
                <label class="form-check-label" for="use_public">Usa link pubblici (altrimenti download allegati)</label>
            </div>
        </div>
    </div>

    <div class="mb-3 mt-3">
        <label class="form-label">Prompt</label>
        <textarea id="prompt" class="form-control" rows="8" placeholder="Scrivi qui il messaggio da inviare al provider..."></textarea>
    </div>

    <div class="d-flex gap-2 mb-3">
        <button class="btn btn-primary" id="send_btn">Invia</button>
        <button class="btn btn-secondary" id="clear_btn">Pulisci output</button>
    </div>

    <div class="mb-2">
        <label class="form-label">Risposta</label>
        <pre id="output" class="bg-light p-3 border rounded" style="min-height: 200px; white-space: pre-wrap;"></pre>
    </div>
</div>

<script>
const alertArea = document.getElementById('alert-area');
const output = document.getElementById('output');
const sendBtn = document.getElementById('send_btn');
const clearBtn = document.getElementById('clear_btn');
const providerSelect = document.getElementById('prov');
const modelSelect = document.getElementById('model');

function showAlert(msg, type='danger') {
    alertArea.innerHTML = `<div class="alert alert-${type} alert-dismissible fade show" role="alert">
        ${msg}
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>`;
}

clearBtn.addEventListener('click', () => {
    output.textContent = '';
    alertArea.innerHTML = '';
});

function populateModels(list=[]) {
    modelSelect.innerHTML = '<option value="">Seleziona un modello</option>';
    list.forEach(m => {
        const opt = document.createElement('option');
        opt.value = m;
        opt.textContent = m;
        modelSelect.appendChild(opt);
    });
}

async function refreshModels() {
    const prov = providerSelect.value;
    try {
        const res = await fetch('api_generate_questions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify({action: 'list_models', provider: prov})
        });
        const data = await res.json();
        if (data.models && Array.isArray(data.models) && data.models.length) {
            populateModels(data.models);
            if (!data.has_key) {
                showAlert('Nessuna API key configurata per il provider selezionato: uso solo i modelli fallback.', 'warning');
            }
            return;
        }
    } catch (e) {
        // fallback below
    }
    // fallback statici minimi
    const fallback = {
        gemini: ['gemini-1.5-flash', 'gemini-pro'],
        openai: ['gpt-4o', 'gpt-4o-mini'],
        claude: ['claude-3-5-sonnet', 'claude-3-haiku'],
        openrouter: ['openrouter/gpt-4o-mini', 'openrouter/claude-3-haiku']
    };
    populateModels(fallback[prov] || []);
}

providerSelect.addEventListener('change', refreshModels);
refreshModels();

sendBtn.addEventListener('click', async () => {
    alertArea.innerHTML = '';
    output.textContent = 'Invio richiesta...';

    const provider = document.getElementById('prov').value;
    const model = modelSelect.value.trim();
    const prompt = document.getElementById('prompt').value.trim();
    const usePublic = document.getElementById('use_public').checked;

    if (!provider || !model || !prompt) {
        showAlert('Provider, modello e prompt sono obbligatori.', 'warning');
        output.textContent = '';
        return;
    }

    const payload = {
        provider,
        model,
        use_public_links: usePublic,
        download_attachments: !usePublic,
        attachments: [],
        num_open: 0,
        num_closed_medium: 0,
        num_closed_hard: 0,
        num_closed_expert: 0,
        params: { context: 'test page' },
        override_prompt: prompt
    };
    // format payload per provider (best effort)
    let urlPreview = '';
    if (provider === 'openai') {
        urlPreview = 'POST https://api.openai.com/v1/chat/completions (body model/messages)';
    } else if (provider === 'claude') {
        urlPreview = 'POST https://api.anthropic.com/v1/messages (model/messages)';
    } else if (provider === 'openrouter') {
        urlPreview = 'POST https://openrouter.ai/api/v1/chat/completions (model/messages)';
    } else if (provider === 'gemini') {
        urlPreview = 'POST https://generativelanguage.googleapis.com/v1beta/models/{model}:generateContent (contents.parts.text)';
    }
    output.textContent = 'Invio richiesta...\n' + (urlPreview ? 'Formato atteso: ' + urlPreview + '\n\n' : '');

    try {
        const controller = new AbortController();
        const timer = setTimeout(() => controller.abort(), 30000); // 30s timeout
        const res = await fetch('api_generate_questions.php', {
            method: 'POST',
            headers: {'Content-Type': 'application/json'},
            body: JSON.stringify(payload),
            signal: controller.signal
        });
        clearTimeout(timer);
        const data = await res.json();
        output.textContent = JSON.stringify(data, null, 2);
        if (data.permissions_info && data.permissions_info.made_public) {
            const pub = data.permissions_info.made_public;
            const rev = data.permissions_info.reverted ?? 0;
            showAlert(`Resi pubblici temporaneamente ${pub} file Drive e ripristinati ${rev}.`, 'info');
        } else if (data.error) {
            showAlert('Errore dal provider: ' + data.error, 'danger');
        }
    } catch (err) {
        const msg = err.name === 'AbortError' ? 'Timeout: nessuna risposta entro 30s.' : ('Errore: ' + err);
        output.textContent = msg;
        showAlert(msg, 'danger');
    }
});
</script>
</body>
</html>
