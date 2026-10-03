<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$review = file_get_contents($root . '/public/github_assignment_review.php');
if ($review === false) {
    fwrite(STDERR, "FAIL: impossibile leggere github_assignment_review.php\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$cacheFunctionStart = strpos($review, 'function githubMetadataCacheKey');
$cacheFunctionEnd = $cacheFunctionStart === false
    ? false
    : strpos($review, "\n        }", $cacheFunctionStart);
$cacheFunction = $cacheFunctionStart === false || $cacheFunctionEnd === false
    ? ''
    : substr($review, $cacheFunctionStart, $cacheFunctionEnd - $cacheFunctionStart);

$require($cacheFunctionStart !== false, 'manca la funzione per la chiave cache dei metadati');
$require(str_contains($cacheFunction, 'studentId'), 'la chiave cache non riceve lo studente');
$require(
    preg_match('/return .*StudentId/s', $cacheFunction) === 1,
    'la chiave cache non distingue le attribuzioni dei diversi studenti'
);
$require(
    preg_match('/githubMetadataCacheKey\(repoFull, ref, studentId\)/', $review) === 1,
    'loadRepoMetadata non usa lo studente nella chiave cache'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: cache metadati GitHub distinta per studente.\n");
