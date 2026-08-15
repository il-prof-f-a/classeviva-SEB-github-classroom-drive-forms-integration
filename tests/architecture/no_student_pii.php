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

$domainTables = [
    'STUDENTI',
    'STUDENTI_IDENTITA_ESTERNE',
    'GRUPPI_STUDENTI',
    'STUDENTI_RISORSE_ESTERNE',
    'VOTI',
    'VALUTAZIONI_RUBRICA',
    'VALUTAZIONI_LABORATORIO',
    'PLUSMINUS_QUEUE',
    'TEST_CBM_RISPOSTE',
    'GITHUB_REPO_LOC_SNAPSHOTS',
];
$forbidden = [
    'nome_studente',
    'email_studente',
    'id_studente_cv',
    'id_studente_gc',
    'github_username',
    'roster_identifier',
];
$failures = [];

foreach ($domainTables as $table) {
    foreach (SchemaDefinitions::getSheetColumns($table) ?? [] as $column) {
        if (in_array($column, $forbidden, true)
            || ($table === 'GITHUB_REPO_LOC_SNAPSHOTS' && $column === 'external_user_id')) {
            $failures[] = "dato studente non ammesso: {$table}.{$column}";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: nessun dato anagrafico studente persistito nel dominio.\n");
