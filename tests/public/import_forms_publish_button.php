<?php

declare(strict_types=1);

// Verifica statica: (1) il pulsante "Importa voti" viene abilitato al caricamento
// (checkPublishButton() è invocato in DOMContentLoaded); (2) il tipo "pratico"
// è mappato alla colonna pratico (X=3) e non scritto (X=1) in publishGrade.
$root = dirname(__DIR__, 2);
$failures = [];

$preview = file_get_contents($root . '/public/import_form_results_step_preview_grades.php');
if ($preview === false) {
    fwrite(STDERR, "FAIL: impossibile leggere import_form_results_step_preview_grades.php
");
    exit(1);
}
if (strpos($preview, 'checkPublishButton();') === false) {
    $failures[] = 'preview: checkPublishButton() non invocato (pulsante resta disabilitato)';
}

$api = file_get_contents($root . '/src/Integration/ClasseVivaAPI.php');
if ($api === false) {
    fwrite(STDERR, "FAIL: impossibile leggere ClasseVivaAPI.php
");
    exit(1);
}
// pratico -> causale VA (Altro) con prefix _3_ (colonna pratico)
if (strpos($api, "'pratico' => ['codice' => 'VA'") === false) {
    $failures[] = 'publishGrade: pratico non mappato a codice VA';
}
if (preg_match("/'pratico'.*_3_/s", $api) !== 1) {
    $failures[] = 'publishGrade: pratico non mappato alla colonna _3_';
}
if (preg_match("/'scritto'.*_1_/s", $api) !== 1) {
    $failures[] = 'publishGrade: scritto non mappato alla colonna _1_';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: pulsante Importa voti abilitato al load + mappatura colonna pratico/scritto corretta.
");
