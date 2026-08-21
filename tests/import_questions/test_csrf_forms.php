<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$importQuestions = file_get_contents($root . '/public/import_questions.php') ?: '';
$importPreview = file_get_contents($root . '/public/partials/import_preview.php') ?: '';
$quizImport = file_get_contents($root . '/public/import_quiz_results_excel.php') ?: '';

if (substr_count($importQuestions, 'Csrf::hiddenField($_SESSION)') < 2
    || substr_count($importPreview, 'Security\\Csrf::hiddenField($_SESSION)') < 1) {
    fwrite(STDERR, "FAIL: i tre form di import_questions.php devono includere il token CSRF server-side\n");
    exit(1);
}
if (substr_count($quizImport, 'Csrf::hiddenField($_SESSION)') < 2) {
    fwrite(STDERR, "FAIL: i due form di import_quiz_results_excel.php devono includere il token CSRF server-side\n");
    exit(1);
}

echo "PASS: token CSRF esplicito nei form di importazione\n";
