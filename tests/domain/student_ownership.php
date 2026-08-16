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
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupRepository;

$relativeDb = 'storage/temp/student-ownership-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();

    $studentsA = new StudentRepository($db, 'USR_A');
    $studentsB = new StudentRepository($db, 'USR_B');
    $studentsA->create(['id_studente' => 'STD_COLLIDE']);
    $studentsB->create(['id_studente' => 'STD_COLLIDE']);
    $studentsB->create(['id_studente' => 'STD_B_ONLY']);

    $identitiesA = new StudentIdentityRepository($db, 'USR_A');
    $identitiesB = new StudentIdentityRepository($db, 'USR_B');
    $identitiesA->attach('STD_COLLIDE', [
        'id_identita' => 'SID_COLLIDE', 'provider' => 'classeviva', 'external_user_id' => 'CV_A',
    ]);
    $identitiesB->attach('STD_COLLIDE', [
        'id_identita' => 'SID_COLLIDE', 'provider' => 'classeviva', 'external_user_id' => 'CV_B',
    ]);
    $targetA = $studentsA->create(['id_studente' => 'STD_TARGET_A']);
    $targetB = $studentsB->create(['id_studente' => 'STD_TARGET_B']);
    if (!$identitiesA->updateContext('classeviva', 'CV_A', 'CTX_A')) {
        $failures[] = 'update contesto identità owner-scoped fallito';
    }
    if (($identitiesB->findByExternal('classeviva', 'CV_B')['external_context_id'] ?? '') !== '') {
        $failures[] = 'update contesto identità ha toccato un altro utente';
    }
    if (!$identitiesA->reassign('SID_COLLIDE', (string)$targetA['id_studente'])) {
        $failures[] = 'reassign identità owner-scoped fallito';
    }
    if (($identitiesB->findByExternal('classeviva', 'CV_B')['id_studente'] ?? '') !== 'STD_COLLIDE') {
        $failures[] = 'reassign identità ha toccato un altro utente';
    }
    foreach (['STD_B_ONLY', 'STD_ORPHAN'] as $invalidTarget) {
        try {
            $identitiesA->reassign('SID_COLLIDE', $invalidTarget);
            $failures[] = 'reassign identità ha accettato target non valido';
        } catch (Throwable) {
            if (($identitiesA->findByExternal('classeviva', 'CV_A')['id_studente'] ?? '') !== 'STD_TARGET_A') {
                $failures[] = 'reassign identità ha mutato prima di rifiutare target';
            }
        }
    }

    $membershipsA = new GroupStudentRepository($db, 'USR_A');
    $membershipsB = new GroupStudentRepository($db, 'USR_B');
    (new TeachingGroupRepository($db, 'USR_A'))->create(['id_gruppo' => 'GRP_A', 'nome_gruppo' => 'A']);
    (new TeachingGroupRepository($db, 'USR_B'))->create(['id_gruppo' => 'GRP_B', 'nome_gruppo' => 'B']);
    try {
        $identitiesA->attach('STD_B_ONLY', [
            'provider' => 'google_classroom', 'external_user_id' => 'GC_FOREIGN',
        ]);
        $failures[] = 'attach identità ha accettato uno studente esterno';
    } catch (Throwable) {
        // Expected: the student belongs to USR_B.
    }
    try {
        $membershipsA->add('GRP_B', 'STD_COLLIDE');
        $failures[] = 'add membership ha accettato un gruppo esterno';
    } catch (Throwable) {
        // Expected: the group belongs to USR_B.
    }
    $membershipsA->add('GRP_A', 'STD_COLLIDE', ['id_iscrizione' => 'MEM_COLLIDE']);
    $membershipsB->add('GRP_B', 'STD_COLLIDE', ['id_iscrizione' => 'MEM_COLLIDE']);
    if (!$membershipsA->reassign('MEM_COLLIDE', 'STD_TARGET_A')) {
        $failures[] = 'reassign membership owner-scoped fallito';
    }
    if (($membershipsB->listForGroup('GRP_B')[0]['id_studente'] ?? '') !== 'STD_COLLIDE') {
        $failures[] = 'reassign membership ha toccato un altro utente';
    }
    foreach (['STD_B_ONLY', 'STD_ORPHAN'] as $invalidTarget) {
        try {
            $membershipsA->reassign('MEM_COLLIDE', $invalidTarget);
            $failures[] = 'reassign membership ha accettato target non valido';
        } catch (Throwable) {
            if (($membershipsA->listForGroup('GRP_A')[0]['id_studente'] ?? '') !== 'STD_TARGET_A') {
                $failures[] = 'reassign membership ha mutato prima di rifiutare target';
            }
        }
    }

    $resourcesA = new StudentResourceRepository($db, 'USR_A');
    $resourcesB = new StudentResourceRepository($db, 'USR_B');
    try {
        $resourcesA->attach('STD_B_ONLY', [
            'provider' => 'github_classroom', 'external_context_id' => 'CTX_FOREIGN',
            'external_resource_id' => 'RES_FOREIGN',
        ]);
        $failures[] = 'attach risorsa ha accettato uno studente esterno';
    } catch (Throwable) {
        // Expected: the student belongs to USR_B.
    }
    $resourcesA->attach('STD_COLLIDE', [
        'id_risorsa' => 'RES_COLLIDE', 'provider' => 'github_classroom',
        'external_context_id' => 'CTX_A', 'external_resource_id' => 'RES_A',
    ]);
    $resourcesB->attach('STD_COLLIDE', [
        'id_risorsa' => 'RES_COLLIDE', 'provider' => 'github_classroom',
        'external_context_id' => 'CTX_B', 'external_resource_id' => 'RES_B',
    ]);
    if (!$resourcesA->reassign('RES_COLLIDE', 'STD_TARGET_A')) {
        $failures[] = 'reassign risorsa owner-scoped fallito';
    }
    if (($resourcesB->listForStudent('STD_COLLIDE')[0]['external_context_id'] ?? '') !== 'CTX_B') {
        $failures[] = 'reassign risorsa ha toccato un altro utente';
    }
    foreach (['STD_B_ONLY', 'STD_ORPHAN'] as $invalidTarget) {
        try {
            $resourcesA->reassign('RES_COLLIDE', $invalidTarget);
            $failures[] = 'reassign risorsa ha accettato target non valido';
        } catch (Throwable) {
            if (($resourcesA->listForStudent('STD_TARGET_A')[0]['external_context_id'] ?? '') !== 'CTX_A') {
                $failures[] = 'reassign risorsa ha mutato prima di rifiutare target';
            }
        }
    }

    if (!$identitiesA->detach('classeviva', 'CV_A')) {
        $failures[] = 'detach identità owner-scoped fallito';
    }
    if ($identitiesB->findByExternal('classeviva', 'CV_B') === null) {
        $failures[] = 'detach identità ha toccato un altro utente';
    }
    if (!$membershipsA->delete('MEM_COLLIDE')) {
        $failures[] = 'delete membership owner-scoped fallito';
    }
    if ($membershipsB->listForGroup('GRP_B') === []) {
        $failures[] = 'delete membership ha toccato un altro utente';
    }
    if (!$studentsA->delete('STD_COLLIDE')) {
        $failures[] = 'delete studente owner-scoped fallito';
    }
    if ($studentsB->findById('STD_COLLIDE') === null) {
        $failures[] = 'delete studente ha toccato un altro utente';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($resourcesA, $resourcesB, $membershipsA, $membershipsB, $identitiesA, $identitiesB, $studentsA, $studentsB, $db);
    gc_collect_cycles();
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

fwrite(STDOUT, "PASS: mutazioni studenti owner-scoped anche con ID tecnici duplicati.\n");
