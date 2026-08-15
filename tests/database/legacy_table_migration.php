<?php

declare(strict_types=1);

use App\Core\Database\DatabaseFactory;
use App\Core\Database\LegacyDomainTableMigration;
use App\Core\Database\SchemaMigrationRunner;

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

$db = DatabaseFactory::createForTesting();
$pdo = $db->getConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "FAIL: connessione PDO non disponibile\n");
    exit(1);
}

$quote = static fn(string $table): string => '"' . $table . '"';
foreach (LegacyDomainTableMigration::TABLES as $table) {
    $pdo->exec('CREATE TABLE IF NOT EXISTS ' . $quote($table) . ' (id_test_legacy TEXT)');
}
$pdo->exec('INSERT INTO "CLASSI" (id_test_legacy) VALUES (\'fixture\')');

$migration = new LegacyDomainTableMigration($db, 'development', 'local');
$dryRun = $migration->dryRun();
if (($dryRun['existing']['CLASSI']['rows'] ?? 0) !== 1) {
    fwrite(STDERR, "FAIL: dry-run non rileva le righe legacy\n");
    exit(1);
}

$applied = $migration->apply();
if (($applied['success'] ?? false) !== true || count($applied['dropped'] ?? []) !== count(LegacyDomainTableMigration::TABLES)) {
    fwrite(STDERR, "FAIL: rimozione legacy incompleta\n");
    exit(1);
}
foreach (LegacyDomainTableMigration::TABLES as $table) {
    if ($db->sheetExists($table)) {
        fwrite(STDERR, "FAIL: tabella ancora presente: {$table}\n");
        exit(1);
    }
}

$schemaReport = (new SchemaMigrationRunner($db))->migrate();
if (($schemaReport['errors'] ?? []) !== []) {
    fwrite(STDERR, "FAIL: migrazione schema dopo la rimozione legacy\n");
    exit(1);
}
foreach (LegacyDomainTableMigration::TABLES as $table) {
    if ($db->sheetExists($table)) {
        fwrite(STDERR, "FAIL: bootstrap ricrea la tabella legacy: {$table}\n");
        exit(1);
    }
}
if (!$db->sheetExists('GITHUB_ASSIGNMENT_STUDENT_LINKS')) {
    fwrite(STDERR, "FAIL: tabella canonica GitHub mancante\n");
    exit(1);
}

fwrite(STDOUT, "PASS: rimozione legacy idempotente e schema canonico stabile.\n");
