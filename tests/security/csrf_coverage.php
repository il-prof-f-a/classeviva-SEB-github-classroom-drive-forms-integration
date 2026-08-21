<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$surface = require $root . '/config/security_surface.php';
$declared = array_values(array_unique(array_map(
    static fn(string $path): string => str_replace('\\', '/', $path),
    $surface['mutating'] ?? []
)));
sort($declared);

$discovered = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public'));
foreach ($iterator as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $source = file_get_contents($file->getPathname()) ?: '';
    if (!preg_match('/\$_(?:POST|FILES)\b|php:\/\/input/', $source)) {
        continue;
    }
    $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root . '/public') + 1));
    $discovered[] = $relative;
}
$discovered = array_values(array_unique($discovered));
sort($discovered);

$errors = [];
$missingFromManifest = array_values(array_diff($discovered, $declared));
$staleManifest = array_values(array_diff($declared, $discovered));
if ($missingFromManifest !== []) {
    $errors[] = 'endpoint mutativi non classificati: ' . implode(', ', $missingFromManifest);
}
if ($staleManifest !== []) {
    $errors[] = 'endpoint mutativi obsoleti nella manifest: ' . implode(', ', $staleManifest);
}

foreach ($declared as $relative) {
    $source = file_get_contents($root . '/public/' . $relative) ?: '';
    $bootstrapPosition = strpos($source, 'bootstrap.php');
    preg_match('/\$_(?:POST|FILES)\b|php:\/\/input/', $source, $inputMatch, PREG_OFFSET_CAPTURE);
    $inputPosition = isset($inputMatch[0][1]) ? (int)$inputMatch[0][1] : PHP_INT_MAX;
    if ($bootstrapPosition === false || $bootstrapPosition > $inputPosition) {
        $errors[] = 'bootstrap caricato dopo l input mutativo: ' . $relative;
    }
}

$bootstrap = file_get_contents($root . '/bootstrap.php') ?: '';
if (!str_contains($bootstrap, 'RequestGuard::assertMutation')) {
    $errors[] = 'guardia mutazioni globale assente dal bootstrap';
}
if (!str_contains($bootstrap, 'Csrf::installHtmlBridge')) {
    $errors[] = 'bridge CSRF globale assente dal bootstrap';
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL: " . implode(' | ', $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: tutte le mutazioni pubbliche sono classificate e protette da CSRF.\n");
