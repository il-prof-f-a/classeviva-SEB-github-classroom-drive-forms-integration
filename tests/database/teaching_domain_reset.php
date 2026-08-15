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
use App\Core\Database\TeachingDomainReset;

$relativeDb = 'storage/temp/reset-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();

    $adapter->insertRow('UTENTI', ['id_utente' => 'USR_TEST', 'email' => 'email@email.it']);
    $adapter->insertRow('INTEGRAZIONI_UTENTE', [
        'id_integrazione' => 'INT_TEST', 'id_utente' => 'USR_TEST', 'provider' => 'google',
    ]);
    $adapter->insertRow('OBIETTIVI_MASTER', ['id_obiettivo' => 'OBJ_TEST', 'id_utente' => 'USR_TEST']);
    $adapter->insertRow('GRUPPI_DIDATTICI', ['id_gruppo' => 'GRP_TEST', 'id_utente' => 'USR_TEST']);
    $adapter->insertRow('STUDENTI', ['id_studente' => 'STD_TEST', 'id_utente' => 'USR_TEST']);
    $adapter->insertRow('VOTI', [
        'id_voto' => 'VOTO_TEST', 'id_gruppo' => 'GRP_TEST',
        'id_studente' => 'STD_TEST', 'id_utente' => 'USR_TEST',
    ]);

    $reset = new TeachingDomainReset($adapter, 'test');
    $dryRun = $reset->dryRun();
    if (!in_array('UTENTI', $dryRun['preserved_tables'] ?? [], true)
        || !in_array('GRUPPI_DIDATTICI', $dryRun['dropped_tables'] ?? [], true)) {
        $failures[] = 'dry-run non descrive correttamente allowlist';
    }

    $report = $reset->apply();
    if (($report['success'] ?? false) !== true) {
        $failures[] = 'reset non riuscito: ' . json_encode($report, JSON_UNESCAPED_UNICODE);
    }
    foreach (['UTENTI', 'INTEGRAZIONI_UTENTE', 'OBIETTIVI_MASTER'] as $table) {
        if ($adapter->count($table) !== 1) {
            $failures[] = "tabella preservata alterata: {$table}";
        }
    }
    foreach (['GRUPPI_DIDATTICI', 'STUDENTI', 'VOTI'] as $table) {
        if ($adapter->count($table) !== 0) {
            $failures[] = "tabella didattica non svuotata: {$table}";
        }
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

fwrite(STDOUT, "PASS: reset didattico preserva account, integrazioni e cataloghi.\n");
