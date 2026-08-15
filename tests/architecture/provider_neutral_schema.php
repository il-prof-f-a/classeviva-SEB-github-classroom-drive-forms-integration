<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\SchemaDefinitions;

$requiredTables = [
    'GRUPPI_DIDATTICI',
    'GRUPPI_INTEGRAZIONI',
    'UDA_GRUPPI',
    'UDA_PUBBLICAZIONI',
    'STUDENTI_IDENTITA_ESTERNE',
    'GRUPPI_STUDENTI',
    'STUDENTI_RISORSE_ESTERNE',
    'SCHEMA_MIGRATIONS',
    'GITHUB_ASSIGNMENT_STUDENT_LINKS',
];

$definitions = SchemaDefinitions::getAllSheets();
$failures = [];

foreach ($requiredTables as $table) {
    if (!isset($definitions[$table])) {
        $failures[] = "tabella mancante: {$table}";
    }
}

$requiredColumns = [
    'GRUPPI_DIDATTICI' => ['id_gruppo', 'id_utente'],
    'GRUPPI_INTEGRAZIONI' => ['id_gruppo', 'provider', 'external_context_id'],
    'UDA_GRUPPI' => ['id_uda', 'id_gruppo', 'id_utente'],
    'STUDENTI' => ['id_studente', 'id_utente'],
    'STUDENTI_IDENTITA_ESTERNE' => ['id_studente', 'provider', 'external_user_id'],
    'GRUPPI_STUDENTI' => ['id_gruppo', 'id_studente'],
];

foreach ($requiredColumns as $table => $columns) {
    $actual = $definitions[$table]['columns'] ?? [];
    foreach ($columns as $column) {
        if (!in_array($column, $actual, true)) {
            $failures[] = "colonna mancante: {$table}.{$column}";
        }
    }
}

$forbidden = ['id_studente_cv', 'id_classe_cv', 'id_materia_cv'];
$allowedLegacyTables = ['GRUPPI_INTEGRAZIONI', 'STUDENTI_IDENTITA_ESTERNE'];
foreach ($definitions as $table => $definition) {
    if (in_array($table, $allowedLegacyTables, true)) {
        continue;
    }
    foreach ($definition['columns'] ?? [] as $column) {
        if (in_array($column, $forbidden, true)) {
            $failures[] = "identificatore provider nel dominio: {$table}.{$column}";
        }
    }
}

foreach (['CLASSI', 'CLASSI_ASSEGNATE', 'CLASSROOM_MAPPINGS', 'GITHUB_CLASSROOMS', 'MAPPATURA_STUDENTI', 'GITHUB_ASSIGNMENT_STUDENT_MAP'] as $legacyTable) {
    if (isset($definitions[$legacyTable])) {
        $failures[] = "tabella legacy ancora nello schema: {$legacyTable}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: schema didattico indipendente dai provider.\n");
