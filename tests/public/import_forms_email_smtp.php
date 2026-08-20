<?php

declare(strict_types=1);

// Verifica statica: l'email riepilogativa dell'import Google Forms deve essere
// inviata via SMTP (NotificationManager/PHPMailer), NON tramite la funzione
// mail() che richiede un MTA locale (sendmail/postfix) assente nel container.
$root = dirname(__DIR__, 2);
$file = $root . '/public/import_form_results.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere import_form_results.php
");
    exit(1);
}
foreach (['NotificationManager', 'sendHtmlEmail'] as $required) {
    if (strpos($source, $required) === false) {
        $failures[] = "riferimento mancante: {$required}";
    }
}
if (preg_match('/return\s+mail\s*\(/', $source)) {
    $failures[] = 'riepilogo email usa ancora la funzione mail() (serve SMTP)';
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: email riepilogativa import Forms inviata via SMTP (NotificationManager), non via mail().
");
