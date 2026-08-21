<?php

declare(strict_types=1);

// Regressione: quando un gruppo didattico è collegato solo a un provider (es.
// GitHub Classroom) e non ha membership interne, la risoluzione centralizzata dei
// nomi deve comunque restituire gli studenti dal roster del provider.
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
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/runtime-roster-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_ROSTER';

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

    $rosters = [
        'github_classroom' => [
            ['external_user_id' => 'alice-dev', 'display_name' => 'Alice Rossi'],
            ['external_user_id' => 'bob-dev', 'display_name' => 'Bob Bianchi'],
        ],
    ];

    $service = new RuntimeStudentNameService(
        $adapter,
        $userId,
        static fn(string $provider, string $contextId): array => $rosters[$provider] ?? []
    );
    $resolved = $service->resolveGroupStudents($groupId);

    if (count($resolved) !== 2) {
        $failures[] = 'attesi 2 studenti dal roster, trovati ' . count($resolved);
    }
    $names = array_map(static fn(array $r): string => (string)($r['nome_completo'] ?? ''), $resolved);
    sort($names);
    if ($names !== ['Alice Rossi', 'Bob Bianchi']) {
        $failures[] = 'nomi roster errati: ' . implode(', ', $names);
    }
    foreach ($resolved as $row) {
        if (($row['provider'] ?? '') !== 'github_classroom') {
            $failures[] = 'provider non atteso: ' . ($row['provider'] ?? '');
        }
        if (($row['id_studente_internal'] ?? '') !== '') {
            $failures[] = 'id_studente_internal deve essere vuoto senza membership';
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

fwrite(STDOUT, "PASS: risoluzione nomi studenti dal roster senza membership interne.
");
