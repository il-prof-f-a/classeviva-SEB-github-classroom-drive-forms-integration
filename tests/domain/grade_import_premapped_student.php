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

$relativeDb = 'storage/temp/grade-import-premapped-' . bin2hex(random_bytes(6)) . '.db';
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

    // Studente già mappato (identità classeviva + google) e membership con
    // provider_origine=classeviva (come la conversione / il gruppo reale).
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
    $roster = [
        ['external_user_id' => '109919865179305355257', 'email' => 'email@email.it', 'display_name' => 'Maria Bianchi'],
        ['external_user_id' => 'OTHER_GC_USER', 'email' => 'email@email.it', 'display_name' => 'Altro Studente'],
    ];
    $result = $service->resolveByEmail('google_classroom', '874780791029', $roster, [
        'email@email.it',
        'email@email.it',
    ]);

    $match = $result['matches']['email@email.it'] ?? null;
    if (!is_array($match) || ($match['external_user_id'] ?? '') !== '109919865179305355257') {
        $failures[] = 'studente premappato non matchato per email';
    } elseif (($match['id_studente'] ?? '') !== $studentId) {
        $failures[] = 'email matchata ma risolta a id_studente sbagliato: ' . ($match['id_studente'] ?? 'null') . ' (atteso ' . $studentId . ')';
    }
    if (!in_array('email@email.it', $result['unmatched'] ?? [], true)) {
        $failures[] = 'email non mappata assente da unmatched';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    @unlink($absoluteDb); @unlink($absoluteDb . '-wal'); @unlink($absoluteDb . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: risoluzione email -> studente premappato (identità google già esistente + membership classeviva).\n");
