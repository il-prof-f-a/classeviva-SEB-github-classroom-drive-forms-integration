<?php

// INIZIO CODICE DI DEBUG
error_reporting(E_ALL);
// FINE CODICE DI DEBUG

// Include il file di bootstrap che inizializza l'applicazione e le configurazioni
$config = require_once __DIR__ . '/../bootstrap.php';

// Utilizziamo le classi che abbiamo creato
use App\Core\GoogleTokenProvider;
use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\UserIntegrationManager;
use Google\Client;

$udas = [];
$error_message = null;
$classiAssegnateAll = [];
$googleTokenWarning = null;
$initialConfigMissing = false;
$profileConfig = [];
$currentUserEmail = strtolower(trim($_SESSION['user_email'] ?? ''));
$isDebugUser = is_admin_user($currentUserEmail);
$dashboardColClass = 'col-md-3';

try {
    // 1. Crea un'istanza del gestore delle UDA
    $udaManager = new UDAManager($config);
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    // Verifica configurazione di base del profilo/scuola
    $userIdForProfile = (string)($_SESSION['user_id'] ?? '');
    $mostraGuida = true; // Default
    if ($userIdForProfile !== '') {
        $integrationManager = new UserIntegrationManager($dbAdapter, $userIdForProfile);
        $profileConfig = $integrationManager->getConfig('profile');
        $initialConfigMissing = empty(array_filter($profileConfig ?? [], static fn($v) => trim((string)$v) !== ''));

        // Recupera preferenza guida dall'utente
        $utente = $dbAdapter->findOne('UTENTI', 'id_utente', $userIdForProfile);

        // Controllo consenso privacy: se non è stato dato, reindirizza alla pagina di consenso
        if ($utente && (!isset($utente['privacy_consent_given']) || $utente['privacy_consent_given'] != 1)) {
            header('Location: privacy-consent.php');
            exit;
        }

        if ($utente && isset($utente['mostra_guida'])) {
            $mostraGuida = (bool)(int)($utente['mostra_guida']);
        }
    }
    
    // 2. Recupera tutte le UDA
    $udasRaw = $udaManager->getAllUDAs();
    // Mappa disciplina/materia originale per ID (fallback nel rendering)
    $disciplinaById = [];
    foreach ($udasRaw as $orig) {
        if (!empty($orig->id_uda)) {
            $disciplinaById[$orig->id_uda] = $orig->disciplina ?? $orig->materia ?? '';
        }
    }

    $udas = $udasRaw;
    // Deduplica eventuali UDA duplicate (es. se caricate da join con assegnazioni)
    $udaMap = [];
    foreach ($udas as $u) {
        $key = $u->id_uda ?? spl_object_hash($u);
        if (!isset($udaMap[$key])) {
            $udaMap[$key] = $u;
            continue;
        }
        // Se esiste giÃ , mantieni quello con piÃ¹ informazioni (es. disciplina valorizzata)
        $existing = $udaMap[$key];
        if (empty($existing->disciplina) && !empty($u->disciplina)) {
            $existing->disciplina = $u->disciplina;
        }
        if (empty($existing->argomento) && !empty($u->argomento)) {
            $existing->argomento = $u->argomento;
        }
        $udaMap[$key] = $existing;
    }
    $udas = array_values($udaMap);
    $classiAssegnateAll = $dbAdapter->findAll('CLASSI_ASSEGNATE');

} catch (Exception $e) {
    // Se qualcosa va storto (es. file non trovato, database corrotto), mostra un messaggio di errore
    $error_message = "Si è verificato un errore durante il caricamento del database: " . $e->getMessage();
$show_db_link = true;
}

// =========================
// Filtri e ordinamenti UDA
// =========================
$currentYearConfig = $config['academic_year']['current'] ?? null;
if (!$currentYearConfig) {
    $year = (int)date('Y');
    $month = (int)date('n');
    $startYear = $month >= 8 ? $year : ($year - 1);
    $currentYearConfig = $startYear . '-' . substr((string)($startYear + 1), -2);
}

$hasAnnoFilter = array_key_exists('anno', $_GET) && trim((string)$_GET['anno']) !== '';
$filterYear = $hasAnnoFilter ? (string)$_GET['anno'] : '';
$filterClasse = $_GET['classe'] ?? '';
$filterMateria = $_GET['materia'] ?? '';
$sortField = $_GET['sort'] ?? 'titolo';
$sortDir = strtolower($_GET['dir'] ?? 'asc') === 'desc' ? 'desc' : 'asc';

