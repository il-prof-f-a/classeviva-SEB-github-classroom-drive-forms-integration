<?php

declare(strict_types=1);

// Verifica che il modal "Collega un test esistente" di uda_tests.php esponga il
// catalogo degli assignment della GitHub Classroom mappata (provider-neutral),
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
foreach (['githubCatalogContainer', 'githubAssignmentList', 'githubClassroomId', 'githubCatalogNomap'] as $id) {
    $require(str_contains($source, 'id="' . $id . '"'), 'markup GitHub assente: ' . $id);
}

// Il catalogo riusa lo stesso endpoint provider-neutral del wizard (id_gruppo).
$require(str_contains($source, 'ajax_get_wizard_github_catalog.php'), 'endpoint catalog GitHub non referenziato');
$require(str_contains($source, 'id_gruppo[]'), 'catalog GitHub non invia id_gruppo');

// Il contesto lato client con le classroom mappate è iniettato.
$require(str_contains($source, 'udaGithubContext'), 'contesto GitHub lato client assente');

// Il caricamento degli assignment è agganciato alla selezione della piattaforma github.
$require(str_contains($source, 'loadGithubAssignments'), 'loadGithubAssignments non definito');

// Gli id esterni vengono salvati tramite campi hidden coerenti con l'handler POST.
$require(str_contains($source, "name = 'github_classroom_id'"), 'hidden github_classroom_id assente');
$require(str_contains($source, "name = 'github_assignment_id'"), 'hidden github_assignment_id assente');

// La risoluzione server-side usa il resolver provider-neutral sui mapping GitHub.
$require(str_contains($source, 'UdaIntegrationResolver::githubForGroup'), 'risoluzione classroom non usa UdaIntegrationResolver');
$require(str_contains($source, 'listGithubClassroomMappings'), 'listGithubClassroomMappings non referenziato');

// Il picker catalogo è incluso (stesso componente del wizard).
$require(str_contains($source, 'catalog-picker.js'), 'catalog-picker.js non incluso');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: catalogo GitHub Classroom nel modal di uda_tests.php.
");
