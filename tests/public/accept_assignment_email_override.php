<?php

declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../../public/accept_assignment.php');
if ($source === false) {
    fwrite(STDERR, "Impossibile leggere public/accept_assignment.php\n");
    exit(1);
}

$required = [
    'email_studente',
    'strtolower(trim((string)($link[\'email_studente\'] ?? \'\')))',
    'find_assignment_link_by_github_username',
];

$missing = [];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        $missing[] = $needle;
    }
}

if ($missing !== []) {
    fwrite(STDERR, 'Override email assignment non gestito: ' . implode(', ', $missing) . "\n");
    exit(1);
}

echo "PASS: accept_assignment gestisce l'email override della riga assignment.\n";
