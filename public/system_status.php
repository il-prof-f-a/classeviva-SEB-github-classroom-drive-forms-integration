<?php
/**
 * Dashboard Stato Sistema UDA
 * Verifica configurazione e connessioni API
 */

// Enable error reporting for debugging
error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\Database\DatabaseFactory;
use App\Core\SchemaDefinitions;
use App\Integration\ClasseVivaAPI;

$currentEmail = strtolower(trim($_SESSION['user_email'] ?? ''));
if (!is_admin_user($currentEmail)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo "Accesso non autorizzato.\n";
    exit(1);
}

// Esegui verifiche
$checks = [
    'php_version' => ['status' => 'pending', 'message' => '', 'details' => ''],
    'extensions' => ['status' => 'pending', 'message' => '', 'details' => []],
    'composer' => ['status' => 'pending', 'message' => '', 'details' => ''],
    'database' => ['status' => 'pending', 'message' => '', 'details' => ''],
    'config' => ['status' => 'pending', 'message' => '', 'details' => []],
    'classeviva' => ['status' => 'pending', 'message' => '', 'details' => ''],
    'google_drive' => ['status' => 'pending', 'message' => '', 'details' => ''],
    'google_classroom' => ['status' => 'pending', 'message' => '', 'details' => ''],
];

$cvConfig = $config['classeviva'] ?? [];
$cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvTokenError = $cvConfig['token_error'] ?? null;
$cvEnabled = !empty($cvConfig['enabled']);
$cvHasToken = !empty($cvTokenPayload['token']);
$cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;
$cvTokenNotice = null;

if (!$cvReady) {
    if (!$cvEnabled) {
        $cvTokenNotice = 'ClasseViva disabilitato.';
    } elseif (!$cvHasToken) {
        $cvTokenNotice = 'Token ClasseViva non configurato per questo utente.';
    } else {
        $cvTokenNotice = $cvTokenError
            ? "Token ClasseViva non valido: {$cvTokenError}"
            : 'Token ClasseViva scaduto o errato.';
    }
}

// Check 1: Versione PHP
$phpVersion = phpversion();
if (version_compare($phpVersion, '8.0.0', '>=')) {
    $checks['php_version']['status'] = 'success';
    $checks['php_version']['message'] = "PHP {$phpVersion}";
} else {
    $checks['php_version']['status'] = 'error';
    $checks['php_version']['message'] = "PHP {$phpVersion} - Richiesta versione >= 8.0";
}

// Check 2: Estensioni PHP
$requiredExtensions = ['gd', 'zip', 'xml', 'mbstring', 'json', 'curl', 'fileinfo'];
$missingExtensions = [];
foreach ($requiredExtensions as $ext) {
    if (!extension_loaded($ext)) {
        $missingExtensions[] = $ext;
    }
}

if (empty($missingExtensions)) {
    $checks['extensions']['status'] = 'success';
    $checks['extensions']['message'] = count($requiredExtensions) . ' estensioni caricate';
    $checks['extensions']['details'] = $requiredExtensions;
} else {
    $checks['extensions']['status'] = 'error';
    $checks['extensions']['message'] = count($missingExtensions) . ' estensioni mancanti';
    $checks['extensions']['details'] = ['missing' => $missingExtensions, 'loaded' => array_diff($requiredExtensions, $missingExtensions)];
}

// Check 3: Composer dependencies
$vendorPath = __DIR__ . '/../vendor/autoload.php';
if (file_exists($vendorPath)) {
    $checks['composer']['status'] = 'success';
    $checks['composer']['message'] = 'Dipendenze installate';

    // Conta pacchetti
    $composerLock = __DIR__ . '/../composer.lock';
    if (file_exists($composerLock)) {
        $lockData = json_decode(file_get_contents($composerLock), true);
        $packageCount = count($lockData['packages'] ?? []);
        $checks['composer']['details'] = "{$packageCount} pacchetti";
    }
} else {
    $checks['composer']['status'] = 'error';
    $checks['composer']['message'] = 'Eseguire: composer install';
}

