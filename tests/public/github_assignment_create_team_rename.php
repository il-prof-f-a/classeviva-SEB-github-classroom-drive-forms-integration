<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$needles = [
    'team-rename-modal',
    'team-rename-input',
    'dblclick',
    'pointerdown',
    'pointerup',
    'pointercancel',
    'group.name',
    'syncPayload',
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

fwrite(STDOUT, "PASS: rinomina gruppi team verificata.\n");
