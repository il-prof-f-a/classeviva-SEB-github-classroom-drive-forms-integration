<?php

declare(strict_types=1);

// Verifica statica: l'email riepilogativa dell'import Google Forms include il
// dettaglio per studente (risposte + composizione voto) e un allegato Excel.
$root = dirname(__DIR__, 2);
$failures = [];

$source = file_get_contents($root . '/public/import_form_results.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere import_form_results.php
");
    exit(1);
}
foreach (['generaExcelDettaglio', 'Dettaglio_risposte_', 'Composizione Voto', 'form_items'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "import_form_results.php: riferimento mancante {$required}";
    }
}

$nm = file_get_contents($root . '/src/Core/NotificationManager.php');
if ($nm === false) {
    fwrite(STDERR, "FAIL: impossibile leggere NotificationManager.php
");
    exit(1);
}
if (strpos($nm, 'addAttachment') === false || strpos($nm, '$attachments') === false) {
    $failures[] = 'NotificationManager: manca il supporto allegati in sendHtmlEmail';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: email riepilogativa con dettaglio risposte/composizione + allegato Excel.
");
