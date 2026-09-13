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
use App\Core\GitHubAssignmentGroupRepository;
use App\Core\GitHubAssignmentService;

$failures = [];
$db = null;
$absoluteDb = '';

try {
    $relativeDb = 'storage/temp/gh-assignment-multi-groups-' . bin2hex(random_bytes(6)) . '.db';
    $absoluteDb = $root . '/' . $relativeDb;
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();

    $user = 'USR_A';
    $groups = new GitHubAssignmentGroupRepository($db, $user);

    $stored = $groups->replaceForTest('TEST_MULTI', [' GRP_A ', 'GRP_B', 'GRP_A', '']);
    if ($stored !== 2) {
        $failures[] = 'replaceForTest deve salvare due gruppi distinti';
    }
    if ($groups->listGroupIdsForTest('TEST_MULTI') !== ['GRP_A', 'GRP_B']) {
        $failures[] = 'i gruppi collegati non rispettano ordine e deduplicazione';
    }

    $groups->replaceForTest('TEST_MULTI', ['GRP_C']);
    if ($groups->listGroupIdsForTest('TEST_MULTI') !== ['GRP_C']) {
        $failures[] = 'replaceForTest deve sostituire i collegamenti precedenti';
    }

    $otherUser = new GitHubAssignmentGroupRepository($db, 'USR_B');
    $otherUser->replaceForTest('TEST_MULTI', ['GRP_OTHER']);
    if ($groups->listGroupIdsForTest('TEST_MULTI') !== ['GRP_C']) {
        $failures[] = 'i collegamenti di un altro utente non devono essere visibili';
    }

    $service = new GitHubAssignmentService('{cognome}.{nome}', 'studenti.example');
    $merged = $service->mergeResolvedStudents([
        [
            ['id_studente' => 'STD_1', 'nome' => '', 'email' => ''],
            ['id_studente' => 'STD_2', 'nome' => 'Mario Rossi', 'email' => 'email@email.it'],
        ],
        [
            ['id_studente' => 'STD_1', 'nome' => 'Alice Bianchi', 'email' => 'email@email.it'],
            ['id_studente' => 'STD_2', 'nome' => 'Mario Rossi', 'email' => ''],
            ['id_studente' => '', 'nome' => 'senza id', 'email' => 'email@email.it'],
        ],
    ]);
    if (count($merged) !== 2) {
        $failures[] = 'studenti presenti in più gruppi devono produrre una sola riga/repository';
    }
    if (($merged[0]['id_studente'] ?? '') !== 'STD_1'
        || ($merged[0]['nome'] ?? '') !== 'Alice Bianchi'
        || ($merged[0]['email'] ?? '') !== 'email@email.it') {
        $failures[] = 'mergeResolvedStudents deve completare i dati mancanti senza duplicare lo studente';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    if ($absoluteDb !== '') {
        @unlink($absoluteDb);
        @unlink($absoluteDb . '-wal');
        @unlink($absoluteDb . '-shm');
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: assignment GitHub multi-gruppo, ownership e deduplicazione studenti.\n");
