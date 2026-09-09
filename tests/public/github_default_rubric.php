<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rubricPage = file_get_contents($root . '/public/github_rubriche.php');
$reviewPage = file_get_contents($root . '/public/github_assignment_review.php');
$templatePath = $root . '/Materiale/Rubrica valutazione github VUOTA.xlsx';
if ($rubricPage === false || $reviewPage === false) {
    fwrite(STDERR, "FAIL: impossibile leggere la pagina rubriche GitHub\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$require(is_file($templatePath), 'template Excel GitHub default assente');
$require(str_contains($rubricPage, 'GitHubRubricTemplateService'), 'pagina senza servizio template');
$require(str_contains($rubricPage, 'Ricarica rubrica associata al test'), 'etichetta ricarica test assente');
$require(str_contains($rubricPage, 'githubTestSelectionForm') && str_contains($rubricPage, 'selectTest'), 'selettore test GitHub assente');
$require(str_contains($rubricPage, 'templateSourceSelect'), 'optionbox rubriche assente');
$require(!str_contains($rubricPage, 'Carica Rubrica di default'), 'vecchia etichetta rubrica default ancora presente');
$require(!str_contains($reviewPage, 'ghDefaultGitRubricDefinition'), 'fallback hardcoded ancora presente nella review');
$service = file_get_contents($root . '/src/Core/GitHubRubricTemplateService.php');
$require(is_string($service) && str_contains($service, 'Rubrica valutazione github VUOTA.xlsx'), 'nome template default non definito nel servizio');

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: rubrica GitHub default basata sul template Excel.\n");
