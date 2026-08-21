<?php
/**
 * Pagina di Test Integrazioni API
 *
 * Permette di testare le funzionalita' di scrittura sulle piattaforme esterne:
 * - Google Drive (upload file, creazione cartelle)
 * - Google Classroom (topic, materiali, compiti)
 * - ClasseViva (voti, annotazioni)
 */

if (!defined('SKIP_CV_TOKEN_POPUP')) {
    define('SKIP_CV_TOKEN_POPUP', true);
}

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Integration\GoogleDriveAPI;
use App\Integration\GoogleClassroomAPI;
use App\Integration\ClasseVivaAPI;
use App\Core\Security\Authorization;

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
Authorization::assertAdmin($_SESSION, array_filter(array_map('trim', explode(',', (string)env('ADMIN_EMAILS', '')))));

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

// Banner onboarding (prime 4 settimane dalla privacy policy)
$showOnboardingBanner = false;
$onboardingBannerDays = 28;
try {
    $userIdForBanner = (string)($_SESSION['user_id'] ?? '');
    if ($userIdForBanner !== '') {
        $bannerUser = $dbAdapter->findOne('UTENTI', 'id_utente', $userIdForBanner);
        $consentDate = is_array($bannerUser) ? (string)($bannerUser['privacy_consent_date'] ?? '') : '';
        if ($consentDate !== '') {
            $consentTs = strtotime($consentDate);
            if ($consentTs !== false) {
                $showOnboardingBanner = (time() - $consentTs) <= ($onboardingBannerDays * 86400);
            }
        }
    }
} catch (Exception $e) {
    $showOnboardingBanner = false;
}

// Verifica stato integrazioni
$googleEnabled = !empty($config['google']['enabled']);
$driveEnabled = $googleEnabled && !empty($config['google']['drive']['enabled']);
$classroomEnabled = $googleEnabled && !empty($config['google']['classroom']['enabled']);
$formsEnabled = $googleEnabled && !empty($config['google']['forms']['enabled']);

$cvConfig = $config['classeviva'] ?? [];
$cvEnabled = !empty($cvConfig['enabled']);
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvReady = $cvEnabled && $cvTokenValid;

