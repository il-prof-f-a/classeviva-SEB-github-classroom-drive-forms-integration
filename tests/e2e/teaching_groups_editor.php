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
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupStudentService;
use App\Core\UdaGroupRepository;

$relativeDb = 'storage/temp/teaching-groups-editor-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $config = [
        'database' => [
            'type' => 'sqlite',
            'sqlite' => ['file' => $relativeDb],
        ],
    ];
    $adapter = DatabaseFactory::create($config);
    (new SchemaMigrationRunner($adapter))->migrate();

    $userId = 'E2E_GROUP_EDITOR';
    $groupService = new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    );

    // 1. A group is valid before any external provider is connected.
    $primaryGroup = $groupService->createGroup([
        'id_gruppo' => 'GRP_E2E_PRIMARY',
        'nome_gruppo' => 'Gruppo E2E principale',
        'anno_scolastico' => '2026/27',
    ]);
    $primaryGroupId = (string)$primaryGroup['id_gruppo'];
    if ($primaryGroupId !== 'GRP_E2E_PRIMARY'
        || $adapter->findWhere('GRUPPI_INTEGRAZIONI', ['id_gruppo' => $primaryGroupId]) !== []) {
        $failures[] = 'creazione del gruppo vuoto non verificata';
    }

    // 2. All supported providers can be linked to the same internal group.
    foreach ([
        ['provider' => 'classeviva', 'external_context_id' => 'CV_CLASS_E2E', 'external_subject_id' => 'CV_SUBJECT_E2E', 'external_name' => 'ClasseViva E2E'],
        ['provider' => 'google_classroom', 'external_context_id' => 'GC_COURSE_E2E', 'external_name' => 'Google Classroom E2E'],
        ['provider' => 'github_classroom', 'external_context_id' => 'GH_ROSTER_E2E', 'external_name' => 'GitHub Classroom E2E'],
    ] as $providerData) {
        $linked = $groupService->linkProvider($primaryGroupId, $providerData);
        if (($linked['id_gruppo'] ?? '') !== $primaryGroupId
            || ($linked['provider'] ?? '') !== $providerData['provider']
            || ($linked['external_context_id'] ?? '') !== $providerData['external_context_id']) {
            $failures[] = 'collegamento provider non persistito: ' . $providerData['provider'];
        }
    }

    // 3. The same UDA may target two independent groups.
    $secondaryGroup = $groupService->createGroup([
        'id_gruppo' => 'GRP_E2E_SECONDARY',
        'nome_gruppo' => 'Gruppo E2E secondario',
        'anno_scolastico' => '2026/27',
    ]);
    $secondaryGroupId = (string)$secondaryGroup['id_gruppo'];
    $adapter->insertRow('UDA_ANAGRAFICA', [
        'id_uda' => 'UDA_E2E_GROUP_EDITOR',
        'titolo' => 'UDA E2E gruppi didattici',
        'stato' => 'bozza',
        'data_creazione' => date('Y-m-d H:i:s'),
        'ultima_modifica' => date('Y-m-d H:i:s'),
        'id_utente_owner' => $userId,
        'id_utente' => $userId,
    ]);
    $assignments = new UdaGroupRepository($adapter, $userId);
    $assignments->assign('UDA_E2E_GROUP_EDITOR', $primaryGroupId);
    $assignments->assign('UDA_E2E_GROUP_EDITOR', $secondaryGroupId);
    $assignedGroupIds = array_map(
        static fn(array $row): string => (string)($row['id_gruppo'] ?? ''),
        $assignments->listForUda('UDA_E2E_GROUP_EDITOR')
    );
    sort($assignedGroupIds);
    $expectedGroupIds = [$primaryGroupId, $secondaryGroupId];
    sort($expectedGroupIds);
    if ($assignedGroupIds !== $expectedGroupIds) {
        $failures[] = 'UDA non assegnata a entrambi i gruppi';
    }

    // 4. Synchronise three provider rosters. The GitHub row remains unmapped.
    $studentService = new TeachingGroupStudentService($adapter, $userId);
    $studentService->syncRoster($primaryGroupId, 'classeviva', 'CV_CLASS_E2E', [[
        'external_user_id' => 'CV_STUDENT_E2E',
        'display_name' => 'Nome temporaneo non persistibile',
    ]]);
    $studentService->syncRoster($primaryGroupId, 'google_classroom', 'GC_COURSE_E2E', [[
        'external_user_id' => 'GC_STUDENT_E2E',
        'display_name' => 'Email temporanea non email@email.it',
    ]]);
    $studentService->syncRoster($primaryGroupId, 'github_classroom', 'GH_ROSTER_E2E', [[
        'external_user_id' => 'GH_STUDENT_UNMAPPED_E2E',
        'display_name' => 'Riga non mappata',
    ]]);

    // 5. Match the ClasseViva and Google identities (two external IDs).
    $matched = $studentService->linkIdentities($primaryGroupId, [[
        'provider' => 'classeviva',
        'external_user_id' => 'CV_STUDENT_E2E',
        'matches' => [[
            'provider' => 'google_classroom',
            'external_user_id' => 'GC_STUDENT_E2E',
        ]],
    ]]);
    if ($matched !== 1) {
        $failures[] = 'match delle due identita esterne non contabilizzato';
    }

    $identityRows = $adapter->findWhere('STUDENTI_IDENTITA_ESTERNE', ['id_utente' => $userId]);
    $identityStudentIds = [];
    foreach ($identityRows as $identity) {
        $identityStudentIds[(string)($identity['external_user_id'] ?? '')] = (string)($identity['id_studente'] ?? '');
    }
    if (($identityStudentIds['CV_STUDENT_E2E'] ?? '') === ''
        || ($identityStudentIds['CV_STUDENT_E2E'] ?? '') !== ($identityStudentIds['GC_STUDENT_E2E'] ?? '')) {
        $failures[] = 'identita ClasseViva e Google non condividono lo stesso id interno';
    }

    $matrix = $studentService->matrix($primaryGroupId);
    $matrixByExternalId = [];
    foreach ($matrix as $row) {
        foreach (($row['external_ids'] ?? []) as $provider => $ids) {
            foreach (is_array($ids) ? $ids : [$ids] as $externalId) {
                $matrixByExternalId[(string)$externalId] = (string)($row['id_studente'] ?? '');
            }
        }
    }
    if (($matrixByExternalId['CV_STUDENT_E2E'] ?? '') === '') {
        $failures[] = 'identita ClasseViva assente dalla matrice';
    }
    if (($matrixByExternalId['GH_STUDENT_UNMAPPED_E2E'] ?? '') === '') {
        $failures[] = 'riga GitHub non mappata assente dalla matrice';
    }
    if (($matrixByExternalId['GH_STUDENT_UNMAPPED_E2E'] ?? '') === ($identityStudentIds['CV_STUDENT_E2E'] ?? '')) {
        $failures[] = 'riga non mappata erroneamente fusa con il match';
    }

    $rawStudentData = json_encode([
        $adapter->findAll('STUDENTI'),
        $adapter->findAll('STUDENTI_IDENTITA_ESTERNE'),
        $adapter->findAll('GRUPPI_STUDENTI'),
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    foreach (['Nome temporaneo non persistibile', 'Email temporanea non persistibile', 'Riga non mappata', '@example.invalid'] as $forbidden) {
        if (stripos($rawStudentData, $forbidden) !== false) {
            $failures[] = 'PII del roster persistita: ' . $forbidden;
        }
    }

    // 6. Close and reopen the same SQLite file, then verify the internal IDs.
    unset($studentService, $assignments, $groupService, $adapter);
    gc_collect_cycles();
    $reopened = DatabaseFactory::create($config);
    (new SchemaMigrationRunner($reopened))->migrate();
    $reopenedGroups = new TeachingGroupRepository($reopened, $userId);
    if ($reopenedGroups->findById($primaryGroupId) === null
        || $reopenedGroups->findById($secondaryGroupId) === null) {
        $failures[] = 'ID dei gruppi non recuperabili dopo la riapertura SQLite';
    }
    $reopenedAssignments = new UdaGroupRepository($reopened, $userId);
    $reopenedAssignedIds = array_map(
        static fn(array $row): string => (string)($row['id_gruppo'] ?? ''),
        $reopenedAssignments->listForUda('UDA_E2E_GROUP_EDITOR')
    );
    sort($reopenedAssignedIds);
    if ($reopenedAssignedIds !== $expectedGroupIds) {
        $failures[] = 'ID assegnazioni UDA non recuperabili dopo la riapertura SQLite';
    }
    $reopenedIdentityRows = $reopened->findWhere('STUDENTI_IDENTITA_ESTERNE', ['id_utente' => $userId]);
    $reopenedIdentityStudentIds = [];
    foreach ($reopenedIdentityRows as $identity) {
        $reopenedIdentityStudentIds[(string)($identity['external_user_id'] ?? '')] = (string)($identity['id_studente'] ?? '');
    }
    foreach (['CV_STUDENT_E2E', 'GC_STUDENT_E2E', 'GH_STUDENT_UNMAPPED_E2E'] as $externalId) {
        if (($reopenedIdentityStudentIds[$externalId] ?? '') === '') {
            $failures[] = 'ID esterno non recuperabile dopo la riapertura SQLite: ' . $externalId;
        }
    }
    if (($reopenedIdentityStudentIds['CV_STUDENT_E2E'] ?? '') !== ($reopenedIdentityStudentIds['GC_STUDENT_E2E'] ?? '')) {
        $failures[] = 'match ClasseViva/Google non recuperabile dopo la riapertura SQLite';
    }
    unset($reopenedIdentityRows, $reopenedIdentityStudentIds, $reopenedAssignedIds, $reopenedAssignments, $reopenedGroups, $reopened);
    gc_collect_cycles();
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    // Repositories keep the PDO handle alive; release every reference before
    // deleting the temporary SQLite database and its journal files.
    unset(
        $reopenedAssignments,
        $reopenedGroups,
        $reopened,
        $studentService,
        $assignments,
        $groupService,
        $adapter
    );
    gc_collect_cycles();
    @unlink($absoluteDb);
    @unlink($absoluteDb . '-wal');
    @unlink($absoluteDb . '-shm');
    foreach ([$absoluteDb, $absoluteDb . '-wal', $absoluteDb . '-shm'] as $temporaryFile) {
        if (is_file($temporaryFile)) {
            $failures[] = 'file temporaneo E2E non rimosso: ' . basename($temporaryFile);
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: E2E editor gruppi didattici SQLite, provider, UDA e matrice studenti.\n");
