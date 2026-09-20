<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require $root . '/src/Core/SchemaDefinitions.php';

$servicePath = $root . '/src/Core/GitHubAssignmentEditorService.php';
$failures = [];
if (!is_file($servicePath)) {
    $failures[] = 'servizio editor assignment GitHub mancante';
} else {
    require $servicePath;
}

use App\Core\GitHubAssignmentEditorService;
use App\Core\SchemaDefinitions;

if (class_exists(GitHubAssignmentEditorService::class)) {
    $valid = GitHubAssignmentEditorService::parseRepositoryUrl('https://github.com/Org-Name/repo_name');
    if ($valid !== ['owner' => 'Org-Name', 'repo' => 'repo_name']) {
        $failures[] = 'URL repository GitHub valido non riconosciuto';
    }
    foreach ([
        'https://gitlab.com/org/repo',
        'https://github.com/org/repo/issues/1',
        'https://user:email@email.it/org/repo',
        'javascript:alert(1)',
    ] as $invalid) {
        if (GitHubAssignmentEditorService::parseRepositoryUrl($invalid) !== null) {
            $failures[] = 'URL repository non valido accettato: ' . $invalid;
        }
    }
    if (GitHubAssignmentEditorService::normalizeEmail(' email@email.it ') !== 'email@email.it') {
        $failures[] = 'normalizzazione email errata';
    }
    if (GitHubAssignmentEditorService::normalizeEmail('not-an-email') !== null) {
        $failures[] = 'email malformata accettata';
    }
    if (GitHubAssignmentEditorService::normalizeUsername(' mario_rossi ') !== 'mario_rossi') {
        $failures[] = 'normalizzazione username errata';
    }
    if (GitHubAssignmentEditorService::normalizeUsername('utente con spazi') !== null) {
        $failures[] = 'username GitHub malformato accettato';
    }
}

$columns = SchemaDefinitions::getSheetColumns('GITHUB_ASSIGNMENT_STUDENT_LINKS') ?? [];
if (!in_array('email_studente', $columns, true)) {
    $failures[] = 'colonna email_studente assente dallo schema';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: contratto editor assignment GitHub verificato.\n");
