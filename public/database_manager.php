<?php
/**
 * Database Manager - Gestione e Manutenzione Database
 *
 * Pagina per:
 * - Validare struttura database
 * - Riparare database corrotto
 * - Inizializzare database da zero
 * - Creare backup
 * - Visualizzare stato
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\Database\DatabaseInitializer;
use App\Core\Database\DatabaseMigration;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\SchemaDefinitions;
use App\Core\Security\Authorization;
use App\Core\Security\Csrf;

error_reporting(E_ALL);

function isAbsolutePath(string $path): bool
{
    return preg_match('#^(?:[A-Za-z]:\\\\|/)#', $path) === 1;
}

function buildAbsolutePath(string $path): string
{
    if (isAbsolutePath($path)) {
        return $path;
    }
    return ROOT_PATH . '/' . ltrim($path, '/\\');
}

function createUserBackupJsonFile(array $config, string $userId, string $userEmail): string
{
    $adapter = DatabaseFactory::createWithInitialization($config, true);
    $payload = [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'created_at' => date('c'),
        'tables' => []
    ];

    foreach (SchemaDefinitions::getAllSheets() as $sheetName => $definition) {
        $rows = $sheetName === 'UTENTI'
            ? $adapter->findWhere('UTENTI', ['id_utente' => $userId])
            : $adapter->findAll($sheetName);

        if (empty($rows)) {
            continue;
        }

        $columns = SchemaDefinitions::getSheetColumns($sheetName) ?? array_keys($rows[0]);
        $payload['tables'][$sheetName] = [
            'columns' => $columns,
            'rows' => array_values($rows)
        ];
    }

    $backupDir = ROOT_PATH . '/database/backup';
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0755, true);
    }

    $safeEmail = preg_replace('/[^a-z0-9]+/', '_', strtolower($userEmail ?: $userId));
    if ($safeEmail === '') {
        $safeEmail = 'user';
    }

    $fileName = 'uda_user_' . $safeEmail . '_' . date('Y-m-d_H-i-s') . '.json';
    $relativePath = 'database/backup/' . $fileName;
    $fullPath = ROOT_PATH . '/' . $relativePath;

    if (file_put_contents($fullPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        throw new Exception("Impossibile scrivere backup utente: {$relativePath}");
    }

    return $relativePath;
}

function convertJsonBackupToSqliteFile(array $config, string $sourceRelativePath): string
{
    $fullSourcePath = buildAbsolutePath($sourceRelativePath);
    if (!file_exists($fullSourcePath)) {
        throw new Exception("File JSON non trovato: {$sourceRelativePath}");
    }

    $payload = json_decode(file_get_contents($fullSourcePath), true);
    if (!is_array($payload)) {
        throw new Exception("Formato JSON non valido: {$sourceRelativePath}");
    }

    $tables = $payload['tables'] ?? [];
    if (!is_array($tables) || empty($tables)) {
        throw new Exception("Nessuna tabella trovata nel file JSON");
    }

    $tempDir = ROOT_PATH . '/storage/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempRelative = 'storage/temp/import_json_' . uniqid() . '.db';
    $sqliteConfig = $config;
    $sqliteConfig['database']['type'] = 'sqlite';
    $sqliteConfig['database']['sqlite']['file'] = $tempRelative;

    $adapter = DatabaseFactory::create($sqliteConfig);

    foreach ($tables as $sheetName => $tableData) {
        $rows = $tableData['rows'] ?? [];
        if (empty($rows)) {
            continue;
        }

        $columns = $tableData['columns'] ?? array_keys(reset($rows));

        if (!$adapter->sheetExists($sheetName)) {
            $adapter->createSheet($sheetName, $columns);
        } else {
            $adapter->clearSheet($sheetName);
        }

        foreach ($rows as $row) {
            $filteredRow = array_intersect_key($row, array_flip($columns));
            if (!empty($filteredRow)) {
                $adapter->insertRow($sheetName, $filteredRow);
            }
        }
    }

    return $tempRelative;
}

/**
 * Costruisce il payload JSON di backup per l'utente corrente (senza scrivere su disco).
 */
function buildUserBackupPayload(array $config, string $userId, string $userEmail): array
{
    $adapter = DatabaseFactory::createWithInitialization($config, true);

    $payload = [
        'user_id' => $userId,
        'user_email' => $userEmail,
        'created_at' => date('c'),
        'tables' => []
    ];

    foreach (SchemaDefinitions::getAllSheets() as $sheetName => $definition) {
        $rows = $sheetName === 'UTENTI'
            ? $adapter->findWhere('UTENTI', ['id_utente' => $userId])
            : $adapter->findAll($sheetName);

        if (empty($rows)) {
            continue;
        }

        $columns = SchemaDefinitions::getSheetColumns($sheetName) ?? array_keys($rows[0]);
        $payload['tables'][$sheetName] = [
            'columns' => $columns,
            'rows' => array_values($rows)
        ];
    }

    return $payload;
}

