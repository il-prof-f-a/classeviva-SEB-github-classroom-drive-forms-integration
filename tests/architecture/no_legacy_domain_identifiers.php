<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) define('ROOT_PATH', $root);
spl_autoload_register(static function (string $class) use ($root): void {
    if (!str_starts_with($class, 'App\\')) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
    if (is_file($path)) require $path;
});

use App\Core\SchemaDefinitions;

$failures = [];
foreach (['GRUPPI_DIDATTICI', 'GRUPPI_INTEGRAZIONI', 'UDA_GRUPPI', 'STUDENTI', 'STUDENTI_IDENTITA_ESTERNE', 'GRUPPI_STUDENTI', 'STUDENTI_RISORSE_ESTERNE'] as $table) {
    $columns = SchemaDefinitions::getSheetColumns($table) ?? [];
    foreach (['id_classe_cv', 'id_materia_cv', 'id_studente_cv', 'id_studente_gc', 'github_username', 'nome_studente', 'email_studente'] as $legacy) {
        if (in_array($legacy, $columns, true)) $failures[] = "colonna legacy {$legacy} in {$table}";
    }
}
foreach (['src/Core/DatabaseManager.php', 'src/Core/Database/ExcelDatabaseAdapter.php', 'src/Core/Database/GoogleSheetsDatabaseAdapter.php'] as $removed) {
    if (is_file($root . '/' . $removed)) $failures[] = "adapter legacy presente: {$removed}";
}
$factory = file_get_contents($root . '/src/Core/Database/DatabaseFactory.php') ?: '';
if (str_contains($factory, "case 'excel'") || str_contains($factory, 'GoogleSheetsDatabaseAdapter')) {
    $failures[] = 'factory espone backend non SQL';
}

if ($failures !== []) { foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n"); exit(1); }
fwrite(STDOUT, "PASS: schema canonico e factory non espongono identificativi/adapter legacy.\n");
