<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';

$required = [
    'name="classi_target"',
    'name="classi_target_usa_assegnazioni"',
    'data-class-target-suggestion',
    'refreshClassTargetSuggestion',
    '$manualClassTarget',
    '$useAssignedClassTarget',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: elemento destinatari mancante: {$needle}\n");
        exit(1);
    }
}
foreach (['validPairCount', 'obbligatorio assegnare almeno una classe'] as $forbidden) {
    if (stripos($source, $forbidden) !== false) {
        fwrite(STDERR, "FAIL: vincolo classi ancora obbligatorio: {$forbidden}\n");
        exit(1);
    }
}
if (preg_match('/classi_target[^\n]+=>\s*UdaMetadataHelper::classTargetFromAssignments/', $source)) {
    fwrite(STDERR, "FAIL: classi_target viene ancora sovrascritto sempre dalle assegnazioni\n");
    exit(1);
}

echo "PASS: wizard destinatari editabili e assegnazioni facoltative\n";
