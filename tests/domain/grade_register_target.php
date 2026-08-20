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
use App\Core\Database\SchemaMigrationRunner;
use App\Core\GradeImportStudentService;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/grade-register-target-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_A';

    $group = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    ))->createGroup(['nome_gruppo' => '4L TPSIT']);
    $groupId = (string)$group['id_gruppo'];
    $integrations = new TeachingGroupIntegrationRepository($adapter, $userId);
    $integrations->link([
        'id_gruppo' => $groupId,
        'provider' => 'classeviva',
        'external_context_id' => '2153414',
        'external_subject_id' => '213121',
        'external_name' => 'TPSIT 4L',
    ]);

    $students = new StudentRepository($adapter, $userId);
    $identities = new StudentIdentityRepository($adapter, $userId);
    $memberships = new GroupStudentRepository($adapter, $userId);
    $resources = new StudentResourceRepository($adapter, $userId);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);

    $student = $students->create();
    $studentId = (string)$student['id_studente'];
    $identities->attach($studentId, ['provider' => 'classeviva', 'external_user_id' => '14941281']);
    $memberships->add($groupId, $studentId, ['provider_origine' => 'classeviva', 'external_context_id' => '2153414']);

    // studente senza identità ClasseViva (nello stesso gruppo)
    $studentNoCv = $students->create();
    $studentNoCvId = (string)$studentNoCv['id_studente'];
    $memberships->add($groupId, $studentNoCvId, ['provider_origine' => 'google_classroom', 'external_context_id' => '874780791029']);

    // gruppo senza mapping ClasseViva
    $groupNoCv = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    ))->createGroup(['nome_gruppo' => 'Senza CV']);
    $groupNoCvId = (string)$groupNoCv['id_gruppo'];

    $service = new GradeImportStudentService($adapter, $userId);

    // 1. risoluzione completa
    $target = $service->resolveClasseVivaForRegister($groupId, $studentId);
    if (!is_array($target) || ($target['student_id'] ?? '') !== '14941281') {
        $failures[] = 'target register: student_id errato';
    }
    if (($target['class_id'] ?? '') !== '2153414' || ($target['subject_id'] ?? '') !== '213121') {
        $failures[] = 'target register: class_id/subject_id errati';
    }

    // 2. gruppo senza mapping ClasseViva -> null
    if ($service->resolveClasseVivaForRegister($groupNoCvId, $studentId) !== null) {
        $failures[] = 'gruppo senza mapping ClasseViva dovrebbe risolvere a null';
    }

    // 3. studente senza identità ClasseViva -> null
    if ($service->resolveClasseVivaForRegister($groupId, $studentNoCvId) !== null) {
        $failures[] = 'studente senza identità ClasseViva dovrebbe risolvere a null';
    }

    // 4. input vuoti -> null
    if ($service->resolveClasseVivaForRegister('', $studentId) !== null) {
        $failures[] = 'gruppo vuoto dovrebbe risolvere a null';
    }
    if ($service->resolveClasseVivaForRegister($groupId, '') !== null) {
        $failures[] = 'studente vuoto dovrebbe risolvere a null';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($service, $adapter, $group);
    gc_collect_cycles();
    @unlink($absoluteDb); @unlink($absoluteDb . '-wal'); @unlink($absoluteDb . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: risoluzione target registro ClasseViva (gruppo + studente -> student_id/class_id/subject_id).
");