// Prepara dati per i dropdown (caricati via AJAX)
$driveRootFolder = $config['google']['drive']['root_folder_id'] ?? '';
$csrfSession = &$_SESSION;
$csrfToken = \App\Core\Security\Csrf::token($csrfSession);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Test Integrazioni API - UDA System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .card { box-shadow: 0 2px 6px rgba(0,0,0,0.08); }
        .test-card { border-left: 4px solid #6c757d; transition: border-color 0.3s; }
        .test-card.write-test { border-left-color: #ffc107; }
        .test-card.success { border-left-color: #198754; }
        .test-card.error { border-left-color: #dc3545; }
        .test-card.running { border-left-color: #0d6efd; }
        .warning-icon { color: #ffc107; }
        .log-entry { font-family: monospace; font-size: 0.85rem; padding: 4px 8px; border-bottom: 1px solid #eee; }
        .log-entry.success { background: #d1e7dd; }
        .log-entry.error { background: #f8d7da; }
        .log-entry.info { background: #cff4fc; }
        .spinner-border-sm { width: 1rem; height: 1rem; }
        .btn-test { min-width: 120px; }
        .nav-tabs .nav-link { color: #495057; }
        .nav-tabs .nav-link.active { font-weight: 600; }
        .status-badge { font-size: 0.75rem; }
        .cleanup-created { margin-top: 0.5rem; }
        .cleanup-created .btn { font-size: 0.75rem; padding: 2px 8px; }
        .verify-link { margin-left: 0.5rem; }
        .onboarding-banner ul { padding-left: 1.2rem; }
        .onboarding-banner .alert-heading { font-size: 1.05rem; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">UDA System</a>
        <span class="navbar-text">Test Integrazioni API</span>
    </div>
</nav>

<?php if ($showOnboardingBanner): ?>
    <div class="container my-3">
        <div class="alert alert-warning border-warning onboarding-banner">
            <div class="d-flex align-items-start gap-3">
                <div class="fs-3 text-warning"><i class="bi bi-exclamation-triangle-fill"></i></div>
                <div>
                    <div class="alert-heading mb-2"><strong>Sistema in fase di test: fai i test con gli studenti.</strong></div>
                    <p class="mb-2">
                        Nelle prime 4 settimane di utilizzo prova le integrazioni insieme agli studenti e
                        <strong>ricontrolla sempre</strong> tutte le azioni svolte dalla piattaforma. <strong>Ricontrolla sempre.</strong>
                    </p>
                    <ul class="small mb-2">
                        <li><strong>Test prima delle automazioni:</strong> esegui i test prima di attivare operazioni automatiche
                            su ClasseViva e Google Classroom. Sono strumenti di valutazione con valore legale e il proprietario
                            non si assume alcuna responsabilita'.
                        </li>
                        <li><strong>ClasseViva puo' essere personalizzato</strong> dalla scuola e puo' comportarsi in modo diverso
                            durante quadrimestri, trimestri, pentamestri o bimestri: ricontrolla sempre i risultati.
                        </li>
                        <li>Se la piattaforma non funziona correttamente, contatta lo sviluppatore per supporto tecnico e per migliorare la piattaforma.</li>
                    </ul>
                    <a href="guida_portale.php" class="btn btn-outline-dark btn-sm">
                        <i class="bi bi-book"></i> Apri Guida e Note di Test
                    </a>
                </div>
            </div>
        </div>
    </div>
<?php endif; ?>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0"><i class="bi bi-plug"></i> Test Integrazioni API</h3>
            <small class="text-muted">Verifica le funzionalita' di scrittura sulle piattaforme esterne</small>
        </div>
        <a class="btn btn-outline-secondary" href="user_integrations.php">
            <i class="bi bi-gear"></i> Configura Integrazioni
        </a>
    </div>

    <!-- Stato Integrazioni -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card">
                <div class="card-body d-flex align-items-center">
                    <i class="bi bi-cloud fs-3 me-3 text-primary"></i>
                    <div>
                        <div class="fw-bold">Google Drive</div>
                        <?php if ($driveEnabled): ?>
                            <span class="badge bg-success">Attivo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disattivo</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body d-flex align-items-center">
                    <i class="bi bi-mortarboard fs-3 me-3 text-success"></i>
                    <div>
                        <div class="fw-bold">Classroom</div>
                        <?php if ($classroomEnabled): ?>
                            <span class="badge bg-success">Attivo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disattivo</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body d-flex align-items-center">
                    <i class="bi bi-ui-checks fs-3 me-3 text-purple" style="color: #7c3aed;"></i>
                    <div>
                        <div class="fw-bold">Google Forms</div>
                        <?php if ($formsEnabled): ?>
                            <span class="badge bg-success">Attivo</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disattivo</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card">
                <div class="card-body d-flex align-items-center">
                    <i class="bi bi-journal-check fs-3 me-3 text-danger"></i>
                    <div>
                        <div class="fw-bold">ClasseViva</div>
                        <?php if ($cvReady): ?>
                            <span class="badge bg-success">Attivo</span>
                        <?php elseif ($cvEnabled): ?>
                            <span class="badge bg-warning text-dark">Token non valido</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disattivo</span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Tab Navigation -->
    <ul class="nav nav-tabs mb-4" id="testTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="drive-tab" data-bs-toggle="tab" data-bs-target="#drive-panel" type="button">
                <i class="bi bi-cloud-upload"></i> Google Drive
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="classroom-tab" data-bs-toggle="tab" data-bs-target="#classroom-panel" type="button">
                <i class="bi bi-mortarboard"></i> Google Classroom
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="forms-tab" data-bs-toggle="tab" data-bs-target="#forms-panel" type="button">
                <i class="bi bi-ui-checks"></i> Google Forms
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="classeviva-tab" data-bs-toggle="tab" data-bs-target="#classeviva-panel" type="button">
                <i class="bi bi-journal-check"></i> ClasseViva
            </button>
        </li>
    </ul>

    <div class="tab-content">
        <!-- GOOGLE DRIVE TAB -->
        <div class="tab-pane fade show active" id="drive-panel" role="tabpanel">
            <?php if (!$driveEnabled): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Google Drive non configurato.
                    <a href="user_integrations.php#google-section">Configura l'integrazione</a>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Cartella di destinazione</label>
                        <select class="form-select" id="drive-folder-select">
                            <option value="<?= htmlspecialchars($driveRootFolder) ?>">Root configurata</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary mt-2" onclick="loadDriveFolders()">
                            <i class="bi bi-arrow-clockwise"></i> Carica cartelle
                        </button>
                    </div>
                    <div class="col-md-6 text-end">
                        <button class="btn btn-primary" onclick="runBatchTests('drive')">
                            <i class="bi bi-play-fill"></i> Esegui tutti i test Drive
                        </button>
                        <button class="btn btn-outline-danger" onclick="cleanupBatch('drive')">
                            <i class="bi bi-trash"></i> Cleanup batch
                        </button>
                    </div>
                </div>

                <!-- Test Upload File -->
                <div class="card test-card write-test mb-3" id="test-drive-upload">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Upload File Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Carica un file di test su Google Drive. Il file verra' creato nella cartella selezionata.
                                </p>
                                <div class="test-result" id="result-drive-upload"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('drive', 'upload')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-cloud-upload"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Crea Cartella -->
                <div class="card test-card write-test mb-3" id="test-drive-folder">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Crea Cartella Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Crea una cartella di test su Google Drive.
                                </p>
                                <div class="test-result" id="result-drive-folder"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('drive', 'folder')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-folder-plus"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Lista File (lettura) -->
                <div class="card test-card mb-3" id="test-drive-list">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Lista File/Cartelle</h6>
                                <p class="card-text text-muted small mb-2">
                                    Recupera la lista dei file nella cartella selezionata.
                                </p>
                                <div class="test-result" id="result-drive-list"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('drive', 'list')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-list"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- GOOGLE CLASSROOM TAB -->
        <div class="tab-pane fade" id="classroom-panel" role="tabpanel">
            <?php if (!$classroomEnabled): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Google Classroom non configurato.
                    <a href="user_integrations.php#google-section">Configura l'integrazione</a>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-6">
                        <label class="form-label">Corso Classroom</label>
                        <select class="form-select" id="classroom-course-select">
                            <option value="">-- Seleziona corso --</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary mt-2" onclick="loadClassroomCourses()">
                            <i class="bi bi-arrow-clockwise"></i> Carica corsi
                        </button>
                    </div>
                    <div class="col-md-6 text-end">
                        <button class="btn btn-primary" onclick="runBatchTests('classroom')">
                            <i class="bi bi-play-fill"></i> Esegui tutti i test Classroom
                        </button>
                        <button class="btn btn-outline-danger" onclick="cleanupBatch('classroom')">
                            <i class="bi bi-trash"></i> Cleanup batch
                        </button>
                    </div>
                </div>

                <!-- Test Lista Corsi -->
                <div class="card test-card mb-3" id="test-classroom-courses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Lista Corsi</h6>
                                <p class="card-text text-muted small mb-2">
                                    Recupera l'elenco dei corsi Classroom del docente.
                                </p>
                                <div class="test-result" id="result-classroom-courses"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('classroom', 'courses')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-list"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Crea Topic -->
                <div class="card test-card write-test mb-3" id="test-classroom-topic">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Crea Argomento Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Crea un argomento (topic) di test nel corso selezionato.
                                </p>
                                <div class="test-result" id="result-classroom-topic"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('classroom', 'topic')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-bookmark-plus"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Pubblica Materiale -->
                <div class="card test-card write-test mb-3" id="test-classroom-material">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Pubblica Materiale Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Pubblica un materiale didattico di test (come BOZZA).
                                </p>
                                <div class="test-result" id="result-classroom-material"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('classroom', 'material')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-file-earmark-plus"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Crea Compito -->
                <div class="card test-card write-test mb-3" id="test-classroom-assignment">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Crea Compito Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Crea un compito (assignment) di test (come BOZZA).
                                </p>
                                <div class="test-result" id="result-classroom-assignment"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('classroom', 'assignment')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-clipboard-plus"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Lista Studenti -->
                <div class="card test-card mb-3" id="test-classroom-students">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Lista Studenti Corso</h6>
                                <p class="card-text text-muted small mb-2">
                                    Recupera l'elenco degli studenti del corso selezionato.
                                </p>
                                <div class="test-result" id="result-classroom-students"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('classroom', 'students')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-people"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>

        <!-- GOOGLE FORMS TAB -->
        <div class="tab-pane fade" id="forms-panel" role="tabpanel">
            <?php if (!$formsEnabled): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> Google Forms non configurato.
                    <a href="user_integrations.php#google-section">Configura l'integrazione</a>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-8">
                        <p class="text-muted mb-0">
                            <i class="bi bi-info-circle"></i>
                            Testa la creazione di un Google Form (quiz) con una domanda e il recupero delle risposte.
                        </p>
                    </div>
                    <div class="col-md-4 text-end">
                        <button class="btn btn-primary" onclick="runBatchTests('forms')">
                            <i class="bi bi-play-fill"></i> Esegui tutti i test Forms
                        </button>
                        <button class="btn btn-outline-danger" onclick="cleanupBatch('forms')">
                            <i class="bi bi-trash"></i> Cleanup batch
                        </button>
                    </div>
                </div>

                <!-- Test Crea Form -->
                <div class="card test-card write-test mb-3" id="test-forms-create">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Crea Form Quiz Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Crea un Google Form (quiz) con una domanda a scelta multipla di test.
                                    Il form verra' creato nel tuo Google Drive.
                                </p>
                                <div class="test-result" id="result-forms-create"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('forms', 'create')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-file-earmark-plus"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Recupera Risposte -->
                <div class="card test-card mb-3" id="test-forms-responses">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Recupera Risposte Form</h6>
                                <p class="card-text text-muted small mb-2">
                                    Recupera le risposte dal form creato (richiede prima la creazione del form).
                                </p>
                                <div class="mb-2">
                                    <label class="form-label small">Form ID (dal test precedente)</label>
                                    <input type="text" class="form-control form-control-sm" id="forms-test-id"
                                           placeholder="Verra' popolato automaticamente dopo la creazione" style="max-width: 400px;">
                                </div>
                                <div class="test-result" id="result-forms-responses"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('forms', 'responses')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-list-check"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <!-- CLASSEVIVA TAB -->
        <div class="tab-pane fade" id="classeviva-panel" role="tabpanel">
            <?php if (!$cvReady): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i> ClasseViva non configurato o token non valido.
                    <a href="user_integrations.php#classeviva-section">Configura l'integrazione</a>
                </div>
            <?php else: ?>
                <div class="row mb-3">
                    <div class="col-md-3">
                        <label class="form-label">Classe</label>
                        <select class="form-select" id="cv-class-select" onchange="onCvClassChange()">
                            <option value="">-- Seleziona classe --</option>
                        </select>
                        <button class="btn btn-sm btn-outline-secondary mt-2" onclick="loadCvClasses()">
                            <i class="bi bi-arrow-clockwise"></i> Carica classi
                        </button>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Studente</label>
                        <select class="form-select" id="cv-student-select">
                            <option value="">-- Prima seleziona classe --</option>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Materia</label>
                        <select class="form-select" id="cv-subject-select">
                            <option value="">-- Prima seleziona classe --</option>
                        </select>
                    </div>
                    <div class="col-md-3 text-end">
                        <label class="form-label">&nbsp;</label>
                        <div>
                            <button class="btn btn-primary" onclick="runBatchTests('classeviva')">
                                <i class="bi bi-play-fill"></i> Esegui tutti
                            </button>
                            <button class="btn btn-outline-danger" onclick="cleanupBatch('classeviva')">
                                <i class="bi bi-trash"></i> Cleanup
                            </button>
                        </div>
                    </div>
                </div>

                <!-- Test Login/Token -->
                <div class="card test-card mb-3" id="test-classeviva-login">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Verifica Autenticazione</h6>
                                <p class="card-text text-muted small mb-2">
                                    Verifica che il token ClasseViva sia valido e la sessione attiva.
                                </p>
                                <div class="test-result" id="result-classeviva-login"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('classeviva', 'login')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-key"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Lista Classi -->
                <div class="card test-card mb-3" id="test-classeviva-classes">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">Lista Classi</h6>
                                <p class="card-text text-muted small mb-2">
                                    Recupera l'elenco delle classi con studenti.
                                </p>
                                <div class="test-result" id="result-classeviva-classes"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-outline-primary btn-test" onclick="runTest('classeviva', 'classes')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-people"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Inserisci Voto -->
                <div class="card test-card write-test mb-3" id="test-classeviva-grade">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Inserisci Voto Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Inserisce un voto orale di test (valore selezionato) per lo studente selezionato.
                                    <strong class="text-danger">Il voto sara' visibile sul registro!</strong>
                                </p>
                                <div class="row mb-2">
                                    <div class="col-auto">
                                        <label class="form-label small">Tipo voto</label>
                                        <select class="form-select form-select-sm" id="cv-grade-type" style="width:120px">
                                            <option value="orale">Orale</option>
                                            <option value="scritto">Scritto</option>
                                            <option value="pratico">Pratico</option>
                                        </select>
                                    </div>
                                    <div class="col-auto">
                                        <label class="form-label small">Valore</label>
                                        <select class="form-select form-select-sm" id="cv-grade-value" style="width:80px">
                                            <option value="7" selected>7</option>
                                            <option value="6">6</option>
                                            <option value="8">8</option>
                                            <option value="8.5">8,5</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="test-result" id="result-classeviva-grade"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('classeviva', 'grade')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-pencil-square"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Test Inserisci Annotazione -->
                <div class="card test-card write-test mb-3" id="test-classeviva-annotation">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="card-title">
                                    <i class="bi bi-exclamation-triangle warning-icon"></i>
                                    Inserisci Annotazione Test
                                </h6>
                                <p class="card-text text-muted small mb-2">
                                    Inserisce un'annotazione positiva di test per lo studente selezionato.
                                    <strong class="text-danger">L'annotazione sara' visibile sul registro!</strong>
                                </p>
                                <div class="row mb-2">
                                    <div class="col-auto">
                                        <label class="form-label small">Tipo</label>
                                        <select class="form-select form-select-sm" id="cv-annotation-type" style="width:120px">
                                            <option value="positive">Positiva (verde)</option>
                                            <option value="negative">Negativa (rosso)</option>
                                            <option value="neutral">Neutra</option>
                                        </select>
                                    </div>
                                </div>
                                <div class="test-result" id="result-classeviva-annotation"></div>
                            </div>
                            <div class="text-end">
                                <button class="btn btn-warning btn-test" onclick="runTest('classeviva', 'annotation')">
                                    <span class="spinner-border spinner-border-sm d-none" role="status"></span>
                                    <i class="bi bi-chat-left-text"></i> Esegui
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Log Area -->
    <div class="card mt-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="bi bi-terminal"></i> Log Risultati</span>
            <button class="btn btn-sm btn-outline-secondary" onclick="clearLog()">
                <i class="bi bi-trash"></i> Pulisci log
            </button>
        </div>
        <div class="card-body p-0" style="max-height: 300px; overflow-y: auto;">
            <div id="log-container">
                <div class="log-entry info">
                    <span class="text-muted"><?= date('H:i:s') ?></span> - Sistema pronto. Seleziona un test da eseguire.
                </div>
            </div>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Stato globale per cleanup
const createdResources = {
    drive: [],
    classroom: [],
    forms: [],
    classeviva: []
};

// Cache dati caricati
let cvClassesData = [];
let cvSubjectsData = [];

function addLog(message, type = 'info') {
    const container = document.getElementById('log-container');
    const time = new Date().toLocaleTimeString('it-IT');
    const icon = type === 'success' ? '<i class="bi bi-check-circle text-success"></i>' :
                 type === 'error' ? '<i class="bi bi-x-circle text-danger"></i>' :
                 '<i class="bi bi-info-circle text-info"></i>';
    const entry = document.createElement('div');
    entry.className = `log-entry ${type}`;
    entry.innerHTML = `<span class="text-muted">${time}</span> ${icon} ${message}`;
    container.insertBefore(entry, container.firstChild);
}

function clearLog() {
    document.getElementById('log-container').innerHTML = '';
    addLog('Log pulito', 'info');
}

function setTestState(platform, test, state) {
    const card = document.getElementById(`test-${platform}-${test}`);
    if (!card) return;
    card.classList.remove('success', 'error', 'running');
    if (state) card.classList.add(state);

    const btn = card.querySelector('.btn-test');
    const spinner = btn.querySelector('.spinner-border');
    if (state === 'running') {
        spinner.classList.remove('d-none');
        btn.disabled = true;
    } else {
        spinner.classList.add('d-none');
        btn.disabled = false;
    }
}

function showTestResult(platform, test, success, message, data = null) {
    const resultDiv = document.getElementById(`result-${platform}-${test}`);
    if (!resultDiv) return;

    let html = '';
    if (success) {
        html = `<div class="alert alert-success py-2 mb-0 small">
            <i class="bi bi-check-circle"></i> ${message}`;

        // Aggiungi link verifica per test di scrittura
        if (data && data.verifyUrl) {
            html += ` <a href="${data.verifyUrl}" target="_blank" class="verify-link">
                <i class="bi bi-box-arrow-up-right"></i> Verifica sulla piattaforma
            </a>`;
        }

        // Aggiungi pulsante cleanup se risorsa creata
        // Per ClasseViva grade/annotation, mostra solo se canCleanup è true
        const isClasseVivaWriteTest = platform === 'classeviva' && (test === 'grade' || test === 'annotation');
        const showCleanup = data && data.resourceId &&
            (!isClasseVivaWriteTest || data.canCleanup);

        if (showCleanup) {
            const resourceData = {
                test: test,
                id: data.resourceId,
                name: data.resourceName || data.resourceId
            };

            // Per ClasseViva grade, salva anche i dati extra necessari per il cleanup
            if (platform === 'classeviva' && test === 'grade') {
                resourceData.student_id = data.gradeData?.student_id || '';
                resourceData.class_id = data.gradeData?.class_id || '';
                resourceData.subject_id = data.gradeData?.subject_id || '';
                resourceData.description_code = data.description_code || '';
            }

            // Per ClasseViva annotation, salva i dati extra necessari per il cleanup
            if (platform === 'classeviva' && test === 'annotation') {
                resourceData.student_id = data.annotationData?.student_id || '';
                resourceData.class_id = data.annotationData?.class_id || '';
                resourceData.annotation_date = data.annotationData?.date || '';
            }

            createdResources[platform].push(resourceData);
            html += `<div class="cleanup-created">
                <button class="btn btn-outline-danger btn-sm" onclick="cleanupSingle('${platform}', '${test}', '${data.resourceId}')">
                    <i class="bi bi-trash"></i> Elimina "${data.resourceName || 'risorsa'}"
                </button>
            </div>`;
        } else if (isClasseVivaWriteTest && data && !data.canCleanup) {
            // Voto/annotazione creato ma senza evento_id - cleanup manuale
            const resourceType = test === 'grade' ? 'voto' : 'annotazione';
            html += `<div class="cleanup-manual mt-2">
                <small class="text-warning"><i class="bi bi-exclamation-triangle"></i> ${resourceType.charAt(0).toUpperCase() + resourceType.slice(1)} deve essere eliminato manualmente dal registro ClasseViva</small>
            </div>`;
        }

        html += `</div>`;
    } else {
        html = `<div class="alert alert-danger py-2 mb-0 small">
            <i class="bi bi-x-circle"></i> ${message}
        </div>`;
    }
    resultDiv.innerHTML = html;
}

async function runTest(platform, test) {
    setTestState(platform, test, 'running');
    addLog(`Esecuzione test: ${platform}/${test}...`, 'info');

    const params = new URLSearchParams();
    params.append('csrf_token', <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    params.append('action', 'run_test');
    params.append('platform', platform);
    params.append('test', test);

    // Aggiungi parametri specifici
    if (platform === 'drive') {
        params.append('folder_id', document.getElementById('drive-folder-select')?.value || '');
    } else if (platform === 'classroom') {
        params.append('course_id', document.getElementById('classroom-course-select')?.value || '');
    } else if (platform === 'classeviva') {
        params.append('class_id', document.getElementById('cv-class-select')?.value || '');
        params.append('student_id', document.getElementById('cv-student-select')?.value || '');
        params.append('subject_id', document.getElementById('cv-subject-select')?.value || '');

        // Dati aggiuntivi per la materia (nome)
        const subjectSelect = document.getElementById('cv-subject-select');
        if (subjectSelect && subjectSelect.selectedIndex > 0) {
            params.append('subject_name', subjectSelect.options[subjectSelect.selectedIndex].text);
        }

        if (test === 'grade') {
            params.append('grade_type', document.getElementById('cv-grade-type')?.value || 'orale');
            params.append('grade_value', document.getElementById('cv-grade-value')?.value || '7');
        } else if (test === 'annotation') {
            params.append('annotation_type', document.getElementById('cv-annotation-type')?.value || 'positive');
        }
    } else if (platform === 'forms') {
        if (test === 'responses') {
            params.append('form_id', document.getElementById('forms-test-id')?.value || '');
        }
    }

    try {
        const response = await fetch('api/test_api_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });

        const result = await response.json();

        if (result.success) {
            setTestState(platform, test, 'success');
            showTestResult(platform, test, true, result.message, result.data);
            addLog(`Test ${platform}/${test}: SUCCESSO - ${result.message}`, 'success');

            // Se abbiamo creato un form, popola automaticamente l'ID per il test risposte
            if (platform === 'forms' && test === 'create' && result.data?.resourceId) {
                const formIdInput = document.getElementById('forms-test-id');
                if (formIdInput) {
                    formIdInput.value = result.data.resourceId;
                }
            }
        } else {
            setTestState(platform, test, 'error');
            showTestResult(platform, test, false, result.error || 'Errore sconosciuto');
            addLog(`Test ${platform}/${test}: ERRORE - ${result.error}`, 'error');
        }
    } catch (err) {
        setTestState(platform, test, 'error');
        showTestResult(platform, test, false, err.message);
        addLog(`Test ${platform}/${test}: ERRORE - ${err.message}`, 'error');
    }
}

async function cleanupSingle(platform, test, resourceId) {
    if (!confirm(`Eliminare la risorsa creata dal test?`)) return;

    addLog(`Cleanup: eliminazione risorsa ${resourceId}...`, 'info');

    const params = new URLSearchParams();
    params.append('csrf_token', <?= json_encode($csrfToken, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>);
    params.append('action', 'cleanup');
    params.append('platform', platform);
    params.append('test', test);
    params.append('resource_id', resourceId);

    // Aggiungi course_id per classroom
    if (platform === 'classroom') {
        params.append('course_id', document.getElementById('classroom-course-select')?.value || '');
    }

    // Per ClasseViva grade, aggiungi i parametri extra salvati
    if (platform === 'classeviva' && test === 'grade') {
        const resource = createdResources[platform].find(r => r.id === resourceId && r.test === test);
        if (resource) {
            params.append('student_id', resource.student_id || '');
            params.append('class_id', resource.class_id || '');
            params.append('subject_id', resource.subject_id || '');
            params.append('description_code', resource.description_code || '');
        }
    }

    // Per ClasseViva annotation, aggiungi i parametri extra salvati
    if (platform === 'classeviva' && test === 'annotation') {
        const resource = createdResources[platform].find(r => r.id === resourceId && r.test === test);
        if (resource) {
            params.append('student_id', resource.student_id || '');
            params.append('class_id', resource.class_id || '');
            params.append('annotation_date', resource.annotation_date || '');
        }
    }

    try {
        const response = await fetch('api/test_api_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: params.toString()
        });

        const result = await response.json();

        if (result.success) {
            addLog(`Cleanup ${platform}/${test}: risorsa eliminata`, 'success');
            // Rimuovi dalla lista e aggiorna UI
            createdResources[platform] = createdResources[platform].filter(r => r.id !== resourceId);
            const resultDiv = document.getElementById(`result-${platform}-${test}`);
            if (resultDiv) {
                resultDiv.innerHTML = `<div class="alert alert-info py-2 mb-0 small">
                    <i class="bi bi-info-circle"></i> Risorsa eliminata. Esegui di nuovo il test.
                </div>`;
            }
            setTestState(platform, test, '');
        } else {
            addLog(`Cleanup ${platform}/${test}: ERRORE - ${result.error}`, 'error');
        }
    } catch (err) {
        addLog(`Cleanup ${platform}/${test}: ERRORE - ${err.message}`, 'error');
    }
}

async function cleanupBatch(platform) {
    const resources = createdResources[platform];
    if (resources.length === 0) {
        addLog(`Cleanup batch ${platform}: nessuna risorsa da eliminare`, 'info');
        return;
    }

    if (!confirm(`Eliminare ${resources.length} risorse create dai test ${platform}?`)) return;

    addLog(`Cleanup batch ${platform}: eliminazione di ${resources.length} risorse...`, 'info');

    for (const resource of [...resources]) {
        await cleanupSingle(platform, resource.test, resource.id);
    }

    addLog(`Cleanup batch ${platform}: completato`, 'success');
}

async function runBatchTests(platform) {
    const tests = [];

    if (platform === 'drive') {
        tests.push('list', 'upload', 'folder');
    } else if (platform === 'classroom') {
        tests.push('courses', 'topic', 'material', 'assignment', 'students');
    } else if (platform === 'forms') {
        tests.push('create', 'responses');
    } else if (platform === 'classeviva') {
        tests.push('login', 'classes', 'grade', 'annotation');
    }

    addLog(`Batch test ${platform}: avvio ${tests.length} test...`, 'info');

    for (const test of tests) {
        await runTest(platform, test);
        // Piccola pausa tra i test
        await new Promise(resolve => setTimeout(resolve, 500));
    }

    addLog(`Batch test ${platform}: completato`, 'success');
}

// Caricamento dati per dropdown
async function loadDriveFolders() {
    addLog('Caricamento cartelle Drive...', 'info');
    try {
        const response = await fetch('api/test_api_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=load_data&type=drive_folders&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
        });
        const result = await response.json();
        if (result.success && result.data) {
            const select = document.getElementById('drive-folder-select');
            // Mantieni prima opzione (root configurata)
            const firstOption = select.options[0];
            select.innerHTML = '';
            select.appendChild(firstOption);
            result.data.forEach(f => {
                const opt = document.createElement('option');
                opt.value = f.id;
                opt.textContent = f.name;
                select.appendChild(opt);
            });
            addLog(`Caricate ${result.data.length} cartelle Drive`, 'success');
        } else if (result.error) {
            addLog('Errore cartelle Drive: ' + result.error, 'error');
        }
    } catch (err) {
        addLog('Errore caricamento cartelle: ' + err.message, 'error');
    }
}

async function loadClassroomCourses() {
    addLog('Caricamento corsi Classroom...', 'info');
    try {
        const response = await fetch('api/test_api_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=load_data&type=classroom_courses&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
        });
        const result = await response.json();
        if (result.success && result.data) {
            const select = document.getElementById('classroom-course-select');
            select.innerHTML = '<option value="">-- Seleziona corso --</option>';
            result.data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                select.appendChild(opt);
            });
            addLog(`Caricati ${result.data.length} corsi Classroom`, 'success');

            // Auto-seleziona primo corso
            if (result.data.length > 0) {
                select.selectedIndex = 1;
            }
        } else if (result.error) {
            addLog('Errore corsi Classroom: ' + result.error, 'error');
        }
    } catch (err) {
        addLog('Errore caricamento corsi: ' + err.message, 'error');
    }
}

async function loadCvClasses() {
    addLog('Caricamento classi ClasseViva...', 'info');
    try {
        const response = await fetch('api/test_api_handler.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: 'action=load_data&type=cv_classes&csrf_token=' + encodeURIComponent(<?= json_encode($csrfToken) ?>)
        });
        const result = await response.json();
        if (result.success && result.data) {
            cvClassesData = result.data;
            const select = document.getElementById('cv-class-select');
            select.innerHTML = '<option value="">-- Seleziona classe --</option>';
            result.data.forEach(c => {
                const opt = document.createElement('option');
                opt.value = c.id;
                opt.textContent = c.name;
                opt.dataset.students = JSON.stringify(c.students || []);
                opt.dataset.subjects = JSON.stringify(c.subjects || []);
                select.appendChild(opt);
            });
            addLog(`Caricate ${result.data.length} classi ClasseViva`, 'success');

            // Auto-seleziona prima classe e carica studenti/materie
            if (result.data.length > 0) {
                select.selectedIndex = 1;
                onCvClassChange();
            }
        } else if (result.error) {
            addLog('Errore classi: ' + result.error, 'error');
        }
    } catch (err) {
        addLog('Errore caricamento classi: ' + err.message, 'error');
    }
}

function onCvClassChange() {
    const classSelect = document.getElementById('cv-class-select');
    const studentSelect = document.getElementById('cv-student-select');
    const subjectSelect = document.getElementById('cv-subject-select');

    // Reset dropdown
    studentSelect.innerHTML = '<option value="">-- Seleziona studente --</option>';
    subjectSelect.innerHTML = '<option value="">-- Seleziona materia --</option>';

    if (!classSelect.value) return;

    const selectedOption = classSelect.options[classSelect.selectedIndex];

    // Carica studenti
    const students = JSON.parse(selectedOption.dataset.students || '[]');
    students.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name;
        studentSelect.appendChild(opt);
    });

    // Auto-seleziona primo studente
    if (students.length > 0) {
        studentSelect.selectedIndex = 1;
    }

    // Carica materie della classe
    const subjects = JSON.parse(selectedOption.dataset.subjects || '[]');
    subjects.forEach(s => {
        const opt = document.createElement('option');
        opt.value = s.id;
        opt.textContent = s.name || s.id;
        subjectSelect.appendChild(opt);
    });

    // Auto-seleziona prima materia
    if (subjects.length > 0) {
        subjectSelect.selectedIndex = 1;
    }

    addLog(`Classe selezionata: ${students.length} studenti, ${subjects.length} materie`, 'info');
}

// Carica dati all'avvio
document.addEventListener('DOMContentLoaded', function() {
    // Auto-load dopo un breve delay
    setTimeout(() => {
        <?php if ($driveEnabled): ?>
        loadDriveFolders();
        <?php endif; ?>
        <?php if ($classroomEnabled): ?>
        loadClassroomCourses();
        <?php endif; ?>
        <?php if ($cvReady): ?>
        loadCvClasses();
        <?php endif; ?>
    }, 500);
});
</script>
</body>
</html>
