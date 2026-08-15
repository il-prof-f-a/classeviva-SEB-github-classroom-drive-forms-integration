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
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;
use App\Core\UdaPublicationRepository;

$relativeDb = 'storage/temp/uda-groups-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $groups = new TeachingGroupRepository($adapter, 'USR_A');
    $group = $groups->create(['nome_gruppo' => '4 Informatica']);
    $adapter->insertRow('UDA_ANAGRAFICA', [
        'id_uda' => 'UDA_TEST', 'titolo' => 'UDA test', 'id_utente' => 'USR_A',
    ]);

    $assignments = new UdaGroupRepository($adapter, 'USR_A');
    $assignment = $assignments->assign('UDA_TEST', (string)$group['id_gruppo'], [
        'note' => 'assegnazione test', 'stato' => 'assegnata',
    ]);
    if (($assignment['id_gruppo'] ?? '') !== $group['id_gruppo']) {
        $failures[] = 'assegnazione non collegata al gruppo interno';
    }
    if (isset($assignment['id_classe_cv'], $assignment['id_materia_cv'])) {
        $failures[] = 'assegnazione contiene ancora chiavi CV';
    }
    if (count($assignments->listForUda('UDA_TEST')) !== 1) {
        $failures[] = 'lista assegnazioni UDA incompleta';
    }

    $publications = new UdaPublicationRepository($adapter, 'USR_A');
    $publication = $publications->publish('UDA_TEST', (string)$group['id_gruppo'], [
        'provider' => 'google_classroom',
        'external_resource_id' => 'GC_WORK_1',
        'external_url' => 'https://classroom.example.invalid/work/1',
    ]);
    if (($publication['provider'] ?? '') !== 'google_classroom'
        || count($publications->listForUda('UDA_TEST')) !== 1) {
        $failures[] = 'pubblicazione UDA non registrata';
    }
    if (!$assignments->delete((string)$assignment['id_assegnazione'])) {
        $failures[] = 'eliminazione assegnazione fallita';
    }

    $adapter->insertClasseAssegnata([
        'id_assegnazione' => 'LEGACY_ASSIGN_1',
        'id_uda' => 'UDA_TEST',
        'id_classe' => 'CV_4C',
        'id_materia_cv' => 'CV_INF',
        'nome_classe' => '4C',
        'nome_materia' => 'Informatica',
        'id_utente' => 'USR_A',
    ]);
    $legacyRows = $adapter->findClassiAssegnate('UDA_TEST');
    if (($legacyRows[0]['id_gruppo'] ?? '') === '' || ($legacyRows[0]['id_classe'] ?? '') !== 'CV_4C') {
        $failures[] = 'facciata adapter legacy non deriva il gruppo interno';
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

fwrite(STDOUT, "PASS: assegnazioni UDA e pubblicazioni usano il gruppo interno.\n");
