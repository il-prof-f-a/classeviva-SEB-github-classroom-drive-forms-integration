<?php

declare(strict_types=1);

// Verifica che lo step 5 del wizard mostri le domande importate (UDA temporanea)
// e che il ritorno da import_questions.php inneschi un refresh (evento opener,
// focus e visibilitychange), non più solo il conteggio.
$root = dirname(__DIR__, 2);

$endpoint = file_get_contents($root . '/public/ajax_get_temp_questions.php') ?: '';
foreach (['UDA_TMP', 'DOMANDE_INTERROGAZIONE', "'questions'", 'uda_create_temp_id'] as $needle) {
    if (!str_contains($endpoint, $needle)) {
        fwrite(STDERR, "FAIL: endpoint domande temporanee mancante: {$needle}
");
        exit(1);
    }
}

$wizard = file_get_contents($root . '/public/uda_create.php') ?: '';
foreach ([
    'refreshImportedQuestions',
    'imported-questions-section',
    'imported-questions-list',
    'ajax_get_temp_questions.php',
    'uda-questions-imported',
    'visibilitychange',
] as $needle) {
    if (!str_contains($wizard, $needle)) {
        fwrite(STDERR, "FAIL: wizard step 5 domande importate mancante: {$needle}
");
        exit(1);
    }
}

$import = file_get_contents($root . '/public/import_questions.php') ?: '';
if (!str_contains($import, 'uda-questions-imported')) {
    fwrite(STDERR, "FAIL: il pulsante Chiudi non segnala il ritorno al wizard
");
    exit(1);
}

echo "PASS: wizard step 5 mostra e aggiorna le domande importate
";
