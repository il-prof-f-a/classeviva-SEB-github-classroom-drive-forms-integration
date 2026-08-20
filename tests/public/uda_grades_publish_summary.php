<?php

declare(strict_types=1);

// Verifica statica: dopo la pubblicazione sul registro, uda_grades.php mostra
// un riepilogo (banner verde) con studente, classe, voto, colonna/tipo e link
// al registro Spaggiari (regvoti.php); publishGrade espone slot_position.
$root = dirname(__DIR__, 2);
$failures = [];

$grades = file_get_contents($root . '/public/uda_grades.php');
if ($grades === false) {
    fwrite(STDERR, "FAIL: impossibile leggere uda_grades.php
");
    exit(1);
}
foreach (['publish_summary', 'regvoti.php', 'slot_position', 'getClassesWithTeacherSubjects'] as $required) {
    if (strpos($grades, $required) === false) {
        $failures[] = "uda_grades.php: riferimento mancante {$required}";
    }
}

$api = file_get_contents($root . '/src/Integration/ClasseVivaAPI.php');
if ($api === false) {
    fwrite(STDERR, "FAIL: impossibile leggere ClasseVivaAPI.php
");
    exit(1);
}
if (strpos($api, "'slot_position' =>") === false) {
    $failures[] = 'ClasseVivaAPI: publishGrade non espone slot_position';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: riepilogo pubblicazione registro (banner + slot_position + link regvoti).
");
