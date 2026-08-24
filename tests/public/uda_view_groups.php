<?php

declare(strict_types=1);

// Verifica statica che uda_view.php mostri i gruppi didattici (provider badges)
// invece della sezione legacy "CLASSI ASSEGNATE".
$file = dirname(__DIR__, 2) . '/public/uda_view.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere uda_view.php\n");
    exit(1);
}
foreach (['CLASSI ASSEGNATE', 'CLASSI_ASSEGNATE'] as $forbidden) {
    if (strpos($source, $forbidden) !== false) {
        $failures[] = "sezione/tabella legacy ancora presente: {$forbidden}";
    }
}
foreach (['GRUPPI DIDATTICI', 'id_gruppo', 'google_classroom', 'findForGroupProvider'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "riferimento provider-neutral assente: {$required}";
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: uda_view.php mostra gruppi didattici provider-neutral.\n");