// Controllo token Google per avvisare il rinnovo (tenta refresh automatico)
$googleTokenData = GoogleTokenProvider::getToken($config);

if (empty($googleTokenData) || !is_array($googleTokenData)) {
    $googleTokenWarning = $googleTokenWarning ?? "Token Google non trovato. Rigenera le credenziali.";
} else {
    $expiresAt = $googleTokenData['expires_at'] ?? ($googleTokenData['created'] ?? 0) + ($googleTokenData['expires_in'] ?? 0);
    $needsRefresh = empty($googleTokenData['access_token']) || time() >= ($expiresAt - 60);

    if ($needsRefresh) {
        $googleTokenWarning = "Token Google scaduto o non valido. Rigenera le credenziali.";
    }
}
// Mappatura UDA -> classi assegnate
$classiByUda = [];
foreach ($classiAssegnateAll as $c) {
    $id = $c['id_uda'] ?? null;
    if (!$id) continue;
    $classiByUda[$id][] = $c;
}

// Liste distinte per filtri
$anniDistinct = [];
$classiDistinct = [];
$materieDistinct = [];
foreach ($udas as $u) {
    if (!empty($u->anno_scolastico)) {
        $anniDistinct[$u->anno_scolastico] = true;
    }
    if (!empty($u->disciplina)) {
        $materieDistinct[$u->disciplina] = true;
    }
    $cls = $classiByUda[$u->id_uda] ?? [];
    foreach ($cls as $c) {
        if (!empty($c['nome_classe'])) {
            $classiDistinct[$c['nome_classe']] = true;
        }
        if (!empty($c['nome_materia'])) {
            $materieDistinct[$c['nome_materia']] = true;
        }
    }
}
if ($currentYearConfig !== '') {
    $anniDistinct[$currentYearConfig] = true;
}
ksort($anniDistinct);
$anniKeys = array_keys($anniDistinct);
$maxAnno = $anniKeys !== [] ? max($anniKeys) : $currentYearConfig;
if (!$hasAnnoFilter) {
    $filterYear = $maxAnno;
}
ksort($classiDistinct);
ksort($materieDistinct);

if ($filterYear !== '' && !isset($anniDistinct[$filterYear])) {
    $filterYear = $maxAnno;
}

// Funzione colore deterministico per badge
function badgeColor(string $key): string {
    $palette = ['#0d6efd','#198754','#d63384','#fd7e14','#6f42c1','#20c997','#0dcaf0','#6610f2','#e83e8c','#dc3545'];
    $idx = abs(crc32($key)) % count($palette);
    return $palette[$idx];
}

// Filtra
$filteredUdas = array_filter($udas, function($u) use ($filterYear, $filterClasse, $filterMateria, $classiByUda) {
    if ($filterYear && ($u->anno_scolastico ?? '') !== $filterYear) {
        return false;
    }
    $classes = $classiByUda[$u->id_uda] ?? [];
    $classeNames = array_map(fn($c) => $c['nome_classe'] ?? '', $classes);
    $materieNames = array_filter(array_merge(
        [$u->disciplina ?? ''],
        array_map(fn($c) => $c['nome_materia'] ?? '', $classes)
    ));

    if ($filterClasse && !in_array($filterClasse, $classeNames, true)) {
        return false;
    }
    if ($filterMateria && !in_array($filterMateria, $materieNames, true)) {
        return false;
    }
    return true;
});

// Ordina
usort($filteredUdas, function($a, $b) use ($sortField, $sortDir, $classiByUda) {
    $getVal = function($u) use ($sortField, $classiByUda) {
        switch ($sortField) {
            case 'anno':
                return strtolower($u->anno_scolastico ?? '');
            case 'argomento':
                return strtolower($u->argomento ?? '');
            case 'disciplina':
                return strtolower($u->disciplina ?? '');
            case 'classe':
                $classes = $classiByUda[$u->id_uda] ?? [];
                return strtolower($classes[0]['nome_classe'] ?? '');
            default:
                return strtolower($u->titolo ?? '');
        }
    };
    $va = $getVal($a);
    $vb = $getVal($b);
    if ($va === $vb) return 0;
    $res = $va <=> $vb;
    return $sortDir === 'asc' ? $res : -$res;
});

