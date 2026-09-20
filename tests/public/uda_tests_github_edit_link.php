<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../public/uda_tests.php');
if ($source === false) {
    fwrite(STDERR, "Impossibile leggere public/uda_tests.php\n");
    exit(1);
}

$required = [
    'github_assignment_edit.php?test_id=',
    'if ($piattaforma === \'github\')',
    'data-bs-target="#editTestModal"',
    'loadTestForEdit(',
];

$missing = [];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        $missing[] = $needle;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'Link editor GitHub o modal piattaforme non GitHub assenti: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "PASS: uda_tests instrada i test GitHub verso l'editor dedicato.\n";
