<?php

declare(strict_types=1);

ob_start();
$questionEditorId = 'test-question-editor';
include dirname(__DIR__, 2) . '/public/partials/question_editor.php';
$markup = ob_get_clean();
foreach (['data-question-field="argomento"', 'data-question-field="difficolta"', 'data-question-field="tipo_domanda"', 'data-question-field="domanda"', 'data-answer-open', 'data-answer-multiple', 'data-question-options', 'data-keyword-chips'] as $required) {
    if (!str_contains($markup, $required)) {
        fwrite(STDERR, "FAIL: markup editor mancante: {$required}\n");
        exit(1);
    }
}
$js = file_get_contents(dirname(__DIR__, 2) . '/public/assets/js/question-editor.js');
if (!str_contains($js, 'data-option-correct')) {
    fwrite(STDERR, "FAIL: controllo risposta corretta mancante nel componente JS\n");
    exit(1);
}
foreach (['tempo_risposta_min', 'ordine_consigliato', 'collegata_a', 'name="note"'] as $forbidden) {
    if (str_contains($markup, $forbidden)) {
        fwrite(STDERR, "FAIL: campo obsoleto presente: {$forbidden}\n");
        exit(1);
    }
}
echo "PASS: editor markup\n";