// Check 4: Database (via adapter)
try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $expectedSheets = array_keys(SchemaDefinitions::getAllSheets());
    $missingSheets = [];
    foreach ($expectedSheets as $sheet) {
        try {
            $dbAdapter->findAll($sheet);
        } catch (Exception $e) {
            $missingSheets[] = $sheet;
        }
    }

    if (empty($missingSheets)) {
        $checks['database']['status'] = 'success';
        $checks['database']['message'] = count($expectedSheets) . ' tabelle presenti';
        $checks['database']['details'] = $expectedSheets;
    } else {
        $checks['database']['status'] = 'warning';
        $checks['database']['message'] = count($missingSheets) . ' tabelle mancanti: ' . implode(', ', $missingSheets);
    }
} catch (Exception $e) {
    $checks['database']['status'] = 'error';
    $checks['database']['message'] = 'Errore: ' . $e->getMessage();
}

// Check 5: File di configurazione (.env + credenziali Google)
$configChecks = [
    '.env' => file_exists(__DIR__ . '/../config/.env') || file_exists(__DIR__ . '/../.env'),
    'google_credentials.json' => file_exists(__DIR__ . '/../config/google_credentials.json'),
];

$allConfigPresent = !in_array(false, $configChecks, true);
$checks['config']['status'] = $allConfigPresent ? 'success' : 'warning';
$checks['config']['message'] = array_sum($configChecks) . '/' . count($configChecks) . ' file configurazione';
$checks['config']['details'] = $configChecks;

// Check 6: ClasseViva
if (!$cvEnabled) {
    $checks['classeviva']['status'] = 'disabled';
    $checks['classeviva']['message'] = 'ClasseViva non abilitato';
} elseif (!$cvReady) {
    $checks['classeviva']['status'] = 'warning';
    $checks['classeviva']['message'] = $cvTokenNotice ?? 'Token ClasseViva non valido';
} else {
    try {
        $classeVivaAPI = new ClasseVivaAPI($config);
        $classi = $classeVivaAPI->listTeacherClasses();
        $checks['classeviva']['status'] = 'success';
        $checks['classeviva']['message'] = 'Token valido - ' . count($classi) . ' classi';
        $checks['classeviva']['details'] = count($classi) . ' classi disponibili';
    } catch (Exception $e) {
        $checks['classeviva']['status'] = 'error';
        $checks['classeviva']['message'] = 'Errore: ' . substr($e->getMessage(), 0, 50);
    }
}

// Check 7: Google Drive
if (!($config['google']['drive']['enabled'] ?? false)) {
    $checks['google_drive']['status'] = 'disabled';
    $checks['google_drive']['message'] = 'Non abilitato';
} else {
    $credentialsPath = __DIR__ . '/../config/google_credentials.json';

    if (!file_exists($credentialsPath)) {
        $checks['google_drive']['status'] = 'warning';
        $checks['google_drive']['message'] = 'File credenziali mancante';
    } else {
        $tokenData = GoogleTokenProvider::getToken($config);

        if (!is_array($tokenData) || empty($tokenData['access_token'])) {
            $checks['google_drive']['status'] = 'warning';
            $checks['google_drive']['message'] = 'Autorizzazione OAuth richiesta per questo utente';
        } else {
            $checks['google_drive']['status'] = 'success';
            $checks['google_drive']['message'] = 'Autorizzato';

            if (isset($tokenData['expires_in'], $tokenData['created'])) {
                $expiresAt = $tokenData['created'] + $tokenData['expires_in'];
                if ($expiresAt > time()) {
                    $remainingMinutes = round(($expiresAt - time()) / 60);
                    $checks['google_drive']['details'] = "Token valido ({$remainingMinutes}min)";
                } else {
                    $checks['google_drive']['status'] = 'warning';
                    $checks['google_drive']['message'] = 'Token scaduto';
                }
            }
        }
    }
}

