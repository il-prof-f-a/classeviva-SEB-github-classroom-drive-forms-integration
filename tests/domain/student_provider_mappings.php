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
use App\Core\StudentProviderMappingService;

$relative = 'storage/temp/student-provider-map-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$failures = [];
try {
    $db = DatabaseFactory::create(['database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]]]);
    (new SchemaMigrationRunner($db))->migrate();
    $service = new StudentProviderMappingService($db, 'user-1');
    $mapping = new App\Core\ProviderNeutralMappingService($db, 'user-1');
    $mappingRow = $mapping->upsertGoogleClassroomMapping([
        'classeviva_class_id' => 'cv-class',
        'classeviva_class_name' => '4C',
        'classeviva_subject_id' => 'subj',
        'classeviva_subject_name' => 'Informatica',
        'google_course_id' => 'course-1',
        'google_course_name' => 'Corso',
    ]);
    $service->link((string)$mappingRow['id_gruppo'], 'cv-student-1', 'gc-student-1', 'course-1');
    $rows = $service->listForGroup((string)$mappingRow['id_gruppo']);
    if (($rows['cv-student-1'] ?? '') !== 'gc-student-1') {
        $failures[] = 'identità CV/Classroom non collegate';
    }
    $legacyRows = $db->findAll('MAPPATURA_STUDENTI');
    if (count($legacyRows) !== 1 || ($legacyRows[0]['id_studente_cv'] ?? '') !== 'cv-student-1') {
        $failures[] = 'facade legacy MAPPATURA_STUDENTI non leggibile';
    }
    foreach (['STUDENTI', 'STUDENTI_IDENTITA_ESTERNE', 'STUDENTI_RISORSE_ESTERNE'] as $table) {
        foreach ($db->findAll($table) as $row) {
            if (isset($row['nome'], $row['cognome'], $row['email'])) $failures[] = "PII nella tabella {$table}";
        }
    }
} catch (Throwable $e) {
    $failures[] = $e->getMessage();
} finally {
    @unlink($absolute); @unlink($absolute . '-wal'); @unlink($absolute . '-shm');
}
if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: mapping studenti su identità interne provider-neutral.\n");
