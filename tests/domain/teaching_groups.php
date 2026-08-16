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

    // Un gruppo può esistere anche senza provider collegati.
    if ($links->listForGroup($groupId) !== []) {
        $failures[] = 'gruppo iniziale con provider inattesi';
    }

    // Il nome del gruppo è modificabile e resta confinato all'utente proprietario.
    $updatedGroup = $service->updateGroup($groupId, ['nome_gruppo' => '4 Informatica aggiornata']);
    if (($updatedGroup['nome_gruppo'] ?? '') !== '4 Informatica aggiornata') {
        $failures[] = 'modifica nome gruppo fallita';
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

    // Per ogni provider/tipo di risorsa deve rimanere un solo collegamento attivo:
    // un nuovo contesto aggiorna quello già presente per lo stesso gruppo/provider.
    $service->linkProvider($groupId, [
        'provider' => 'google_classroom', 'tipo_risorsa' => 'course',
        'external_context_id' => 'GC_COURSE_2', 'external_name' => '4C Informatica aggiornata',
    ]);
    $activeGoogleLinks = array_values(array_filter(
        $links->listForGroup($groupId),
        static fn(array $row): bool => ($row['provider'] ?? '') === 'google_classroom'
            && ($row['stato'] ?? 'attivo') === 'attivo'
    ));
    if (count($activeGoogleLinks) !== 1 || ($activeGoogleLinks[0]['external_context_id'] ?? '') !== 'GC_COURSE_2') {
        $failures[] = 'più provider google attivi per lo stesso gruppo';
    }

    $invalidProviderRejected = false;
    try {
        $service->linkProvider($groupId, [
            'provider' => 'unsupported_provider',
            'external_context_id' => 'INVALID_PROVIDER_CONTEXT',
        ]);
    } catch (Throwable) {
        $invalidProviderRejected = true;
    }
    if (!$invalidProviderRejected) {
        $failures[] = 'provider non autorizzato accettato';
    }
    foreach ([
        static fn() => $links->findForGroupProvider($groupId, 'unsupported_provider'),
        static fn() => $links->findByExternal('unsupported_provider', 'INVALID'),
        static fn() => $links->findByContext('unsupported_provider', 'INVALID'),
    ] as $invalidLookup) {
        try {
            $invalidLookup();
            $failures[] = 'lookup provider non autorizzato accettato';
        } catch (Throwable) {
            // expected
        }
    }

    $missingContextRejected = false;
    try {
        $service->linkProvider($groupId, ['provider' => 'google_classroom']);
    } catch (Throwable) {
        $missingContextRejected = true;
    }
    if (!$missingContextRejected) {
        $failures[] = 'external context mancante accettato';
    }

    // La rimozione per provider disattiva tutte le righe attive storiche.
    $duplicateGroup = $service->createGroup(['nome_gruppo' => 'Gruppo duplicati']);
    foreach ([['GIN_DUP_1', 'GC_DUP_1'], ['GIN_DUP_2', 'GC_DUP_2']] as [$linkId, $contextId]) {
        $adapter->insertRow('GRUPPI_INTEGRAZIONI', [
            'id_collegamento' => $linkId,
            'id_gruppo' => $duplicateGroup['id_gruppo'],
            'provider' => 'google_classroom',
            'tipo_risorsa' => 'course',
            'external_context_id' => $contextId,
            'external_subject_id' => '',
            'external_name' => '',
            'principale' => '1',
            'stato' => 'attivo',
            'metadata_json' => '{}',
            'data_creazione' => date('Y-m-d H:i:s'),
            'ultima_modifica' => date('Y-m-d H:i:s'),
            'id_utente' => 'USR_A',
        ]);
    }
    if (!$links->deactivateForGroupProvider((string)$duplicateGroup['id_gruppo'], 'google_classroom')) {
        $failures[] = 'disattivazione duplicati provider fallita';
    }
    $duplicateRows = $links->listForGroup((string)$duplicateGroup['id_gruppo']);
    if (count(array_filter($duplicateRows, static fn(array $row): bool => ($row['stato'] ?? '') === 'attivo')) !== 0) {
        $failures[] = 'è rimasto un provider duplicato attivo';
    }
    $reuseGroup = $service->createGroup(['nome_gruppo' => 'Gruppo riuso contesto']);
    try {
        $service->linkProvider((string)$reuseGroup['id_gruppo'], [
            'provider' => 'google_classroom', 'external_context_id' => 'GC_DUP_1',
        ]);
    } catch (Throwable) {
        $failures[] = 'contesto disattivato non riutilizzabile';
    }
    $sourceInactive = $service->createGroup(['nome_gruppo' => 'Sorgente contesto disattivo']);
    $targetActive = $service->createGroup(['nome_gruppo' => 'Destinazione contesto attivo']);
    $adapter->insertRow('GRUPPI_INTEGRAZIONI', [
        'id_collegamento' => 'GIN_EDGE_OLD',
        'id_gruppo' => $sourceInactive['id_gruppo'],
        'provider' => 'google_classroom', 'tipo_risorsa' => 'course',
        'external_context_id' => 'GC_EDGE_C1', 'external_subject_id' => '',
        'external_name' => '', 'principale' => '1', 'stato' => 'disattivo',
        'metadata_json' => '{}', 'data_creazione' => date('Y-m-d H:i:s'),
        'ultima_modifica' => date('Y-m-d H:i:s'), 'id_utente' => 'USR_A',
    ]);
    $service->linkProvider((string)$targetActive['id_gruppo'], [
        'provider' => 'google_classroom', 'external_context_id' => 'GC_EDGE_C2',
    ]);
    $service->linkProvider((string)$targetActive['id_gruppo'], [
        'provider' => 'google_classroom', 'external_context_id' => 'GC_EDGE_C1',
    ]);
    $edgeActive = array_values(array_filter(
        $links->listForGroup((string)$targetActive['id_gruppo']),
        static fn(array $row): bool => ($row['stato'] ?? '') === 'attivo'
    ));
    if (count($edgeActive) !== 1 || ($edgeActive[0]['external_context_id'] ?? '') !== 'GC_EDGE_C1') {
        $failures[] = 'upsert con contesto disattivo non ha mantenuto un solo attivo';
    }

    // La disattivazione conserva il record nel catalogo completo ma lo rimuove dagli attivi.
    if (!$groups->deactivate($groupId)
        || $groups->findById($groupId)['stato'] !== 'disattivo'
        || count(array_filter($groups->listAll(), static fn(array $row): bool => ($row['id_gruppo'] ?? '') === $groupId)) !== 1
    ) {
        $failures[] = 'disattivazione gruppo fallita';
    }

    $otherGroup = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_B'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_B')
    ))->createGroup(['nome_gruppo' => 'Gruppo B']);

    $sameUserGroup = $service->createGroup(['nome_gruppo' => 'Gruppo A2']);

    // Lo stesso contesto non può appartenere a due gruppi dello stesso utente.
    $duplicateRejected = false;
    try {
        $service->linkProvider((string)$sameUserGroup['id_gruppo'], [
            'provider' => 'github_classroom', 'tipo_risorsa' => 'classroom',
            'external_context_id' => 'GH_CLASS_1',
        ]);
    } catch (Throwable) {
        $duplicateRejected = true;
    }
    if (!$duplicateRejected) {
        $failures[] = 'external context duplicato accettato nello stesso utente';
    }

    // Id gruppo e id collegamento non sono sufficienti per attraversare il confine utente.
    $scopedGroupA = $service->createGroup(['id_gruppo' => 'GRP_SHARED_SCOPE', 'nome_gruppo' => 'Scope A']);
    $serviceB = new TeachingGroupService(
        new TeachingGroupRepository($adapter, 'USR_B'),
        new TeachingGroupIntegrationRepository($adapter, 'USR_B')
    );
    $scopedGroupB = $serviceB->createGroup(['id_gruppo' => 'GRP_SHARED_SCOPE', 'nome_gruppo' => 'Scope B']);
    $service->linkProvider((string)$scopedGroupA['id_gruppo'], [
        'id_collegamento' => 'GIN_SHARED_SCOPE',
        'provider' => 'google_classroom', 'external_context_id' => 'GC_SCOPE_A',
    ]);
    $serviceB->linkProvider((string)$scopedGroupB['id_gruppo'], [
        'id_collegamento' => 'GIN_SHARED_SCOPE',
        'provider' => 'google_classroom', 'external_context_id' => 'GC_SCOPE_B',
    ]);
    $linksA = new TeachingGroupIntegrationRepository($adapter, 'USR_A');
    $serviceB->updateGroup('GRP_SHARED_SCOPE', ['nome_gruppo' => 'Scope B aggiornato']);
    $groupAAfterBUpdate = $groups->findById('GRP_SHARED_SCOPE');
    if (($groupAAfterBUpdate['nome_gruppo'] ?? '') !== 'Scope A') {
        $failures[] = 'update gruppo ha attraversato utenti';
    }
    $serviceB->linkProvider((string)$scopedGroupB['id_gruppo'], [
        'id_collegamento' => 'GIN_SHARED_SCOPE',
        'provider' => 'google_classroom', 'external_context_id' => 'GC_SCOPE_B2',
    ]);
    if (($linksA->findForGroupProvider('GRP_SHARED_SCOPE', 'google_classroom')['external_context_id'] ?? '') !== 'GC_SCOPE_A') {
        $failures[] = 'upsert collegamento ha attraversato utenti';
    }
    if (!$groups->deactivate('GRP_SHARED_SCOPE')) {
        $failures[] = 'disattivazione gruppo proprietario fallita';
    }
    $groupBAfterADeactivate = (new TeachingGroupRepository($adapter, 'USR_B'))->findById('GRP_SHARED_SCOPE');
    if (($groupBAfterADeactivate['stato'] ?? '') !== 'attivo') {
        $failures[] = 'disattivazione gruppo ha attraversato utenti';
    }
    $linksB = new TeachingGroupIntegrationRepository($adapter, 'USR_B');
    if (!$linksB->deactivate('GIN_SHARED_SCOPE')) {
        $failures[] = 'disattivazione collegamento proprietario fallita';
    }
    if (($linksA->findForGroupProvider('GRP_SHARED_SCOPE', 'google_classroom')['stato'] ?? '') !== 'attivo') {
        $failures[] = 'disattivazione collegamento ha attraversato utenti';
    }

    $crossUserOwnershipRejected = false;
    try {
        $serviceB->updateGroup($groupId, ['nome_gruppo' => 'non autorizzato']);
    } catch (Throwable) {
        $crossUserOwnershipRejected = true;
    }
    if (!$crossUserOwnershipRejected) {
        $failures[] = 'update gruppo di altro utente accettato';
    }
    if ($resolver->resolveByExternal('google_classroom', 'GC_COURSE_2') === null) {
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
