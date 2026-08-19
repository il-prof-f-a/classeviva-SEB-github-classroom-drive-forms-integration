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

$relativeDb = 'storage/temp/gh-grade-repos-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();
    $service = new GitHubAssignmentRosterService($db, 'USR_A');

    $studentMap = [
        ['id_studente' => 'S1', 'github_username' => 'alice-dev', 'roster_identifier' => '', 'student_repository_url' => ''],
        ['id_studente' => 'S2', 'github_username' => 'bob-dev', 'roster_identifier' => '', 'student_repository_url' => ''],
        ['id_studente' => 'S3', 'github_username' => 'carol-dev', 'roster_identifier' => '', 'student_repository_url' => ''],
    ];

    $grades = [
        [
            'github_username' => 'alice-dev',
            'roster_identifier' => 'alice bianchi',
            'student_repository_url' => 'https://github.com/org/alice-repo',
        ],
        [
            'github_username' => 'bob-dev',
            'roster_identifier' => 'bob rossi',
            'student_repository_url' => 'https://github.com/org/bob-repo',
        ],
    ];

    $enriched = $service->enrichRepositories($studentMap, $grades);
    $byUser = [];
    foreach ($enriched as $row) {
        $byUser[strtolower(trim((string)($row['github_username'] ?? '')))] = $row;
    }

    if (($byUser['alice-dev']['student_repository_url'] ?? '') !== 'https://github.com/org/alice-repo') {
        $failures[] = 'repo di alice non valorizzata dai grades';
    }
    if (($byUser['alice-dev']['roster_identifier'] ?? '') !== 'alice bianchi') {
        $failures[] = 'roster_identifier di alice non valorizzato dai grades';
    }
    if (($byUser['bob-dev']['student_repository_url'] ?? '') !== 'https://github.com/org/bob-repo') {
        $failures[] = 'repo di bob non valorizzata dai grades';
    }
    if (($byUser['carol-dev']['student_repository_url'] ?? '') !== '') {
        $failures[] = 'carol (senza grade) deve restare senza repo';
    }

    // Non sovrascrivere una repo già presente.
    $withExisting = [
        ['id_studente' => 'S1', 'github_username' => 'alice-dev', 'roster_identifier' => '', 'student_repository_url' => 'https://github.com/org/manual-repo'],
    ];
    $enrichedExisting = $service->enrichRepositories($withExisting, $grades);
    if (($enrichedExisting[0]['student_repository_url'] ?? '') !== 'https://github.com/org/manual-repo') {
        $failures[] = 'repo manuale sovrascritta dai grades';
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
fwrite(STDOUT, "PASS: enrichRepositories valorizza repo e roster dai grades senza sovrascrivere le repo esistenti.\n");
