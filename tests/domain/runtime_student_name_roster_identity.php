<?php

declare(strict_types=1);

// Regressione: quando un gruppo è collegato solo a GitHub Classroom e non ha
// membership interne, la risoluzione centralizzata dei nomi deve restituire l'id
// INTERNO (risolto dall'identità GitHub) quando esiste, così il salvataggio delle
// valutazioni e il caricamento restano coerenti (stesso id_studente).
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
use App\Core\RuntimeStudentNameService;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/runtime-roster-id-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_ROSTER_ID';

    $group = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    ))->createGroup(['nome_gruppo' => 'Gruppo solo GitHub']);
    $groupId = (string)$group['id_gruppo'];

    (new TeachingGroupIntegrationRepository($adapter, $userId))->link([
        'id_gruppo' => $groupId,
        'provider' => 'github_classroom',
        'external_context_id' => 'GH_CLASS',
        'external_name' => 'GitHub',
    ]);

    // Studente con identità GitHub già esistente (come dopo un syncRoster).
    $student = (new StudentRepository($adapter, $userId))->create();
    $internalId = (string)$student['id_studente'];
    (new StudentIdentityRepository($adapter, $userId))->attach($internalId, [
        'provider' => 'github_classroom',
        'external_user_id' => 'alice-dev',
    ]);

    $rosters = [
        'github_classroom' => [
            ['external_user_id' => 'alice-dev', 'display_name' => 'Alice Rossi'],
        ],
    ];

    $service = new RuntimeStudentNameService(
        $adapter,
        $userId,
        static fn(string $provider, string $contextId): array => $rosters[$provider] ?? []
    );
    $resolved = $service->resolveGroupStudents($groupId);

    if (count($resolved) !== 1) {
        $failures[] = 'atteso 1 studente, trovati ' . count($resolved);
    } else {
        $row = $resolved[0];
        if (($row['id_studente'] ?? '') !== $internalId) {
            $failures[] = 'id_studente non risolto (atteso ' . $internalId . '): ' . ($row['id_studente'] ?? '');
        }
        if (($row['id_studente_internal'] ?? '') !== $internalId) {
            $failures[] = 'id_studente_internal non valorizzato correttamente';
        }
        if (($row['nome_completo'] ?? '') !== 'Alice Rossi') {
            $failures[] = 'nome runtime non risolto: ' . ($row['nome_completo'] ?? '');
        }
        if (($row['external_ids']['github_classroom'][0] ?? '') !== 'alice-dev') {
            $failures[] = 'alias esterno GitHub non esposto per il matching runtime';
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
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: roster GitHub risolve l'id interno dall'identità per coerenza col salvataggio.
");
