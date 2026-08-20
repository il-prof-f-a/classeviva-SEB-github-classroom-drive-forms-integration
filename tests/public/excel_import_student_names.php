<?php

declare(strict_types=1);

// Regressione: l'anteprima Excel deve ricostruire il nome dal roster runtime
// anche quando la sessione contiene dati creati da una versione precedente.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/import_quiz_results_excel.php') ?: '';
$failures = [];

$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

foreach (['resolveQuizStudentNames', 'getCourseStudents', 'listForStudent', 'student_name'] as $required) {
    $require(str_contains($source, $required), "risoluzione nome runtime assente: {$required}");
}
$require(!str_contains($source, "'student_name' => (\$studentId !== null && \$studentId !== '') ? 'ID: '")
        && !str_contains($source, "'student_name' => \$groupId"),
    'l’anteprima usa ancora un identificativo interno o il gruppo come nome studente');
$require(str_contains($source, '$displayStudentName =')
        && str_contains($source, "Nome non disponibile"),
    'la grafica può ancora mostrare l’identificativo al posto del nome');
$require(str_contains($source, "\$_SESSION['import_quiz_excel_data']['rows']")
        || str_contains($source, "\$_SESSION['import_quiz_excel_data'] = \$importData"),
    'il nome runtime non viene aggiornato nella sessione dell’anteprima');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: import Excel mostra i nomi runtime degli studenti.\n");