// Check 8: Google Classroom
if (!($config['google']['classroom']['enabled'] ?? false)) {
    $checks['google_classroom']['status'] = 'disabled';
    $checks['google_classroom']['message'] = 'Non abilitato';
} else {
    // Classroom usa le stesse credenziali di Drive
    if ($checks['google_drive']['status'] === 'success') {
        $checks['google_classroom']['status'] = 'success';
        $checks['google_classroom']['message'] = 'Configurato';
        $checks['google_classroom']['details'] = 'Usa credenziali Google Drive';
    } elseif ($checks['google_drive']['status'] === 'disabled') {
        $checks['google_classroom']['status'] = 'warning';
        $checks['google_classroom']['message'] = 'Configura prima Google Drive';
    } else {
        $checks['google_classroom']['status'] = $checks['google_drive']['status'];
        $checks['google_classroom']['message'] = $checks['google_drive']['message'];
    }
}

// Calcola stato generale
$totalChecks = count($checks);
$successChecks = 0;
$errorChecks = 0;
$warningChecks = 0;

foreach ($checks as $check) {
    if ($check['status'] === 'success') $successChecks++;
    elseif ($check['status'] === 'error') $errorChecks++;
    elseif ($check['status'] === 'warning') $warningChecks++;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Stato Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .status-badge { font-size: 1.2rem; }
        .check-card { transition: all 0.3s; }
        .check-card:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,0.1); }
        .status-success { color: #28a745; }
        .status-error { color: #dc3545; }
        .status-warning { color: #ffc107; }
        .status-disabled { color: #6c757d; }
        .overall-status { padding: 2rem; border-radius: 10px; margin-bottom: 2rem; }
        .overall-status.success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
        .overall-status.warning { background: linear-gradient(135deg, #ffc107 0%, #fd7e14 100%); color: white; }
        .overall-status.error { background: linear-gradient(135deg, #dc3545 0%, #e83e8c 100%); color: white; }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-speedometer"></i> Stato Sistema';
    $pageSubtitle = 'Verifica configurazione e connettivita';
    $headerActions = '<a class="nav-link" href="index.php">Dashboard</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-4">
<!-- Stato Generale -->
        <div class="overall-status <?= $errorChecks > 0 ? 'error' : ($warningChecks > 0 ? 'warning' : 'success') ?>">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-0">
                        <?php if ($errorChecks > 0): ?>
                            <i class="bi bi-exclamation-triangle"></i> Configurazione Necessaria
                        <?php elseif ($warningChecks > 0): ?>
                            <i class="bi bi-exclamation-circle"></i> Sistema Parzialmente Configurato
                        <?php else: ?>
                            <i class="bi bi-check-circle"></i> Sistema Operativo
                        <?php endif; ?>
                    </h2>
                    <p class="mb-0 mt-2">
                        <?= $successChecks ?> verifiche OK,
                        <?= $warningChecks ?> avvisi,
                        <?= $errorChecks ?> errori
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar bg-success" style="width: <?= ($successChecks / $totalChecks * 100) ?>%">
                            <?= round($successChecks / $totalChecks * 100) ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checks Grid -->
        <div class="row g-3">
            <!-- PHP Version -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Versione PHP</h6>
                                <p class="mb-0"><?= $checks['php_version']['message'] ?></p>
                            </div>
                            <span class="status-badge status-<?= $checks['php_version']['status'] ?>">
                                <?php if ($checks['php_version']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Extensions -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Estensioni PHP</h6>
                                <p class="mb-0"><?= $checks['extensions']['message'] ?></p>
                                <?php if (!empty($checks['extensions']['details']['missing'] ?? [])): ?>
                                    <small class="text-danger">
                                        Mancanti: <?= implode(', ', $checks['extensions']['details']['missing']) ?>
                                    </small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['extensions']['status'] ?>">
                                <?php if ($checks['extensions']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Composer -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Dipendenze Composer</h6>
                                <p class="mb-0"><?= $checks['composer']['message'] ?></p>
                                <?php if (!empty($checks['composer']['details'])): ?>
                                    <small class="text-muted"><?= $checks['composer']['details'] ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['composer']['status'] ?>">
                                <?php if ($checks['composer']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Database -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Database Excel</h6>
                                <p class="mb-0"><?= $checks['database']['message'] ?></p>
                                <?php if (!empty($checks['database']['details'])): ?>
                                    <small class="text-muted"><?= $checks['database']['details'] ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['database']['status'] ?>">
                                <?php if ($checks['database']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php elseif ($checks['database']['status'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Config Files -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">File Configurazione</h6>
                                <p class="mb-0"><?= $checks['config']['message'] ?></p>
                                <small class="text-muted">
                                    <?php foreach ($checks['config']['details'] as $file => $exists): ?>
                                        <span class="<?= $exists ? 'text-success' : 'text-danger' ?>">
                                            <?= $exists ? '✓' : '✗' ?> <?= $file ?>
                                        </span><br>
                                    <?php endforeach; ?>
                                </small>
                            </div>
                            <span class="status-badge status-<?= $checks['config']['status'] ?>">
                                <?php if ($checks['config']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ClasseViva -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">ClasseViva API</h6>
                                <p class="mb-0"><?= $checks['classeviva']['message'] ?></p>
                                <?php if (!empty($checks['classeviva']['details'])): ?>
                                    <small class="text-muted"><?= $checks['classeviva']['details'] ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['classeviva']['status'] ?>">
                                <?php if ($checks['classeviva']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php elseif ($checks['classeviva']['status'] === 'disabled'): ?>
                                    <i class="bi bi-dash-circle-fill"></i>
                                <?php elseif ($checks['classeviva']['status'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Google Drive -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Google Drive API</h6>
                                <p class="mb-0"><?= $checks['google_drive']['message'] ?></p>
                                <?php if (!empty($checks['google_drive']['details'])): ?>
                                    <small class="text-muted"><?= $checks['google_drive']['details'] ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['google_drive']['status'] ?>">
                                <?php if ($checks['google_drive']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php elseif ($checks['google_drive']['status'] === 'disabled'): ?>
                                    <i class="bi bi-dash-circle-fill"></i>
                                <?php elseif ($checks['google_drive']['status'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Google Classroom -->
            <div class="col-md-6 col-lg-4">
                <div class="card check-card h-100">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-start">
                            <div>
                                <h6 class="text-muted">Google Classroom API</h6>
                                <p class="mb-0"><?= $checks['google_classroom']['message'] ?></p>
                                <?php if (!empty($checks['google_classroom']['details'])): ?>
                                    <small class="text-muted"><?= $checks['google_classroom']['details'] ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="status-badge status-<?= $checks['google_classroom']['status'] ?>">
                                <?php if ($checks['google_classroom']['status'] === 'success'): ?>
                                    <i class="bi bi-check-circle-fill"></i>
                                <?php elseif ($checks['google_classroom']['status'] === 'disabled'): ?>
                                    <i class="bi bi-dash-circle-fill"></i>
                                <?php elseif ($checks['google_classroom']['status'] === 'warning'): ?>
                                    <i class="bi bi-exclamation-circle-fill"></i>
                                <?php else: ?>
                                    <i class="bi bi-x-circle-fill"></i>
                                <?php endif; ?>
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($cvTokenNotice && $checks['classeviva']['status'] !== 'success'): ?>
            <div class="alert alert-warning mt-4">
                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <br>
                <small class="text-muted">
                    Vai su <a href="user_integrations.php#classeviva-section">Integrazioni</a> per aggiornare il token ClasseViva.
                </small>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
