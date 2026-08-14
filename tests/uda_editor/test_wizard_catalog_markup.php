<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/uda_create.php') ?: '';

foreach ([
    'Dopo la creazione dell\'UDA',
    'catalog-picker.js',
    'google-forms',
    'google-classroom',
    'github',
    'test_url_studenti[]',
    'test_url_docente[]',
    'test_classroom_assignment_id[]'
] as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "FAIL: {$needle}\n");
        exit(1);
    }
}

echo "PASS: wizard catalog markup\n";
