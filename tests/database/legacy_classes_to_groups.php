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
use App\Core\Database\LegacyClassesToGroupsMigration;
use App\Core\Database\SchemaMigrationRunner;

$failures = [];
$relativeDb = 'storage/temp/legacy-classes-groups-' . bin2hex(random_bytes(6)) . '.db';
$absoluteDb = $root . '/' . $relativeDb;
$adapter = DatabaseFactory::create([
    'database' => ['type' => 'sqlite', 'sqlite' => ['file' => $relativeDb]],
]);
$pdo = $adapter->getConnection();
if (!$pdo instanceof PDO) {
    fwrite(STDERR, "FAIL: connessione PDO di test non disponibile\n");
    exit(1);
}

(new SchemaMigrationRunner($adapter))->migrate();

$adapter->createSheet('CLASSROOM_MAPPINGS', [
    'id_mapping', 'id_classe_cv', 'nome_classe_cv', 'id_materia_cv',
    'nome_materia_cv', 'id_corso_gc', 'nome_corso_gc', 'data_mapping',
    'stato', 'note', 'id_utente',
]);
$adapter->createSheet('CLASSI_ASSEGNATE', [
    'id_assegnazione', 'id_uda', 'id_classe', 'nome_classe', 'id_materia_cv',
    'nome_materia', 'data_assegnazione', 'data_inizio', 'data_fine', 'note',
    'pubblicato_classroom', 'classroom_url', 'stato', 'id_utente',
]);
$insertLegacyClassroom = static function (array $row) use ($pdo): void {
    $columns = array_keys($row);
    $quoted = array_map(static fn(string $column): string => '`' . $column . '`', $columns);
    $statement = $pdo->prepare(
        'INSERT INTO `CLASSROOM_MAPPINGS` (' . implode(',', $quoted) . ') VALUES ('
        . implode(',', array_fill(0, count($columns), '?')) . ')'
    );
    $statement->execute(array_values($row));
};

$owner = 'USR_MIGRATION';
$pairs = [
    ['C1', 'M1', 'Classe 1', 'Materia 1'],
    ['C1', 'M2', 'Classe 1', 'Materia 2'],
    ['C2', 'M1', 'Classe 2', 'Materia 1'],
    ['C2', 'M2', 'Classe 2', 'Materia 2'],
    ['C3', 'M1', 'Classe 3', 'Materia 1'],
    ['C3', 'M2', 'Classe 3', 'Materia 2'],
    ['C4', 'M1', 'Classe 4', 'Materia 1'],
    ['C4', 'M2', 'Classe 4', 'Materia 2'],
    ['C5', 'M1', 'Classe 5', 'Materia 1'],
    ['C5', 'M2', 'Classe 5', 'Materia 2'],
    ['C6', 'M1', 'Classe 6', 'Materia 1'],
    ['C6', 'M2', 'Classe 6', 'Materia 2'],
    ['C7', 'M1', 'Classe 7', 'Materia 1'],
    ['C7', 'M2', 'Classe 7', 'Materia 2'],
];

foreach ($pairs as $index => [$classId, $subjectId, $className, $subjectName]) {
    $adapter->insertRow('CLASSI_ASSEGNATE', [
        'id_assegnazione' => 'ASSIGN_' . $index,
        'id_uda' => 'UDA_' . $index,
        'id_classe' => $classId,
        'nome_classe' => $className,
        'id_materia_cv' => $subjectId,
        'nome_materia' => $subjectName,
        'data_assegnazione' => '2026-08-20 10:00:00',
        'stato' => 'assegnata',
        'id_utente' => $owner,
    ]);
}

$insertLegacyClassroom([
    'id_mapping' => 'MAP_1', 'id_classe_cv' => 'C1', 'nome_classe_cv' => 'Classe 1',
    'id_materia_cv' => 'M1', 'nome_materia_cv' => 'Materia 1',
    'id_corso_gc' => 'GC_DUP', 'nome_corso_gc' => 'Corso condiviso',
    'stato' => 'attivo', 'id_utente' => $owner,
]);
$insertLegacyClassroom([
    'id_mapping' => 'MAP_2', 'id_classe_cv' => 'C2', 'nome_classe_cv' => 'Classe 2',
    'id_materia_cv' => 'M1', 'nome_materia_cv' => 'Materia 1',
    'id_corso_gc' => 'GC_DUP', 'nome_corso_gc' => 'Corso condiviso',
    'stato' => 'attivo', 'id_utente' => $owner,
]);
$insertLegacyClassroom([
    'id_mapping' => 'MAP_EMPTY', 'id_classe_cv' => '', 'id_materia_cv' => '',
    'id_corso_gc' => '', 'stato' => 'attivo', 'id_utente' => $owner,
]);

