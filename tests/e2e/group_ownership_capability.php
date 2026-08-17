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
use App\Core\ProviderCapabilityResolver;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\UdaGroupRepository;

$relative = 'storage/temp/group-ownership-' . bin2hex(random_bytes(6)) . '.db';
$absolute = $root . '/' . $relative;
$failures = [];

try {
    $db = DatabaseFactory::createWithInitialization([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relative]],
    ]);
    $userA = 'USER_A';
    $userB = 'USER_B';

    $group = (new TeachingGroupService(
        new TeachingGroupRepository($db, $userA),
        new TeachingGroupIntegrationRepository($db, $userA)
    ))->createGroup(['nome_gruppo' => 'Solo Google']);
    $groupId = (string)$group['id_gruppo'];
    (new TeachingGroupIntegrationRepository($db, $userA))->link([
        'id_gruppo' => $groupId,
        'provider' => 'google_classroom',
        'external_context_id' => 'GC_1',
        'external_name' => 'Corso',
    ]);

    // User A: google supportato, classeviva/github no.
    if (!ProviderCapabilityResolver::supportsForGroup($db, $userA, $groupId, 'google_classroom', 'list_courses')) {
        $failures[] = 'utente A: capability google non risolta';
    }
    if (ProviderCapabilityResolver::supportsForGroup($db, $userA, $groupId, 'classeviva', 'publish_grade')) {
        $failures[] = 'utente A: capability classeviva risolta senza link';
    }
    if (ProviderCapabilityResolver::supportsForGroup($db, $userA, $groupId, 'github_classroom', 'list_assignments')) {
        $failures[] = 'utente A: capability github risolta senza link';
    }

    // Ownership: l'utente B non vede il gruppo né i suoi link.
    if ((new TeachingGroupRepository($db, $userB))->findById($groupId) !== null) {
        $failures[] = 'utente B: vede il gruppo di un altro utente';
    }
    if ((new TeachingGroupIntegrationRepository($db, $userB))->findForGroupProvider($groupId, 'google_classroom') !== null) {
        $failures[] = 'utente B: vede il link provider di un altro utente';
    }
    if (ProviderCapabilityResolver::supportsForGroup($db, $userB, $groupId, 'google_classroom', 'list_courses')) {
        $failures[] = 'utente B: capability risolta su gruppo altrui';
    }

    // UDA ownership: assegnazione solo per l'utente proprietario.
    $udaId = 'UDA_OWN_1';
    $db->insertRow('UDA_ANAGRAFICA', [
        'id_uda' => $udaId, 'titolo' => 'UDA ownership', 'stato' => 'bozza',
        'data_creazione' => date('Y-m-d H:i:s'), 'ultima_modifica' => date('Y-m-d H:i:s'),
        'id_utente_owner' => $userA, 'id_utente' => $userA,
    ]);
    (new UdaGroupRepository($db, $userA))->assign($udaId, $groupId);
    if ((new UdaGroupRepository($db, $userB))->listForUda($udaId) !== []) {
        $failures[] = 'utente B: vede le assegnazioni UDA di un altro utente';
    }

    // supportsCvForPair: coppia CV non collegata -> fallback legacy (permessa).
    if (!ProviderCapabilityResolver::supportsCvForPair($db, $userA, 'CV_X', 'SUB_X', 'publish_grade')) {
        $failures[] = 'supportsCvForPair: fallback legacy non permesso';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    @unlink($absolute);
    @unlink($absolute . '-wal');
    @unlink($absolute . '-shm');
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}
fwrite(STDOUT, "PASS: E2E ownership gruppi e capability provider-neutral.\n");
