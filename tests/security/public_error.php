<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/src/Core/Security/PublicError.php';

use App\Core\Security\PublicError;

$message = PublicError::message(new RuntimeException('SQLSTATE token=secret C:\\private\\app.php'), 'fixture');
foreach (['SQLSTATE', 'secret', 'private', 'app.php'] as $sensitive) {
    if (stripos($message, $sensitive) !== false) {
        throw new RuntimeException('Dettaglio sensibile nella risposta pubblica');
    }
}
if (!preg_match('/Riferimento: [a-f0-9]{16}/', $message)) {
    throw new RuntimeException('Correlation ID pubblico mancante');
}

$root = dirname(__DIR__, 2);
$errors = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/public')) as $file) {
    if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
    $source = file_get_contents($file->getPathname()) ?: '';
    if (preg_match('/["\']error["\']\s*=>\s*\$[A-Za-z_][A-Za-z0-9_]*->getMessage\(\)/', $source)) {
        $errors[] = basename($file->getPathname());
    }
}
if ($errors !== []) {
    throw new RuntimeException('Eccezioni grezze in risposte JSON: ' . implode(', ', array_unique($errors)));
}

$publicBoundaryFiles = [
    'api/oral_rubric_load.php',
    'api/oral_rubric_save.php',
    'api/test_api_handler.php',
    'github_callback.php',
    'oauth_callback.php',
    'uda_export.php',
];
foreach ($publicBoundaryFiles as $relativePath) {
    $source = file_get_contents($root . '/public/' . $relativePath) ?: '';
    if (str_contains($source, 'getMessage()') && !str_contains($source, 'PublicError::')) {
        $errors[] = $relativePath;
    }
}
if ($errors !== []) {
    throw new RuntimeException('Eccezioni grezze ai confini pubblici: ' . implode(', ', array_unique($errors)));
}
fwrite(STDOUT, "PASS: errori pubblici redatti e correlabili.\n");
