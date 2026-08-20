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
use App\Core\StudentIdentityRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupStudentService;

$relativeDb = 'storage/temp/group-remap-cleanup-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $groups = new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_A'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_A')
    );
    $group = $groups->createGroup(['id_gruppo' => 'GRP_REMAP', 'nome_gruppo' => '4L Informatica - TPSIT']);
    $otherGroup = $groups->createGroup(['id_gruppo' => 'GRP_OTHER', 'nome_gruppo' => '5 Informatica']);
    $groupId = (string)$group['id_gruppo'];
    $otherGroupId = (string)$otherGroup['id_gruppo'];
    $service = new TeachingGroupStudentService($adapter, 'USR_A');

    $service->syncRoster($groupId, 'classeviva', 'CV_CLASS_1', [
        ['external_user_id' => 'CV_A'],
        ['external_user_id' => 'CV_B'],
    ]);
    $service->syncRoster($groupId, 'google_classroom', 'GC_COURSE_1', [
        ['external_user_id' => 'GC_A'],
        ['external_user_id' => 'GC_B'],
    ]);
    $service->syncRoster($groupId, 'github_classroom', 'GH_CLASS_1', [
        ['external_user_id' => 'GH_A'],
        ['external_user_id' => 'GH_B'],
    ]);
    $service->syncRoster($otherGroupId, 'classeviva', 'CV_CLASS_2', [
        ['external_user_id' => 'CV_OTHER'],
    ]);

    $identities = new StudentIdentityRepository($adapter, 'USR_A');
    $cvA = $identities->findByExternal('classeviva', 'CV_A');
    $gcA = $identities->findByExternal('google_classroom', 'GC_A');
    $sourceStudentId = (string)($gcA['id_studente'] ?? '');
    if ($cvA === null || $gcA === null || $sourceStudentId === '') {
        throw new RuntimeException('fixture identità incompleta');
    }

    // Lo storico deve rimanere associato allo studente tecnico, anche se la
    // sua membership del gruppo viene rimossa durante il remapping.
    $adapter->insertRow('VOTI', [
        'id_voto' => 'VOTO_REMAP',
        'id_uda' => 'UDA_REMAP',
        'id_gruppo' => $groupId,
        'id_studente' => $sourceStudentId,
        'tipo_voto' => 'scritto',
        'voto' => 7,
        'giudizio' => '',
        'descrizione' => 'test',
        'data_valutazione' => '2026-08-21',
        'data_creazione' => '2026-08-21 10:00:00',
        'pubblicato' => 0,
        'provider_pubblicazione' => null,
        'external_publication_id' => null,
        'num_evidenze_positive' => null,
        'num_evidenze_negative' => null,
        'num_evidenze_totali' => null,
        'id_utente' => 'USR_A',
        'link_origine' => '',
    ]);

    $service->assignMappings($groupId, [
        [
            'provider' => 'classeviva',
            'external_user_id' => 'CV_A',
            'matches' => [
                ['provider' => 'google_classroom', 'external_user_id' => 'GC_A'],
                ['provider' => 'github_classroom', 'external_user_id' => 'GH_A'],
            ],
        ],
        [
            'provider' => 'classeviva',
            'external_user_id' => 'CV_B',
            'matches' => [
                ['provider' => 'google_classroom', 'external_user_id' => 'GC_B'],
                ['provider' => 'github_classroom', 'external_user_id' => 'GH_B'],
            ],
        ],
    ]);

    $groupMemberships = $adapter->findWhere('GRUPPI_STUDENTI', [
        'id_utente' => 'USR_A',
        'id_gruppo' => $groupId,
    ]);
    if (count($groupMemberships) !== 2) {
        $failures[] = 'il remapping lascia membership orfane nel gruppo';
    }
    $targetIds = [
        (string)($identities->findByExternal('classeviva', 'CV_A')['id_studente'] ?? ''),
        (string)($identities->findByExternal('classeviva', 'CV_B')['id_studente'] ?? ''),
    ];
    foreach ($groupMemberships as $membership) {
        if (!in_array((string)($membership['id_studente'] ?? ''), $targetIds, true)) {
            $failures[] = 'membership residua assegnata a uno studente sorgente';
        }
    }
    foreach ([
        ['provider' => 'google_classroom', 'external' => 'GC_A', 'target' => 'CV_A'],
        ['provider' => 'github_classroom', 'external' => 'GH_A', 'target' => 'CV_A'],
        ['provider' => 'google_classroom', 'external' => 'GC_B', 'target' => 'CV_B'],
        ['provider' => 'github_classroom', 'external' => 'GH_B', 'target' => 'CV_B'],
    ] as $mapping) {
        $identity = $identities->findByExternal($mapping['provider'], $mapping['external']);
        $target = $identities->findByExternal('classeviva', $mapping['target']);
        if ($identity === null || $target === null || (string)$identity['id_studente'] !== (string)$target['id_studente']) {
            $failures[] = 'identità non riassegnata allo studente target: ' . $mapping['external'];
        }
    }
    if ($identities->findByExternal('classeviva', 'CV_OTHER') === null) {
        $failures[] = 'fixture altro gruppo mancante';
    }
    if ($adapter->findWhere('GRUPPI_STUDENTI', ['id_utente' => 'USR_A', 'id_gruppo' => $otherGroupId]) === []) {
        $failures[] = 'membership di altro gruppo rimossa erroneamente';
    }
    if ($adapter->findWhere('STUDENTI', ['id_utente' => 'USR_A', 'id_studente' => $sourceStudentId]) === []) {
        $failures[] = 'studente tecnico sorgente eliminato insieme alla membership';
    }
    if ($adapter->findWhere('VOTI', ['id_utente' => 'USR_A', 'id_voto' => 'VOTO_REMAP']) === []) {
        $failures[] = 'voto storico sorgente eliminato';
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

fwrite(STDOUT, "PASS: remapping pulisce le membership orfane e preserva storico e altri gruppi.\n");
