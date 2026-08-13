<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Utils/QuestionEditorHelper.php';

use App\Utils\QuestionEditorHelper;

function assertSameValue(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

$catalog = [
    ['codice' => 'OBJ001', 'descrizione' => 'Gestire processi', 'competenza' => 'Sistemi operativi', 'parole_chiave' => 'scheduler'],
    ['codice' => 'OBJ002', 'descrizione' => 'Comunicare in rete', 'competenza' => 'Reti', 'parole_chiave' => 'protocolli'],
];
assertSameValue(2, count(QuestionEditorHelper::filterObjectives($catalog, '')), 'query vuota');
assertSameValue(['OBJ001'], array_column(QuestionEditorHelper::filterObjectives($catalog, 'SCHEDULER'), 'codice'), 'ricerca case insensitive');
assertSameValue(['OBJ002'], array_column(QuestionEditorHelper::filterObjectives($catalog, 'reti'), 'codice'), 'ricerca su competenza');
assertSameValue(['rete', 'Protocolli'], QuestionEditorHelper::normalizeKeywords('rete, rete; Protocolli'), 'keyword uniche');

$open = QuestionEditorHelper::normalizePayload([
    'argomento' => 'Processi', 'domanda' => 'Che cos\'e un processo?', 'tipo_domanda' => 'aperta',
    'risposta_attesa' => 'Un programma in esecuzione.', 'parole_chiave' => 'processo, programma', 'difficolta' => 3,
]);
assertSameValue('aperta', $open['tipo_domanda'], 'tipo aperta');
assertSameValue('processo,programma', $open['parole_chiave'], 'keyword payload aperta');
assertSameValue('Un programma in esecuzione.', $open['risposta_attesa'], 'risposta aperta');

$multiple = QuestionEditorHelper::normalizePayload([
    'domanda' => 'Quali sono vere?', 'tipo_domanda' => 'multipla',
    'risposte' => [
        ['testo' => 'A', 'corretta' => true], ['testo' => 'B', 'corretta' => true], ['testo' => 'C', 'corretta' => false],
    ],
]);
assertSameValue('multipla', $multiple['tipo_domanda'], 'tipo multipla');
assertSameValue(2, count(array_filter($multiple['opzioni'], static fn(array $option): bool => $option['corretta'])), 'multiple corrette');
$decoded = json_decode($multiple['risposta_attesa'], true, 512, JSON_THROW_ON_ERROR);
assertSameValue(3, count($decoded['risposte']), 'json multiple');

foreach ([[], [['testo' => 'solo', 'corretta' => true]], [['testo' => 'A', 'corretta' => false], ['testo' => 'B', 'corretta' => false]]] as $options) {
    try {
        QuestionEditorHelper::normalizePayload(['tipo_domanda' => 'multipla', 'domanda' => 'X', 'risposte' => $options]);
        fwrite(STDERR, "FAIL: una multipla non valida e' stata accettata\n");
        exit(1);
    } catch (InvalidArgumentException) {
    }
}

$legacy = QuestionEditorHelper::parseOptions('A|B*|C');
assertSameValue(true, $legacy[1]['corretta'], 'formato legacy con asterisco');

echo "PASS: editor utils\n";
