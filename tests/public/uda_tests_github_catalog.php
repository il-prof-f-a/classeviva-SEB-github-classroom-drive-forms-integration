<?php

declare(strict_types=1);

// Verifica che il modal "Collega un test esistente" di uda_tests.php esponga il
// catalogo degli assignment GitHub dal DB interno (TEST piattaforma='github'),
// speculare a uda_create.php step 6, e che riempia i campi del test.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_tests.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere public/uda_tests.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

// Markup del catalogo GitHub nel modal.
foreach (['githubCatalogContainer', 'githubAssignmentList', 'githubCatalogNomap'] as $id) {
    $require(str_contains($source, 'id="' . $id . '"'), 'markup GitHub assente: ' . $id);
}

// Il catalogo riusa lo stesso endpoint provider-neutral del wizard (id_gruppo).
$require(str_contains($source, 'ajax_get_wizard_github_catalog.php'), 'endpoint catalog GitHub non referenziato');
$require(str_contains($source, 'id_gruppo[]'), 'catalog GitHub non invia id_gruppo');

// Il contesto lato client con i gruppi dell'UDA è iniettato.
$require(str_contains($source, 'udaGithubContext'), 'contesto GitHub lato client assente');

// Il caricamento degli assignment è agganciato alla selezione della piattaforma github.
$require(str_contains($source, 'loadGithubAssignments'), 'loadGithubAssignments non definito');

// Gli id esterni vengono salvati tramite campi hidden coerenti con l'handler POST.
$require(str_contains($source, "name = 'github_classroom_id'"), 'hidden github_classroom_id assente');
$require(str_contains($source, "name = 'github_assignment_id'"), 'hidden github_assignment_id assente');

// Il picker catalogo è incluso (stesso componente del wizard).
$require(str_contains($source, 'catalog-picker.js'), 'catalog-picker.js non incluso');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: catalogo GitHub dal DB nel modal di uda_tests.php.
");
