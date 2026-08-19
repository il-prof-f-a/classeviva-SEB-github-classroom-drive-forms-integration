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
use App\Core\ProviderNeutralMappingService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;

$relativeDb = 'storage/temp/gh-cv-pair-' . bin2hex(random_bytes(6)) . '.db';
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

    // Gruppo moderno: integrazione github_classroom con metadata vuoto + classeviva separata.
    $groups->create(['id_gruppo' => 'GRP_1', 'nome_gruppo' => 'Gruppo moderno']);
    $integrations->link([
        'id_gruppo' => 'GRP_1',
        'provider' => 'github_classroom',
        'tipo_risorsa' => 'roster',
        'external_context_id' => 'gh-327652',
        'external_name' => 'GH Classroom',
        'metadata_json' => '{}',
    ]);
    $integrations->link([
        'id_gruppo' => 'GRP_1',
        'provider' => 'classeviva',
        'tipo_risorsa' => 'classe_materia',
        'external_context_id' => 'cv-class-1',
        'external_subject_id' => 'cv-subject-1',
        'external_name' => 'Classe CV 1',
        'metadata_json' => '{}',
    ]);

    // Gruppo legacy: la coppia CV è già nel metadata della github_classroom -> non sovrascrivere.
    $groups->create(['id_gruppo' => 'GRP_2', 'nome_gruppo' => 'Gruppo legacy']);
    $integrations->link([
        'id_gruppo' => 'GRP_2',
        'provider' => 'github_classroom',
        'tipo_risorsa' => 'roster',
        'external_context_id' => 'gh-327653',
        'external_name' => 'GH Classroom 2',
        'metadata_json' => json_encode([
            'classeviva_class_id' => 'meta-class-2',
            'classeviva_subject_id' => 'meta-subject-2',
        ], JSON_UNESCAPED_UNICODE),
    ]);
    $integrations->link([
        'id_gruppo' => 'GRP_2',
        'provider' => 'classeviva',
        'tipo_risorsa' => 'classe_materia',
        'external_context_id' => 'cv-class-2',
        'external_subject_id' => 'cv-subject-2',
        'external_name' => 'Classe CV 2',
    ]);

    $service = new ProviderNeutralMappingService($db, $user);
    $mappings = $service->listGithubClassroomMappings();

    $byClassroom = [];
    foreach ($mappings as $m) {
        $byClassroom[(string)($m['github_classroom_id'] ?? '')] = $m;
    }

    if (count($mappings) !== 2) {
        $failures[] = 'attese 2 mappature, trovate ' . count($mappings);
    }
    if (($byClassroom['gh-327652']['id_classe_cv'] ?? '') !== 'cv-class-1') {
        $failures[] = 'gruppo moderno: id_classe_cv non risolto dalla classeviva separata';
    }
    if (($byClassroom['gh-327652']['id_materia_cv'] ?? '') !== 'cv-subject-1') {
        $failures[] = 'gruppo moderno: id_materia_cv non risolto dalla classeviva separata';
    }
    if (($byClassroom['gh-327653']['id_classe_cv'] ?? '') !== 'meta-class-2') {
        $failures[] = 'gruppo legacy: il metadata deve avere precedenza sulla classeviva separata';
    }
    if (($byClassroom['gh-327653']['id_materia_cv'] ?? '') !== 'meta-subject-2') {
        $failures[] = 'gruppo legacy: il metadata deve avere precedenza sulla materia separata';
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
fwrite(STDOUT, "PASS: coppia ClasseViva dei mapping github risolta dall'integrazione separata (fallback) senza sovrascrivere il metadata.\n");
