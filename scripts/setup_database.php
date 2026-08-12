<?php

declare(strict_types=1);

use App\Core\Database\DatabaseFactory;

$options = getopt('', ['wait::', 'validate']);
$waitSeconds = max(0, (int)($options['wait'] ?? 0));
$deadline = time() + $waitSeconds;
$lastError = null;
$config = require_once dirname(__DIR__) . '/bootstrap.php';

do {
    try {
        $adapter = DatabaseFactory::createWithInitialization($config, true);
        $validation = $adapter->validate();

        if (!$validation['valid'] || $validation['errors'] || $validation['warnings']) {
            fwrite(STDERR, "Database non valido: " . json_encode($validation, JSON_UNESCAPED_UNICODE) . PHP_EOL);
            exit(1);
        }

        $tables = $adapter->getAllSheetNames();
        printf("Database pronto: %d tabelle validate.%s", count($tables), PHP_EOL);
        exit(0);
    } catch (Throwable $error) {
        $lastError = $error;
        if (time() >= $deadline) {
            break;
        }
        sleep(2);
    }
} while (true);

fwrite(STDERR, 'Database non raggiungibile: ' . ($lastError?->getMessage() ?? 'errore sconosciuto') . PHP_EOL);
exit(1);
