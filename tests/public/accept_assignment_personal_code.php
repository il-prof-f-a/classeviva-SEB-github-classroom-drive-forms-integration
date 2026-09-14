<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/accept_assignment.php') ?: '';
$failures = [];

foreach ([
    'Il codice personale identifica già' => 'il codice personale deve poter identificare lo studente quando il roster non è raggiungibile',
    'I link generici continuano invece a richiedere' => 'il fallback deve essere limitato al link personale',
] as $needle => $message) {
    if (!str_contains($page, $needle)) {
        $failures[] = $message;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: accettazione tramite codice personale senza roster verificata.\n");
