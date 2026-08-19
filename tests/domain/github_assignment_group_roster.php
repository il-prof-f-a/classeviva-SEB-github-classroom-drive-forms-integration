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
use App\Core\GitHubAssignmentRosterService;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupRepository;

$relativeDb = 'storage/temp/gh-group-roster-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();
    $user = 'USR_A';

    $students = new StudentRepository($db, $user);
    $identities = new StudentIdentityRepository($db, $user);
    $memberships = new GroupStudentRepository($db, $user);
    $resources = new StudentResourceRepository($db, $user);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);

    (new TeachingGroupRepository($db, $user))->create(['id_gruppo' => 'GRP_1', 'nome_gruppo' => 'Gruppo test']);

    $studentA = $resolver->resolveOrCreate('github_classroom', 'alice-dev');
    $studentB = $resolver->resolveOrCreate('github_classroom', 'bob-dev');
    $memberships->add('GRP_1', (string)$studentA['id_studente']);
    $memberships->add('GRP_1', (string)$studentB['id_studente']);

    $service = new GitHubAssignmentRosterService($db, $user);

    // Roster API vuoto: l'elenco deve comunque contenere gli studenti del gruppo
    // con identità GitHub (la fonte primaria è il roster del gruppo, non le API).
    $map = $service->buildStudentMap([], 'gh-1', 'GRP_1');

    if (count($map) !== 2) {
        $failures[] = 'attese 2 righe dal gruppo, trovate ' . count($map);
    }
    $byUser = [];
    foreach ($map as $row) {
        $byUser[strtolower(trim((string)($row['github_username'] ?? '')))] = $row;
    }
    if (($byUser['alice-dev']['id_studente'] ?? '') !== (string)($studentA['id_studente'] ?? '')) {
        $failures[] = 'alice-dev non risolta dallo studente del gruppo';
    }
    if (($byUser['bob-dev']['id_studente'] ?? '') !== (string)($studentB['id_studente'] ?? '')) {
        $failures[] = 'bob-dev non risolta dallo studente del gruppo';
    }

    // Un membro del gruppo senza identità GitHub non deve comparire.
    $studentC = $students->create();
    $memberships->add('GRP_1', (string)$studentC['id_studente']);
    $map2 = $service->buildStudentMap([], 'gh-1', 'GRP_1');
    if (count($map2) !== 2) {
        $failures[] = 'il membro senza identità GitHub deve essere escluso (trovate ' . count($map2) . ')';
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
fwrite(STDOUT, "PASS: roster assignment GitHub costruito dagli studenti del gruppo (identità GitHub) anche senza accepted assignments.\n");
