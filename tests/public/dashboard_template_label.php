<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/index.php') ?: '';
if (!str_contains($source, 'Template GitHub') || str_contains($source, 'Repository Template GitHub')) {
    fwrite(STDERR, "FAIL: label dashboard Template GitHub non aggiornata.\n");
    exit(1);
}

fwrite(STDOUT, "PASS: label dashboard Template GitHub verificata.\n");
