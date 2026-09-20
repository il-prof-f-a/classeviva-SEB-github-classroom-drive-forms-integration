<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_repo_templates.php') ?: '';
$failures = [];

foreach ([
    'Project template' => 'manca la sezione Project template',
    'Apri su GitHub' => 'manca il link di apertura del Project',
    'Nessun project template disponibile' => 'manca lo stato vuoto Project',
    'github.com/orgs/' => 'manca il link alla creazione/gestione Project dell’organizzazione',
    'GITHUB_REPO_TEMPLATES' => 'la pagina non deve perdere il catalogo repository template',
] as $needle => $message) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pannello Project template repository verificato.\n");
