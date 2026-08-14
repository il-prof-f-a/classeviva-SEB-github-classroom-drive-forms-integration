<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';

foreach (['data-wizard-integration-summary', 'renderWizardIntegrationSummary', 'Modifica mappature', 'Nessuna classe o mappatura configurata', 'uda_create.php#2'] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: riepilogo integrazioni mancante: {$needle}\n");
        exit(1);
    }
}

if (substr_count($source, 'data-wizard-integration-summary') < 4) {
    fwrite(STDERR, "FAIL: il riepilogo non è presente negli step 3–6\n");
    exit(1);
}

echo "PASS: riepilogo integrazioni e fallback manuale\n";
