<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/teaching_groups.php');
$failures = [];
if ($source === false) {
    $failures[] = 'teaching_groups.php illeggibile';
} else {
    if (!str_contains($source, "case 'sync_students':") || !str_contains($source, '$studentService->syncRoster($groupId')) {
        $failures[] = 'sincronizzazione esplicita roster assente';
    }
    if (str_contains($source, '$studentService->syncRoster($selectedGroupId')) {
        $failures[] = 'sincronizzazione roster eseguita durante il caricamento pagina';
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: caricamento pagina gruppi senza scritture roster automatiche.\n");
