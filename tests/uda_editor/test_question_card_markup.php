<?php

$root = dirname(__DIR__, 2);

$questionCardData = [
    'id_domanda' => 'DOM_TEST',
    'argomento' => 'Scheduling',
    'domanda' => 'Quale algoritmo evita la starvation?',
    'difficolta' => 3,
    'tipo_domanda' => 'multipla',
    'risposta_attesa' => '',
    'opzioni' => [
        ['text' => 'Round Robin', 'correct' => true],
        ['text' => 'FIFO', 'correct' => false],
    ],
    'parole_chiave' => 'scheduling, starvation',
];
$questionCardActions = 'server';
$questionCardEditPayload = $questionCardData;
$questionCardDeleteId = 'DOM_TEST';
$questionCardLabel = '';
ob_start();
include $root . '/public/partials/question_card.php';
$html = ob_get_clean();

foreach ([
    'data-question-card',
    'data-question-card-text',
    'data-question-card-options',
    'data-question-card-difficulty',
    'data-question-card-keywords',
    'data-question-edit',
    'data-question-delete',
    'Round Robin',
    'Parole chiave',
] as $required) {
    if (!str_contains($html, $required)) {
        fwrite(STDERR, "FAIL: markup card domanda mancante: {$required}\n");
        exit(1);
    }
}

foreach (['tempo_risposta_min', 'ordine_consigliato', 'collegata_a', 'name="note"'] as $forbidden) {
    if (str_contains($html, $forbidden)) {
        fwrite(STDERR, "FAIL: campo obsoleto nella card: {$forbidden}\n");
        exit(1);
    }
}

$templateActions = 'wizard';
$questionCardTemplate = true;
ob_start();
include $root . '/public/partials/question_card.php';
$templateHtml = ob_get_clean();
if (!str_contains($templateHtml, 'data-question-edit') || !str_contains($templateHtml, 'data-question-delete')) {
    fwrite(STDERR, "FAIL: azioni template card mancanti\n");
    exit(1);
}

echo "PASS: question card markup\n";
