<?php

declare(strict_types=1);
$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (str_starts_with($class, 'App\\')) {
        $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
        if (is_file($path)) require $path;
    }
});
use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\StudentReferenceGateway;
$relative = 'storage/temp/student-reference-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$failures = [];
try {
    $db = DatabaseFactory::create(['database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]]]);
    (new SchemaMigrationRunner($db))->migrate();
    $payload = StudentReferenceGateway::normalizeWrite($db, 'VOTI', [
        'id_voto' => 'V-1', 'id_uda' => 'UDA-1', 'id_studente_cv' => 'CV-1',
        'voto' => '8', 'nome_studente' => 'Nome da non salvare', 'id_utente' => 'u-1',
    ]);
    if (($payload['id_studente'] ?? '') === '' || isset($payload['id_studente_cv']) || isset($payload['nome_studente'])) {
        $failures[] = 'normalizzazione voto non provider-neutral';
    }
    $db->insertRow('VOTI', $payload);
    $rows = StudentReferenceGateway::exposeRows($db, 'VOTI', $db->findAll('VOTI'));
    if (($rows[0]['id_studente_cv'] ?? '') !== 'CV-1') $failures[] = 'ID ClasseViva non esposto dalla facade';
} catch (Throwable $e) { $failures[] = $e->getMessage(); }
finally { @unlink($absolute); @unlink($absolute . '-wal'); @unlink($absolute . '-shm'); }
if ($failures !== []) { foreach ($failures as $f) fwrite(STDERR, "FAIL: {$f}\n"); exit(1); }
fwrite(STDOUT, "PASS: riferimenti studente normalizzati su ID interno.\n");
