<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

$required = [
    '$assignmentData' => 'il flusso automatico deve preparare i dati di un compito Classroom',
    "'state' => 'DRAFT'" => 'il compito automatico deve restare in bozza',
    "'materials' => [\$genericLink]" => 'il compito automatico deve allegare il link generico',
    '$gc->createAssignment($gcCourse, $assignmentData)' => 'il flusso automatico deve usare CourseWork',
    'classroom_assignment_id' => 'l ID Classroom deve essere persistito',
    'classroom_url' => 'l URL Classroom deve essere persistito',
    'Pubblicazione Classroom fallita per il corso' => 'gli errori Classroom devono essere tracciabili',
];

foreach ($required as $needle => $message) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if (str_contains($source, '$gc->createMaterial($gcCourse, $materialData)')) {
    $failures[] = 'la creazione assignment non deve usare CourseWorkMaterial';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pubblicazione automatica come compito Classroom verificata.\n");
