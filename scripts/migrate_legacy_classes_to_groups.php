<?php

declare(strict_types=1);

use App\Core\Database\DatabaseFactory;
use App\Core\Database\LegacyClassesToGroupsMigration;

/**
 * Migrazione idempotente delle sole classi/materie legacy verso il dominio
 * dei gruppi didattici. Le tabelle legacy non vengono cancellate.
 *
 * CLI:
 *   php scripts/migrate_legacy_classes_to_groups.php --dry-run --target=local
 *   php scripts/migrate_legacy_classes_to_groups.php --apply --target=staging \
 *     --confirm=MIGRATE-CLASSES-TO-GROUPS --token=... \
 *     --output=storage/reports/legacy-classes-groups.json
 *
 * L'esecuzione web temporanea accetta POST action=apply, target, confirm e
 * token. Il file deve essere rimosso dal server dopo l'uso.
 */

$root = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';
$options = $isCli ? (getopt('', ['dry-run', 'apply', 'confirm:', 'target:', 'token:', 'output::']) ?: []) : [];
$action = $isCli
    ? (array_key_exists('apply', $options) ? 'apply' : 'dry-run')
    : ((($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'apply') ? 'apply' : 'dry-run');
$target = strtolower(trim((string)($isCli ? ($options['target'] ?? '') : ($_POST['target'] ?? $_GET['target'] ?? ''))));
$target = $target !== '' ? $target : 'local';
$confirmation = (string)($isCli ? ($options['confirm'] ?? '') : ($_POST['confirm'] ?? ''));
$providedToken = (string)($isCli ? ($options['token'] ?? '') : ($_POST['token'] ?? ''));
$reportPath = $isCli ? (string)($options['output'] ?? '') : '';

function migrationClassesOutput(array $payload, bool $isCli, string $reportPath = ''): never
{
    if ($reportPath !== '') {
        $directory = dirname($reportPath);
        if (!is_dir($directory)) {
            mkdir($directory, 0750, true);
        }
        file_put_contents($reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    if (!$isCli) {
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . PHP_EOL;
    exit(($payload['success'] ?? false) === true ? 0 : 1);
}

try {
    /** @var array<string,mixed> $config */
    $config = require $root . '/bootstrap.php';
    $environment = strtolower(trim((string)($config['system']['environment'] ?? '')));
    $stageTargets = ['stage', 'staging', 'preproduction'];
    if ($action === 'apply') {
        if (in_array($target, ['production', 'prod'], true)
            || ($environment === 'production' && !in_array($target, $stageTargets, true))) {
            throw new RuntimeException('Migrazione vietata in ambiente production.');
        }
        if (!in_array($target, ['local', 'development', 'test', ...$stageTargets], true)) {
            throw new RuntimeException('Target non riconosciuto.');
        }
        if ($confirmation !== 'MIGRATE-CLASSES-TO-GROUPS') {
            throw new RuntimeException('Conferma mancante o non valida.');
        }
        if (in_array($target, $stageTargets, true)) {
            $tokenFile = $root . '/config/.stage_migration_token';
            $expectedToken = is_file($tokenFile) ? trim((string)file_get_contents($tokenFile)) : '';
            if ($expectedToken === '' || $providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                throw new RuntimeException('Token staging mancante o non valido.');
            }
        }
    }

    $db = DatabaseFactory::create($config);
    $schemaReport = ['ensured' => []];
    foreach ([
        'GRUPPI_DIDATTICI' => ['id_gruppo', 'nome_gruppo', 'nome_classe', 'nome_materia', 'anno_scolastico', 'descrizione', 'stato', 'data_creazione', 'ultima_modifica', 'id_utente'],
        'GRUPPI_INTEGRAZIONI' => ['id_collegamento', 'id_gruppo', 'provider', 'tipo_risorsa', 'external_context_id', 'external_subject_id', 'external_name', 'principale', 'stato', 'metadata_json', 'data_creazione', 'ultima_modifica', 'id_utente'],
        'UDA_GRUPPI' => ['id_assegnazione', 'id_uda', 'id_gruppo', 'data_assegnazione', 'data_inizio', 'data_fine', 'note', 'stato', 'id_utente'],
    ] as $table => $columns) {
        if (!$db->sheetExists($table)) {
            if (!$db->createSheet($table, $columns)) {
                throw new RuntimeException("Impossibile creare {$table}.");
            }
            $schemaReport['ensured'][] = $table;
        }
    }
    $pdo = $db->getConnection();
    if (!$pdo instanceof PDO) {
        throw new RuntimeException('La connessione del database non è PDO.');
    }
    $migration = new LegacyClassesToGroupsMigration($pdo);
    $report = $action === 'apply' ? $migration->apply() : $migration->plan();
    $report['mode'] = $action;
    $report['target'] = $target;
    $report['schema_migration'] = $schemaReport;
    $report['generated_at'] = date(DATE_ATOM);
    $report['success'] = true;
    if ($isCli && $reportPath === '') {
        $reportPath = $root . '/storage/reports/legacy-classes-groups-' . date('Ymd-His') . '.json';
    }
    migrationClassesOutput($report, $isCli, $reportPath);
} catch (Throwable $exception) {
    migrationClassesOutput([
        'success' => false,
        'mode' => $action,
        'target' => $target,
        'error' => $exception->getMessage(),
        'generated_at' => date(DATE_ATOM),
    ], $isCli, '');
}
