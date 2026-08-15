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
use App\Core\StudentiManager;

$relativeDb = 'storage/temp/cv-sync-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $manager = new StudentiManager($adapter, null, ['user_id' => 'USR_A']);
    $result = $manager->sincronizzaRoster('GRP_1', 'classeviva', 'CV_CLASS_1', [
        ['id' => 'CV_STUDENT_1', 'nome' => 'Nome', 'cognome' => 'Cognome'],
        ['id' => 'CV_STUDENT_2', 'nome' => 'Secondo', 'cognome' => 'Studente'],
    ]);
    if (($result['sincronizzati'] ?? 0) !== 2 || ($result['nuovi'] ?? 0) !== 2) {
        $failures[] = 'sincronizzazione roster non restituisce statistiche corrette';
    }
    $students = $adapter->findAll('STUDENTI');
    $identities = $adapter->findAll('STUDENTI_IDENTITA_ESTERNE');
    $memberships = $adapter->findAll('GRUPPI_STUDENTI');
    if (count($students) !== 2 || count($identities) !== 2 || count($memberships) !== 2) {
        $failures[] = 'sincronizzazione non crea studenti, identità e membership interne';
    }
    $serialized = json_encode($students, JSON_UNESCAPED_UNICODE);
    foreach (['Nome', 'Cognome', 'nome_studente', 'nome_classe'] as $forbidden) {
        if (stripos((string)$serialized, $forbidden) !== false) {
            $failures[] = "dato non autorizzato persistito: {$forbidden}";
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
fwrite(STDOUT, "PASS: sync ClasseViva salva solo identità e membership interne.\n");