/**
 * Converte un payload JSON di backup in un file SQLite temporaneo
 * (usato per l'import da file caricato). Il file ÃÂ¨ creato solo in
 * storage/temp e viene eliminato al termine dell'import.
 */
function convertJsonBackupPayloadToSqlite(array $config, array $payload): string
{
    $tables = $payload['tables'] ?? [];
    if (!is_array($tables) || empty($tables)) {
        throw new Exception("Nessuna tabella trovata nel file JSON");
    }

    $tempDir = ROOT_PATH . '/storage/temp';
    if (!is_dir($tempDir)) {
        mkdir($tempDir, 0755, true);
    }

    $tempRelative = 'storage/temp/import_json_' . uniqid() . '.db';
    $sqliteConfig = $config;
    $sqliteConfig['database']['type'] = 'sqlite';
    $sqliteConfig['database']['sqlite']['file'] = $tempRelative;

    $adapter = DatabaseFactory::create($sqliteConfig);

    foreach ($tables as $sheetName => $tableData) {
        $rows = $tableData['rows'] ?? [];
        if (empty($rows)) {
            continue;
        }

        $columns = $tableData['columns'] ?? array_keys(reset($rows));

        if (!$adapter->sheetExists($sheetName)) {
            $adapter->createSheet($sheetName, $columns);
        } else {
            $adapter->clearSheet($sheetName);
        }

        foreach ($rows as $row) {
            $filteredRow = array_intersect_key($row, array_flip($columns));
            if (!empty($filteredRow)) {
                $adapter->insertRow($sheetName, $filteredRow);
            }
        }
    }

    return $tempRelative;
}

$dbAdapter = DatabaseFactory::create($config);
$dbInit = new DatabaseInitializer($dbAdapter, $config);
$schemaMigrationRunner = new SchemaMigrationRunner($dbAdapter);
$pendingMigrations = $schemaMigrationRunner->pending();
$schemaVersion = SchemaMigrationRunner::PROVIDER_NEUTRAL_VERSION;

$currentEmail = strtolower(trim($_SESSION['user_email'] ?? ''));
$isAdminUser = is_admin_user($currentEmail);
$adminEmails = array_filter(array_map('trim', explode(',', (string)env('ADMIN_EMAILS', ''))));
Authorization::assertAdmin($_SESSION, $adminEmails);
$csrfSession = &$_SESSION;
$csrfToken = Csrf::token($csrfSession);

$actionResult = null;
$actionType = null;

