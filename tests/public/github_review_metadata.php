<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$review = file_get_contents($root . '/public/github_assignment_review.php');
$integration = file_get_contents($root . '/src/Integration/GitHubIntegration.php');
$normalizer = file_get_contents($root . '/src/Core/GitHubReviewMetadata.php');
if ($review === false || $integration === false || $normalizer === false) {
    fwrite(STDERR, "FAIL: impossibile leggere i file della review GitHub\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($review, "postAction === 'repo_metadata'"), 'handler repo_metadata assente');
$require(str_contains($review, "action: 'repo_metadata'"), 'client repo_metadata assente');
$require(str_contains($review, 'Csrf::assertValid'), 'repo_metadata senza validazione CSRF');
$require(str_contains($review, 'github-review-metadata'), 'contenitore metadati repository assente');
$require(str_contains($review, 'github-commits-list'), 'lista commit dinamica assente');
$require(str_contains($review, 'bi-exclamation-circle'), 'icona issue assente');
$require(str_contains($review, 'github-tag-badge'), 'label tag azzurra assente');
$require(str_contains($review, 'github-issue-link'), 'link issue arancione assente');
$require(str_contains($review, 'github-branch-link'), 'link branch verde assente');
$require(str_contains($review, '.repo-loc {'), 'pannello LOC colorato assente');
$require(str_contains($review, '#fff1f3'), 'colore rosa LOC assente');
$require(str_contains($review, 'Branch origine (PR)'), 'indicazione branch PR assente');
$require(str_contains($review, 'Branch attuale (HEAD)'), 'indicazione branch HEAD assente');
$require(str_contains($review, 'Origine non determinabile'), 'messaggio origine non determinabile assente');
$require(str_contains($review, 'Troppe richieste di metadati'), 'rate limit metadati assente');
$require(str_contains($review, 'OutputEncoder::json($csrfToken)'), 'token CSRF non codificato nel bridge JS');

foreach ([
    'listRepoIssues',
    'listRepoIssuesAll',
    'listIssueTimeline',
    'listRepoBranches',
    'listRepoTags',
    'listCommitBranches',
    'listCommitPullRequests',
    'listRepoCommitsAll',
] as $method) {
    $require(str_contains($integration, 'function ' . $method), 'metodo GitHub mancante: ' . $method);
}
$require(str_contains($integration, '/issues/{$issueNumber}/timeline'), 'endpoint timeline issue non corretto');
$require(str_contains($integration, '/branches-where-head'), 'endpoint branch HEAD non corretto');
$require(str_contains($review, "listRepoIssuesAll(\$owner, \$repo, 'all'"), 'issue non richieste con stato all');
$require(str_contains($normalizer, 'extractIssueReferences'), 'parser riferimenti issue assente');
$require(str_contains($normalizer, 'mapTagsBySha'), 'mapping tag SHA assente');

// Il JSON incorporato deve passare dall'encoder centrale, non da json_encode
// grezzo nel markup della pagina.
$require(!preg_match('/<\?=\s*json_encode\s*\(/', $review), 'json_encode grezzo nel markup review');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: endpoint e UI metadati GitHub Review.\n");
