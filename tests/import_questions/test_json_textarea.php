<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$parserPath = $root . '/src/Utils/QuestionImportParser.php';
if (!is_file($parserPath)) {
    fwrite(STDERR, "FAIL: QuestionImportParser non presente\n");
    exit(1);
}
require_once $parserPath;

use App\Utils\QuestionImportParser;

$json = "\xEF\xBB\xBF" . json_encode([
    'domande' => [[
        'argomento' => 'Processi',
        'tipo' => 'aperta',
        'domanda' => 'Che cos’è un processo?',
        'risposta_attesa' => 'Un programma in esecuzione',
        'parole_chiave' => ['programma', 'processo'],
        'difficolta' => 3,
    ]],
], JSON_UNESCAPED_UNICODE);
$parsed = QuestionImportParser::parseJsonContent($json);
if (count($parsed) !== 1 || $parsed[0]['domanda'] !== 'Che cos’è un processo?') {
    fwrite(STDERR, "FAIL: parsing JSON da textarea\n");
    exit(1);
}

try {
    QuestionImportParser::parseJsonContent('{invalid');
    fwrite(STDERR, "FAIL: JSON non valido accettato\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

try {
    QuestionImportParser::parseJsonContent('{"domande":[]}');
    fwrite(STDERR, "FAIL: JSON senza domande accettato\n");
    exit(1);
} catch (InvalidArgumentException) {
    // expected
}

echo "PASS: JSON textarea parser\n";
