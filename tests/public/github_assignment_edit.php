<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$sourcePath = $root . '/public/github_assignment_edit.php';
$source = is_file($sourcePath) ? (file_get_contents($sourcePath) ?: '') : '';
$failures = [];

if ($source === '') {
    $failures[] = 'pagina editor assignment GitHub mancante';
}

$needles = [
    'Authorization::assertAuthenticated($_SESSION)',
    'Csrf::assertValid',
    "'piattaforma'",
    "'github'",
    "'update_github_test'",
    "'update_github_links'",
    'GITHUB_ASSIGNMENT_STUDENT_LINKS',
    'RuntimeStudentNameService',
    'email_studente',
    'student_repository_url',
    'github_username',
    'id_studente',
    'parseRepositoryUrl',
    'normalizeEmail',
    'normalizeUsername',
    'addCollaborator',
    'teacher_token',
    'beginTransaction',
    'github_assignment_review.php',
    'Salva dati test',
    'Salva associazioni',
];
foreach ($needles as $needle) {
    if (!str_contains($source, $needle)) {
        $failures[] = "manca {$needle}";
    }
}

if (preg_match('/name=["\']id_studente(?:\[|["\'])/i', $source) === 1) {
    $failures[] = 'id_studente esposto come input modificabile';
}
if (!str_contains($source, 'id_map[') || !str_contains($source, 'id_assignment')) {
    $failures[] = 'associazione form non vincolata alla riga assignment';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: pagina editor assignment GitHub verificata.\n");
