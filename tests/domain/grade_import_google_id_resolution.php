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

$relativeDb = 'storage/temp/grade-import-google-id-' . bin2hex(random_bytes(6)) . '.db';
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
    (new TeachingGroupIntegrationRepository($adapter, $userId))->link([
        'id_gruppo' => $groupId,
        'provider' => 'google_classroom',
        'external_context_id' => '874780791029',
        'external_name' => 'TPSIT 4L',
    ]);

    // Studente premappato: identità classeviva + google, membership classeviva.
    $students = new StudentRepository($adapter, $userId);
    $identities = new StudentIdentityRepository($adapter, $userId);
    $memberships = new GroupStudentRepository($adapter, $userId);
    $resources = new StudentResourceRepository($adapter, $userId);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);

    $student = $students->create();
    $studentId = (string)$student['id_studente'];
    $identities->attach($studentId, ['provider' => 'classeviva', 'external_user_id' => '14941281']);
    $identities->attach($studentId, ['provider' => 'google_classroom', 'external_user_id' => '109919865179305355257', 'external_context_id' => '874780791029']);
    $memberships->add($groupId, $studentId, ['provider_origine' => 'classeviva', 'external_context_id' => '2153414']);

    $service = new GradeImportStudentService($adapter, $userId);
    $result = $service->resolve('google_classroom', '874780791029', [
        ['external_user_id' => '109919865179305355257', 'display_name' => 'maria bianchi'],
        ['external_user_id' => 'GC_UNKNOWN', 'display_name' => 'Sconosciuto'],
    ]);

    if (($result['group_id'] ?? '') !== $groupId) {
        $failures[] = 'gruppo risolto diverso da quello collegato';
    }

    $byExternal = [];
    foreach ($result['rows'] as $row) {
        $byExternal[(string)($row['external_user_id'] ?? '')] = $row;
    }

    $mapped = $byExternal['109919865179305355257'] ?? null;
    if (!is_array($mapped) || ($mapped['id_studente'] ?? '') !== $studentId) {
        $failures[] = 'id Google non risolto verso lo studente premappato';
    }
    if (($mapped['cv_id'] ?? null) !== '14941281') {
        $failures[] = 'cv_id mancante o errato: ' . var_export($mapped['cv_id'] ?? null, true);
    }

    $unknown = $byExternal['GC_UNKNOWN'] ?? null;
    if (!is_array($unknown)) {
        $failures[] = 'riga per id Google sconosciuto assente';
    } else {
        // Lo studente viene sincronizzato (identità google) ma non è mappato in ClasseViva.
        if (($unknown['cv_id'] ?? null) !== null) {
            $failures[] = 'id Google sconosciuto con cv_id valorizzato';
        }
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
fwrite(STDOUT, "PASS: risoluzione id Google -> studente interno + id ClasseViva (senza email).
");
