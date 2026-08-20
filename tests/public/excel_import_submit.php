<?php

declare(strict_types=1);

// Regressione: il form di revisione deve poter inviare i voti associati.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/import_quiz_results_excel.php') ?: '';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($source, '$righeImportabili'),
    'il form non calcola le righe importabili');
$require(!str_contains($source, 'id="publishBtn" disabled'),
    'il pulsante Importa voti è sempre disabilitato');
$require(str_contains($source, "\$step === 'import'")
        && str_contains($source, "\$_POST['student_id']"),
    'il POST di importazione non riceve gli studenti associati');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: import Excel abilita il salvataggio dei voti associati.\n");
