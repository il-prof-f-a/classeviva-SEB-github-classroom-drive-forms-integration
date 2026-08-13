<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/QuestionEditorHelper.php';

use App\Utils\QuestionEditorHelper;

$source = file_get_contents(dirname(__DIR__, 2) . '/public/uda_create.php');
foreach (['domanda_tipo[]', 'domanda_risposte[]', 'question-editor.js'] as $required) {
    if (!str_contains($source, $required)) {
        fwrite(STDERR, "FAIL: campo/componente wizard mancante: {$required}\n");
        exit(1);
    }
}
$payload = QuestionEditorHelper::normalizePayload([
    'domanda_argomento' => 'Processi', 'domanda_testo' => 'Quali sono vere?', 'domanda_tipo' => 'multipla',
    'domanda_risposte' => json_encode(['risposte' => [
        ['testo' => 'A', 'corretta' => true], ['testo' => 'B', 'corretta' => true],
    ]], JSON_UNESCAPED_UNICODE), 'domanda_parole' => 'processo, scheduler', 'domanda_livello' => '4',
]);
if ($payload['tipo_domanda'] !== 'multipla' || count($payload['opzioni']) !== 2 || str_contains(json_encode($payload), 'tempo_risposta_min')) {
    fwrite(STDERR, "FAIL: payload domanda wizard\n");
    exit(1);
}
echo "PASS: wizard question payload\n";
