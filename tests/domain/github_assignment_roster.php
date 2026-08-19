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
use App\Core\LegacyGithubStudentMapGateway;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;

$relativeDb = 'storage/temp/github-roster-' . bin2hex(random_bytes(6)) . '.db';
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

    $studentA = $resolver->resolveOrCreate('github_classroom', 'alice-dev');
    $studentB = $resolver->resolveOrCreate('github_classroom', 'bob-dev');

    // Link manuale per alice: il repo è un override del valore API.
    if (!LegacyGithubStudentMapGateway::insert($db, [
        'id_map' => 'map-1',
        'id_assignment' => 'gh-1',
        'github_username' => 'alice-dev',
        'student_repository_url' => 'https://github.com/org/alice-manual-repo',
        'id_utente' => $user,
    ])) {
        $failures[] = 'link manuale GitHub non salvato';
    }

    $accepted = [
        [
            'students' => [['login' => 'alice-dev']],
            'roster_identifier' => 'roster-1',
            'repository' => ['html_url' => 'https://github.com/org/alice-repo'],
        ],
        [
            'students' => [['login' => 'bob-dev']],
            'roster_identifier' => 'roster-2',
            'repository' => ['html_url' => 'https://github.com/org/bob-repo'],
        ],
        [
            'students' => [['login' => 'carol-dev']],
            'roster_identifier' => 'roster-3',
            'repository' => ['html_url' => 'https://github.com/org/carol-repo'],
        ],
    ];

    $service = new GitHubAssignmentRosterService($db, $user);
    $map = $service->buildStudentMap($accepted, 'gh-1');

    if (count($map) !== 3) {
        $failures[] = 'attese 3 righe, trovate ' . count($map);
    }

    $byUser = [];
    foreach ($map as $row) {
        $byUser[strtolower(trim((string)($row['github_username'] ?? '')))] = $row;
    }

    if (($byUser['alice-dev']['id_studente'] ?? '') !== (string)($studentA['id_studente'] ?? '')) {
        $failures[] = 'alice-dev non risolta verso lo studente interno';
    }
    if (($byUser['bob-dev']['id_studente'] ?? '') !== (string)($studentB['id_studente'] ?? '')) {
        $failures[] = 'bob-dev non risolta verso lo studente interno';
    }
    if (($byUser['carol-dev']['id_studente'] ?? '') !== '') {
        $failures[] = 'carol-dev deve restare non associata (identità assente)';
    }
    if (($byUser['alice-dev']['student_repository_url'] ?? '') !== 'https://github.com/org/alice-manual-repo') {
        $failures[] = 'override repo manuale di alice non applicato';
    }
    if (($byUser['bob-dev']['student_repository_url'] ?? '') !== 'https://github.com/org/bob-repo') {
        $failures[] = 'repo API di bob non valorizzata';
    }
    if (($byUser['carol-dev']['roster_identifier'] ?? '') !== 'roster-3') {
        $failures[] = 'roster_identifier di carol non preservato';
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
fwrite(STDOUT, "PASS: roster assignment GitHub risolto provider-neutral (API + identità + overlay link).\n");
