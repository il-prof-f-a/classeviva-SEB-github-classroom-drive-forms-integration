<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'App\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

use App\Core\GitHubContributionAttribution;
use App\Core\GitHubBlameLocAttributor;

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$identities = GitHubContributionAttribution::normalizeIdentities(
    ['Student'],
    ['email@email.it']
);
$globalLoc = [
    'total' => 20,
    'blank' => 2,
    'comment' => 4,
    'code' => 14,
    'files' => 3,
];

$result = GitHubContributionAttribution::attributeCommits([
    ['sha' => 'aaaaaaaa', 'author_login' => 'student', 'author_email' => 'email@email.it', 'additions' => 7],
    ['sha' => 'bbbbbbbb', 'author_login' => 'other', 'author_email' => 'email@email.it', 'additions' => 9],
    ['sha' => 'cccccccc', 'author_login' => null, 'author_email' => null, 'additions' => 4],
], $identities, $globalLoc);

$require($result['student_additions'] === 7, 'solo le additions dello studente devono essere sommate');
$require($result['owned_shas'] === ['aaaaaaaa'], 'solo il commit attribuibile deve risultare owned');
$require($result['global']['total'] === 20, 'le LOC globali devono restare separate');
$require($result['partial'] === false, 'il risultato completo non deve essere parziale');
$require($result['commits'][0]['student_owned'] === true, 'commit dello studente non classificato');
$require($result['commits'][1]['student_owned'] === false, 'commit di altro autore classificato come studente');
$require($result['commits'][2]['attribution_state'] === 'unknown', 'autore assente non marcato unknown');

$coauthored = [
    'sha' => 'eeeeeeee',
    'author_login' => 'other',
    'author_email' => 'email@email.it',
    'committer_login' => 'other',
    'committer_email' => 'email@email.it',
    'message' => "Implementazione condivisa\n\nCo-authored-by: Student Name <email@email.it>",
    'additions' => 5,
];
$require(
    GitHubContributionAttribution::commitOwner($coauthored, $identities) === 'student',
    'un commit co-autore dello studente deve essere attribuito anche allo studente'
);
$coauthoredResult = GitHubContributionAttribution::attributeCommits([$coauthored], $identities, $globalLoc);
$require($coauthoredResult['commits'][0]['student_owned'] === true, 'commit co-autore non colorato come contributo dello studente');
$require($coauthoredResult['student_additions'] === 5, 'LOC del commit co-autore non conteggiate per lo studente');

$partial = GitHubContributionAttribution::attributeCommits(
    [['sha' => 'dddddddd', 'author_login' => 'student', 'additions' => 2]],
    $identities,
    $globalLoc,
    true
);
$require($partial['partial'] === true, 'cronologia parziale non dichiarata');
$require($partial['warnings'] !== [], 'cronologia parziale senza warning');

$branches = GitHubContributionAttribution::attributeBranches([
    ['name' => 'feature/student', 'first_unique_commit_author_login' => 'student'],
    ['name' => 'feature/other', 'first_unique_commit_author_login' => 'other'],
    ['name' => 'feature/unknown', 'first_unique_commit_author_login' => null],
], $identities);
$require($branches[0]['student_owned'] === true, 'branch dello studente non attribuito');
$require($branches[1]['student_owned'] === false, 'branch di altro autore attribuito allo studente');
$require($branches[2]['attribution_state'] === 'unknown', 'branch senza autore non marcato unknown');

$issues = GitHubContributionAttribution::attributeIssues([
    ['number' => 1, 'author_login' => 'student'],
    ['number' => 2, 'author_login' => 'other'],
    ['number' => 3, 'author_login' => null],
], $identities);
$require($issues[0]['student_owned'] === true, 'issue dello studente non attribuita');
$require($issues[1]['student_owned'] === false, 'issue di altro autore attribuita allo studente');
$require($issues[2]['attribution_state'] === 'unknown', 'issue senza autore non marcata unknown');