// Gestione azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $action = $_POST['action'];
    $createBackup = isset($_POST['create_backup']);

    try {
        Csrf::assertValid($_SESSION, $_POST['csrf_token'] ?? null);
        $adminActions = ['validate', 'repair', 'initialize', 'backup'];
        if (in_array($action, $adminActions, true) && !$isAdminUser) {
            throw new Exception("Permesso negato per l'operazione richiesta.");
        }
        switch ($action) {
            case 'validate':
                $actionResult = $dbInit->validate();
                $actionType = 'validate';
                break;

            case 'repair':
                $actionResult = $dbInit->repair($createBackup);
                $actionType = 'repair';
                break;

            case 'initialize':
                $actionResult = $dbInit->initialize($createBackup);
                $actionType = 'initialize';
                break;

            case 'import':
                $tempSqlitePath = null;
                try {
                    if (empty($_FILES['import_file']) || !is_uploaded_file($_FILES['import_file']['tmp_name'])) {
                        throw new Exception("Nessun file caricato per l'import.");
                    }

                    $upload = $_FILES['import_file'];
                    if (!empty($upload['error'])) {
                        throw new Exception("Errore caricamento file (codice {$upload['error']}).");
                    }

                    $ext = strtolower(pathinfo($upload['name'] ?? '', PATHINFO_EXTENSION));
                    if ($ext !== 'json') {
                        throw new Exception('Formato file non supportato. Usa un backup JSON esportato dall\'applicazione.');
                    }

                    $jsonContent = file_get_contents($upload['tmp_name']);
                    if ($jsonContent === false) {
                        throw new Exception('Impossibile leggere il file caricato.');
                    }

                    $payload = json_decode($jsonContent, true);
                    if (!is_array($payload)) {
                        throw new Exception('JSON non valido: impossibile effettuare l\'import.');
                    }

                    $tempSqlitePath = convertJsonBackupPayloadToSqlite($config, $payload);

                    $sourceConfig = $config;
                    $sourceConfig['database']['type'] = 'sqlite';
                    $sourceConfig['database']['sqlite']['file'] = $tempSqlitePath;
                    $sourceAdapter = DatabaseFactory::create($sourceConfig);

                    $targetAdapter = DatabaseFactory::createWithInitialization($config, true);
                    $overwriteExisting = !empty($_POST['overwrite_existing']);
                    $preserveExisting = !$overwriteExisting;

                    $migration = new DatabaseMigration($sourceAdapter, $targetAdapter);
                    $actionResult = $migration->importAll($preserveExisting, $createBackup);
                    $actionType = 'import';
                } finally {
                    if ($tempSqlitePath) {
                        $cleanupPath = buildAbsolutePath($tempSqlitePath);
                        if (file_exists($cleanupPath)) {
                            @unlink($cleanupPath);
                        }
                    }
                }
                break;

            case 'export_user_sqlite':
                // Esporta i dati relativi all'utente corrente in un file SQLite o JSON (per reimport)
                $exportDirRelative = 'database/export';
                $exportDirPath = ROOT_PATH . '/' . $exportDirRelative;

                $currentUserId = $_SESSION['user_id'] ?? null;
                if (!$currentUserId) {
                    throw new Exception("Utente non autenticato, impossibile esportare dati utente.");
                }

                $exportFormat = strtolower(trim($_POST['export_format'] ?? 'sqlite'));
                if (!in_array($exportFormat, ['sqlite', 'json'], true)) {
                    $exportFormat = 'sqlite';
                }

                if ($exportFormat === 'json') {
                    $payload = buildUserBackupPayload($config, $currentUserId, $_SESSION['user_email'] ?? '');
                    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                    $fileName = trim($_POST['target_sqlite_filename'] ?? '');
                    if ($fileName === '') {
                        $safeUserId = preg_replace('/[^a-zA-Z0-9_]/', '_', $currentUserId);
                        $fileName = 'uda_user_' . $safeUserId . '_' . date('Ymd_His') . '.json';
                    }
                    $fileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileName);
                    header('Content-Type: application/json');
                    header('Content-Disposition: attachment; filename="' . $fileName . '"');
                    header('Content-Length: ' . strlen($json));
                    echo $json;
                    exit;
                }

                if (!is_dir($exportDirPath)) {
                    if (!mkdir($exportDirPath, 0755, true) && !is_dir($exportDirPath)) {
                        throw new Exception("Impossibile creare la directory di export: {$exportDirPath}");
                    }
                }

                // Nome file (senza percorso), opzionale
                $fileName = trim($_POST['target_sqlite_filename'] ?? '');
                if ($fileName === '') {
                    $safeUserId = preg_replace('/[^a-zA-Z0-9_]/', '_', $_SESSION['user_id'] ?? 'user');
                    $fileName = 'uda_user_' . $safeUserId . '_' . date('Ymd_His') . '.db';
                }

                // Sanitizza il nome file
                $fileName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $fileName);
                $targetRelative = $exportDirRelative . '/' . $fileName;

                // Configurazione per adapter SQLite di destinazione (nuovo file)
                $targetConfig = $config;
                $targetConfig['database']['type'] = 'sqlite';
                $targetConfig['database']['sqlite']['file'] = $targetRelative;

                $targetAdapter = DatabaseFactory::create($targetConfig);

                // Inizializza il database SQLite di destinazione con lo schema standard
                $targetAdapter->initialize();

                // Adapter sorgente: database principale, wrappato per utente
                $sourceAdapter = DatabaseFactory::createWithInitialization($config, true);

                $startExport = microtime(true);
                $importedSheets = [];
                $totalRows = 0;
                $log = [];

                $allSheetsDef = SchemaDefinitions::getAllSheets();

                foreach ($allSheetsDef as $sheetName => $definition) {
                    try {
                        // Dati da esportare: per tutte le tabelle user-scoped, lo UserScopedDatabaseAdapter
                        // filtra già per id_utente. Per UTENTI filtriamo manualmente.
                        if ($sheetName === 'UTENTI') {
                            $rows = $sourceAdapter->findWhere('UTENTI', ['id_utente' => $currentUserId]);
                        } else {
                            $rows = $sourceAdapter->findAll($sheetName);
                        }

                        if (empty($rows)) {
                            $log[] = "Foglio {$sheetName}: nessuna riga per questo utente (saltato)";
                            continue;
                        }

                        // Assicura che la tabella esista sul target (initialize l'ha già creata, ma per sicurezza)
                    $targetAdapter->ensureSheetExists($sheetName);
                    $targetColumns = $targetAdapter->getColumns($sheetName);
                    $allowed = array_flip($targetColumns);

                        $inserted = 0;
                        foreach ($rows as $row) {
                            // Mantieni solo le colonne supportate dal target; ignora eventuali colonne extra
                            $filteredRow = array_intersect_key($row, $allowed);
                            if (empty($filteredRow)) {
                                continue;
                            }

                            // Normalizza CBM per esport JSON: se cbm_enabled non presente, fallback
                            if ($sheetName === 'TEST') {
                                if (!isset($filteredRow['cbm_enabled'])) {
                                    $filteredRow['cbm_enabled'] = 'NO';
                                }
                                if (!isset($filteredRow['cbm_levels_json'])) {
                                    $filteredRow['cbm_levels_json'] = null;
                                }
                                if (!isset($filteredRow['cbm_scoring_model'])) {
                                    $filteredRow['cbm_scoring_model'] = null;
                                }
                                if (!isset($filteredRow['cbm_form_config_json'])) {
                                    $filteredRow['cbm_form_config_json'] = null;
                                }
                            }

                            $targetAdapter->insertRow($sheetName, $filteredRow);
                            $inserted++;
                        }

                        $importedSheets[] = $sheetName;
                        $totalRows += $inserted;
                        $log[] = "Foglio {$sheetName}: {$inserted} righe esportate";

                    } catch (Exception $e) {
                        $log[] = "Errore esportando {$sheetName}: " . $e->getMessage();
                        // Non bloccare l'export per un singolo foglio
                    }
                }

                $duration = round(microtime(true) - $startExport, 2);

                $actionResult = [
                    'success' => true,
                    'imported_sheets' => $importedSheets,
                    'skipped_sheets' => [],
                    'total_sheets' => count($allSheetsDef),
                    'total_rows' => $totalRows,
                    'errors' => [],
                    'log' => $log,
                    'duration_seconds' => $duration,
                    'export_file' => $targetRelative,
                ];
                $actionType = 'export_user_sqlite';

                // Stream immediato del file verso il browser e rimozione dal server
                $fullExportPath = buildAbsolutePath($targetRelative);
                if (file_exists($fullExportPath)) {
                    header('Content-Type: application/vnd.sqlite3');
                    header('Content-Disposition: attachment; filename="' . basename($targetRelative) . '"');
                    header('Content-Length: ' . filesize($fullExportPath));
                    readfile($fullExportPath);
                    @unlink($fullExportPath);
                    exit;
                } else {
                    throw new Exception("File di export non trovato: {$targetRelative}");
                }
                break;
        }
    } catch (Exception $e) {
        $actionResult = [
            'success' => false,
            'error' => \App\Core\Security\PublicError::message($e, 'database_manager')
        ];
        $actionType = $action;
    }
}

