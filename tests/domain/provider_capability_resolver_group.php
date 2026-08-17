<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\Database\SchemaMigrationRunner;
use App\Core\ProviderCapabilityResolver;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupService;
use App\Core\TeachingGroupIntegrationRepository;

$relativeDb = 'storage/temp/capability-resolver-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$failures = [];

try {
    $adapter = DatabaseFactory::create([
        'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
    ]);
    (new SchemaMigrationRunner($adapter))->migrate();
    $userId = 'USR_A';
    $group = (new TeachingGroupService(
        new TeachingGroupRepository($adapter, $userId),
        new TeachingGroupIntegrationRepository($adapter, $userId)
    ))->createGroup(['nome_gruppo' => '4 Informatica']);
    $groupId = (string)$group['id_gruppo'];
    (new TeachingGroupIntegrationRepository($adapter, $userId))->link([
        'id_gruppo' => $groupId,
        'provider' => 'classeviva',
        'external_context_id' => 'CV_CLASS_1',
        'external_subject_id' => 'SUB_1',
        'external_name' => '4C',
    ]);

    // Il gruppo ha un link ClasseViva attivo.
    if (!ProviderCapabilityResolver::supportsForGroup($adapter, $userId, $groupId, 'classeviva', 'publish_grade')) {
        $failures[] = 'supportsForGroup non risolve il link ClasseViva del gruppo';
    }
    if (!ProviderCapabilityResolver::supportsForGroup($adapter, $userId, $groupId, 'classeviva', 'list_classes')) {
        $failures[] = 'operazione list_classes non riconosciuta per ClasseViva';
    }
    // Nessun link Google su questo gruppo.
    if (ProviderCapabilityResolver::supportsForGroup($adapter, $userId, $groupId, 'google_classroom', 'list_courses')) {
        $failures[] = 'supportsForGroup accetta provider Google non collegato';
    }
    // Gruppo inesistente.
    if (ProviderCapabilityResolver::supportsForGroup($adapter, $userId, 'NOPE', 'classeviva', 'publish_grade')) {
        $failures[] = 'supportsForGroup accetta gruppo inesistente';
    }
    // supportsCvForPair: deriva il gruppo dalla coppia classe/materia.
    if (!ProviderCapabilityResolver::supportsCvForPair($adapter, $userId, 'CV_CLASS_1', 'SUB_1', 'publish_grade')) {
        $failures[] = 'supportsCvForPair non risolve la coppia ClasseViva';
    }
    if (!ProviderCapabilityResolver::supportsCvForPair($adapter, $userId, 'NOPE', 'SUB_1', 'publish_grade')) {
        $failures[] = 'supportsCvForPair non permette la classe legacy (fallback)';
    }
} catch (Throwable $exception) {
    $failures[] = 'errore inatteso: ' . $exception->getMessage();
} finally {
    unset($adapter, $group);
    gc_collect_cycles();
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
fwrite(STDOUT, "PASS: supportsForGroup risolve le capability per gruppo.\n");
