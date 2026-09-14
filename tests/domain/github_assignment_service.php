<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
require $root . '/src/Core/GitHubAssignmentService.php';
require $root . '/src/Core/StudentEmailResolver.php';

use App\Core\GitHubAssignmentService;

$failures = [];

// --- pure helpers ---
if (GitHubAssignmentService::repoPrefix('Prova Lunga Assignment') !== 'prova-lunga-assignment') {
    $failures[] = 'repoPrefix errato';
}
if (!str_starts_with(GitHubAssignmentService::assignmentSlug('Esempio'), 'esempio-')) {
    $failures[] = 'assignmentSlug senza prefisso';
}
if (strlen(GitHubAssignmentService::generateAcceptanceCode()) !== 32) {
    $failures[] = 'acceptanceCode lunghezza errata';
}

$used = [];
$names = [];
for ($i = 0; $i < 20; $i++) {
    $names[] = GitHubAssignmentService::repoName('Esempio', $used);
}
if (count(array_unique($names)) !== 20) {
    $failures[] = 'repoName non univoco';
}
foreach ($names as $n) {
    if (!str_starts_with($n, 'esempio-stud-') || strlen($n) > 100) {
        $failures[] = 'repoName formato errato: ' . $n;
    }
}

// --- resolveStudents ---
$svc = new GitHubAssignmentService('{cognome}.{nome}@{domain}', 'istituto.it');
$matrix = [
    ['id_studente' => 'STD_1', 'identities' => [['provider' => 'google_classroom', 'external_user_id' => 'G1']]],
    ['id_studente' => 'STD_2', 'identities' => [['provider' => 'classeviva', 'external_user_id' => 'CV2']]],
    ['id_studente' => 'STD_3', 'identities' => [['provider' => 'classeviva', 'external_user_id' => 'CV9']]],
    // STD_4 ha entrambe le identità: il default usa ClasseViva, il parametro lista_preferita usa Google.
    ['id_studente' => 'STD_4', 'identities' => [
        ['provider' => 'classeviva', 'external_user_id' => 'CV4'],
        ['provider' => 'google_classroom', 'external_user_id' => 'G4'],
    ]],
];
$rosters = [
    'google_classroom' => [['id' => 'G1', 'name' => 'Mario Rossi', 'email' => 'email@email.it'], ['id' => 'G4', 'name' => 'Maria Bianchi', 'email' => 'email@email.it']],
    'classeviva' => [['id' => 'CV2', 'nome' => 'Anna', 'cognome' => 'Bianchi'], ['id' => 'CV4', 'nome' => 'Maria', 'cognome' => 'Bianchi']],
];
$resolved = $svc->resolveStudents($matrix, $rosters);
$byId = [];
foreach ($resolved as $r) {
    $byId[$r['id_studente']] = $r;
}
if (($byId['STD_1']['email'] ?? '') !== 'email@email.it') {
    $failures[] = 'GC email errata';
}
if (($byId['STD_2']['email'] ?? '') !== 'email@email.it') {
    $failures[] = 'CV email errata: ' . ($byId['STD_2']['email'] ?? '(vuota)');
}
if (($byId['STD_3']['email'] ?? '') !== '') {
    $failures[] = 'studente non in roster deve avere email vuota';
}
if (($byId['STD_4']['email'] ?? '') !== 'email@email.it') {
    $failures[] = 'default (ClasseViva prima) errato: ' . ($byId['STD_4']['email'] ?? '(vuota)');
}

// Con lista_preferita='google_classroom' deve vincere l'email Google reale.
$resolvedGoogle = $svc->resolveStudents($matrix, $rosters, 'google_classroom');
$byIdGoogle = [];
foreach ($resolvedGoogle as $r) {
    $byIdGoogle[$r['id_studente']] = $r;
}
if (($byIdGoogle['STD_4']['email'] ?? '') !== 'email@email.it') {
    $failures[] = 'lista_preferita google_classroom errata: ' . ($byIdGoogle['STD_4']['email'] ?? '(vuota)');
}

// Un gruppo collegato solo a Google Classroom può non avere ancora righe in
// GRUPPI_STUDENTI: il roster deve comunque essere utilizzabile per creare
// l'assignment, usando l'identificativo esterno come chiave runtime.
$resolvedRosterOnly = $svc->resolveStudents([], [
    'google_classroom' => [
        ['id' => 'G_ONLY_1', 'name' => 'Studente Classroom', 'email' => 'email@email.it'],
    ],
], 'google_classroom');
if (count($resolvedRosterOnly) !== 1
    || ($resolvedRosterOnly[0]['id_studente'] ?? '') !== 'G_ONLY_1'
    || ($resolvedRosterOnly[0]['nome'] ?? '') !== 'Studente Classroom'
    || ($resolvedRosterOnly[0]['email'] ?? '') !== 'email@email.it') {
    $failures[] = 'gruppo solo Google Classroom senza membership non risolto dal roster';
}

if ($failures !== []) {
    foreach ($failures as $f) {
        fwrite(STDERR, "FAIL: {$f}\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: GitHubAssignmentService (naming + risoluzione email provider-neutral).\n");
