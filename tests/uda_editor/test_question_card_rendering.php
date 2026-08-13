<?php

$root = dirname(__DIR__, 2);
$questionCardData = [
    'domanda' => 'Seleziona le risposte corrette',
    'difficolta' => 4,
    'tipo_domanda' => 'multipla',
    'opzioni' => [
        ['text' => 'Prima corretta', 'correct' => true],
        ['text' => 'Seconda errata', 'correct' => false],
    ],
    'parole_chiave' => 'prima, verifica',
];
$questionCardActions = 'none';
$questionCardLabel = 'Domanda 1';
ob_start();
include $root . '/public/partials/question_card.php';
$html = ob_get_clean();

if (!str_contains($html, 'bi-check-square-fill') || !str_contains($html, 'bi-square')) {
    fwrite(STDERR, "FAIL: card multipla non distingue risposta corretta ed errata\n");
    exit(1);
}
if (!str_contains($html, 'Difficoltà: 4/5') || !str_contains($html, 'Domanda 1')) {
    fwrite(STDERR, "FAIL: metadati card domanda mancanti\n");
    exit(1);
}

echo "PASS: question card rendering\n";
