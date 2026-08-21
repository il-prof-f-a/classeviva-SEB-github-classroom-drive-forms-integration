<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/Core/Security/Csrf.php';
$_SESSION = [];
$source = file_get_contents($root . '/public/import_questions.php') ?: '';
$preview = file_get_contents($root . '/public/partials/import_preview.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/import-questions.js') ?: '';
$css = file_get_contents($root . '/public/assets/css/question-card.css') ?: '';
$combined = $source . "\n" . $preview;

foreach ([
    "\$questionCardActions = 'none'",
    'id="selectAllImportQuestions"',
    'id="deselectAllImportQuestions"',
    'data-question-card-import',
    'is-unselected',
    'importCompleted',
] as $required) {
    if (!str_contains($combined . $js . $css, $required)) {
        fwrite(STDERR, "FAIL: gestione selezione import mancante: {$required}\n");
        exit(1);
    }
}

if (!str_contains($source, "\$selectionHiddenClass = (\$importCompleted || !empty(\$previewQuestions))")) {
    fwrite(STDERR, "FAIL: i pannelli non sono condizionati dal completamento dell'importazione\n");
    exit(1);
}

if (!str_contains($js, 'setAllImportSelections') || !str_contains($js, 'updateImportCardState')) {
    fwrite(STDERR, "FAIL: controlli globali import non collegati al comportamento delle card\n");
    exit(1);
}

$questionCardData = [
    'domanda' => 'Domanda importata',
    'difficolta' => 3,
    'tipo' => 'aperta',
    'risposta_attesa' => 'Risposta',
];
$previewQuestions = [$questionCardData];
$modalita = 'json';
$udaId = 'UDA_TEST';
ob_start();
include $root . '/public/partials/import_preview.php';
$rendered = ob_get_clean();

foreach (['selectAllImportQuestions', 'deselectAllImportQuestions', 'data-question-card-import', 'Domanda importata'] as $required) {
    if (!str_contains($rendered, $required)) {
        fwrite(STDERR, "FAIL: rendering controlli import incompleto: {$required}\n");
        exit(1);
    }
}

foreach (['data-question-edit', 'data-question-delete', 'Modifica'] as $forbidden) {
    if (str_contains($rendered, $forbidden)) {
        fwrite(STDERR, "FAIL: azione non disponibile nell'anteprima import: {$forbidden}\n");
        exit(1);
    }
}

echo "PASS: import selection controls\n";