// Helper per costruire link di ordinamento mantenendo i filtri
function sortLink(string $field, string $label, string $currentField, string $currentDir, string $anno, string $classe, string $materia): string {
    $dir = ($currentField === $field && $currentDir === 'asc') ? 'desc' : 'asc';
    $params = http_build_query([
        'sort' => $field,
        'dir' => $dir,
        'anno' => $anno,
        'classe' => $classe,
        'materia' => $materia
    ]);
    $arrow = '';
    if ($currentField === $field) {
        $arrow = $currentDir === 'asc' ? ' <i class="bi bi-arrow-up"></i>' : ' <i class="bi bi-arrow-down"></i>';
    }
    return "<a href=\"?{$params}\" class=\"text-decoration-none\">{$label}{$arrow}</a>";
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - Sistema Gestione UDA</title>
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#0f5f9f">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <meta name="mobile-web-app-capable" content="yes">
    <!-- Utilizziamo Bootstrap per uno stile pulito e moderno -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .uda-table-container {
            overflow-x: auto;
        }
        .uda-table {
            min-width: 920px;
        }
        .uda-row {
            cursor: pointer;
        }
        .uda-row td {
            vertical-align: middle;
        }
        .uda-row:hover {
            background-color: #f8f9fa;
        }
        .subject-badge {
            max-width: 120px;
            display: inline-block;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .argomento-cell {
            max-width: 180px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .uda-actions {
            display: flex;
            flex-wrap: wrap;
            gap: 0.25rem;
        }
        .dashboard-toggle-btn {
            min-width: 150px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
        }
        .dashboard-toggle-btn .toggle-label {
            white-space: nowrap;
        }
        .dashboard-toggle-btn.active {
            box-shadow: 0 0 0 1px currentColor;
        }
        .dashboard-section {
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .dashboard-section.d-none {
            display: none !important;
            opacity: 0;
            transform: translateY(-4px);
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-speedometer2"></i> Dashboard UDA';
    $pageSubtitle = !empty($_SESSION['user_email'])
        ? 'Utente loggato: ' . $_SESSION['user_email']
        : '';
    $headerActions = '<a href="materiali.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-folder-fill"></i> Materiali</a>'
        . '<a href="obiettivi.php" class="btn btn-outline-success btn-sm"><i class="bi bi-bullseye"></i> Obiettivi</a>';
    if ($isDebugUser) {
        $headerActions .= '<a href="status.php" class="btn btn-outline-secondary btn-sm"><i class="bi bi-speedometer2"></i> Stato Sistema</a>';
    }
    $headerActions .= '<a href="guida_portale.php" class="btn btn-outline-info btn-sm" target="_blank"><i class="bi bi-book"></i> Guida</a>'
        . '<a href="uda_create.php" class="btn btn-primary btn-sm"><i class="bi bi-plus-circle"></i> Crea Nuova UDA</a>'
        . '<a href="logout.php" class="btn btn-outline-danger btn-sm"><i class="bi bi-box-arrow-right"></i> Logout</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>
    <div class="container mt-5">

        <?php if ($initialConfigMissing): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-gear-wide-connected"></i>
                Non hai ancora completato la configurazione iniziale (profilo/scuola).
                Apri <strong>Configurazione → Integrazioni Utente</strong> e compila almeno i dati del profilo.
                <a href="user_integrations.php#profile-section" class="btn btn-sm btn-outline-primary ms-2">
                    Vai a Integrazioni Utente
                </a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($googleTokenWarning): ?>
            <div class="alert alert-warning alert-dismissible fade show" role="alert">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($googleTokenWarning) ?>
                <a class="btn btn-sm btn-outline-primary ms-2" href="google_auth.php">Rigenera token Google</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <?php if ($error_message): ?>
            <div class="alert alert-danger">
                <h5><i class="bi bi-exclamation-triangle-fill"></i> Errore Database</h5>
                <p><?php echo htmlspecialchars($error_message); ?></p>
                <?php if (isset($show_db_link) && $show_db_link): ?>
                    <hr>
                    <p class="mb-0">
                        <strong>Possibili soluzioni:</strong>
                    </p>
                    <ul class="mb-2">
                        <li>Verifica che il database sia configurato correttamente in <code>config/config.yaml</code></li>
                        <li>Usa la <strong>Gestione Database</strong> per inizializzare, riparare o importare il database</li>
                    </ul>
                    <a href="database_manager.php" class="btn btn-warning">
                        <i class="bi bi-database"></i> Vai a Gestione Database
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <!-- Sezione Navigazione Rapida -->
        <div class="row mb-4 dashboard-sections">
            <!-- Configurazione -->
            <div class="<?= $dashboardColClass ?> mb-3">
                <button type="button"
                        class="btn <?= $configurationIssue ? 'btn-outline-danger' : 'btn-outline-primary' ?> dashboard-toggle-btn w-100 mb-2"
                        data-target="config-section"
                        data-show-text="Configurazione"
                        data-hide-text="Chiudi Configurazione"
                        data-auto-open="<?= $configurationIssue ? 'true' : 'false' ?>"
                        aria-pressed="false"
                >
                    <i class="bi bi-gear-fill"></i> <span class="toggle-label">Configurazione</span>
                </button>
                <div class="card h-100 <?= $configurationCardBorder ?> dashboard-section d-none" id="config-section">
                    <div class="card-header <?= $configurationHeaderClass ?>">
                        <h5 class="mb-0"><i class="bi bi-gear-fill"></i> Configurazione</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="user_integrations.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-person-badge"></i> Integrazioni Utente
                            </a>
                            <a href="database_manager.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-database"></i> Gestione Database
                            </a>
                            <a href="db_cleanup.php" class="btn btn-outline-danger btn-sm" title="Cancella righe da una tabella">
                                <i class="bi bi-trash"></i> Pulizia Tabelle DB
                            </a>
                            <a href="google_auth.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-google"></i> Autenticazione Google
                            </a>
                            <a href="user_integrations.php#github-section" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-github"></i> Gestione Token GitHub
                            </a>
                            <a href="github_repo_templates.php" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-code-square"></i> Repository Template GitHub
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Setup -->
            <div class="<?= $dashboardColClass ?> mb-3">
                <button type="button"
                        class="btn btn-outline-success dashboard-toggle-btn w-100 mb-2"
                        data-target="setup-section"
                        data-show-text="Setup & Mappature"
                        data-hide-text="Chiudi Setup & Mappature"
                        aria-pressed="false"
                >
                    <i class="bi bi-tools"></i> <span class="toggle-label">Setup & Mappature</span>
                </button>
                <div class="card h-100 border-success dashboard-section d-none" id="setup-section">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-tools"></i> Setup & Mappature</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="teaching_groups.php" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-people"></i> Gruppi didattici &amp; Mappature
                            </a>
                            <a href="github_assignments.php" class="btn btn-outline-success btn-sm">
                                <i class="bi bi-github"></i> Gestione Assignment GitHub
                            </a>
                        </div>
                    </div>
                </div>
            </div>
			
            <!-- Valutazioni & Studenti -->
            <div class="<?= $dashboardColClass ?> mb-3">
                <button type="button"
                        class="btn btn-outline-info dashboard-toggle-btn w-100 mb-2"
                        data-target="students-section"
                        data-show-text="Studenti & Valutazioni"
                        data-hide-text="Chiudi Studenti & Valutazioni"
                        aria-pressed="false"
                >
                    <i class="bi bi-people-fill"></i> <span class="toggle-label">Studenti & Valutazioni</span>
                </button>
                <div class="card h-100 border-info dashboard-section d-none" id="students-section">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-people-fill"></i> Studenti & Valutazioni</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="studenti_sync.php" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-arrow-repeat"></i> Sincronizzazione Studenti
                            </a>
                            <a href="manage_grades.php" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-clipboard-data"></i> Gestione Voti
                            </a>
                            <a href="verify_grades.php" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-check2-circle"></i> Verifica & Sincronizza Voti
                            </a>
                            <a href="rubrica_orale_v2.php" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-chat-left-text"></i> Rubrica Orale
                            </a>
                            <a href="laboratorio_griglia.php" class="btn btn-outline-info btn-sm">
                                <i class="bi bi-plus-slash-minus"></i> Laboratorio +/-
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test & Debug -->
            <div class="<?= $dashboardColClass ?> mb-3">
                <button type="button"
                        class="btn btn-outline-warning dashboard-toggle-btn w-100 mb-2"
                        data-target="test-section"
                        data-show-text="Test & Debug"
                        data-hide-text="Chiudi Test & Debug"
                        aria-pressed="false"
                >
                    <i class="bi bi-bug-fill"></i> <span class="toggle-label">Test & Debug</span>
                </button>
                <div class="card h-100 border-warning dashboard-section d-none" id="test-section">
                    <div class="card-header bg-warning text-dark">
                        <h5 class="mb-0"><i class="bi bi-bug-fill"></i> Test & Debug</h5>
                    </div>
                    <div class="card-body">
                        <div class="d-grid gap-2">
                            <a href="test_api_integrations.php" class="btn btn-outline-warning btn-sm">
                                <i class="bi bi-plug"></i> Test Integrazioni API
                                <span class="badge bg-dark text-warning float-end">Consigliato</span>
                            </a>
                            <?php if ($isDebugUser): ?>
                                <a href="test.php" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-clipboard-check"></i> Test Sistema
                                </a>
                                <a href="system_status.php" class="btn btn-outline-warning btn-sm">
                                    <i class="bi bi-speedometer2"></i> Stato Sistema Avanzato
                                </a>
                            <?php endif; ?>
                        </div>
                        <?php if (!$isDebugUser): ?>
                            <div class="small text-muted mt-2">
                                Alcune voci di debug avanzato sono riservate all'amministratore.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="card">
            <div class="card-header">
                Elenco Unità di Apprendimento
            </div>
            <div class="card-body">
                <!-- Filtri -->
                <form method="GET" class="row g-2 mb-3">
                    <div class="col-md-3">
                        <label class="form-label mb-1">Anno scolastico</label>
                        <select name="anno" class="form-select" onchange="this.form.submit()">
                            <option value="" <?= $filterYear === '' ? 'selected' : '' ?>>Tutti</option>
                            <?php foreach ($anniDistinct as $anno => $_): ?>
                                <option value="<?= htmlspecialchars($anno) ?>" <?= $anno === $filterYear ? 'selected' : '' ?>><?= htmlspecialchars($anno) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Classe destinazione</label>
                        <select name="classe" class="form-select" onchange="this.form.submit()">
                            <option value="">Tutte</option>
                            <?php foreach ($classiDistinct as $cls => $_): ?>
                                <option value="<?= htmlspecialchars($cls) ?>" <?= $cls === $filterClasse ? 'selected' : '' ?>><?= htmlspecialchars($cls) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label mb-1">Disciplina/Materia</label>
                        <select name="materia" class="form-select" onchange="this.form.submit()">
                            <option value="">Tutte</option>
                            <?php foreach ($materieDistinct as $mat => $_): ?>
                                <option value="<?= htmlspecialchars($mat) ?>" <?= $mat === $filterMateria ? 'selected' : '' ?>><?= htmlspecialchars($mat) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3 d-flex align-items-end">
                        <a href="index.php" class="btn btn-outline-secondary w-100">Reset filtri</a>
                    </div>
                </form>

                <?php if (empty($filteredUdas) && !$error_message): ?>
                    <div class="alert alert-info">
                        Nessuna Unità di Apprendimento trovata con i filtri selezionati. <a href="uda_create.php">Inizia creandone una nuova!</a>
                    </div>
                <?php else: ?>
                    <div class="uda-table-container">
                        <table class="table table-hover uda-table mb-0">
                            <thead>
                                <tr>
                                    <th><?= sortLink('titolo', 'Titolo UDA', $sortField, $sortDir, $filterYear, $filterClasse, $filterMateria); ?></th>
                                    <th><?= sortLink('argomento', 'Argomento', $sortField, $sortDir, $filterYear, $filterClasse, $filterMateria); ?></th>
                                    <th><?= sortLink('classe', 'Classi e Discipline', $sortField, $sortDir, $filterYear, $filterClasse, $filterMateria); ?></th>
                                    <th><?= sortLink('anno', 'Anno Scolastico', $sortField, $sortDir, $filterYear, $filterClasse, $filterMateria); ?></th>
                                    <th>Stato</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($filteredUdas as $uda): ?>
                                    <tr class="uda-row" data-href="uda_view.php?id=<?= urlencode($uda->id_uda) ?>">
                                        <td>
                                            <strong><?= htmlspecialchars($uda->titolo) ?></strong>
                                        </td>
                                        <td class="argomento-cell text-muted">
                                            <span><?= htmlspecialchars($uda->argomento ?? 'N/D') ?></span>
                                        </td>
                                        <td>
                                            <?php
                                            $clsList = $classiByUda[$uda->id_uda] ?? [];
                                            // Deduplica per nome classe mantenendo tutti gli elementi
                                            $seenClasses = [];
                                            $uniqueClasses = [];
                                            foreach ($clsList as $cItem) {
                                                $nameKey = $cItem['nome_classe'] ?? json_encode($cItem);
                                                if (isset($seenClasses[$nameKey])) {
                                                    continue;
                                                }
                                                $seenClasses[$nameKey] = true;
                                                $uniqueClasses[] = $cItem;
                                            }
                                            $clsList = $uniqueClasses;
                                            if (empty($clsList)): ?>
                                                <span class="badge bg-secondary">N/A</span>
                                            <?php else:
                                                foreach ($clsList as $c):
                                                    $label = $c['nome_classe'] ?? 'Classe';
                                                    $labelShort = (strlen($label) > 20) ? substr($label, 0, 17) . '...' : $label;
                                                    $color = badgeColor($label);
                                                    ?>
                                                    <span class="badge" style="background-color: <?= $color ?>; color: #fff;" title="<?= htmlspecialchars($label) ?>"><?= htmlspecialchars($labelShort) ?></span>
                                                <?php endforeach;
                                            endif; ?>

                                            <?php
                                            $materie = [];
                                            $seenMaterie = [];
                                            $disciplinaUda = $uda->disciplina ?? '';
                                            if ($disciplinaUda === '' && !empty($uda->id_uda) && !empty($disciplinaById[$uda->id_uda])) {
                                                $disciplinaUda = $disciplinaById[$uda->id_uda];
                                            }
                                            if ($disciplinaUda !== '') {
                                                $key = strtolower($disciplinaUda);
                                                if (!isset($seenMaterie[$key])) {
                                                    $seenMaterie[$key] = true;
                                                    $materie[] = $disciplinaUda;
                                                }
                                            }
                                            foreach ($classiByUda[$uda->id_uda] ?? [] as $c) {
                                                if (!empty($c['nome_materia'])) {
                                                    $key = strtolower($c['nome_materia']);
                                                    if (!isset($seenMaterie[$key])) {
                                                        $seenMaterie[$key] = true;
                                                        $materie[] = $c['nome_materia'];
                                                    }
                                                }
                                            }
                                            ?>
                                            <div class="mt-2">
                                                <?php if (empty($materie)): ?>
                                                    <span class="badge bg-secondary">N/A</span>
                                                <?php else:
                                                    foreach ($materie as $m):
                                                        $color = badgeColor($m);
                                                        ?>
                                                        <span class="badge subject-badge" style="background-color: <?= $color ?>; color: #fff;"><?= htmlspecialchars($m) ?></span>
                                                    <?php endforeach;
                                                endif; ?>
                                            </div>
                                        </td>
                                        <td><?= htmlspecialchars($uda->anno_scolastico ?? '') ?></td>
                                        <td>
                                            <?php
                                                $badge_class = 'bg-secondary';
                                                if ($uda->stato === 'attiva') $badge_class = 'bg-success';
                                                if ($uda->stato === 'bozza') $badge_class = 'bg-warning text-dark';
                                                if ($uda->stato === 'completata') $badge_class = 'bg-info';
                                            ?>
                                            <span class="badge <?= $badge_class ?>"><?= htmlspecialchars(ucfirst($uda->stato ?? '')) ?></span>
                                        </td>
                                        <td>
                                            <div class="uda-actions">
                                                <a href="uda_view.php?id=<?= urlencode($uda->id_uda); ?>" class="btn btn-sm btn-info" title="Visualizza"><i class="bi bi-eye"></i></a>
                                                <a href="uda_edit.php?id=<?= urlencode($uda->id_uda); ?>" class="btn btn-sm btn-warning" title="Modifica"><i class="bi bi-pencil"></i></a>
                                                <a href="uda_assign.php?id=<?= urlencode($uda->id_uda); ?>" class="btn btn-sm btn-primary" title="Assegna Classi"><i class="bi bi-people"></i></a>
                                                <a href="uda_publish.php?id=<?= urlencode($uda->id_uda); ?>" class="btn btn-sm btn-success" title="Pubblica su Classroom"><i class="bi bi-send"></i></a>
                                                <a href="uda_delete.php?id=<?= urlencode($uda->id_uda); ?>" class="btn btn-sm btn-danger" title="Elimina"><i class="bi bi-trash"></i></a>
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
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        if ('serviceWorker' in navigator) {
            window.addEventListener('load', function () {
                navigator.serviceWorker
                    .register('sw.js')
                    .catch(function (err) {
                        console.error('Service worker registration failed:', err);
                    });
            });
        }
    </script>
    <script>
        document.addEventListener('DOMContentLoaded', function () {
            document.querySelectorAll('.uda-row').forEach(function (row) {
                row.addEventListener('click', function (event) {
                    const ignore = event.target.closest('a, button');
                    if (ignore) {
                        return;
                    }
                    const href = row.getAttribute('data-href');
                    if (href) {
                        window.location.href = href;
                    }
                });
            });
            const dashboardToggleButtons = document.querySelectorAll('.dashboard-toggle-btn');
            dashboardToggleButtons.forEach(btn => {
                const targetId = btn.dataset.target;
                if (!targetId) {
                    return;
                }
                const target = document.getElementById(targetId);
                if (!target) {
                    return;
                }
                const label = btn.querySelector('.toggle-label');
                const showText = btn.dataset.showText || (label ? label.textContent.trim() : '');
                const hideText = btn.dataset.hideText || showText;
                const toggle = () => {
                    const hidden = target.classList.toggle('d-none');
                    const visible = !hidden;
                    btn.classList.toggle('active', visible);
                    btn.setAttribute('aria-pressed', String(visible));
                    if (label) {
                        label.textContent = visible ? hideText : showText;
                    }
                };
                btn.addEventListener('click', toggle);
                if (btn.dataset.autoOpen === 'true') {
                    toggle();
                }
            });
        });
    </script>

    <!-- Modal Guida Onboarding -->
    <?php if ($mostraGuida): ?>
    <div class="modal fade" id="guidaOnboardingModal" tabindex="-1" aria-labelledby="guidaOnboardingModalLabel" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h4 class="modal-title" id="guidaOnboardingModalLabel">
                        <i class="bi bi-book-half"></i> Benvenuto nel Sistema di Gestione UDA
                    </h4>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <?php
                    // Include il contenuto riutilizzabile della guida
                    $section = 'full';
                    $containerClass = 'container-fluid';
                    include __DIR__ . '/partials/guida_content.php';
                    ?>
                </div>
                <div class="modal-footer">
                    <div class="form-check me-auto">
                        <input class="form-check-input" type="checkbox" id="nonMostrarePiu">
                        <label class="form-check-label" for="nonMostrarePiu">
                            Non mostrare più questa guida
                        </label>
                    </div>
                    <button type="button" class="btn btn-primary" id="chiudiGuidaBtn">
                        <i class="bi bi-check-circle"></i> Ho capito, iniziamo!
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script>
        // Gestione popup guida onboarding
        document.addEventListener('DOMContentLoaded', function() {
            const guidaModal = new bootstrap.Modal(document.getElementById('guidaOnboardingModal'));
            const chiudiBtn = document.getElementById('chiudiGuidaBtn');
            const checkboxNonMostrare = document.getElementById('nonMostrarePiu');

            // Mostra il modal all'apertura della pagina
            guidaModal.show();

            // Gestione click su "Ho capito"
            chiudiBtn.addEventListener('click', function() {
                const nonMostrare = checkboxNonMostrare.checked;

                if (nonMostrare) {
                    // Salva la preferenza via AJAX
                    fetch('ajax_save_guide_preference.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'mostra_guida=0'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            console.log('Preferenza guida salvata');
                        } else {
                            console.error('Errore salvataggio preferenza:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Errore AJAX:', error);
                    });
                }

                // Chiudi il modal
                guidaModal.hide();
            });
        });
    </script>
    <?php endif; ?>

    <!-- Footer con link legali -->
    <footer class="text-center text-muted py-4 mt-5" style="background-color: #f8f9fa; border-top: 1px solid #dee2e6;">
        <div class="container">
            <small>
                &copy; <?= date('Y') ?> Sistema UDA - Tutti i diritti riservati
                <span class="mx-2">|</span>
                <a href="../privacy-policy.html" target="_blank" class="text-decoration-none">Privacy Policy</a>
                <span class="mx-2">|</span>
                <a href="../termini-servizio.html" target="_blank" class="text-decoration-none">Termini di Servizio</a>
            </small>
        </div>
    </footer>

</body>
</html>
