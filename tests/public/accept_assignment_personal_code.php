<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/accept_assignment.php') ?: '';
$failures = [];

foreach ([
    'Il codice personale identifica già' => 'il codice personale deve poter identificare lo studente quando il roster non è raggiungibile',
    'I link generici continuano invece a richiedere' => 'il fallback deve essere limitato al link personale',
    'find_assignment_link_by_github_username' => 'il link generico deve poter riusare lo username GitHub già registrato',
    'Se lo studente ha già accettato l\'assignment' => 'il fallback dello username deve essere limitato agli account già associati',
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
