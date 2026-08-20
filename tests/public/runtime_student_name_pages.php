<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$checks = [
    'rubrica_orale_v2.php' => [
        'required' => ['RuntimeStudentNameService', 'resolveGroupStudents'],
        'forbidden' => ['GoogleClassroomAPI', 'getCourseStudents', 'getStudentiClasse'],
    ],
    'laboratorio_griglia.php' => [
        'required' => ['RuntimeStudentNameService', 'resolveGroupStudents'],
        'forbidden' => ['getStudentiClasse'],
    ],
    'uda_grades.php' => [
        'required' => ['RuntimeStudentNameService', 'resolveGroupStudents'],
        'forbidden' => ['studentCachePerClasse', 'getStudentiClasse'],
    ],
    'import_quiz_results_excel.php' => [
        'required' => ['RuntimeStudentNameService', 'providerRoster'],
        'forbidden' => ['GoogleClassroomAPI', 'getCourseStudents'],
    ],
    'import_kahoot_grades.php' => [
        'required' => ['RuntimeStudentNameService', 'providerRoster'],
        'forbidden' => ['GoogleClassroomAPI', 'getCourseStudents'],
    ],
    'import_form_results.php' => [
        'required' => ['RuntimeStudentNameService', 'providerRoster'],
        'forbidden' => ['getStudente'],
    ],
    'import_classroom_grades.php' => [
        'required' => ['RuntimeStudentNameService', 'providerRoster'],
        'forbidden' => ['getStudente'],
    ],
    'github_assignment_review.php' => [
        'required' => ['RuntimeStudentNameService', 'resolveGroupStudents'],
        'forbidden' => [],
    ],
    'pubblicazione_cv.php' => [
        'required' => ['RuntimeStudentNameService', 'resolveGroupStudents'],
        'forbidden' => ["Studente ' . substr("]
    ],
    'teaching_groups.php' => [
        'required' => ['RuntimeStudentNameService', 'providerRoster'],
        'forbidden' => ['getCourseStudents', 'getStudentiClasse'],
    ],
];

$failures = [];
foreach ($checks as $file => $rules) {
    $source = file_get_contents($root . '/public/' . $file);
    if ($source === false) {
        $failures[] = "file mancante: {$file}";
        continue;
    }
    foreach ($rules['required'] as $needle) {
        if (strpos($source, $needle) === false) {
            $failures[] = "{$file}: servizio centralizzato assente ({$needle})";
        }
    }
    foreach ($rules['forbidden'] as $needle) {
        if (strpos($source, $needle) !== false) {
            $failures[] = "{$file}: chiamata duplicata ancora presente ({$needle})";
        }
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pagine di valutazione/import usano il resolver centralizzato dei nomi.\n");
