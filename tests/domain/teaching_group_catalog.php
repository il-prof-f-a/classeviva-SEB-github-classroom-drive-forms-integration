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
use App\Core\TeachingGroupCatalogService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;

$relativeDb = 'storage/temp/group-catalog-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();

    $serviceA = new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_A'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_A')
    );
    $firstGroup = $serviceA->createGroup([
        'nome_gruppo' => '4 Informatica',
        'nome_classe' => '4C',
        'nome_materia' => 'Informatica',
        'anno_scolastico' => '2025-26',
    ]);
    $secondGroup = $serviceA->createGroup([
        'nome_gruppo' => '5 Informatica',
        'nome_classe' => '5C',
        'nome_materia' => 'Informatica',
        'anno_scolastico' => '2025-26',
    ]);
    $inactiveGroup = $serviceA->createGroup([
        'nome_gruppo' => '6 Informatica disattivo',
        'stato' => 'disattivo',
    ]);
    $serviceB = new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_B'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_B')
    );
    $groupB = $serviceB->createGroup(['nome_gruppo' => 'Gruppo B']);
    $groupBId = (string)$groupB['id_gruppo'];

    $groupId = (string)$firstGroup['id_gruppo'];
    foreach ([
        [
            'provider' => 'classeviva',
            'tipo_risorsa' => 'classe_materia',
            'external_context_id' => 'CV_CLASS_4C',
            'external_subject_id' => 'CV_SUBJ_INF',
            'external_name' => '4C Informatica',
        ],
        [
            'provider' => 'google_classroom',
            'tipo_risorsa' => 'course',
            'external_context_id' => 'GC_COURSE_1',
            'external_name' => '4C Informatica',
        ],
        [
            'provider' => 'github_classroom',
            'tipo_risorsa' => 'classroom',
            'external_context_id' => 'GH_CLASS_1',
            'external_name' => '4C Informatica',
        ],
    ] as $link) {
        $serviceA->linkProvider($groupId, $link);
    }

    $catalog = new TeachingGroupCatalogService($adapter, 'USR_A');
    $rows = $catalog->listForWizard();
    if (count($rows) !== 2) {
        $failures[] = 'il catalogo non è user-scoped';
    }
    $first = $rows[0] ?? [];
    foreach (['id_gruppo', 'nome_gruppo', 'nome_classe', 'nome_materia', 'anno_scolastico', 'stato', 'providers'] as $key) {
        if (!array_key_exists($key, $first)) {
            $failures[] = "chiave catalogo mancante: {$key}";
        }
    }
    if (($first['anno_scolastico'] ?? '') !== '2025-26' || ($first['stato'] ?? '') !== 'attivo') {
        $failures[] = 'campi anno scolastico/stato non riepilogati';
    }
    if (array_keys($first['providers'] ?? []) !== ['classeviva', 'google_classroom', 'github_classroom']) {
        $failures[] = 'riepilogo provider incompleto o non ordinato';
    }
    if (($first['providers']['classeviva']['external_context_id'] ?? '') !== 'CV_CLASS_4C') {
        $failures[] = 'collegamento ClasseViva non riepilogato';
    }
    if (($first['providers']['google_classroom']['external_context_id'] ?? '') !== 'GC_COURSE_1') {
        $failures[] = 'collegamento Google Classroom non riepilogato';
    }
    if (($first['providers']['github_classroom']['external_context_id'] ?? '') !== 'GH_CLASS_1') {
        $failures[] = 'collegamento GitHub Classroom non riepilogato';
    }
    $providerShape = ['external_context_id', 'external_subject_id', 'external_name'];
    foreach (['classeviva', 'google_classroom', 'github_classroom'] as $provider) {
        $providerRow = $first['providers'][$provider] ?? null;
        if (!is_array($providerRow) || array_keys($providerRow) !== $providerShape) {
            $failures[] = "shape provider inatteso: {$provider}";
        }
        if (is_array($providerRow) && array_key_exists('metadata_json', $providerRow)) {
            $failures[] = "metadata_json grezzo esposto per {$provider}";
        }
    }
    if (($rows[1]['id_gruppo'] ?? '') !== (string)$secondGroup['id_gruppo']) {
        $failures[] = 'ordine o contenuto dei gruppi non deterministico (' . ($rows[0]['nome_gruppo'] ?? '') . ',' . ($rows[1]['nome_gruppo'] ?? '') . ')';
    }
    if (!array_key_exists('classeviva', $rows[1]['providers'] ?? [])
        || $rows[1]['providers']['classeviva'] !== null
        || $rows[1]['providers']['google_classroom'] !== null
        || $rows[1]['providers']['github_classroom'] !== null) {
        $failures[] = 'provider assenti non normalizzati a null';
    }
    $allRows = $catalog->listForWizard(false);
    if (count($allRows) !== 3 || ($allRows[2]['id_gruppo'] ?? '') !== (string)$inactiveGroup['id_gruppo']) {
        $failures[] = 'listForWizard(false) non include i gruppi disattivi (' . count($allRows) . ', ' . implode(',', array_map(static fn(array $row): string => (string)($row['id_gruppo'] ?? ''), $allRows)) . ', attivo=' . (string)$inactiveGroup['id_gruppo'] . ')';
    }
    if ($catalog->findForWizard($groupId) === null || $catalog->findForWizard('GRP_MISSING') !== null) {
        $failures[] = 'findForWizard non risolve correttamente i gruppi';
    }
    if ($catalog->findForWizard($groupBId) !== null) {
        $failures[] = 'findForWizard espone gruppi non appartenenti all’utente';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($catalog, $serviceA, $serviceB, $firstGroup, $secondGroup, $inactiveGroup, $groupB, $adapter);
    gc_collect_cycles();
    $adapter = null;
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

fwrite(STDOUT, "PASS: catalogo gruppi user-scoped con riepilogo provider.\n");
