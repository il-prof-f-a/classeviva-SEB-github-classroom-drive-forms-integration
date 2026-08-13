<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/QuestionEditorHelper.php';

use App\Utils\QuestionEditorHelper;

$source = file_get_contents(dirname(__DIR__, 2) . '/public/uda_questions.php');
foreach (['QuestionEditorHelper::normalizePayload', 'data-editor-payload', 'question_editor.php'] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, "FAIL: integrazione editor pagina domande mancante: {$required}\n");
        exit(1);
    }
}
$legacy = QuestionEditorHelper::normalizePayload([
    'domanda' => 'Domanda legacy', 'risposta_attesa' => 'Risposta legacy', 'parole_chiave' => 'legacy',
]);
if ($legacy['tipo_domanda'] !== 'aperta' || $legacy['risposta_attesa'] !== 'Risposta legacy') {
    fwrite(STDERR, "FAIL: compatibilita' domanda legacy\n");
    exit(1);
}
echo "PASS: questions handler\n";
