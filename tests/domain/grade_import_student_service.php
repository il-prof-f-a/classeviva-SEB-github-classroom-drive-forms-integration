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
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\GradeImportStudentService;

$relativeDb = 'storage/temp/grade-import-' . bin2hex(random_bytes(6)) . '.db';
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
    ))->createGroup(['nome_gruppo' => '4 Informatica']);
    $groupId = (string)$group['id_gruppo'];

    $integrations = new TeachingGroupIntegrationRepository($adapter, $userId);
    $integrations->link([
        'id_gruppo' => $groupId,
        'provider' => 'google_classroom',
        'external_context_id' => 'GC_COURSE_1',
        'external_name' => 'Corso Classroom',
    ]);

    $service = new GradeImportStudentService($adapter, $userId);

    // Risoluzione provider-neutral per un corso Classroom collegato.
    $result = $service->resolve('google_classroom', 'GC_COURSE_1', [
        ['external_user_id' => 'GC_STUDENT_1', 'display_name' => 'Mario Rossi', 'email' => 'email@email.it', 'assigned_grade' => 85],
        ['external_user_id' => 'GC_STUDENT_2', 'assigned_grade' => 70],
        ['external_user_id' => '', 'assigned_grade' => 99],
    ]);
    if (($result['group_id'] ?? '') !== $groupId) {
        $failures[] = 'gruppo risolto diverso da quello collegato';
    }
    if ($service->resolveGroupId('google_classroom', 'GC_COURSE_1') !== $groupId) {
        $failures[] = 'resolveGroupId non restituisce il gruppo collegato';
    }
    if (count($result['rows']) !== 2) {
        $failures[] = 'righe risolte attese 2, ottenute ' . count($result['rows']);
    }
    $byExternal = [];
    foreach ($result['rows'] as $row) {
        $byExternal[(string)($row['external_user_id'] ?? '')] = (string)($row['id_studente'] ?? '');
    }
    if (($byExternal['GC_STUDENT_1'] ?? '') === '' || ($byExternal['GC_STUDENT_2'] ?? '') === '') {
        $failures[] = 'id_studente interno non risolto per ogni identita esterna';
    }
    if (($byExternal['GC_STUDENT_1'] ?? '') === ($byExternal['GC_STUDENT_2'] ?? '')) {
        $failures[] = 'identita esterne distinte mappate allo stesso id_studente';
    }
    // Il voto grezzo resta associato alla riga (non viene perso nella risoluzione).
    $first = $result['rows'][0] ?? [];
    if (($first['assigned_grade'] ?? null) === null) {
        $failures[] = 'campo extra (assigned_grade) perso nella risoluzione';
    }

    // Contesto non collegato deve fallire chiaramente, senza dipendere da ClasseViva.
    try {
        $service->resolve('google_classroom', 'GC_UNKNOWN', [['external_user_id' => 'X']]);
        $failures[] = 'contesto non collegato accettato';
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() === '') {
            $failures[] = 'messaggio di errore vuoto per contesto non collegato';
        }
    }

    // Nessuna riga esterna -> nessuna riga risolta.
    $empty = $service->resolve('google_classroom', 'GC_COURSE_1', []);
    if (($empty['group_id'] ?? '') !== $groupId || $empty['rows'] !== []) {
        $failures[] = 'risoluzione vuota incoerente';
    }

    // Nessuna PII persistita: nomi/email non devono finire nello storage.
    $raw = json_encode([
        $adapter->findAll('STUDENTI'),
        $adapter->findAll('STUDENTI_IDENTITA_ESTERNE'),
        $adapter->findAll('GRUPPI_STUDENTI'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['Mario Rossi', 'email@email.it', '@'] as $forbidden) {
        if (stripos($raw, $forbidden) !== false) {
            $failures[] = "PII persistita: {$forbidden}";
        }
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($service, $adapter, $group);
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

fwrite(STDOUT, "PASS: risoluzione studenti provider-neutral per import voti.\n");
