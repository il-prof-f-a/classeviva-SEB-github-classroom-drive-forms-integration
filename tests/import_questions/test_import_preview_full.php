<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/import_questions.php') ?: '';
$preview = file_get_contents($root . '/public/partials/import_preview.php') ?: '';
$js = file_get_contents($root . '/public/assets/js/import-questions.js') ?: '';
$combined = $source . "\n" . $preview;

$requiredMarkup = [
    'data-preview-mode',
    "'/question_card.php'",
    "'/partials/question_editor.php'",
    'question-preview-list',
    'id="importPreviewSection"',
];
foreach ($requiredMarkup as $required) {
    if (!str_contains($combined, $required)) {
        fwrite(STDERR, "FAIL: anteprima import mancante: {$required}\n");
        exit(1);
    }
}

if (!str_contains($source, '$selectionHiddenClass = ($importCompleted || !empty($previewQuestions))')) {
    fwrite(STDERR, "FAIL: le sezioni di selezione non vengono nascoste durante l'anteprima\n");
    exit(1);
}

foreach (['Annulla', 'question-preview-list'] as $required) {
    if (!str_contains($combined, $required)) {
        fwrite(STDERR, "FAIL: comportamento anteprima mancante: {$required}\n");
        exit(1);
    }
}

foreach (['Esempio compilato', 'Ricarica file', '<table class="table table-striped align-middle">', 'legacy-preview-table', 'Copia struttura di riferimento', 'data-copy-reference-json', 'navigator.clipboard.writeText'] as $forbidden) {
    if (str_contains($combined . $js, $forbidden)) {
        fwrite(STDERR, "FAIL: markup precedente ancora presente: {$forbidden}\n");
        exit(1);
    }
}

if (!str_contains($combined, 'questionCardActions = \'none\'')) {
    fwrite(STDERR, "FAIL: anteprima import non disattiva le azioni della card\n");
    exit(1);
}

$previewPosition = strpos($source, "partials/import_preview.php");
$firstFormPosition = strpos($source, 'id="existingTestForm"');
if ($previewPosition === false || $firstFormPosition === false || $previewPosition > $firstFormPosition) {
    fwrite(STDERR, "FAIL: la sezione anteprima non è collocata prima dei pannelli di importazione\n");
    exit(1);
}

$previewQuestions = [[
    'argomento' => 'Test',
    'domanda' => 'Domanda di prova?',
    'tipo' => 'multipla',
    'risposta_attesa' => '{"risposte":[{"testo":"A","corretta":true},{"testo":"B","corretta":false}]}',
    'opzioni' => [
        ['testo' => 'A', 'corretta' => true],
        ['testo' => 'B', 'corretta' => false],
    ],
    'parole_chiave' => 'prova',
    'difficolta' => 3,
    'ordine_consigliato' => 1,
    'tempo_risposta_min' => 3,
    'note' => '',
]];
$modalita = 'json';
$udaId = 'UDA_TEST';
ob_start();
include $root . '/public/partials/import_preview.php';
$rendered = ob_get_clean();
foreach (['id="importPreviewSection"', 'id="question-preview-list"', 'Domanda di prova?', 'questions[0][import]', 'Importa le domande selezionate'] as $required) {
    if (!str_contains($rendered, $required)) {
        fwrite(STDERR, "FAIL: rendering anteprima incompleto: {$required}\n");
        exit(1);
    }
}

echo "PASS: import preview full markup\n";
