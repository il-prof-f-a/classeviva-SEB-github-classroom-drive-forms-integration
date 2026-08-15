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

if (!interface_exists(App\Core\Database\DatabaseAdapterInterface::class)) {
    // Il caricamento lazy permette al test di restare indipendente da Composer.
    class_exists(App\Core\Database\DatabaseAdapterInterface::class);
}

use App\Core\SpreadsheetFileService;

$root = dirname(__DIR__, 2);
$service = new SpreadsheetFileService([$root . '/database/templates']);
$failures = [];

$adapterReflection = new ReflectionClass(App\Core\Database\DatabaseAdapterInterface::class);
foreach (['loadExternalFile', 'loadTemplate'] as $method) {
    if ($adapterReflection->hasMethod($method)) {
        $failures[] = "il contratto database espone ancora {$method}()";
    }
}

$cases = [
    'missing file' => static function () use ($service): void {
        $service->loadExternalFile(sys_get_temp_dir() . '/uda-file-does-not-exist.xlsx');
    },
    'unsupported extension' => static function () use ($service): void {
        $path = tempnam(sys_get_temp_dir(), 'uda-');
        $txtPath = $path . '.txt';
        try {
            rename($path, $txtPath);
            file_put_contents($txtPath, 'not a spreadsheet');
            $service->loadExternalFile($txtPath);
        } finally {
            @unlink($path);
            @unlink($txtPath);
        }
    },
    'template traversal' => static function () use ($service): void {
        $service->loadTemplate('../.env');
    },
];

foreach ($cases as $label => $case) {
    try {
        $case();
        $failures[] = "caso {$label} non ha sollevato eccezione";
    } catch (Throwable) {
        // Errore atteso.
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: servizio file tabellari valida path, estensioni e template.\n");
