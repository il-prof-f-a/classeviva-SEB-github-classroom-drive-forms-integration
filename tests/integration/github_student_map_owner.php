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
use App\Core\Database\UserScopedDatabaseAdapter;
use App\Core\StudentIdentityResolver;
use App\Core\StudentIdentityRepository;
use App\Core\StudentRepository;
use App\Core\GroupStudentRepository;
use App\Core\StudentResourceRepository;

$relativeDb = 'storage/temp/gh-map-owner-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $base = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($base))->migrate();

    $user = 'USR_A';
    $students = new StudentRepository($base, $user);
    $identities = new StudentIdentityRepository($base, $user);
    $memberships = new GroupStudentRepository($base, $user);
    $resources = new StudentResourceRepository($base, $user);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);
    $resolver->resolveOrCreate('github_classroom', 'gh-login-1');

    // Il chiamante usa l'adapter user-scoped e NON passa id_utente (come fa
    // github_classroom_mapping.php in save_student_map). L'adapter deve
    // iniettare l'owner corrente prima di delegare al gateway legacy.
    $scoped = new UserScopedDatabaseAdapter($base, $user);
    if (!$scoped->insertRow('GITHUB_ASSIGNMENT_STUDENT_MAP', [
        'id_map' => 'map-1',
        'id_assignment' => 'gh-assignment-1',
        'github_username' => 'gh-login-1',
        'student_repository_url' => 'https://github.com/org/repo-1',
        'match_confidence' => 'AUTO',
    ])) {
        $failures[] = 'inserimento mappatura GitHub non riuscito';
    }

    $links = $base->findWhere('GITHUB_ASSIGNMENT_STUDENT_LINKS', ['id_assignment' => 'gh-assignment-1']);
    if (count($links) !== 1) {
        $failures[] = 'attesa 1 riga in GITHUB_ASSIGNMENT_STUDENT_LINKS, trovate ' . count($links);
    } else {
        $owner = trim((string)($links[0]['id_utente'] ?? ''));
        if ($owner !== $user) {
            $failures[] = "owner atteso '{$user}', trovato '{$owner}' (era 'system' prima del fix)";
        }
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
fwrite(STDOUT, "PASS: insertRow user-scoped scrive id_utente corretto per GITHUB_ASSIGNMENT_STUDENT_MAP.\n");
