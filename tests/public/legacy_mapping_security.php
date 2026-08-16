<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$pages = [
    'map_classes' => file_get_contents($root . '/public/map_classes.php') ?: '',
    'github_mapping' => file_get_contents($root . '/public/github_classroom_mapping.php') ?: '',
    'map_students' => file_get_contents($root . '/public/map_students.php') ?: '',
];
$failures = [];
$require = static function (string $page, string $needle, string $message) use (&$failures, $pages): void {
    if (!str_contains($pages[$page] ?? '', $needle)) {
        $failures[] = $message;
    }
};

foreach (array_keys($pages) as $page) {
    $require($page, 'hash_equals', "$page non verifica il token CSRF con hash_equals");
    if (!preg_match('/name=["\']csrf_token["\']/i', $pages[$page])) {
        $failures[] = "$page non espone il campo csrf_token";
    }
}

$require('github_mapping', "REQUEST_METHOD'] === 'POST'", 'delete GitHub non e\u0300 vincolato a POST');
$require('github_mapping', "'action') === 'delete'", 'delete GitHub non e\u0300 vincolato all\'azione delete');
$require('github_mapping', "listGithubClassroomMappings()", 'delete GitHub non verifica ownership della mappatura');
$require('github_mapping', 'listAssignments', 'GitHub non limita l\'assignment al roster selezionato');
$require('github_mapping', 'listAcceptedAssignments', 'GitHub non verifica il roster accettato prima di modificare');
$require('map_students', 'TeachingGroupCatalogService', 'map_students non valida il gruppo con il catalogo owner-scoped');
$require('map_students', 'findForWizard', 'map_students non verifica ownership/stato del gruppo');
$require('map_classes', 'TeachingGroupCatalogService', 'map_classes non usa il catalogo provider-neutral');

foreach (['map_classes', 'github_mapping', 'map_students'] as $page) {
    if (preg_match('/trim\(\s*\$_(?:POST|GET)\b/', $pages[$page])) {
        $failures[] = "$page applica trim direttamente a input non normalizzato";
    }
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: sicurezza pagine legacy provider-neutral.\n");
