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
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\StudentIdentityResolver;
use App\Core\StudentRepository;
use App\Core\StudentResourceRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupStudentService;

$relativeDb = 'storage/temp/matrix-context-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $db = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($db))->migrate();
    $user = 'USR_A';

    $groups = new TeachingGroupRepository($db, $user);
    $integrations = new TeachingGroupIntegrationRepository($db, $user);
    $groups->create(['id_gruppo' => 'GRP_1', 'nome_gruppo' => 'Gruppo']);
    $integrations->link(['id_gruppo' => 'GRP_1', 'provider' => 'classeviva', 'tipo_risorsa' => 'classe_materia', 'external_context_id' => 'cv-ctx']);
    $integrations->link(['id_gruppo' => 'GRP_1', 'provider' => 'google_classroom', 'tipo_risorsa' => 'course', 'external_context_id' => 'gc-ctx']);
    $integrations->link(['id_gruppo' => 'GRP_1', 'provider' => 'github_classroom', 'tipo_risorsa' => 'roster', 'external_context_id' => 'gh-ctx']);

    $students = new StudentRepository($db, $user);
    $identities = new StudentIdentityRepository($db, $user);
    $memberships = new GroupStudentRepository($db, $user);
    $resources = new StudentResourceRepository($db, $user);
    $resolver = new StudentIdentityResolver($students, $identities, $memberships, $resources);

    // Studente con 3 identità (contesti vuoti, come nella conversione) e una
    // sola membership provider_origine=classeviva.
    $cv = $resolver->resolveOrCreate('classeviva', 'cv-1');
    $gc = $resolver->resolveOrCreate('google_classroom', 'gc-1');
    $gh = $resolver->resolveOrCreate('github_classroom', 'gh-1');
    $resolver->merge((string)$gc['id_studente'], (string)$cv['id_studente']);
    $resolver->merge((string)$gh['id_studente'], (string)$cv['id_studente']);
    $memberships->add('GRP_1', (string)$cv['id_studente'], ['provider_origine' => 'classeviva', 'external_context_id' => 'cv-ctx']);

    $service = new TeachingGroupStudentService($db, $user);
    $matrix = $service->matrix('GRP_1');

    if (count($matrix) !== 1) {
        $failures[] = 'attesa 1 riga nel matrix, trovate ' . count($matrix);
    }
    $providers = [];
    if (isset($matrix[0]['identities']) && is_array($matrix[0]['identities'])) {
        foreach ($matrix[0]['identities'] as $identity) {
            $providers[(string)($identity['provider'] ?? '')] = true;
        }
    }
    foreach (['classeviva', 'google_classroom', 'github_classroom'] as $expected) {
        if (!isset($providers[$expected])) {
            $failures[] = "identity provider {$expected} mancante nel matrix (trovati: " . implode(',', array_keys($providers)) . ')';
        }
    }

    // Se il corso Classroom è stato rimappato, una vecchia identità Google può
    // avere ancora il contesto precedente. La membership del gruppo resta però
    // valida: il roster runtime deve poterla risolvere sul contesto corrente.
    $staleGoogle = $resolver->resolveOrCreate('google_classroom', 'gc-stale');
    $identities->updateContext('google_classroom', 'gc-stale', 'old-gc-ctx');
    $memberships->add('GRP_1', (string)$staleGoogle['id_studente'], [
        'provider_origine' => 'classeviva',
        'external_context_id' => 'cv-ctx',
    ]);
    $matrixWithStaleContext = $service->matrix('GRP_1');
    $staleRow = null;
    foreach ($matrixWithStaleContext as $row) {
        if ((string)($row['id_studente'] ?? '') === (string)$staleGoogle['id_studente']) {
            $staleRow = $row;
            break;
        }
    }
    $hasStaleIdentity = false;
    foreach (($staleRow['identities'] ?? []) as $identity) {
        if (($identity['provider'] ?? '') === 'google_classroom'
            && ($identity['external_user_id'] ?? '') === 'gc-stale') {
            $hasStaleIdentity = true;
            break;
        }
    }
    if (!$hasStaleIdentity) {
        $failures[] = 'identity Google con contesto precedente esclusa dal roster del gruppo';
    }
    require_once $root . '/src/Core/GitHubAssignmentService.php';
    require_once $root . '/src/Core/StudentEmailResolver.php';
    $assignmentService = new \App\Core\GitHubAssignmentService('{cognome}.{nome}@{domain}', 'studenti.example');
    $resolvedStale = $assignmentService->resolveStudents(
        $matrixWithStaleContext,
        ['google_classroom' => [['id' => 'gc-stale', 'name' => 'Studente Rimappato', 'email' => 'email@email.it']]],
        'google_classroom'
    );
    $resolvedStaleName = '';
    foreach ($resolvedStale as $student) {
        if ((string)($student['id_studente'] ?? '') === (string)$staleGoogle['id_studente']) {
            $resolvedStaleName = (string)($student['nome'] ?? '');
            break;
        }
    }
    if ($resolvedStaleName !== 'Studente Rimappato') {
        $failures[] = 'il roster Classroom corrente non risolve il nome dell’identità rimappata';
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
fwrite(STDOUT, "PASS: matrix del gruppo include tutte le identità provider (anche senza membership per provider).\n");
