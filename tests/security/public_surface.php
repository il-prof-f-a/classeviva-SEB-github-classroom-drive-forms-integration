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
    $mutatingValue = '(?:delete[^"\']*|remove[^"\']*|reset[^"\']*|save[^"\']*|update[^"\']*|create[^"\']*|publish[^"\']*|revoke[^"\']*|import[^"\']*|upload[^"\']*|logout[^"\']*|repo_loc)';
    if (preg_match('/\$_GET\s*\[\s*["\']action["\']\s*\]\s*===?\s*["\']' . $mutatingValue . '["\']/i', $source)) {
        $errors[] = 'mutazione GET diretta rilevata: ' . basename($path);
        continue;
    }
    preg_match_all(
        '/\$([A-Za-z_][A-Za-z0-9_]*)\s*=\s*[^;]*\$_GET\s*\[\s*["\']action["\']\s*\][^;]*;/i',
        $source,
        $assignments
    );
    foreach (array_unique($assignments[1] ?? []) as $variable) {
        if (preg_match('/\$' . preg_quote($variable, '/') . '\s*===?\s*["\']' . $mutatingValue . '["\']/i', $source)) {
            $errors[] = 'mutazione GET indiretta rilevata: ' . basename($path) . ' ($' . $variable . ')';
            break;
        }
    }
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL: " . implode(' | ', $errors) . "\n");
    exit(1);
}
fwrite(STDOUT, "PASS: superficie web classificata e quarantena verificata.\n");
