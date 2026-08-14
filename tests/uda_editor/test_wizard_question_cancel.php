<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';

foreach ([
    'data-wizard-question-cancel',
    'wizardQuestionIsNew',
    'removeWizardQuestionIfEmpty',
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: gestione annullamento domanda mancante: {$needle}\n");
        exit(1);
    }
}

echo "PASS: annullamento nuova domanda vuota\n";
