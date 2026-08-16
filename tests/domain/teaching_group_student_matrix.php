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
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupStudentService;

/** @param mixed $matrix */
function matrixStudentIdForExternal(mixed $matrix, string $externalId, ?string $studentId = null): ?string
{
    if (!is_array($matrix)) {
        return null;
    }
    if (isset($matrix['id_studente']) && is_scalar($matrix['id_studente'])) {
        $studentId = (string)$matrix['id_studente'];
    }
    foreach ($matrix as $value) {
        if (is_scalar($value) && (string)$value === $externalId) {
            return $studentId;
        }
        $found = matrixStudentIdForExternal($value, $externalId, $studentId);
        if ($found !== null) {
            return $found;
        }
    }
    return null;
}

$relativeDb = 'storage/temp/group-student-matrix-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $group = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_A'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_A')
    ))->createGroup(['nome_gruppo' => '4 Informatica']);
    $groupId = (string)$group['id_gruppo'];

    $service = new TeachingGroupStudentService($adapter, 'USR_A');
    $cvSyncedRows = $service->syncRoster($groupId, 'classeviva', 'CV_CLASS_1', [
        [
            'external_user_id' => 'CV_STUDENT_1',
            'display_name' => ['non scalar'],
            'email' => 'email@email.it',
        ],
        [
            'external_user_id' => ['non scalar'],
            'display_name' => 'Riga da ignorare',
        ],
    ]);
    if (count($cvSyncedRows) !== 1 || isset($cvSyncedRows[0]['display_name'])) {
        $failures[] = 'campi roster non scalari accettati nel roster';
    }
    $service->syncRoster($groupId, 'google_classroom', 'GC_COURSE_1', [
        ['external_user_id' => 'GC_STUDENT_1', 'display_name' => 'Nome temporaneo'],
        ['external_user_id' => 'GC_STUDENT_UNMAPPED', 'display_name' => 'Riga non mappata'],
    ]);
    $service->syncRoster($groupId, 'github_classroom', 'GH_CLASS_1', [
        ['external_user_id' => 'GH_STUDENT_1', 'display_name' => 'Nome temporaneo'],
    ]);
    $linked = $service->linkIdentities($groupId, [
        [
            'provider' => 'classeviva',
            'external_user_id' => 'CV_STUDENT_1',
            'matches' => [[
                'provider' => 'google_classroom',
                'external_user_id' => 'GC_STUDENT_1',
            ]],
        ],
    ]);
    if ($linked !== 1) {
        $failures[] = 'merge delle identità provider non contabilizzato';
    }

    // A failing batch must not leave the first merge partially applied.
    try {
        $service->linkIdentities($groupId, [[
            'provider' => 'classeviva',
            'external_user_id' => 'CV_STUDENT_1',
            'matches' => [
                ['provider' => 'github_classroom', 'external_user_id' => 'GH_STUDENT_1'],
                ['provider' => 'github_classroom', 'external_user_id' => 'GH_MISSING'],
            ],
        ]]);
        $failures[] = 'match inesistente accettato';
    } catch (Throwable) {
        $afterConflict = $service->matrix($groupId);
        $cvAfterConflict = matrixStudentIdForExternal($afterConflict, 'CV_STUDENT_1');
        $ghAfterConflict = matrixStudentIdForExternal($afterConflict, 'GH_STUDENT_1');
        if ($cvAfterConflict === null || $ghAfterConflict === null || $cvAfterConflict === $ghAfterConflict) {
            $failures[] = 'conflitto roster con scritture parziali';
        }
    }

    $matrix = $service->matrix($groupId);
    if ($matrix === []) {
        $failures[] = 'matrice studenti vuota dopo la sincronizzazione';
    }
    $serializedMatrix = json_encode($matrix, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['CV_STUDENT_1', 'GC_STUDENT_1', 'GH_STUDENT_1', 'GC_STUDENT_UNMAPPED'] as $externalId) {
        if (!str_contains($serializedMatrix, $externalId)) {
            $failures[] = "identità o riga non mappata assente dalla matrice: {$externalId}";
        }
    }
    $cvStudentId = matrixStudentIdForExternal($matrix, 'CV_STUDENT_1');
    $googleStudentId = matrixStudentIdForExternal($matrix, 'GC_STUDENT_1');
    if ($cvStudentId === null || $googleStudentId === null) {
        $failures[] = 'identità ClasseViva/Google non associate a una riga studente';
    } elseif ($cvStudentId !== $googleStudentId) {
        $failures[] = 'identità ClasseViva e Google non condividono lo stesso id_studente';
    }
    if (!$service->unlinkIdentity($googleStudentId ?? '', 'google_classroom', 'GC_STUDENT_1')) {
        $failures[] = 'scollegamento identità Google non riuscito';
    }
    if ($service->unlinkIdentity($googleStudentId ?? '', 'google_classroom', 'GC_STUDENT_1')) {
        $failures[] = 'scollegamento identità Google non idempotente';
    }
    $raw = json_encode([
        $adapter->findAll('STUDENTI'),
        $adapter->findAll('STUDENTI_IDENTITA_ESTERNE'),
        $adapter->findAll('GRUPPI_STUDENTI'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['Nome temporaneo', 'Riga non mappata', 'email@email.it', '@', 'display_name'] as $forbidden) {
        if (stripos($raw, $forbidden) !== false) {
            $failures[] = "PII persistita nella matrice studenti: {$forbidden}";
        }
    }

    // An identity moved to another roster context must not leak into this group's matrix.
    $otherGroup = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_A'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_A')
    ))->createGroup(['nome_gruppo' => '5 Informatica']);
    $otherGroupId = (string)$otherGroup['id_gruppo'];
    $service->syncRoster($otherGroupId, 'classeviva', 'CV_CLASS_2', [
        ['external_user_id' => 'CV_STUDENT_1', 'display_name' => 'Nome temporaneo'],
    ]);
    $groupOneMatrix = $service->matrix($groupId);
    $groupOneSerialized = json_encode($groupOneMatrix, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (str_contains($groupOneSerialized, 'CV_STUDENT_1')) {
        $failures[] = 'identità di un altro contesto esposta nella matrice del gruppo';
    }
    $groupTwoSerialized = json_encode($service->matrix($otherGroupId), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    if (!str_contains($groupTwoSerialized, 'CV_STUDENT_1')) {
        $failures[] = 'identità del contesto corrente assente dalla matrice';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($service, $group, $adapter);
    gc_collect_cycles();
    $adapter = null;
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

fwrite(STDOUT, "PASS: matrice studenti provider-neutral senza PII persistita.\n");