// Informazioni database corrente
$dbType = strtolower((string)($config['database']['type'] ?? 'sqlite'));

// Determina il percorso/identificativo basandosi sul tipo
switch ($dbType) {
    case 'sqlite':
    case 'sqlite3':
        $dbPath = $config['database']['sqlite']['file'] ?? 'database/uda_database.db';
        $dbDisplayName = 'SQLite';
        break;
    case 'mysql':
        $mysqlConf = $config['database']['mysql'] ?? [];
        $dbHost = $mysqlConf['host'] ?? '127.0.0.1';
        $dbName = $mysqlConf['database'] ?? '';
        $dbPath = $dbHost . ($dbName ? " / DB: {$dbName}" : '');
        $dbDisplayName = 'MySQL';
        break;
    default:
        throw new Exception("Backend non supportato: {$dbType}. Usare SQLite o MySQL.");
}

// Informazioni schema
$allSheets = SchemaDefinitions::getAllSheets();
$totalSheets = count($allSheets);

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Database - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .action-card {
            transition: transform 0.2s, box-shadow 0.2s;
            cursor: pointer;
            height: 100%;
        }

        .action-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }

        .action-icon {
            font-size: 3rem;
            margin-bottom: 1rem;
        }

        .result-box {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-top: 20px;
        }

        .log-entry {
            font-family: 'Courier New', monospace;
            font-size: 0.9rem;
            padding: 5px 10px;
            margin: 2px 0;
            border-radius: 4px;
        }

        .log-success { background-color: #d4edda; color: #155724; }
        .log-error { background-color: #f8d7da; color: #721c24; }
        .log-warning { background-color: #fff3cd; color: #856404; }
        .log-info { background-color: #d1ecf1; color: #0c5460; }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-database"></i> Gestione Database';
    $headerActions = '<a href="index.php" class="btn btn-outline-light btn-sm"><i class="fas fa-home me-1"></i>Home</a>' . '<a href="system_status.php" class="btn btn-outline-light btn-sm"><i class="fas fa-chart-line me-1"></i>Stato Sistema</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container-fluid mt-4">
        <!-- Informazioni Database -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Informazioni Database</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-3">
                        <strong>Tipo Database:</strong><br>
                        <?php
                        $badgeColor = match($dbType) {
                            'sqlite', 'sqlite3' => 'primary',
                            'mysql' => 'success',
                            default => 'secondary'
                        };
                        ?>
                        <span class="badge bg-<?= $badgeColor ?> fs-6">
                            <?= htmlspecialchars($dbDisplayName) ?>
                        </span>
                    </div>
                    <div class="col-md-2">
                        <strong>Fogli Definiti:</strong><br>
                        <span class="badge bg-primary fs-6"><?= $totalSheets ?> fogli</span>
                    </div>
                    <div class="col-md-7">
                        <strong>Percorso/Identificativo:</strong><br>
                        <span class="text-muted">nascosto per sicurezza</span>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-md-4">
                        <strong>Schema:</strong><br>
                        <code><?= htmlspecialchars($schemaVersion) ?></code>
                    </div>
                    <div class="col-md-4">
                        <strong>Migrazioni pendenti:</strong><br>
                        <span class="badge bg-<?= empty($pendingMigrations) ? 'success' : 'warning' ?> fs-6"><?= count($pendingMigrations) ?></span>
                    </div>
                    <div class="col-md-4">
                        <strong>Persistenza:</strong><br>
                        <span class="text-muted">solo SQLite/MySQL; file tabellari solo import/export</span>
                    </div>
                </div>
                <div class="row mt-3">
                    <div class="col-12">
                        <details>
                            <summary style="cursor: pointer;" class="text-muted">
                                <small><i class="fas fa-list me-1"></i>Mostra elenco fogli (<?= $totalSheets ?>)</small>
                            </summary>
                            <div class="mt-2">
                                <div class="row">
                                    <?php
                                    $sheetsArray = array_keys($allSheets);
                                    $half = ceil($totalSheets / 2);
                                    ?>
                                    <div class="col-md-6">
                                        <ul class="small mb-0">
                                            <?php for ($i = 0; $i < $half; $i++): ?>
                                                <li><code><?= htmlspecialchars($sheetsArray[$i]) ?></code></li>
                                            <?php endfor; ?>
                                        </ul>
                                    </div>
                                    <div class="col-md-6">
                                        <ul class="small mb-0">
                                            <?php for ($i = $half; $i < $totalSheets; $i++): ?>
                                                <li><code><?= htmlspecialchars($sheetsArray[$i]) ?></code></li>
                                            <?php endfor; ?>
                                        </ul>
                                    </div>
                                </div>
                            </div>
                        </details>
                    </div>
                </div>
            </div>
        </div>

        <!-- Risultato Azione -->
        <?php if ($actionResult !== null): ?>
            <?php
            $actionLabel = match ($actionType) {
                'validate' => 'Validazione',
                'repair' => 'Riparazione',
                'initialize' => 'Inizializzazione',
                // backup disabilitato
                'import' => 'Import dati utente (JSON)',
                'export_user_sqlite' => 'Export dati utente',
                default => ucfirst((string)$actionType),
            };
            ?>
            <div class="alert alert-<?= ($actionResult['success'] ?? $actionResult['valid'] ?? false) ? 'success' : 'danger' ?> alert-dismissible fade show">
                <h5>
                    <i class="fas fa-<?= ($actionResult['success'] ?? $actionResult['valid'] ?? false) ? 'check-circle' : 'exclamation-triangle' ?> me-2"></i>
                    Risultato: <?= htmlspecialchars($actionLabel) ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                <?php if (isset($actionResult['error'])): ?>
                    <p class="mb-0"><?= htmlspecialchars($actionResult['error']) ?></p>
                <?php endif; ?>

                <?php if ($actionType === 'validate'): ?>
                    <p class="mb-2">
                        <strong>Database <?= $actionResult['valid'] ? 'VALIDO' : 'NON VALIDO' ?></strong>
                    </p>

                    <?php if (!empty($actionResult['errors'])): ?>
                        <div class="mb-2">
                            <strong>Errori:</strong>
                            <?php foreach ($actionResult['errors'] as $error): ?>
                                <div class="log-entry log-error"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['warnings'])): ?>
                        <div class="mb-2">
                            <strong>Avvisi:</strong>
                            <?php foreach ($actionResult['warnings'] as $warning): ?>
                                <div class="log-entry log-warning"><?= htmlspecialchars($warning) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['info'])): ?>
                        <div>
                            <strong>Info:</strong>
                            <?php foreach ($actionResult['info'] as $info): ?>
                                <div class="log-entry log-info"><?= htmlspecialchars($info) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($actionType === 'repair'): ?>
                    <?php if (isset($actionResult['backup_created'])): ?>
                        <div class="log-entry log-success mb-2">
                            â Backup creato: <?= htmlspecialchars($actionResult['backup_created']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['fixed'])): ?>
                        <div class="mb-2">
                            <strong>Riparazioni effettuate:</strong>
                            <?php foreach ($actionResult['fixed'] as $fix): ?>
                                <div class="log-entry log-success"><?= htmlspecialchars($fix) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['failed'])): ?>
                        <div>
                            <strong>Riparazioni fallite:</strong>
                            <?php foreach ($actionResult['failed'] as $fail): ?>
                                <div class="log-entry log-error"><?= htmlspecialchars($fail) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($actionType === 'initialize'): ?>
                    <?php if (isset($actionResult['backup_created'])): ?>
                        <div class="log-entry log-success mb-2">
                            â Backup creato: <?= htmlspecialchars($actionResult['backup_created']) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['created_sheets'])): ?>
                        <div class="mb-2">
                            <strong>Fogli creati:</strong>
                            <?php foreach ($actionResult['created_sheets'] as $sheet): ?>
                                <div class="log-entry log-success"><?= htmlspecialchars($sheet) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['existing_sheets'])): ?>
                        <div class="mb-2">
                            <strong>Fogli già esistenti:</strong>
                            <?php foreach ($actionResult['existing_sheets'] as $sheet): ?>
                                <div class="log-entry log-info"><?= htmlspecialchars($sheet) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['errors'])): ?>
                        <div>
                            <strong>Errori:</strong>
                            <?php foreach ($actionResult['errors'] as $error): ?>
                                <div class="log-entry log-error"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>

                <?php if ($actionType === 'backup'): ?>
                    <p class="mb-0">
                        <strong>Backup salvato in:</strong><br>
                        <code><?= htmlspecialchars($actionResult['backup_path']) ?></code>
                    </p>
                <?php endif; ?>

                <?php if ($actionType === 'import' || $actionType === 'export_user_sqlite'): ?>
                    <div class="mb-2">
                        <strong>Statistiche operazione:</strong>
                        <ul class="mb-2">
                            <li>Fogli importati: <strong><?= count($actionResult['imported_sheets'] ?? []) ?></strong> / <?= $actionResult['total_sheets'] ?? 0 ?></li>
                            <li>Righe totali: <strong><?= $actionResult['total_rows'] ?? 0 ?></strong></li>
                            <li>Tempo impiegato: <strong><?= $actionResult['duration_seconds'] ?? 0 ?> secondi</strong></li>
                        </ul>
                    </div>

                    <?php if ($actionType === 'export_user_sqlite' && !empty($actionResult['export_file'])): ?>
                        <div class="mb-2">
                            <strong>File esportato:</strong><br>
                            <code><?= htmlspecialchars($actionResult['export_file']) ?></code>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['imported_sheets'])): ?>
                        <div class="mb-2">
                            <strong>Fogli importati:</strong>
                            <?php foreach ($actionResult['imported_sheets'] as $sheet): ?>
                                <div class="log-entry log-success"><?= htmlspecialchars($sheet) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['skipped_sheets'])): ?>
                        <div class="mb-2">
                            <strong>Fogli saltati (vuoti):</strong>
                            <?php foreach ($actionResult['skipped_sheets'] as $sheet): ?>
                                <div class="log-entry log-warning"><?= htmlspecialchars($sheet) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['errors'])): ?>
                        <div class="mb-2">
                            <strong>Errori:</strong>
                            <?php foreach ($actionResult['errors'] as $error): ?>
                                <div class="log-entry log-error"><?= htmlspecialchars($error) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($actionResult['log'])): ?>
                        <details class="mt-3">
                            <summary style="cursor: pointer;" class="text-muted">
                                <small><i class="fas fa-list me-1"></i>Mostra log dettagliato (<?= count($actionResult['log']) ?> voci)</small>
                            </summary>
                            <div class="mt-2" style="max-height: 300px; overflow-y: auto;">
                                <?php foreach ($actionResult['log'] as $logEntry): ?>
                                    <div class="log-entry log-info" style="font-size: 0.8rem;"><?= htmlspecialchars($logEntry) ?></div>
                                <?php endforeach; ?>
                            </div>
                        </details>
                    <?php endif; ?>
                <?php endif; ?>

                <small class="text-muted mt-2 d-block">
                    <i class="fas fa-clock me-1"></i>
                    <?= $actionResult['timestamp'] ?? date('Y-m-d H:i:s') ?>
                </small>
            </div>
        <?php endif; ?>

        <!-- Azioni Disponibili -->
        <h3 class="mb-3"><i class="fas fa-tasks me-2"></i>Operazioni Database</h3>

        <?php if ($isAdminUser): ?>
        <div class="row g-4 mb-4">
            <div class="col-md-2 col-lg-2">
                <div class="card action-card border-primary" data-bs-toggle="modal" data-bs-target="#validateModal">
                    <div class="card-body text-center">
                        <div class="action-icon text-primary">
                            <i class="fas fa-check-circle"></i>
                        </div>
                        <h5 class="card-title">Valida Database</h5>
                        <p class="card-text text-muted">
                            Verifica che lo schema del database sia completo e coerente
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-lg-2">
                <div class="card action-card border-warning" data-bs-toggle="modal" data-bs-target="#repairModal">
                    <div class="card-body text-center">
                        <div class="action-icon text-warning">
                            <i class="fas fa-tools"></i>
                        </div>
                        <h5 class="card-title">Ripara Database</h5>
                        <p class="card-text text-muted">
                            Aggiunge fogli o colonne mancanti e pulisce la struttura
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-lg-2">
                <div class="card action-card border-danger" data-bs-toggle="modal" data-bs-target="#initializeModal">
                    <div class="card-body text-center">
                        <div class="action-icon text-danger">
                            <i class="fas fa-rocket"></i>
                        </div>
                        <h5 class="card-title">Inizializza Database</h5>
                        <p class="card-text text-muted">
                            Ricrea la struttura completa del database da zero
                        </p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row g-4 mb-4">
            <div class="col-md-3 col-lg-3">
                <div class="card action-card border-info" data-bs-toggle="modal" data-bs-target="#importModal">
                    <div class="card-body text-center">
                        <div class="action-icon text-info">
                            <i class="fas fa-file-import"></i>
                        </div>
                        <h5 class="card-title">Importa dati utente</h5>
                        <p class="card-text text-muted">
                            Importa i dati associati all'utente corrente da un backup JSON
                        </p>
                    </div>
                </div>
            </div>
            <div class="col-md-3 col-lg-3 mt-4 mt-md-0">
                <div class="card action-card border-secondary" data-bs-toggle="modal" data-bs-target="#exportUserSqliteModal">
                    <div class="card-body text-center">
                        <div class="action-icon text-secondary">
                            <i class="fas fa-file-export"></i>
                        </div>
                        <h5 class="card-title">Esporta dati utente</h5>
                        <p class="card-text text-muted">
                            Esporta i dati dell'utente corrente in JSON o SQLite per reimport
                        </p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Documentazione -->
        <div class="card">
            <div class="card-header bg-secondary text-white">
                <h5 class="mb-0"><i class="fas fa-book me-2"></i>Documentazione</h5>
            </div>
            <div class="card-body">
                <h6>Cosa puoi fare da qui:</h6>
                <ul>
                    <li><strong>Valida</strong>: verifica l'integrità di fogli e colonne senza modificare i dati.</li>
                    <li><strong>Ripara</strong>: aggiunge fogli/colonne mancanti quando la validazione segnala problemi.</li>
                    <li><strong>Inizializza</strong>: ricrea il database da zero solo quando strettamente necessario.</li>
                    <li><strong>Importa/Esporta dati utente</strong>: scambia i dati dell'utente tramite backup JSON e download SQLite.</li>
                </ul>

                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong>Attenzione:</strong> Riparazione e Inizializzazione modificano il database; usa le opzioni di backup automatico nelle modali se disponibili. Il backup manuale su file interno è disabilitato in questa pagina.
                </div>
            </div>
        </div>
    </div>

    <?php if ($isAdminUser): ?>
    <!-- Modale Validazione -->
    <div class="modal fade" id="validateModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header bg-primary text-white">
                        <h5 class="modal-title"><i class="fas fa-check-circle me-2"></i>Valida Database</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Questa operazione controllerà:</p>
                        <ul>
                            <li>Accessibilità del database</li>
                            <li>Esistenza di tutti i fogli richiesti</li>
                            <li>Correttezza delle colonne</li>
                            <li>integrità dei dati</li>
                        </ul>
                        <p class="text-muted mb-0">
                            <i class="fas fa-info-circle me-1"></i>
                            Questa operazione è sicura e non modifica il database.
                        </p>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="validate">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-play me-1"></i>Esegui Validazione
                        </button>
                    </div>
                </form>
    </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- Modale Riparazione -->
    <div class="modal fade" id="repairModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header bg-warning text-dark">
                        <h5 class="modal-title"><i class="fas fa-tools me-2"></i>Ripara Database</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Questa operazione correggerà:</p>
                        <ul>
                            <li>Fogli mancanti</li>
                            <li>Colonne incomplete</li>
                            <li>Errori di struttura</li>
                        </ul>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_backup" id="repairBackup" checked>
                            <label class="form-check-label" for="repairBackup">
                                <strong>Crea backup prima di riparare</strong> (consigliato)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="repair">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-warning">
                            <i class="fas fa-wrench me-1"></i>Ripara Database
                        </button>
                    </div>
                </form>
    </div>
    </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdminUser): ?>
    <!-- Modale Inizializzazione -->
    <div class="modal fade" id="initializeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-rocket me-2"></i>Inizializza Database</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-danger">
                            <i class="fas fa-exclamation-triangle me-2"></i>
                            <strong>ATTENZIONE!</strong> Questa operazione creerà un database nuovo.
                        </div>
                        <p>Verrà creato un database con:</p>
                        <ul>
                            <li>Tutti i fogli necessari</li>
                            <li>Struttura corretta delle colonne</li>
                            <li>Dati di esempio (se configurato)</li>
                        </ul>
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_backup" id="initBackup" checked>
                            <label class="form-check-label" for="initBackup">
                                <strong>Crea backup del database esistente</strong> (fortemente consigliato)
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="initialize">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-danger">
                            <i class="fas fa-rocket me-1"></i>Inizializza
                        </button>
                    </div>
                </form>
    </div>
    </div>
    </div>
    <?php endif; ?>

    <!-- Modale Importa -->
    <div class="modal fade" id="importModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="importForm" enctype="multipart/form-data">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header bg-info text-white">
                        <h5 class="modal-title"><i class="fas fa-file-import me-2"></i>Importa dati utente (backup JSON)</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Importante:</strong> carica il file JSON esportato dall'app. Il contenuto viene solo letto e validato come JSON, mai eseguito o salvato in percorsi arbitrari. Dopo l'importazione, usa "Ripara" per aggiungere eventuali tabelle mancanti richieste dal sistema.
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><strong>Database Corrente (Destinazione):</strong></label>
                            <div class="alert alert-secondary py-2 mb-0">
                                <i class="fas fa-database me-2"></i><?= htmlspecialchars($dbDisplayName) ?>
                                - <code><?= htmlspecialchars($dbPath) ?></code>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="importFile" class="form-label">Seleziona backup (.json) *</label>
                            <input type="file"
                                   class="form-control"
                                   name="import_file"
                                   id="importFile"
                                   accept=".json"
                                   required>
                            <small class="form-text text-muted">
                                Il file viene analizzato come JSON e importato in modo sicuro; non è possibile specificare percorsi del server.
                            </small>
                        </div>

                        <hr>

                        <div class="form-check mb-2">
                            <input class="form-check-input" type="checkbox" name="create_backup" id="importBackup" checked>
                            <label class="form-check-label" for="importBackup">
                                <strong>Crea backup prima di importare</strong> (fortemente consigliato)
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="overwrite_existing" id="overwriteExisting">
                            <label class="form-check-label" for="overwriteExisting">
                                <strong>Sovrascrivi dati esistenti per questo utente</strong><br>
                                <small class="text-muted">
                                    I dati associati all'utente corrente nelle tabelle UDA, TEST, MATERIALI, ecc.
                                    verranno cancellati prima dell'import. Gli altri utenti non saranno toccati.
                                </small>
                            </label>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="import">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-info">
                            <i class="fas fa-file-import me-1"></i>Importa dati utente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modale Esporta dati utente -->
    <div class="modal fade" id="exportUserSqliteModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <div class="modal-header bg-secondary text-white">
                        <h5 class="modal-title"><i class="fas fa-file-export me-2"></i>Esporta dati utente</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>
                            Verrà generato un file con le UDA e i dati dell'utente corrente e scaricato tramite il browser.
                        </p>
                        <div class="mb-3">
                            <label for="exportFormat" class="form-label">Formato</label>
                            <select class="form-select" id="exportFormat" name="export_format">
                                <option value="json">JSON (riimportabile)</option>
                                <option value="sqlite" selected>SQLite</option>
                            </select>
                            <small class="form-text text-muted">
                                JSON può essere importato direttamente dalla stessa pagina; SQLite è utile per debug o trasferimento.
                            </small>
                        </div>
                        <div class="mb-3">
                            <label for="targetSqliteFilename" class="form-label">Nome file (opzionale)</label>
                            <input type="text"
                                   class="form-control"
                                   name="target_sqlite_filename"
                                   id="targetSqliteFilename"
                                   placeholder="lascia vuoto per generare automaticamente">
                            <small class="form-text text-muted">
                                Il file non viene scritto in percorsi arbitrari: viene creato temporaneamente e scaricato.
                            </small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <input type="hidden" name="action" value="export_user_sqlite">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-secondary">
                            <i class="fas fa-file-export me-1"></i>Esporta dati utente
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
