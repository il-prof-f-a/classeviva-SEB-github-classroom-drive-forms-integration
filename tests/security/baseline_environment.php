<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!version_compare(PHP_VERSION, '8.2.0', '>=') || !version_compare(PHP_VERSION, '8.5.0', '<')) {
    fwrite(STDERR, "FAIL: versione PHP fuori intervallo supportato\n");
    exit(1);
}
foreach (['curl', 'fileinfo', 'openssl', 'pdo', 'pdo_mysql', 'zip'] as $extension) {
    if (!extension_loaded($extension)) {
        if (getenv('CI')) {
            fwrite(STDERR, "FAIL: estensione {$extension} mancante\n");
            exit(1);
        }
        fwrite(STDOUT, "SKIP: estensione {$extension} mancante nell'ambiente locale.\n");
        exit(0);
    }
}
if (!is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "FAIL: vendor incompleto\n");
    exit(1);
}
$encryption = $root . '/src/Utils/EncryptionHelper.php';
if (getenv('APP_ENV') !== 'testing' && is_file($encryption)) {
    require_once $encryption;
    if (!\App\Utils\EncryptionHelper::isConfigured()) {
        fwrite(STDERR, "FAIL: ENCRYPTION_KEY non configurata\n");
        exit(1);
    }
}
fwrite(STDOUT, "PASS: baseline sicurezza disponibile.\n");
