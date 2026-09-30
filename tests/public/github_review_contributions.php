<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$review = file_get_contents($root . '/public/github_assignment_review.php');
$attribution = file_get_contents($root . '/src/Core/GitHubContributionAttribution.php');
$envExample = file_get_contents($root . '/.env.example');
$configEnvExample = file_get_contents($root . '/config/.env.example');
if ($review === false || $attribution === false || $envExample === false || $configEnvExample === false) {
    fwrite(STDERR, "FAIL: impossibile leggere i file della review contributi\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($review, "postAction === 'repo_contributions'"), 'endpoint contributi assente');
$require(str_contains($review, "action: 'repo_contributions'"), 'client contributi assente');
$require(str_contains($review, 'Cache-Control: no-store'), 'contributi cachabili');
$require(str_contains($review, 'student_id'), 'student_id non validato');
$require(str_contains($review, 'GitHubContributionAttribution'), 'classificatore non usato');
$require(str_contains($review, 'student_owned'), 'stato ownership non restituito');
$require(str_contains($review, 'assignment_student'), 'controllo ownership assignment assente');

$require(str_contains($attribution, 'attributeCommits'), 'classificatore commit assente');
$require(str_contains($attribution, 'attributeBranches'), 'classificatore branch assente');
$require(str_contains($attribution, 'attributeIssues'), 'classificatore issue assente');
$require(str_contains($review, 'GitHubBlameLocAttributor'), 'attribuzione LOC blame non usata');
$require(str_contains($review, 'student_loc_disabled'), 'flag disattivazione LOC studente assente');
$require(str_contains($review, 'GITHUB_STUDENT_LOC_MAX_MS'), 'soglia temporale LOC studente assente');
$require(str_contains($review, "env('GITHUB_STUDENT_LOC_MAX_MS', '20000')"), 'soglia LOC studente non impostata a 20 secondi');
$require(str_contains($envExample, 'GITHUB_STUDENT_LOC_MAX_MS=20000'), '.env.example non allineato a 20 secondi');
$require(str_contains($configEnvExample, 'GITHUB_STUDENT_LOC_MAX_MS=20000'), 'config/.env.example non allineato a 20 secondi');
$require(str_contains($review, 'GITHUB_STUDENT_LOC_MAX_FILES'), 'limite file LOC studente assente');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: endpoint contributi GitHub Review.\n");
