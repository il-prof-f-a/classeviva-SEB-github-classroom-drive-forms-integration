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
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupResolver;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/groups-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();

    $groups = new TeachingGroupRepository($adapter, 'USR_A');
    $links = new TeachingGroupIntegrationRepository($adapter, 'USR_A');
    $service = new TeachingGroupService($groups, $links);
    $resolver = new TeachingGroupResolver($groups, $links);

    $group = $service->createGroup([
        'nome_gruppo' => '4 Informatica',
        'nome_classe' => '4C',
        'nome_materia' => 'Informatica',
    ]);
    $groupId = (string)$group['id_gruppo'];
    if (!str_starts_with($groupId, 'GRP_')) {
        $failures[] = 'ID gruppo non opaco';
    }

    $service->linkProvider($groupId, [
        'provider' => 'classeviva', 'tipo_risorsa' => 'classe_materia',
        'external_context_id' => 'CV_CLASS_4C', 'external_subject_id' => 'CV_SUBJ_INF',
        'external_name' => '4C Informatica',
    ]);
    $service->linkProvider($groupId, [
        'provider' => 'google_classroom', 'tipo_risorsa' => 'course',
        'external_context_id' => 'GC_COURSE_1', 'external_name' => '4C Informatica',
    ]);
    $service->linkProvider($groupId, [
        'provider' => 'github_classroom', 'tipo_risorsa' => 'classroom',
        'external_context_id' => 'GH_CLASS_1', 'external_name' => '4C Informatica',
    ]);

    foreach ([
        ['classeviva', 'CV_CLASS_4C', 'CV_SUBJ_INF'],
        ['google_classroom', 'GC_COURSE_1', null],
        ['github_classroom', 'GH_CLASS_1', null],
    ] as [$provider, $context, $subject]) {
        $resolved = $resolver->resolveByExternal($provider, $context, $subject);
        if (($resolved['id_gruppo'] ?? '') !== $groupId) {
            $failures[] = "risoluzione fallita per {$provider}";
        }
    }

    $sameLink = $service->linkProvider($groupId, [
        'provider' => 'google_classroom', 'tipo_risorsa' => 'course',
        'external_context_id' => 'GC_COURSE_1', 'external_name' => '4C Informatica',
    ]);
    if (($sameLink['id_gruppo'] ?? '') !== $groupId) {
        $failures[] = 'collegamento idempotente non restituisce il gruppo';
    }

    $otherGroup = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_B'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_B')
    ))->createGroup(['nome_gruppo' => 'Gruppo B']);
    if ($resolver->resolveByExternal('google_classroom', 'GC_COURSE_1') === null) {
        $failures[] = 'isolamento utente non risolve il gruppo A';
    }
    $otherLinks = new TeachingGroupIntegrationRepository($adapter, 'USR_B');
    $otherLinks->link([
        'id_gruppo' => $otherGroup['id_gruppo'], 'provider' => 'google_classroom',
        'tipo_risorsa' => 'course', 'external_context_id' => 'GC_COURSE_1',
    ]);
    if ($otherLinks->findByExternal('google_classroom', 'GC_COURSE_1', null) === null) {
        $failures[] = 'stesso external ID non isolato correttamente tra utenti';
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
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: gruppi didattici e integrazioni provider isolate per utente.\n");
