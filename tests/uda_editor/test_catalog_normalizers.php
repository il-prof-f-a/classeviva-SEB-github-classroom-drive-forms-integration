<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/src/Integration/GoogleFormsCatalog.php';
require_once $root . '/src/Integration/ClassroomAssignmentCatalog.php';
require_once $root . '/src/Integration/GitHubAssignmentCatalog.php';

use App\Integration\GoogleFormsCatalog;
use App\Integration\ClassroomAssignmentCatalog;
use App\Integration\GitHubAssignmentCatalog;

$form = GoogleFormsCatalog::normalizeFile([
    'id' => 'form-1',
    'name' => 'Verifica reti',
    'createdTime' => '2026-08-10T09:00:00Z',
    'webViewLink' => 'https://docs.google.com/forms/d/form-1/edit',
    'responderUri' => 'https://docs.google.com/forms/d/e/form-1/viewform',
    'description' => 'Test finale',
    'owners' => [['displayName' => 'Docente Test']],
], 4);

if (($form['student_url'] ?? '') !== 'https://docs.google.com/forms/d/e/form-1/viewform') {
    fwrite(STDERR, "FAIL: responder URL Google Forms\n");
    exit(1);
}
if (($form['teacher_url'] ?? '') === '' || ($form['description'] ?? '') !== 'Test finale') {
    fwrite(STDERR, "FAIL: metadati Google Forms\n");
    exit(1);
}
if (count(GoogleFormsCatalog::filter([$form], 'reti')) !== 1) {
    fwrite(STDERR, "FAIL: filtro catalogo Google Forms\n");
    exit(1);
}

echo "PASS: Google Forms catalog normalizer\n";

$assignment = ClassroomAssignmentCatalog::normalize([
    'id' => 'cw-1',
    'title' => 'Verifica scheduling',
    'description' => 'Consegna il file',
    'state' => 'PUBLISHED',
    'maxPoints' => 10,
    'alternateLink' => 'https://classroom.google.com/c/course/a/cw-1/details',
    'topicId' => 'topic-1',
    'creationTime' => '2026-08-10T09:00:00Z',
    'updateTime' => '2026-08-11T09:00:00Z',
    'workType' => 'ASSIGNMENT',
]);

if (($assignment['id'] ?? '') !== 'cw-1'
    || ($assignment['title'] ?? '') !== 'Verifica scheduling'
    || ($assignment['student_url'] ?? '') === ''
    || ($assignment['teacher_url'] ?? '') === '') {
    fwrite(STDERR, "FAIL: normalizzazione Classroom\n");
    exit(1);
}
if (!ClassroomAssignmentCatalog::isImportable($assignment)) {
    fwrite(STDERR, "FAIL: assignment Classroom importabile\n");
    exit(1);
}
if (count(ClassroomAssignmentCatalog::filter([$assignment], 'scheduling')) !== 1) {
    fwrite(STDERR, "FAIL: filtro catalogo Classroom\n");
    exit(1);
}
echo "PASS: Classroom catalog normalizer\n";

$githubAssignment = GitHubAssignmentCatalog::normalize([
    'id' => 123,
    'title' => 'Laboratorio API',
    'slug' => 'laboratorio-api',
    'invite_link' => 'https://classroom.github.com/a/ABC123',
    'html_url' => 'https://classroom.github.com/classrooms/55/assignments/123',
]);

if (($githubAssignment['id'] ?? '') !== '123'
    || ($githubAssignment['student_url'] ?? '') !== 'https://classroom.github.com/a/ABC123'
    || ($githubAssignment['github_classroom_id'] ?? '') !== '55') {
    fwrite(STDERR, "FAIL: normalizzazione GitHub Classroom\n");
    exit(1);
}
if (count(GitHubAssignmentCatalog::filter([$githubAssignment], 'laboratorio')) !== 1) {
    fwrite(STDERR, "FAIL: filtro catalogo GitHub Classroom\n");
    exit(1);
}
echo "PASS: GitHub Classroom catalog normalizer\n";
