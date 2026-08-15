<?php

declare(strict_types=1);

spl_autoload_register(static function (string $class): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__, 2) . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\DatabaseFactory;

$expected = ['sqlite', 'sqlite3', 'mysql'];
$actual = DatabaseFactory::getSupportedTypes();
$failures = [];
$root = dirname(__DIR__, 2);

$bootstrapSource = file_get_contents($root . '/bootstrap.php') ?: '';
$exampleSource = file_get_contents($root . '/.env.example') ?: '';
foreach (['DB_MASTER_FILE', "'google_sheets'"] as $forbidden) {
    if (str_contains($bootstrapSource, $forbidden)) {
        $failures[] = "bootstrap contiene ancora {$forbidden}";
    }
}
foreach (['DB_MASTER_FILE', 'DB_GOOGLE_SHEETS_'] as $forbidden) {
    if (str_contains($exampleSource, $forbidden)) {
        $failures[] = ".env.example contiene ancora {$forbidden}";
    }
}

if ($actual !== $expected) {
    $failures[] = 'backend supportati inattesi: ' . json_encode($actual, JSON_UNESCAPED_UNICODE);
}

$config = ['database' => ['type' => 'excel', 'master_file' => 'database/uda_master.xlsx']];
try {
    DatabaseFactory::create($config);
    $failures[] = 'excel è ancora accettato come backend';
} catch (Throwable $exception) {
    if (!str_contains(strtolower($exception->getMessage()), 'sqlite')
        || !str_contains(strtolower($exception->getMessage()), 'mysql')) {
        $failures[] = 'messaggio backend non supportati incompleto';
    }
}

foreach (['google_sheets', 'sheets'] as $type) {
    try {
        DatabaseFactory::create(['database' => ['type' => $type]]);
        $failures[] = "{$type} è ancora accettato come backend";
    } catch (Throwable) {
        // Comportamento atteso.
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: backend di persistenza limitati a MySQL e SQLite.\n");
