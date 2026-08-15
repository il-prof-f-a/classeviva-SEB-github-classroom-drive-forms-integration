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
use App\Core\ProviderNeutralMappingService;

$relativeDb = 'storage/temp/provider-mappings-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();
    $service = new ProviderNeutralMappingService($db, 'user-1');

    $google = $service->upsertGoogleClassroomMapping([
        'classeviva_class_id' => 'cv-4c',
        'classeviva_class_name' => '4C Informatica',
        'classeviva_subject_id' => 'informatica',
        'classeviva_subject_name' => 'Informatica',
        'google_course_id' => 'gc-123',
        'google_course_name' => 'TPSIT 4C (2025-26)',
    ]);
    $github = $service->upsertGithubClassroomMapping([
        'classeviva_class_id' => 'cv-4c',
        'classeviva_class_name' => '4C Informatica',
        'classeviva_subject_id' => 'informatica',
        'classeviva_subject_name' => 'Informatica',
        'github_classroom_id' => 'gh-456',
        'github_org_name' => 'classe-4c',
        'classroom_name' => '4C Informatica GitHub',
    ]);

    if (($google['id_gruppo'] ?? '') === '' || ($github['id_gruppo'] ?? '') !== ($google['id_gruppo'] ?? '')) {
        $failures[] = 'provider diversi non condividono lo stesso gruppo didattico';
    }
    $rows = $service->listGoogleClassroomMappings();
    if (count($rows) !== 1 || ($rows[0]['id_corso_gc'] ?? '') !== 'gc-123') {
        $failures[] = 'facade Google Classroom non espone la mappatura';
    }
    if (!$service->deactivateMapping((string)($github['id_collegamento'] ?? ''))) {
        $failures[] = 'disattivazione collegamento GitHub fallita';
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

fwrite(STDOUT, "PASS: mappature Classroom/GitHub su gruppo didattico provider-neutral.\n");
