<?php

declare(strict_types=1);

use App\Core\Database\DatabaseFactory;
use App\Core\Database\LegacyDomainTableMigration;
use App\Core\Database\SchemaMigrationRunner;

/**
 * Migrazione esplicita delle tabelle legacy.
 *
 * CLI:
 *   php scripts/migrate_legacy_tables.php --dry-run --target=staging
 *   php scripts/migrate_legacy_tables.php --apply --target=staging \
 *       --confirm=REMOVE-LEGACY-TABLES --output=storage/reports/legacy.json
 *
 * Per lo stage, se eseguito temporaneamente via web, l'azione apply richiede:
 * - POST action=apply, target=staging, confirm=REMOVE-LEGACY-TABLES;
 * - config/.stage_migration_token presente sul server e POST token corretto.
 * Il file token e questo script vanno rimossi subito dopo l'esecuzione.
 */

$root = dirname(__DIR__);
$isCli = PHP_SAPI === 'cli';
$options = $isCli ? (getopt('', ['dry-run', 'apply', 'confirm:', 'target:', 'token:', 'output::']) ?: []) : [];

$action = $isCli
    ? (array_key_exists('apply', $options) ? 'apply' : 'dry-run')
    : (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && ($_POST['action'] ?? '') === 'apply' ? 'apply' : 'dry-run');
$target = $isCli
    ? strtolower(trim((string)($options['target'] ?? '')))
    : strtolower(trim((string)($_POST['target'] ?? $_GET['target'] ?? '')));
$confirmation = $isCli
    ? (string)($options['confirm'] ?? '')
    : (string)($_POST['confirm'] ?? '');
$providedToken = $isCli
    ? (string)($options['token'] ?? '')
    : (string)($_POST['token'] ?? '');

if ($action === 'apply' && !$isCli && ($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $action = 'dry-run';
}
if ($action === 'apply' && $target === '') {
    $target = 'staging';
}
if ($target === '') {
    $target = 'local';
}

$reportPath = $isCli ? (string)($options['output'] ?? '') : '';
if ($reportPath !== '') {
    $normalized = str_replace('\\', '/', $reportPath);
    $isAbsolute = preg_match('/^[A-Za-z]:\//', $normalized) === 1 || str_starts_with($normalized, '/');
    if (!$isAbsolute) {
        $reportPath = $root . '/' . ltrim($reportPath, '/\\');
        $normalized = str_replace('\\', '/', $reportPath);
    }
    $rootPrefix = rtrim(str_replace('\\', '/', $root), '/') . '/';
    if (!str_starts_with($normalized, $rootPrefix)) {
        throw new RuntimeException('Il report deve essere salvato dentro la repo privata.');
    }
}

function migrationOutput(array $payload, bool $isCli, ?string $reportPath = null): never
{
    if ($reportPath !== null && $reportPath !== '') {
        if (file_put_contents($reportPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
            throw new RuntimeException('Impossibile scrivere il report della migrazione.');
        }
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
    $environment = (string)($config['system']['environment'] ?? 'production');

    if ($action === 'apply') {
        if (in_array($target, ['production', 'prod'], true)
            || strtolower(trim($environment)) === 'production') {
            throw new RuntimeException('Migrazione legacy vietata in ambiente production.');
        }
        if (!in_array($target, ['local', 'development', 'test', 'stage', 'staging', 'preproduction'], true)) {
            throw new RuntimeException('Target non riconosciuto: usare local, staging o preproduction.');
        }
        if ($confirmation !== LegacyDomainTableMigration::CONFIRMATION) {
            throw new RuntimeException('Conferma mancante o non valida.');
        }

        $tokenFile = $root . '/config/.stage_migration_token';
        $expectedToken = is_file($tokenFile) ? trim((string)file_get_contents($tokenFile)) : '';
        if (!in_array($target, ['local', 'development', 'test'], true)) {
            if ($expectedToken === '') {
                throw new RuntimeException('Token staging mancante: creare temporaneamente config/.stage_migration_token.');
            }
            if ($providedToken === '' || !hash_equals($expectedToken, $providedToken)) {
                throw new RuntimeException('Token staging non valido.');
            }
        }
    }

    $db = DatabaseFactory::create($config);
    $migration = new LegacyDomainTableMigration($db, $environment, $target);
    if ($action === 'dry-run') {
        $report = $migration->dryRun();
    } else {
        $schemaReport = (new SchemaMigrationRunner($db))->migrate();
        if (($schemaReport['errors'] ?? []) !== []) {
            throw new RuntimeException('Schema provider-neutral non applicabile: ' . implode('; ', $schemaReport['errors']));
        }
        $report = $migration->apply();
        $report['schema_migration'] = $schemaReport;
    }

    if ($isCli && $reportPath === '') {
        $reportPath = $root . '/storage/reports/legacy-domain-migration-' . date('Ymd-His') . '.json';
        if (!is_dir(dirname($reportPath))) {
            mkdir(dirname($reportPath), 0750, true);
        }
    }
    migrationOutput($report, $isCli, $reportPath !== '' ? $reportPath : null);
} catch (Throwable $exception) {
    migrationOutput([
        'success' => false,
        'mode' => $action,
        'target' => $target,
        'error' => $exception->getMessage(),
        'generated_at' => date(DATE_ATOM),
    ], $isCli);
}
