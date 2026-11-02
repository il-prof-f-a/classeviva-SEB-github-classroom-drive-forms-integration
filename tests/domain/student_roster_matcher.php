<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
if (!defined('ROOT_PATH')) {
    define('ROOT_PATH', $root);
}
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/'
        . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\StudentRosterMatcher;

$failures = [];
$anchorRoster = [
    ['external_user_id' => 'CV_2', 'nome' => 'Anna', 'cognome' => 'Bianchi'],
    ['external_user_id' => 'CV_3', 'nome' => 'Luca', 'cognome' => 'Verdi'],
    ['external_user_id' => 'CV_1', 'nome' => 'Mario', 'cognome' => 'Rossi'],
    ['external_user_id' => 'CV_4', 'nome' => 'Paolo', 'cognome' => 'Neri'],
    ['external_user_id' => 'CV_5', 'nome' => 'Marco', 'cognome' => 'Conti'],
];
$targetRoster = [
    ['external_user_id' => 'GC_3', 'display_name' => 'Luca Verdi'],
    ['external_user_id' => 'GC_1', 'display_name' => 'Mario Rossi'],
    ['external_user_id' => 'GC_2', 'display_name' => 'Anna Bianchi'],
    ['external_user_id' => 'GC_4', 'display_name' => 'Giulia Neri'],
    ['external_user_id' => 'GC_5', 'display_name' => 'Sofia Conti'],
    ['external_user_id' => 'GC_6', 'display_name' => 'Alice Neri'],
];

$sortedAnchors = StudentRosterMatcher::sortRoster($anchorRoster);
$sortedNames = array_map(
    static fn(array $entry): string => StudentRosterMatcher::displayName($entry),
    $sortedAnchors
);
if ($sortedNames !== ['Bianchi Anna', 'Conti Marco', 'Neri Paolo', 'Rossi Mario', 'Verdi Luca']) {
    $failures[] = 'lista ClasseViva non ordinata per cognome e nome';
}

$sortedTargets = StudentRosterMatcher::sortRoster($targetRoster);
$sortedTargetNames = array_map(
    static fn(array $entry): string => StudentRosterMatcher::displayName($entry),
    $sortedTargets
);
if ($sortedTargetNames !== ['Bianchi Anna', 'Conti Sofia', 'Neri Alice', 'Neri Giulia', 'Rossi Mario', 'Verdi Luca']) {
    $failures[] = 'lista Google Classroom non ordinata o non visualizzata come cognome nome';
}

$suggestions = StudentRosterMatcher::suggestMappings(
    $anchorRoster,
    $targetRoster,
    ['CV_3' => 'GC_3']
);
$expected = [
    'CV_1' => 'GC_1',
    'CV_2' => 'GC_2',
    'CV_3' => 'GC_3',
    // Il cognome Conti è il fallback univoco per un nome diverso.
    'CV_5' => 'GC_5',
];
foreach ($expected as $anchorId => $targetId) {
    if (($suggestions[$anchorId] ?? null) !== $targetId) {
        $failures[] = "mappatura esatta mancante per {$anchorId}";
    }
}
if (isset($suggestions['CV_4'])) {
    $failures[] = 'fallback per cognome ambiguo applicato erroneamente';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: ordinamento e preselezione roster deterministici.\n");
