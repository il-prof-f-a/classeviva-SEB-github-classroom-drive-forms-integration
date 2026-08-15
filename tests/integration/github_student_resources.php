<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\LegacyGithubStudentMapGateway;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;

$relative = 'storage/temp/github-resources-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$failures = [];

try {
    $db = DatabaseFactory::createWithInitialization([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]],
    ]);
    $user = 'github-user';
    $students = new StudentRepository($db, $user);
    $student = $students->create();
    $identities = new StudentIdentityRepository($db, $user);
    $identities->attach((string)$student['id_studente'], [
        'provider' => 'github_classroom',
        'external_user_id' => 'gh-login-1',
        'external_context_id' => 'roster-1',
    ]);
    (new StudentResourceRepository($db, $user))->attach((string)$student['id_studente'], [
        'provider' => 'github_classroom',
        'external_context_id' => 'roster-1',
        'external_resource_id' => 'repo-1',
        'external_url' => 'https://github.com/org/repo-1',
        'tipo_risorsa' => 'repository',
    ]);

    $db->insertRow('GITHUB_ASSIGNMENTS', [
        'id_assignment' => 'assignment-1',
        'github_assignment_id' => 'gh-assignment-1',
        'assignment_name' => 'Esercizio',
        'id_utente' => $user,
    ]);
    if (!LegacyGithubStudentMapGateway::insert($db, [
        'id_map' => 'map-1',
        'id_assignment' => 'assignment-1',
        'github_username' => 'gh-login-1',
        'student_repository_url' => 'https://github.com/org/repo-1',
        'id_utente' => $user,
    ])) {
        $failures[] = 'mappatura legacy Github non salvata';
    }
    $legacy = LegacyGithubStudentMapGateway::findWhere($db, ['id_map' => 'map-1'], $user);
    if (count($legacy) !== 1 || ($legacy[0]['github_username'] ?? '') !== 'gh-login-1') {
        $failures[] = 'mappatura Github non risolta dall’identità interna';
    }

    $db->insertRow('GITHUB_SUBMISSIONS', [
        'id_submission' => 'submission-1',
        'id_assignment' => 'assignment-1',
        'id_studente' => (string)$student['id_studente'],
        'repository_url' => 'https://github.com/org/repo-1',
        'status' => 'submitted',
        'grade' => '9',
        'id_utente' => $user,
    ]);
    $db->insertRow('GITHUB_REPO_LOC_SNAPSHOTS', [
        'id_snapshot' => 'snapshot-1',
        'id_test' => 'test-1',
        'repo_full_name' => 'org/repo-1',
        'repo_html_url' => 'https://github.com/org/repo-1',
        'external_user_id' => 'gh-login-1',
        'loc_total' => 10,
        'id_utente' => $user,
    ]);
    $snapshot = $db->findWhere('GITHUB_REPO_LOC_SNAPSHOTS', ['id_snapshot' => 'snapshot-1']);
    if (count($snapshot) !== 1 || ($snapshot[0]['github_username'] ?? '') !== 'gh-login-1') {
        $failures[] = 'snapshot Github non espone l’identità in memoria';
    }
    $raw = json_encode(array_merge($db->findAll('STUDENTI'), $db->findAll('STUDENTI_IDENTITA_ESTERNE'), $db->findAll('STUDENTI_RISORSE_ESTERNE')), JSON_UNESCAPED_UNICODE);
    foreach (['nome', 'cognome', 'email'] as $forbidden) {
        if (stripos((string)$raw, $forbidden) !== false) $failures[] = "PII Github persistito: {$forbidden}";
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    @unlink($absolute); @unlink($absolute . '-wal'); @unlink($absolute . '-shm');
}

if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: risorse, submission e mappature studenti GitHub usano ID interni.\n");
