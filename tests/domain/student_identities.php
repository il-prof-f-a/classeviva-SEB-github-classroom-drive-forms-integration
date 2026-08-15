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

$relativeDb = 'storage/temp/students-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $students = new StudentRepository($adapter, 'USR_A');
    $identities = new StudentIdentityRepository($adapter, 'USR_A');
    $memberships = new GroupStudentRepository($adapter, 'USR_A');
    $resources = new StudentResourceRepository($adapter, 'USR_A');
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
    $roster = new StudentRosterService($resolver, $memberships);

    $student = $resolver->resolveOrCreate('classeviva', 'CV_STUDENT_1');
    $sameStudent = $resolver->resolveOrCreate('classeviva', 'CV_STUDENT_1');
    if (($student['id_studente'] ?? '') !== ($sameStudent['id_studente'] ?? '')) {
        $failures[] = 'resolveOrCreate non idempotente';
    }

    $googleStudent = $resolver->resolveOrCreate('google_classroom', 'GC_STUDENT_1');
    if (!$resolver->merge((string)$googleStudent['id_studente'], (string)$student['id_studente'])) {
        $failures[] = 'merge identità non riuscito';
    }
    if ($identities->findByExternal('google_classroom', 'GC_STUDENT_1')['id_studente'] !== $student['id_studente']) {
        $failures[] = 'identità Google non spostata sul target';
    }

    $synced = $roster->sync('GRP_1', 'google_classroom', 'GC_COURSE_1', [[
        'external_user_id' => 'GC_STUDENT_1', 'display_name' => 'Nome non persistito',
    ], [
        'external_user_id' => 'GC_STUDENT_2', 'display_name' => 'Secondo non persistito',
    ]]);
    if (count($synced) !== 2 || ($synced[0]['display_name'] ?? '') !== 'Nome non persistito') {
        $failures[] = 'sync roster non restituisce le etichette in memoria';
    }
    $membershipRows = $memberships->listForGroup('GRP_1');
    if (count($membershipRows) !== 2) {
        $failures[] = 'membership roster non idempotente o incompleta';
    }

    $resources->attach((string)$student['id_studente'], [
        'provider' => 'github', 'external_context_id' => 'GH_ASSIGN_1',
        'external_resource_id' => 'repo-1', 'external_url' => 'https://github.invalid/repo-1',
    ]);
    if (count($resources->listForStudent((string)$student['id_studente'])) !== 1) {
        $failures[] = 'risorsa esterna studente non registrata';
    }

    $rawStudent = $adapter->findWhere('STUDENTI', ['id_utente' => 'USR_A']);
    $rawIdentity = $adapter->findWhere('STUDENTI_IDENTITA_ESTERNE', ['id_utente' => 'USR_A']);
    $serialized = json_encode([$rawStudent, $rawIdentity], JSON_UNESCAPED_UNICODE);
    foreach (['Nome non persistito', 'Secondo non persistito', 'email', 'nome_studente'] as $forbidden) {
        if (stripos((string)$serialized, $forbidden) !== false) {
            $failures[] = "PII persistita: {$forbidden}";
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

fwrite(STDOUT, "PASS: identità esterne, membership e roster non persistono PII.\n");
