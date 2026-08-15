<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/public/database_manager.php') ?: '';
$failures = [];
foreach (['case \'excel\'', 'google_sheets', 'DatabaseManager'] as $forbidden) {
    if (str_contains($source, $forbidden)) $failures[] = "backend legacy nella pagina admin: {$forbidden}";
}
if (!str_contains($source, "case 'sqlite'") || !str_contains($source, "case 'mysql'")) {
    $failures[] = 'pagina admin senza i backend SQL';
}
if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: pagina amministrazione database limitata a SQLite/MySQL.\n");
