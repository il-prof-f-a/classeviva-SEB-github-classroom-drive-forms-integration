<?php

declare(strict_types=1);

// Verifica che i Google Forms già pubblicati nella Classroom associata vengano
// evidenziati e ordinati in cima nel catalogo Drive di import_questions.php.
$root = dirname(__DIR__, 2);

$failures = [];

$classroomApi = file_get_contents($root . '/src/Integration/GoogleClassroomAPI.php') ?: '';
foreach (['listFormIdsInCourse', 'getFormUrl', 'getDriveFile', 'getAlternateLink'] as $needle) {
    if (!str_contains($classroomApi, $needle)) {
        $failures[] = "GoogleClassroomAPI: {$needle} assente in listFormIdsInCourse";
    }
}

$formsAjax = file_get_contents($root . '/public/ajax_list_google_forms.php') ?: '';
foreach (['course_id', 'published_in_classroom', 'GoogleClassroomAPI'] as $needle) {
    if (!str_contains($formsAjax, $needle)) {
        $failures[] = "ajax_list_google_forms.php: {$needle} assente";
    }
}

$importPage = file_get_contents($root . '/public/import_questions.php') ?: '';
if (!str_contains($importPage, 'data-course-id')) {
    $failures[] = 'import_questions.php: attributo data-course-id assente';
}

$importJs = file_get_contents($root . '/public/assets/js/import-questions.js') ?: '';
foreach (['published_in_classroom', 'list-group-item-info', 'dataset.courseId'] as $needle) {
    if (!str_contains($importJs, $needle)) {
        $failures[] = "import-questions.js: {$needle} assente";
    }
}

$pickerJs = file_get_contents($root . '/public/assets/js/catalog-picker.js') ?: '';
if (!str_contains($pickerJs, 'rendered?.highlight')) {
    $failures[] = 'catalog-picker.js: supporto highlight assente';
}

$wizard = file_get_contents($root . '/public/uda_create.php') ?: '';
foreach (['wizard-import-questions-link', 'updateImportQuestionsLink'] as $needle) {
    if (!str_contains($wizard, $needle)) {
        $failures[] = "uda_create.php: {$needle} assente";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

echo "PASS: evidenziazione Forms già pubblicati in Classroom
";
