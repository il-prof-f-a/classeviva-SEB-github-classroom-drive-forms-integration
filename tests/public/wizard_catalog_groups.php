<?php

declare(strict_types=1);

// Verifica che gli endpoint catalog del wizard accettino id_gruppo (provider-neutral).
$root = dirname(__DIR__, 2);
$files = [
    $root . '/public/ajax_get_wizard_classroom_catalog.php' => 'listGoogleClassroomMappings',
    $root . '/public/ajax_get_wizard_github_catalog.php' => 'github_config_json',
];
$failures = [];
foreach ($files as $file => $service) {
    $source = file_get_contents($file);
    $base = basename($file);
    if ($source === false) {
        $failures[] = 'impossibile leggere ' . $base;
        continue;
    }
    foreach (['id_gruppo', $service] as $required) {
        if (strpos($source, $required) === false) {
            $failures[] = $base . ': riferimento assente: ' . $required;
        }
    }
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: endpoint catalog wizard provider-neutral (id_gruppo).
");
