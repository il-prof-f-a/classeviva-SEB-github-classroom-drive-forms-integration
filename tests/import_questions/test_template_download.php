<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/import_questions.php') ?: '';
$endpoint = file_get_contents($root . '/public/download_template_domande.php') ?: '';

foreach ([
    "json: 'download_template_domande.php?format=json'",
    "csv: 'download_template_domande.php?format=csv'",
    "excel: 'download_template_domande.php?format=xlsx'",
    'href="download_template_domande.php?format=json"',
] as $required) {
    if (!str_contains($page, $required)) {
        fwrite(STDERR, "FAIL: collegamento template non corretto: {$required}\n");
        exit(1);
    }
}

if (!str_contains($endpoint, "require_once __DIR__ . '/../bootstrap.php'")) {
    fwrite(STDERR, "FAIL: l'endpoint di download deve applicare autenticazione e header del bootstrap\n");
    exit(1);
}

foreach ([
    "dirname(__DIR__) . '/database/templates/template_domande.json'",
    "dirname(__DIR__) . '/database/templates/template_domande.csv'",
    "dirname(__DIR__) . '/database/templates/template_domande.xlsx'",
    'Content-Disposition: attachment',
] as $required) {
    if (!str_contains($endpoint, $required)) {
        fwrite(STDERR, "FAIL: endpoint download incompleto: {$required}\n");
        exit(1);
    }
}

echo "PASS: template download endpoint\n";
