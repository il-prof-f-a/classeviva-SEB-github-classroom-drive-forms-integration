<?php
/**
 * Dashboard Stato Sistema UDA - Versione Semplificata
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\Database\DatabaseFactory;

if (!is_admin_user()) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
    }
    echo "Accesso non autorizzato.\n";
    exit(1);
}

// Array per memorizzare i risultati dei check
$checks = [];

// Check 1: PHP Version
$phpVersion = phpversion();
$checks[] = [
    'name' => 'Versione PHP',
    'status' => version_compare($phpVersion, '8.0.0', '>=') ? 'success' : 'error',
    'message' => "PHP {$phpVersion}" . (version_compare($phpVersion, '8.0.0', '<') ? ' (richiesta >= 8.0)' : ''),
    'icon' => 'cpu'
];

// Check 2: Estensioni PHP
$requiredExtensions = ['gd', 'zip', 'xml', 'mbstring', 'json', 'curl', 'fileinfo'];
$missingExtensions = array_filter($requiredExtensions, fn($ext) => !extension_loaded($ext));
$checks[] = [
    'name' => 'Estensioni PHP',
    'status' => empty($missingExtensions) ? 'success' : 'error',
    'message' => empty($missingExtensions)
        ? count($requiredExtensions) . ' estensioni OK'
        : 'Mancanti: ' . implode(', ', $missingExtensions),
    'icon' => 'puzzle'
];

// Check 3: Composer
$vendorExists = file_exists(__DIR__ . '/../vendor/autoload.php');
$checks[] = [
    'name' => 'Dipendenze Composer',
    'status' => $vendorExists ? 'success' : 'error',
    'message' => $vendorExists ? 'Installate' : 'Eseguire: composer install',
    'icon' => 'box-seam'
];

// Check 4: Database configurato
try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $expectedSheets = ['UDA_ANAGRAFICA', 'MATERIALI', 'OBIETTIVI', 'RUBRICA', 'TEST', 'CLASSI_ASSEGNATE', 'VOTI'];
    $missingSheets = array_values(array_filter(
        $expectedSheets,
        static fn(string $sheet): bool => !$dbAdapter->sheetExists($sheet)
    ));

    $checks[] = [
        'name' => 'Database applicazione',
        'status' => empty($missingSheets) ? 'success' : 'warning',
        'message' => empty($missingSheets)
            ? count($expectedSheets) . ' fogli OK'
            : count($missingSheets) . ' fogli mancanti',
        'icon' => 'file-earmark-spreadsheet'
    ];
} catch (Exception $e) {
    $checks[] = [
        'name' => 'Database applicazione',
        'status' => 'error',
        'message' => 'Errore: ' . substr($e->getMessage(), 0, 50),
        'icon' => 'file-earmark-spreadsheet'
    ];
}

// Check 5: File Configurazione
$configFiles = [
    'config.yaml' => file_exists(__DIR__ . '/../config/config.yaml'),
    '.env' => file_exists(__DIR__ . '/../config/.env'),
    'google_credentials.json' => file_exists(__DIR__ . '/../config/google_credentials.json'),
];
$allPresent = !in_array(false, $configFiles, true);
$checks[] = [
    'name' => 'File Configurazione',
    'status' => $allPresent ? 'success' : 'warning',
    'message' => array_sum($configFiles) . '/' . count($configFiles) . ' file presenti',
    'icon' => 'gear'
];

// Check 6: ClasseViva
if (!($config['classeviva']['enabled'] ?? false)) {
    $checks[] = [
        'name' => 'ClasseViva API',
        'status' => 'disabled',
        'message' => 'Non abilitato in config',
        'icon' => 'journal-text'
    ];
} else {
    $cvCfg = $config['classeviva'] ?? [];
    $username = $cvCfg['username'] ?? null;
    $password = $cvCfg['password'] ?? null;
    $schoolCode = $cvCfg['school_code'] ?? null;

    $hasAllCredentials = !empty($username) && !empty($password) && !empty($schoolCode);

    if (!$hasAllCredentials) {
        $missing = [];
        if (empty($schoolCode)) $missing[] = 'SCHOOL_CODE';
        if (empty($username)) $missing[] = 'USERNAME';
        if (empty($password)) $missing[] = 'PASSWORD';

        $checks[] = [
            'name' => 'ClasseViva API',
            'status' => 'error',
            'message' => 'Credenziali mancanti (per utente corrente): ' . implode(', ', $missing),
            'icon' => 'journal-text'
        ];
    } else {
        $checks[] = [
            'name' => 'ClasseViva API',
            'status' => 'success',
            'message' => 'Configurato per questo utente',
            'icon' => 'journal-text'
        ];
    }
}

// Check 7: Google Drive
if (!($config['google']['drive']['enabled'] ?? false)) {
    $checks[] = [
        'name' => 'Google Drive API',
        'status' => 'disabled',
        'message' => 'Non abilitato in config',
        'icon' => 'google'
    ];
} else {
    $hasCredentials = file_exists(__DIR__ . '/../config/google_credentials.json');

    if (!$hasCredentials) {
        $status = 'warning';
        $message = 'File credenziali mancante';
    } else {
        $tokenData = GoogleTokenProvider::getToken($config);

        if (!is_array($tokenData) || empty($tokenData['access_token'])) {
            $status = 'warning';
            $message = 'Autorizzazione OAuth richiesta per questo utente';
        } else {
            $status = 'success';
            $message = 'Configurato';
        }
    }

    $checks[] = [
        'name' => 'Google Drive API',
        'status' => $status,
        'message' => $message,
        'icon' => 'google'
    ];
}

// Check 8: Google Classroom
if (!($config['google']['classroom']['enabled'] ?? false)) {
    $checks[] = [
        'name' => 'Google Classroom API',
        'status' => 'disabled',
        'message' => 'Non abilitato in config',
        'icon' => 'google'
    ];
} else {
    $checks[] = [
        'name' => 'Google Classroom API',
        'status' => 'success',
        'message' => 'Configurato',
        'icon' => 'google'
    ];
}

// Calcola statistiche
$totalChecks = count($checks);
$successCount = count(array_filter($checks, fn($c) => $c['status'] === 'success'));
$errorCount = count(array_filter($checks, fn($c) => $c['status'] === 'error'));
$warningCount = count(array_filter($checks, fn($c) => $c['status'] === 'warning'));
$disabledCount = count(array_filter($checks, fn($c) => $c['status'] === 'disabled'));

$overallStatus = $errorCount > 0 ? 'error' : ($warningCount > 0 ? 'warning' : 'success');
$percentage = round(($successCount / $totalChecks) * 100);

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
        .status-success { color: #28a745; }
        .status-error { color: #dc3545; }
        .status-warning { color: #ffc107; }
        .status-disabled { color: #6c757d; }
        .overall-status {
            padding: 2rem;
            border-radius: 10px;
            margin-bottom: 2rem;
        }
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
        <div class="overall-status <?= $overallStatus ?>">
            <div class="row align-items-center">
                <div class="col-md-8">
                    <h2 class="mb-0">
                        <?php if ($errorCount > 0): ?>
                            <i class="bi bi-exclamation-triangle"></i> Configurazione Necessaria
                        <?php elseif ($warningCount > 0): ?>
                            <i class="bi bi-exclamation-circle"></i> Sistema Parzialmente Configurato
                        <?php else: ?>
                            <i class="bi bi-check-circle"></i> Sistema Operativo
                        <?php endif; ?>
                    </h2>
                    <p class="mb-0 mt-2">
                        <?= $successCount ?> OK, <?= $warningCount ?> avvisi, <?= $errorCount ?> errori, <?= $disabledCount ?> disabilitati
                    </p>
                </div>
                <div class="col-md-4 text-end">
                    <div class="progress" style="height: 30px;">
                        <div class="progress-bar bg-light" style="width: <?= $percentage ?>%">
                            <?= $percentage ?>%
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Checks Grid -->
        <div class="row g-3">
            <?php foreach ($checks as $check): ?>
                <div class="col-md-6 col-lg-4">
                    <div class="card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="text-muted">
                                        <i class="bi bi-<?= $check['icon'] ?>"></i> <?= $check['name'] ?>
                                    </h6>
                                    <p class="mb-0"><?= htmlspecialchars($check['message']) ?></p>
                                </div>
                                <span class="fs-3 status-<?= $check['status'] ?>">
                                    <?php if ($check['status'] === 'success'): ?>
                                        <i class="bi bi-check-circle-fill"></i>
                                    <?php elseif ($check['status'] === 'error'): ?>
                                        <i class="bi bi-x-circle-fill"></i>
                                    <?php elseif ($check['status'] === 'warning'): ?>
                                        <i class="bi bi-exclamation-circle-fill"></i>
                                    <?php else: ?>
                                        <i class="bi bi-dash-circle-fill"></i>
                                    <?php endif; ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <!-- Actions -->
        <div class="row mt-4">
            <div class="col-12">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-tools"></i> Test Disponibili</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <h6>Test Database</h6>
                                <p class="small text-muted">Verifica operazioni CRUD su database Excel</p>
                                <code>php tests/test_database.php</code>
                            </div>
                            <div class="col-md-6 mb-3">
                                <h6>Test Integrazioni API</h6>
                                <p class="small text-muted">Verifica connessione ClasseViva, Google Drive, Google Classroom</p>
                                <code>php tests/test_integrations.php</code>
                            </div>
                        </div>
                        <hr>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla Dashboard
                        </a>
                        <a href="?refresh=1" class="btn btn-primary">
                            <i class="bi bi-arrow-clockwise"></i> Aggiorna
                        </a>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($errorCount > 0 || $warningCount > 0): ?>
        <div class="row mt-4">
            <div class="col-12">
                <div class="alert alert-info">
                    <h5><i class="bi bi-info-circle"></i> Azioni Consigliate</h5>
                    <ul class="mb-0">
                        <?php if (in_array(false, array_map(fn($c) => extension_loaded($c), $requiredExtensions))): ?>
                            <li>Abilita estensioni PHP mancanti (esegui <code>enable_gd.bat</code> e riavvia Apache)</li>
                        <?php endif; ?>
                        <?php if (!$vendorExists): ?>
                            <li>Installa dipendenze: <code>composer install --no-dev</code></li>
                        <?php endif; ?>
                        <?php if (!file_exists(__DIR__ . '/../.env')): ?>
                            <li>Copia <code>.env.example</code> in <code>.env</code> e configura credenziali</li>
                        <?php endif; ?>
                        <li>Consulta <code>GUIDA_TEST.md</code> per istruzioni dettagliate</li>
                    </ul>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
