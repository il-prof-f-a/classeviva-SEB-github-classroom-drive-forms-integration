<?php
/**
 * Gestione Repository Template GitHub
 * Gestisce i repository template utilizzabili per gli assignment
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;
use App\Core\Security\PublicError;
use App\Integration\GitHubIntegration;

$pageTitle = "Gestione Repository Template";

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAuthenticated($_SESSION);

// Inizializza servizi
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$github = new GitHubIntegration($config);
$github->loadTokenFromSession();
$githubAuthUrl = '';
if (!$github->isAuthenticated() && !empty($config['github']['client_id'] ?? '')) {
    $githubAuthUrl = $github->getAuthorizationUrl(null, (string)($_SERVER['REQUEST_URI'] ?? 'github_repo_templates.php'));
}

$successMessage = null;
$errorMessage = null;
$csrfSession = &$_SESSION;
$csrfToken = Csrf::token($csrfSession);

// Gestione salvataggio nuovo template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_template') {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $nome = trim($_POST['nome'] ?? '');
        $urlRepository = trim($_POST['url_repository'] ?? '');
        $descrizione = trim($_POST['descrizione'] ?? '');
        $visibilita = $_POST['visibilita'] ?? 'private';
        $linguaggio = trim($_POST['linguaggio'] ?? '');
        $categoria = trim($_POST['categoria'] ?? '');
        $note = trim($_POST['note'] ?? '');

        if (!$nome || !$urlRepository) {
            throw new Exception("Nome e URL repository sono obbligatori");
        }

        // Valida URL GitHub e ricava owner/repository per la chiamata API.
        $repositoryParts = [];
        if (preg_match('#^https://github\.com/([A-Za-z0-9_.-]{1,100})/([A-Za-z0-9_.-]{1,100})/?$#i', $urlRepository, $repositoryParts) !== 1) {
            throw new Exception("URL repository non valido. Formato atteso: https://github.com/username/repo");
        }
        $repoOwner = $repositoryParts[1];
        $repoName = $repositoryParts[2];

        if (!$github->isAuthenticated()) {
            throw new Exception('Autorizza GitHub prima di aggiungere un repository template.');
        }

        // GitHub espone il flag `is_template` solo sui metadati del repository:
        // non salviamo URL che poi non possono essere usati dall’endpoint
        // /generate per creare gli assignment degli studenti.
        $repository = $github->getRepository($repoOwner, $repoName);
        if (empty($repository['is_template'])) {
            throw new Exception('Il repository GitHub non è configurato come template. Abilita "Template repository" nelle impostazioni GitHub e riprova.');
        }

        // Verifica se template esiste già
        $existing = $dbAdapter->findAll('GITHUB_REPO_TEMPLATES');
        foreach ($existing as $tmpl) {
            if ($tmpl['url_repository'] === $urlRepository) {
                throw new Exception("Un template con questo URL esiste già");
            }
        }

        // Crea nuovo template
        $templateId = 'GHRT_' . uniqid();
        $newTemplate = [
            'id_template' => $templateId,
            'nome' => $nome,
            'url_repository' => $urlRepository,
            'descrizione' => $descrizione,
            'visibilita' => $visibilita,
            'linguaggio' => $linguaggio,
            'categoria' => $categoria,
            'data_creazione' => date('d/m/Y H:i:s'),
            'ultima_modifica' => date('d/m/Y H:i:s'),
            'attivo' => 'si',
            'note' => $note
        ];

        $dbAdapter->insertRow('GITHUB_REPO_TEMPLATES', $newTemplate);
        $successMessage = "Template aggiunto con successo!";

    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

// Gestione modifica stato (attivo/inattivo)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'toggle_status' && isset($_POST['id'])) {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $templates = $dbAdapter->findAll('GITHUB_REPO_TEMPLATES');
        foreach ($templates as $index => $tmpl) {
            if ($tmpl['id_template'] === $_POST['id']) {
                $newStatus = $tmpl['attivo'] === 'si' ? 'no' : 'si';
                $tmpl['attivo'] = $newStatus;
                $tmpl['ultima_modifica'] = date('d/m/Y H:i:s');
                $dbAdapter->updateRow('GITHUB_REPO_TEMPLATES', 'id_template', $_POST['id'], $tmpl);
                $successMessage = "Stato template aggiornato!";
                break;
            }
        }
    } catch (Exception $e) {
        $errorMessage = "Errore nell'aggiornamento: " . $e->getMessage();
    }
}

// Gestione eliminazione template
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete' && isset($_POST['id'])) {
    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $dbAdapter->deleteRow('GITHUB_REPO_TEMPLATES', (string) $_POST['id'], 'id_template');
        $successMessage = "Template eliminato con successo!";
    } catch (Exception $e) {
        $errorMessage = "Errore nell'eliminazione: " . $e->getMessage();
    }
}

// Carica templates esistenti
$templates = $dbAdapter->findAll('GITHUB_REPO_TEMPLATES');

$projectTemplates = [];
$projectTemplateError = null;
$githubOrganizations = [];
if ($github->isAuthenticated()) {
    try {
        $githubOrganizations = (array)$github->listOrganizations();
        foreach ($githubOrganizations as $organization) {
            $org = trim((string)($organization['login'] ?? ''));
            if ($org === '') {
                continue;
            }
            foreach ($github->listOrganizationProjectTemplates($org) as $project) {
                $projectTemplates[] = $project;
            }
        }
    } catch (Throwable $exception) {
        $projectTemplateError = PublicError::message($exception, 'github project templates page');
    }
}

// Categorie predefinite
$categorieDisponibili = [
    'Web Development',
    'Mobile Development',
    'Data Science',
    'Machine Learning',
    'Desktop Application',
    'Game Development',
    'DevOps',
    'Database',
    'API Development',
    'Altro'
];

// Linguaggi predefiniti
$linguaggiDisponibili = [
    'JavaScript', 'TypeScript', 'Python', 'Java', 'C++', 'C#',
    'PHP', 'Ruby', 'Go', 'Rust', 'Swift', 'Kotlin',
    'HTML/CSS', 'SQL', 'Shell', 'Altro'
];

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = $pageTitle ?? 'Template GitHub';
    $headerActions = '<a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-arrow-left"></i> Torna alla Dashboard</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
<?php if ($successMessage): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <!-- Informazioni sull'utilizzo -->
            <div class="alert alert-info">
                <h5><i class="bi bi-info-circle"></i> Informazioni</h5>
                <p class="mb-2">
                    I repository template sono repository GitHub che contengono codice starter per gli assignment.
                    Gli studenti riceveranno una copia del template quando accettano l'assignment.
                </p>
                <ul class="mb-0">
                    <li>I template devono essere repository pubblici su GitHub</li>
                    <li>Assicurati che il repository contenga un file README.md con le istruzioni</li>
                    <li>Puoi disattivare temporaneamente un template senza eliminarlo</li>
                </ul>
            </div>

            <!-- Project template GitHub -->
            <div class="card mb-4" id="project-templates">
                <div class="card-header bg-info text-white">
                    <h5 class="mb-0"><i class="bi bi-kanban"></i> Project template disponibili</h5>
                </div>
                <div class="card-body">
                    <?php if (!$github->isAuthenticated()): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-github"></i> Autorizza GitHub per vedere i Project template dell’organizzazione.
                            <?php if ($githubAuthUrl !== ''): ?>
                                <a href="<?= htmlspecialchars($githubAuthUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-dark ms-2">Autorizza GitHub</a>
                            <?php endif; ?>
                        </div>
                    <?php elseif ($projectTemplateError !== null): ?>
                        <div class="alert alert-warning mb-0">
                            <i class="bi bi-exclamation-triangle"></i> Impossibile caricare i Project template in questo momento.
                            <a href="github_repo_templates.php#project-templates" class="alert-link">Riprova</a>.
                        </div>
                    <?php elseif ($projectTemplates === []): ?>
                        <div class="alert alert-light border mb-3">
                            <i class="bi bi-info-circle"></i> Nessun project template disponibile.
                        </div>
                        <?php foreach ($githubOrganizations as $organization): ?>
                            <?php $orgLogin = trim((string)($organization['login'] ?? '')); if ($orgLogin === '') continue; ?>
                            <a href="https://github.com/orgs/<?= rawurlencode($orgLogin) ?>/projects/" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm me-1 mb-1">
                                <i class="bi bi-plus-circle"></i> Crea un nuovo project in <?= htmlspecialchars($orgLogin, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <span class="small text-muted">Crea un nuovo project, impostalo come template, poi torna qui e aggiorna la pagina.</span>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle mb-3">
                                <thead>
                                    <tr><th>Organizzazione</th><th>Nome</th><th>Descrizione</th><th>Stato</th><th>Azioni</th></tr>
                                </thead>
                                <tbody>
                                <?php foreach ($projectTemplates as $project): ?>
                                    <tr>
                                        <td><code><?= htmlspecialchars((string)($project['organization'] ?? ''), ENT_QUOTES, 'UTF-8') ?></code></td>
                                        <td><strong><?= htmlspecialchars((string)($project['title'] ?? 'Project template'), ENT_QUOTES, 'UTF-8') ?></strong></td>
                                        <td><?= htmlspecialchars((string)($project['short_description'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td><span class="badge bg-success">Template attivo</span></td>
                                        <td><a href="<?= htmlspecialchars((string)($project['url'] ?? '#'), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-sm btn-outline-info"><i class="bi bi-box-arrow-up-right"></i> Apri su GitHub</a></td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php foreach (array_unique(array_map(static fn(array $project): string => (string)($project['organization'] ?? ''), $projectTemplates)) as $orgLogin): if ($orgLogin === '') continue; ?>
                            <a href="https://github.com/orgs/<?= rawurlencode($orgLogin) ?>/projects/" target="_blank" rel="noopener" class="btn btn-outline-info btn-sm me-1">
                                <i class="bi bi-plus-circle"></i> Gestisci project in <?= htmlspecialchars($orgLogin, ENT_QUOTES, 'UTF-8') ?>
                            </a>
                            <span class="small text-muted">Per aggiungerne uno: crea un nuovo project, impostalo come template, poi torna qui e aggiorna la pagina.</span>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Templates Esistenti -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-folder-symlink"></i> Repository Template Disponibili
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (empty($templates)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nessun template configurato.
                            Usa il form qui sotto per aggiungerne uno.
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover">
                                <thead>
                                    <tr>
                                        <th>Nome</th>
                                        <th>Repository</th>
                                        <th>Linguaggio</th>
                                        <th>Categoria</th>
                                        <th>Visibilità</th>
                                        <th>Stato</th>
                                        <th>Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($templates as $template): ?>
                                        <tr class="<?= $template['attivo'] === 'no' ? 'table-secondary' : '' ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($template['nome']) ?></strong>
                                                <?php if (!empty($template['descrizione'])): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars($template['descrizione']) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <a href="<?= htmlspecialchars($template['url_repository']) ?>"
                                                   target="_blank" class="text-decoration-none">
                                                    <i class="bi bi-github"></i>
                                                    <?php
                                                    // Estrai username/repo dall'URL
                                                    preg_match('#github\.com/([^/]+/[^/]+)#', $template['url_repository'], $matches);
                                                    echo htmlspecialchars($matches[1] ?? $template['url_repository']);
                                                    ?>
                                                </a>
                                            </td>
                                            <td>
                                                <?php if (!empty($template['linguaggio'])): ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($template['linguaggio']) ?></span>
                                                <?php else: ?>
                                                    <span class="text-muted">-</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><?= htmlspecialchars($template['categoria'] ?: '-') ?></td>
                                            <td>
                                                <span class="badge bg-<?= $template['visibilita'] === 'public' ? 'success' : 'warning' ?>">
                                                    <?= htmlspecialchars($template['visibilita']) ?>
                                                </span>
                                            </td>
                                            <td>
                                                <span class="badge bg-<?= $template['attivo'] === 'si' ? 'success' : 'secondary' ?>">
                                                    <?= $template['attivo'] === 'si' ? 'Attivo' : 'Inattivo' ?>
                                                </span>
                                            </td>
                                            <td>
                                                <div class="btn-group" role="group">
                                                    <form method="POST" class="d-inline">
                                                        <input type="hidden" name="action" value="toggle_status">
                                                        <input type="hidden" name="id" value="<?= htmlspecialchars($template['id_template'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-<?= $template['attivo'] === 'si' ? 'warning' : 'success' ?>" title="<?= $template['attivo'] === 'si' ? 'Disattiva' : 'Attiva' ?>"><i class="bi bi-<?= $template['attivo'] === 'si' ? 'pause' : 'play' ?>-circle"></i></button>
                                                    </form>
                                                    <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler eliminare questo template?')">
                                                        <input type="hidden" name="action" value="delete">
                                                        <input type="hidden" name="id" value="<?= htmlspecialchars($template['id_template'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                                        <button type="submit" class="btn btn-sm btn-outline-danger" title="Elimina"><i class="bi bi-trash"></i></button>
                                                    </form>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form Nuovo Template -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-plus-circle"></i> Aggiungi Nuovo Template
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="add_template">
                        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome Template *</label>
                                <input type="text" name="nome" class="form-control" required
                                       placeholder="Es: Starter Java Spring Boot">
                                <small class="form-text text-muted">
                                    Nome descrittivo che apparirà nella selezione
                                </small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">URL Repository GitHub *</label>
                                <input type="url" name="url_repository" class="form-control" required
                                       placeholder="https://github.com/username/repo-template"
                                       pattern="https://github\.com/[^/]+/[^/]+/?">
                                <small class="form-text text-muted">
                                    URL completo del repository template
                                </small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Linguaggio Principale</label>
                                <select name="linguaggio" class="form-select">
                                    <option value="">-- Seleziona --</option>
                                    <?php foreach ($linguaggiDisponibili as $lang): ?>
                                        <option value="<?= htmlspecialchars($lang) ?>"><?= htmlspecialchars($lang) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Categoria</label>
                                <select name="categoria" class="form-select">
                                    <option value="">-- Seleziona --</option>
                                    <?php foreach ($categorieDisponibili as $cat): ?>
                                        <option value="<?= htmlspecialchars($cat) ?>"><?= htmlspecialchars($cat) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-4 mb-3">
                                <label class="form-label">Visibilità Repository</label>
                                <select name="visibilita" class="form-select">
                                    <option value="public">Public (consigliato)</option>
                                    <option value="private">Private</option>
                                </select>
                                <small class="form-text text-muted">
                                    I template pubblici sono più facili da usare
                                </small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Descrizione</label>
                            <textarea name="descrizione" class="form-control" rows="2"
                                      placeholder="Breve descrizione del template e del suo contenuto"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Note</label>
                            <textarea name="note" class="form-control" rows="2"
                                      placeholder="Note interne (opzionale)"></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i> Aggiungi Template
                            </button>
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Annulla
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
