<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_create.php') ?: '';
$failures = [];

foreach ([
    'name="group_ids[]"' => 'il form deve inviare più gruppi',
    'id="group-selectors"' => 'il form deve avere il contenitore dinamico dei gruppi',
    'GitHubAssignmentGroupRepository' => 'la creazione deve persistere il legame test-gruppi',
    'resolve_students_for_groups' => 'la risoluzione deve aggregare gli studenti di tutti i gruppi',
    'TEST_CLASSROOM_PUBBLICAZIONI' => 'la pubblicazione Classroom deve conservare una riga per corso/gruppo',
    'mergeResolvedStudents' => 'gli studenti duplicati devono essere uniti prima di creare le repository',
] as $needle => $message) {
    if (!str_contains($source, $needle)) {
        $failures[] = $message;
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: form assignment GitHub multi-gruppo verificato.\n");
