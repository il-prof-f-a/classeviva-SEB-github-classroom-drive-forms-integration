<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$needles = [
    'GitHubAssignmentNameService',
    'name_template',
    'repository_visibility',
    'privacy_visibility_forced',
    'name="visibility"',
    'placeholder-token',
    '{gruppo}',
    '{template}',
    '{studente}',
    '{team}',
    '{data}',
    '{anno}',
    '{org}',
    'Inserisci placeholder',
    'Nome repository',
    'student-name-privacy-warning',
    'effectiveVisibility',
    'createRepositoryFromTemplate',
];
$failures = [];
foreach ($needles as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = "manca {$needle}";
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: controlli placeholder e visibilità presenti.\n");
