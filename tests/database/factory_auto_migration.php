<?php

declare(strict_types=1);

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

use App\Core\Database\DatabaseFactory;

$relativeDb = 'storage/temp/factory-migration-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::createWithInitialization([
        'database' => [
            'type' => 'sqlite',
            'sqlite' => ['file' => $relativeDb],
        ],
    ]);

    if (!$adapter->sheetExists('SCHEMA_MIGRATIONS')) {
        $failures[] = 'factory non crea SCHEMA_MIGRATIONS automaticamente';
    }
    $versions = $adapter->findAll('SCHEMA_MIGRATIONS');
    if (count($versions) !== 1 || ($versions[0]['versione'] ?? '') !== '20260815_001_provider_neutral_domain') {
        $failures[] = 'factory non registra la migrazione provider-neutral';
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

fwrite(STDOUT, "PASS: factory applica automaticamente le migrazioni SQL.\n");