$existingKey = $owner . '|C1|M1';
$existingId = 'GRP_LEGACY_' . substr(hash('sha256', $existingKey), 0, 24);
$adapter->insertRow('GRUPPI_DIDATTICI', [
    'id_gruppo' => $existingId,
    'nome_gruppo' => 'Classe 1 - Materia 1',
    'nome_classe' => 'Classe 1',
    'nome_materia' => 'Materia 1',
    'anno_scolastico' => '',
    'descrizione' => '',
    'stato' => 'attivo',
    'data_creazione' => '2026-08-20 10:00:00',
    'ultima_modifica' => '2026-08-20 10:00:00',
    'id_utente' => $owner,
]);
$adapter->insertRow('GRUPPI_DIDATTICI', [
    'id_gruppo' => 'GRP_EXISTING_C2_M1',
    'nome_gruppo' => 'Classe 2 - Materia 1',
    'nome_classe' => 'Classe 2',
    'nome_materia' => 'Materia 1',
    'anno_scolastico' => '',
    'descrizione' => '',
    'stato' => 'attivo',
    'data_creazione' => '2026-08-20 10:00:00',
    'ultima_modifica' => '2026-08-20 10:00:00',
    'id_utente' => $owner,
]);
$adapter->insertRow('GRUPPI_INTEGRAZIONI', [
    'id_collegamento' => 'GIN_EXISTING_C2_M1',
    'id_gruppo' => 'GRP_EXISTING_C2_M1',
    'provider' => 'classeviva',
    'tipo_risorsa' => 'classe_materia',
    'external_context_id' => 'C2',
    'external_subject_id' => 'M1',
    'external_name' => 'Classe 2 - Materia 1',
    'principale' => '1',
    'stato' => 'attivo',
    'metadata_json' => '{}',
    'data_creazione' => '2026-08-20 10:00:00',
    'ultima_modifica' => '2026-08-20 10:00:00',
    'id_utente' => $owner,
]);

$migration = new LegacyClassesToGroupsMigration($pdo);
$plan = $migration->plan();
if (($plan['counts']['pairs'] ?? 0) !== 14) {
    $failures[] = 'il piano non raccoglie 14 coppie distinte';
}
if (($plan['counts']['groups_to_create'] ?? 0) !== 12) {
    $failures[] = 'i gruppi già migrati non vengono riusati';
}
if (count($plan['conflicts'] ?? []) !== 1) {
    $failures[] = 'il conflitto Classroom non viene segnalato una sola volta';
}
if (count($plan['skipped'] ?? []) !== 1) {
    $failures[] = 'la riga legacy incompleta non viene saltata';
}

if ($failures === []) {
    $applied = $migration->apply();
    if (($applied['counts']['groups_inserted'] ?? 0) !== 12
        || ($applied['counts']['integrations_inserted'] ?? 0) !== 14
        || ($applied['counts']['assignments_inserted'] ?? 0) !== 14) {
        $failures[] = 'il piano applicato non produce i conteggi attesi';
    }
    $second = $migration->plan();
    foreach (['groups_to_create', 'integrations_to_create', 'assignments_to_create'] as $countKey) {
        if (($second['counts'][$countKey] ?? -1) !== 0) {
            $failures[] = "la seconda pianificazione non è idempotente ({$countKey})";
        }
    }
    $modernCounts = [];
    foreach (['GRUPPI_DIDATTICI', 'GRUPPI_INTEGRAZIONI', 'UDA_GRUPPI'] as $table) {
        $modernCounts[$table] = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn();
    }
    if ($modernCounts['GRUPPI_DIDATTICI'] !== 14
        || $modernCounts['GRUPPI_INTEGRAZIONI'] !== 15
        || $modernCounts['UDA_GRUPPI'] !== 14) {
        $failures[] = 'i conteggi effettivi nelle tabelle moderne non sono quelli attesi';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

@unlink($absoluteDb);
@unlink($absoluteDb . '-wal');
@unlink($absoluteDb . '-shm');
fwrite(STDOUT, "PASS: pianificazione migrazione classi verso gruppi.\n");
