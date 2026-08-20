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
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\StudentRosterService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/gateway-id-gruppo-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_A';

    $service = new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    );
    $group = $service->createGroup(['nome_gruppo' => '4L TPSIT']);
    $groupId = (string)$group['id_gruppo'];
    $service->linkProvider($groupId, [
        'provider' => 'classeviva',
        'tipo_risorsa' => 'classe_materia',
        'external_context_id' => '2153414',
        'external_subject_id' => '213121',
        'external_name' => 'TPSIT 4L',
    ]);

    $students = new StudentRepository($adapter, $userId);
    $identities = new StudentIdentityRepository($adapter, $userId);
    $memberships = new GroupStudentRepository($adapter, $userId);
    $resources = new StudentResourceRepository($adapter, $userId);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
    $student = $resolver->resolveOrCreate('classeviva', '14941281');
    $studentId = (string)$student['id_studente'];

    // 1. Scrittura con coppia classe/materia legacy -> id_gruppo risolto.
    $adapter->insertRow('VALUTAZIONI_RUBRICA', [
        'id_valutazione' => 'VAL_TEST_1',
        'id_uda' => 'UDA_TEST',
        'id_classe_cv' => '2153414',
        'id_materia_cv' => '213121',
        'id_studente_cv' => '14941281',
        'voto_numerico' => '8',
        'id_utente' => $userId,
    ]);

    $raw = $adapter->findAll('VALUTAZIONI_RUBRICA');
    $written = null;
    foreach ($raw as $r) {
        if (($r['id_valutazione'] ?? '') === 'VAL_TEST_1') {
            $written = $r;
        }
    }
    if ($written === null || ($written['id_gruppo'] ?? '') !== $groupId) {
        $failures[] = 'insert con id_classe_cv non ha risolto id_gruppo';
    }
    if (($written['id_studente'] ?? '') !== $studentId) {
        $failures[] = 'insert con id_studente_cv non ha risolto id_studente';
    }

    // 2. Lettura filtrata per id_classe_cv (deve esporre la coppia e non fallire).
    $rows = $adapter->findWhere('VALUTAZIONI_RUBRICA', ['id_classe_cv' => '2153414', 'id_uda' => 'UDA_TEST']);
    if (count($rows) !== 1) {
        $failures[] = 'findWhere per id_classe_cv non ha trovato la riga attesa (trovate ' . count($rows) . ')';
    } elseif (($rows[0]['id_classe_cv'] ?? '') !== '2153414' || ($rows[0]['id_materia_cv'] ?? '') !== '213121') {
        $failures[] = 'findWhere non espone id_classe_cv/id_materia_cv';
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
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: gateway studente traduce id_classe_cv/id_materia_cv -> id_gruppo (lettura e scrittura).
");
