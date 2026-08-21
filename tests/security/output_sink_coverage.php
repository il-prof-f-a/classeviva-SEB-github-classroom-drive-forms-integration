<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public')) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
        continue;
    }
    $lines = file($file->getPathname()) ?: [];
    foreach ($lines as $index => $line) {
        if (str_contains($line, '<?=') && str_contains($line, 'json_encode(')
            && !str_contains($line, 'OutputEncoder::json(')
            && !str_contains($line, 'JSON_HEX_TAG')) {
            $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
            $errors[] = $relative . ':' . ($index + 1);
        }
    }
}

$googleAuth = file_get_contents($root . '/public/google_auth.php') ?: '';
if (str_contains($googleAuth, '<?= $error_message ?>')) {
    $errors[] = 'google_auth.php stampa error_message senza encoding';
}
$githubCallback = file_get_contents($root . '/public/github_callback.php') ?: '';
if (str_contains($githubCallback, 'die("Errore GitHub OAuth: {$error} - {$errorDescription}")')) {
    $errors[] = 'github_callback.php stampa parametri OAuth non codificati';
}

if ($errors !== []) {
    fwrite(STDERR, "FAIL: sink output non protetti: " . implode(', ', $errors) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: sink HTML e JavaScript protetti con encoding contestuale.\n");
