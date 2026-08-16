<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$path = $root . '/public/uda_create.php';
$source = is_file($path) ? (file_get_contents($path) ?: '') : '';
$failures = [];

foreach ([
    'id_gruppo[]',
    'TeachingGroupCatalogService',
    'teaching-group-select',
    'teaching_groups.php?return_to=uda_create.php#2',
    'selectedGroupIds',
    'sessionStorage',
    '#2',
] as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = "selector gruppi incompleto: {$needle}";
    }
}
if (!preg_match('/<select\\b(?=[^>]*name=["\']id_gruppo\\[\\]["\'])(?=[^>]*\\bmultiple\\b)[^>]*>/i', $source)) {
    $failures[] = 'il selector gruppi non consente zero o piu selezioni';
}
if (!preg_match('/selectedGroupIds\\s*=\\s*\\[\\s*\\]/i', $source)) {
    $failures[] = 'ripristino senza selezioni non esplicito';
}
foreach (['classe_id[]', 'classe_materia[]'] as $legacyInput) {
    if (preg_match('/<(?:input|select|textarea)\b[^>]*name=["\']' . preg_quote($legacyInput, '/') . '["\']/i', $source)) {
        $failures[] = "campo legacy ancora input primario: {$legacyInput}";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: wizard step 2 usa il selector dei gruppi didattici.\n");
