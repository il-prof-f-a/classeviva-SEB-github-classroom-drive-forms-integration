<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$expectedAdmin = trim((string)getenv('EXPECTED_ADMIN_EMAIL'));

$example = file_get_contents($root . '/.env.example') ?: '';
if (!preg_match('/^ADMIN_EMAILS=(.*)$/m', $example, $match)) {
    $failures[] = '.env.example non dichiara ADMIN_EMAILS.';
} elseif ($expectedAdmin !== '' && stripos($match[1], $expectedAdmin) !== false) {
    $failures[] = '.env.example contiene un amministratore personale invece di un esempio generico.';
}

$sourceFiles = [$root . '/bootstrap.php'];
foreach ([$root . '/public', $root . '/src'] as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile() && strtolower($file->getExtension()) === 'php') {
            $sourceFiles[] = $file->getPathname();
        }
    }
}

foreach ($sourceFiles as $path) {
    $source = file_get_contents($path) ?: '';
    if ($expectedAdmin !== '' && stripos($source, $expectedAdmin) !== false) {
        $failures[] = 'Email amministratore hardcoded in ' . str_replace('\\', '/', substr($path, strlen($root) + 1));
    }
}

$bootstrap = file_get_contents($root . '/bootstrap.php') ?: '';
if (!str_contains($bootstrap, "env('ADMIN_EMAILS'")) {
    $failures[] = 'Il controllo amministratore non usa ADMIN_EMAILS.';
}

$localEnvPath = $root . '/config/.env';
if ($expectedAdmin !== '' && is_file($localEnvPath)) {
    $localEnv = file_get_contents($localEnvPath) ?: '';
    preg_match('/^ADMIN_EMAILS=(.*)$/m', $localEnv, $localMatch);
    $configured = array_filter(array_map(
        static fn(string $email): string => strtolower(trim($email, " \t\n\r\0\x0B\"'")),
        explode(',', $localMatch[1] ?? '')
    ));
    if (!in_array(strtolower($expectedAdmin), $configured, true)) {
        $failures[] = 'config/.env non contiene l’amministratore locale atteso.';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: configurazione amministratore basata su ADMIN_EMAILS.\n");
