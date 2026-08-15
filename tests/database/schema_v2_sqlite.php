<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;

$relativeDb = 'storage/temp/schema-v2-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => [
            'type' => 'sqlite',
            'sqlite' => ['file' => $relativeDb],
        ],
    ]);

    $runner = new SchemaMigrationRunner($adapter);
    $first = $runner->migrate();
    $second = $runner->migrate();

    if (($first['applied'] ?? []) !== ['20260815_001_provider_neutral_domain']) {
        $failures[] = 'migrazione v2 non applicata esattamente una volta';
    }
    if (($second['applied'] ?? []) !== [] || ($second['skipped'] ?? []) !== ['20260815_001_provider_neutral_domain']) {
        $failures[] = 'migrazione v2 non idempotente';
    }
    foreach (['SCHEMA_MIGRATIONS', 'GRUPPI_DIDATTICI', 'UDA_GRUPPI', 'STUDENTI_IDENTITA_ESTERNE'] as $table) {
        if (!$adapter->sheetExists($table)) {
            $failures[] = "tabella v2 mancante: {$table}";
        }
    }

    $rows = $adapter->findAll('SCHEMA_MIGRATIONS');
    if (count($rows) !== 1 || ($rows[0]['versione'] ?? '') !== '20260815_001_provider_neutral_domain') {
        $failures[] = 'registro SCHEMA_MIGRATIONS non coerente';
    }

    $connection = $adapter->getConnection();
    $indexRows = $connection->query("PRAGMA index_list('GRUPPI_INTEGRAZIONI')")->fetchAll();
    if ($indexRows === []) {
        $failures[] = 'indice GRUPPI_INTEGRAZIONI non creato';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    @unlink($absoluteDb);
    @unlink($absoluteDb . '-wal');
    @unlink($absoluteDb . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: migrazioni e schema v2 SQLite idempotenti.\n");
