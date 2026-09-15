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

use App\Core\GitHubReviewMetadata;

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$refs = GitHubReviewMetadata::extractIssueReferences(
    "Fix #12 and closes octo/demo#13\n\nRelated to #12.",
    'octo',
    'demo'
);
$require(array_column($refs, 'number') === [12, 13], 'i riferimenti issue devono essere ordinati e deduplicati');
$require(($refs[0]['url'] ?? '') === 'https://github.com/octo/demo/issues/12', 'URL issue locale non corretto');
$require(($refs[1]['url'] ?? '') === 'https://github.com/octo/demo/issues/13', 'URL issue qualificato non corretto');
$require(($refs[0]['icon'] ?? '') === 'bi bi-exclamation-circle', 'icona issue non normalizzata');

$tags = GitHubReviewMetadata::mapTagsBySha([
    ['name' => 'v1.0', 'commit' => ['sha' => 'aaa1111']],
    ['name' => 'release', 'commit' => ['sha' => 'aaa1111']],
    ['name' => 'v2.0', 'commit' => ['sha' => 'bbb2222']],
    ['name' => '', 'commit' => ['sha' => 'ignored']],
]);
$require($tags === ['aaa1111' => ['v1.0', 'release'], 'bbb2222' => ['v2.0']], 'tag per SHA non normalizzati/deduplicati');

$issues = GitHubReviewMetadata::normalizeIssues([
    ['number' => 12, 'title' => 'Bug', 'state' => 'open', 'created_at' => '2026-09-01T10:00:00Z', 'closed_at' => null, 'body' => 'Descrizione', 'user' => ['login' => 'student']],
    ['number' => 12, 'title' => 'Bug duplicato', 'state' => 'open'],
    ['number' => 14, 'title' => 'PR masquerade', 'pull_request' => ['url' => 'https://api.github.com/pulls/14']],
], [
    12 => [
        ['event' => 'referenced', 'commit_id' => 'aaa1111', 'commit_url' => 'https://api.github.com/repos/octo/demo/commits/aaa1111'],
        ['event' => 'referenced', 'commit_id' => 'aaa1111', 'commit_url' => 'https://api.github.com/repos/octo/demo/commits/aaa1111'],
        ['event' => 'labeled', 'commit_id' => null],
    ],
], 'octo', 'demo');
$require(count($issues) === 1, 'le pull request non devono essere trattate come issue');
$require(($issues[0]['number'] ?? 0) === 12, 'numero issue non conservato');
$require(($issues[0]['author_login'] ?? '') === 'student', 'autore issue non conservato');
$require(count($issues[0]['commits'] ?? []) === 1, 'commit issue non deduplicati');
$require(($issues[0]['commits'][0]['url'] ?? '') === 'https://github.com/octo/demo/commit/aaa1111', 'URL commit issue non normalizzato');

$origin = GitHubReviewMetadata::resolveBranchOrigin(
    [['head' => ['ref' => 'feature/login']]],
    [['name' => 'main'], ['name' => 'release']]
);
$require(($origin['label'] ?? '') === 'feature/login', 'branch head della PR non ha precedenza');
$require(($origin['source'] ?? '') === 'pull_request', 'sorgente branch PR non indicata');

$current = GitHubReviewMetadata::resolveBranchOrigin([], [['name' => 'main'], ['name' => 'release']]);
$require(($current['label'] ?? '') === 'main, release', 'branch HEAD non concatenati');
$require(($current['source'] ?? '') === 'head_branches', 'sorgente branch HEAD non indicata');

$unknown = GitHubReviewMetadata::resolveBranchOrigin([], []);
$require(($unknown['label'] ?? '') === 'Origine non determinabile', 'origine non determinabile non dichiarata');
$require(($unknown['source'] ?? '') === 'unknown', 'sorgente origine non determinabile non indicata');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: normalizzazione metadati GitHub Review.\n");
