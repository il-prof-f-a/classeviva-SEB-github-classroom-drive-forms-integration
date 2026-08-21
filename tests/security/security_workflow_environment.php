<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$workflowPath = $root . '/.github/workflows/security.yml';
$workflow = is_file($workflowPath) ? (file_get_contents($workflowPath) ?: '') : '';
$requiredLines = [
    'APP_ENV: testing',
    'APP_DEBUG: false',
    'ENCRYPTION_KEY: 0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef',
];
$failures = [];
if ($workflow === '') {
    $failures[] = 'workflow Security checks assente o vuoto';
}
foreach ($requiredLines as $line) {
    if (!str_contains($workflow, $line)) {
        $failures[] = 'workflow senza ambiente CI richiesto: ' . $line;
    }
}
if (!preg_match('/extensions:\s*[^\r\n]*\bintl\b/', $workflow)) {
    $failures[] = 'workflow senza estensione PHP intl';
}
if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode('; ', $failures) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS: ambiente workflow Security checks\n");
