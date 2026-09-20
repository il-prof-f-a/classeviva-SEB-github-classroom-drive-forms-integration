<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

foreach ([
    'Aggiungi project alla repo da template' => 'manca il flag Project',
    'project_template_id' => 'manca il campo Project template',
    'ajax_github_project_templates.php' => 'manca il catalogo AJAX Project',
    'github_config_json' => 'manca la persistenza configurazione test',
    'project' => 'manca il blocco project nella configurazione',
    'Nessun project template disponibile' => 'manca stato vuoto Project',
    'github_repo_templates.php' => 'manca link alla gestione template',
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

fwrite(STDOUT, "PASS: selezione Project nella creazione assignment verificata.\n");
