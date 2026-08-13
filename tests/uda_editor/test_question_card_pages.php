<?php

$root = dirname(__DIR__, 2);
$create = file_get_contents($root . '/public/uda_create.php') ?: '';
$questions = file_get_contents($root . '/public/uda_questions.php') ?: '';

foreach ([[$create, 'wizard'], [$questions, 'elenco']] as [$source, $label]) {
    if (!str_contains($source, 'question_card.php')) {
        fwrite(STDERR, "FAIL: partial question card non usato nella pagina {$label}\n");
        exit(1);
    }
}

foreach (['question-card.js', 'QuestionCard.createFromTemplate', 'QuestionCard.update'] as $required) {
    if (!str_contains($create, $required)) {
        fwrite(STDERR, "FAIL: integrazione card condivisa nel wizard mancante: {$required}\n");
        exit(1);
    }
}

if (!str_contains($questions, '$questionCardActions = \'server\'')) {
    fwrite(STDERR, "FAIL: uda_questions non usa le azioni server della card condivisa\n");
    exit(1);
}

echo "PASS: question card pages\n";
