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

$relativeDb = 'storage/temp/grade-import-name-' . bin2hex(random_bytes(6)) . '.db';
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
    (new TeachingGroupIntegrationRepository($adapter, $userId))->link([
        'id_gruppo' => $groupId,
        'provider' => 'google_classroom',
        'external_context_id' => 'GC_COURSE_1',
        'external_name' => 'Corso',
    ]);

    $service = new GradeImportStudentService($adapter, $userId);

    $roster = [
        ['external_user_id' => 'GC_USER_1', 'display_name' => 'Mario Rossi', 'email' => 'email@email.it'],
        ['external_user_id' => 'GC_USER_2', 'display_name' => 'Luigi Bianchi', 'email' => 'email@email.it'],
    ];
    $result = $service->resolveByName('google_classroom', 'GC_COURSE_1', $roster, [
        'Mario Rossi',
        'luigi bianchi',
        'Mario Rossy',
        'Sconosciuto',
    ]);

    if (($result['group_id'] ?? '') !== $groupId) {
        $failures[] = 'gruppo risolto diverso da quello collegato';
    }
    $m1 = $result['matches']['Mario Rossi'] ?? null;
    $m2 = $result['matches']['luigi bianchi'] ?? null;
    $m3 = $result['matches']['Mario Rossy'] ?? null;
    if (!is_array($m1) || ($m1['external_user_id'] ?? '') !== 'GC_USER_1' || ($m1['id_studente'] ?? '') === '') {
        $failures[] = 'match esatto nome non risolto';
    }
    if (($m1['display_name'] ?? '') !== 'Mario Rossi') {
        $failures[] = 'nome roster non restituito nel match';
    }
    if (!is_array($m2) || ($m2['external_user_id'] ?? '') !== 'GC_USER_2' || ($m2['id_studente'] ?? '') === '') {
        $failures[] = 'match case-insensitive nome non risolto';
    }
    if (!is_array($m3) || ($m3['external_user_id'] ?? '') !== 'GC_USER_1') {
        $failures[] = 'match fuzzy (levenshtein <= 3) nome non risolto';
    }
    if (($m1['id_studente'] ?? '') === ($m2['id_studente'] ?? '')) {
        $failures[] = 'nomi distinti mappati allo stesso id_studente';
    }
    if (!in_array('Sconosciuto', $result['unmatched'] ?? [], true)) {
        $failures[] = 'nome non mappato assente da unmatched';
    }

    // Nessuna PII persistita.
    $raw = json_encode([
        $adapter->findAll('STUDENTI'),
        $adapter->findAll('STUDENTI_IDENTITA_ESTERNE'),
        $adapter->findAll('GRUPPI_STUDENTI'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['Mario Rossi', 'Luigi Bianchi', 'email@email.it', '@'] as $forbidden) {
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
fwrite(STDOUT, "PASS: risoluzione nome -> id_studente provider-neutral (senza PII persistita).\n");
