<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$surface = require $root . '/config/security_surface.php';
$errors = [];

foreach ($surface['local_only'] as $relative) {
    if (is_file($root . '/public/' . $relative)) {
        $errors[] = "file local-only esposto: {$relative}";
    }
}
foreach ($surface['admin'] as $relative) {
    $path = $root . '/public/' . $relative;
    if (!is_file($path)) continue;
    $source = file_get_contents($path) ?: '';
    if (!str_contains($source, 'Authorization::assertAdmin')) {
        $errors[] = "endpoint admin senza guardia: {$relative}";
    }
}
foreach (glob($root . '/public/*.php') ?: [] as $path) {
    $source = file_get_contents($path) ?: '';
    if (preg_match('/\$_GET\s*\[\s*["\']action["\']\s*\]\s*===?\s*["\'](?:delete|publish_gc|logout_github)["\']/i', $source)) {
        $errors[] = 'mutazione GET rilevata: ' . basename($path);
    }
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL: " . implode(' | ', $errors) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS: superficie web classificata e quarantena verificata.\n");
