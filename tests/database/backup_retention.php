<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}

spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) {
        return;
    }

    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\Database\BackupRetention;

$failures = [];
$directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'uda-backup-retention-' . bin2hex(random_bytes(6));
$now = new DateTimeImmutable('2026-11-02 12:00:00', new DateTimeZone('UTC'));

$removeDirectory = static function (string $path) use (&$removeDirectory): void {
    if (!is_dir($path)) {
        return;
    }

    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $child = $path . DIRECTORY_SEPARATOR . $entry;
        if (is_dir($child) && !is_link($child)) {
            $removeDirectory($child);
        } else {
            @unlink($child);
        }
    }

    @rmdir($path);
};

try {
    if (!class_exists(BackupRetention::class)) {
        $failures[] = 'la classe di retention dei backup non esiste';
    } else {
        mkdir($directory . DIRECTORY_SEPARATOR . 'nested', 0755, true);

        $files = [
            'uda_mysql_old.json' => -40,
            'uda_master_old.db' => -40,
            'uda_mysql_recent.json' => -5,
            'uda_mysql_boundary.json' => -30,
            'unrelated.json' => -40,
            'uda_mysql_old.txt' => -40,
        ];

        foreach ($files as $name => $days) {
            $path = $directory . DIRECTORY_SEPARATOR . $name;
            file_put_contents($path, 'backup-test');
            touch($path, $now->modify("{$days} days")->getTimestamp());
        }

        $nestedFile = $directory . DIRECTORY_SEPARATOR . 'nested' . DIRECTORY_SEPARATOR . 'uda_mysql_nested.json';
        file_put_contents($nestedFile, 'backup-test');
        touch($nestedFile, $now->modify('-40 days')->getTimestamp());

        $removed = BackupRetention::prune($directory, 30, $now);
        if ($removed !== 2) {
            $failures[] = "la retention ha rimosso {$removed} file invece di 2";
        }

        foreach (['uda_mysql_old.json', 'uda_master_old.db'] as $name) {
            if (file_exists($directory . DIRECTORY_SEPARATOR . $name)) {
                $failures[] = "il backup scaduto {$name} non è stato rimosso";
            }
        }

        foreach (['uda_mysql_recent.json', 'uda_mysql_boundary.json', 'unrelated.json', 'uda_mysql_old.txt'] as $name) {
            if (!file_exists($directory . DIRECTORY_SEPARATOR . $name)) {
                $failures[] = "il file non gestito {$name} è stato rimosso";
            }
        }

        if (!file_exists($nestedFile)) {
            $failures[] = 'la retention ha attraversato una sottocartella';
        }
    }
} finally {
    $removeDirectory($directory);
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: retention backup a 30 giorni.\n");