$blameLoc = GitHubBlameLocAttributor::aggregate([
    [
        'path' => 'src/Main.java',
        'language' => 'Java',
        'line_types' => ['code', 'comment', 'blank', 'code'],
        'ranges' => [
            [
                'starting_line' => 1,
                'ending_line' => 2,
                'commit' => [
                    'oid' => 'studentsha',
                    'author_login' => 'student',
                    'author_email' => 'email@email.it',
                    'message' => 'Codice studente',
                ],
            ],
            [
                'starting_line' => 3,
                'ending_line' => 4,
                'commit' => [
                    'oid' => 'othersha',
                    'author_login' => 'other',
                    'author_email' => 'email@email.it',
                    'message' => 'Codice altro',
                ],
            ],
        ],
    ],
], $identities);
$require($blameLoc['enabled'] === true, 'attribuzione blame non abilitata');
$require($blameLoc['totals']['total'] === 4, 'totale righe blame errato');
$require($blameLoc['student']['total'] === 2, 'LOC studente blame errate');
$require($blameLoc['student']['code'] === 1, 'LOC codice studente blame errate');
$require($blameLoc['student']['comment'] === 1, 'commenti studente blame errati');
$require($blameLoc['student']['blank'] === 0, 'righe blank studente blame errate');
$require($blameLoc['by_language']['Java']['student']['total'] === 2, 'riepilogo linguaggio blame errato');
$blameLocChunk = GitHubBlameLocAttributor::aggregate([
    [
        'path' => 'src/Other.java',
        'language' => 'Java',
        'line_types' => ['code', 'blank'],
        'ranges' => [[
            'starting_line' => 1,
            'ending_line' => 1,
            'commit' => ['author_login' => 'student'],
        ]],
    ],
], $identities);
$blameLocMerged = GitHubBlameLocAttributor::mergeAggregates($blameLoc, $blameLocChunk);
$require($blameLocMerged['totals']['total'] === 6, 'merge LOC a chunk: totale righe errato');
$require($blameLocMerged['student']['total'] === 3, 'merge LOC a chunk: LOC studente errate');
$require($blameLocMerged['by_language']['Java']['student']['total'] === 3, 'merge LOC a chunk: riepilogo linguaggio errato');
$require(
    GitHubBlameLocAttributor::lineTypesForText('Main.java', "// comment\n\nint x = 1;\n") === ['comment', 'blank', 'code'],
    'classificazione righe blame errata'
);
$require(GitHubBlameLocAttributor::languageForPath('Main.java') === 'Java', 'linguaggio blame errato');

$selectedSnapshot = GitHubBlameLocAttributor::selectSnapshotFiles([
    ['path' => 'src/First.php', 'size' => 4],
    ['path' => 'docs/Large.md', 'size' => 8],
    ['path' => 'src/Third.php', 'size' => 3],
], 3, 10);
$require($selectedSnapshot['files'] === [
    ['path' => 'src/First.php', 'bytes' => 4],
    ['path' => 'src/Third.php', 'bytes' => 3],
], 'selezione blame non rispetta il limite byte mantenendo i file analizzabili');
$require($selectedSnapshot['partial'] === true, 'selezione blame parziale non dichiarata');
$require($selectedSnapshot['skipped_files'] === 1, 'numero file blame saltati errato');
$require($selectedSnapshot['skipped_bytes'] === 8, 'byte blame saltati errati');

$selectedByCount = GitHubBlameLocAttributor::selectSnapshotFiles([
    ['path' => 'one.php', 'size' => 1],
    ['path' => 'two.php', 'size' => 1],
    ['path' => 'three.php', 'size' => 1],
], 2, 100);
$require(count($selectedByCount['files']) === 2, 'selezione blame non rispetta il limite file');
$require($selectedByCount['skipped_files'] === 1, 'file oltre il limite non conteggiato');

$disabledBlameLoc = GitHubBlameLocAttributor::disabled('timeout', [
    'files' => 2,
    'total' => 4,
]);
$require($disabledBlameLoc['enabled'] === false, 'disattivazione blame non rispettata');
$require($disabledBlameLoc['reason'] === 'timeout', 'motivo disattivazione blame errato');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: attribuzione contributi GitHub.\n");
