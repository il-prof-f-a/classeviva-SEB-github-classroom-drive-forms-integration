<?php

declare(strict_types=1);

require dirname(__DIR__) . '/bootstrap.php';

use App\Core\Database\DatabaseFactory;

try {
    $adapter = DatabaseFactory::createWithInitialization($config, true);
    $report = $adapter->validate();
    $errors = array_values(array_filter($report['errors'] ?? []));

    if (($report['valid'] ?? false) !== true || $errors !== []) {
        throw new RuntimeException('Schema non valido: ' . json_encode($errors, JSON_UNESCAPED_UNICODE));
    }

    $requiredTables = ['UDA_ANAGRAFICA', 'MATERIALI', 'OBIETTIVI', 'RUBRICA', 'TEST', 'VOTI'];
    foreach ($requiredTables as $table) {
        if (!$adapter->sheetExists($table)) {
            throw new RuntimeException("Tabella richiesta non creata: {$table}");
        }
    }

    fwrite(STDOUT, "OK: inizializzazione e validazione MySQL completate.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
