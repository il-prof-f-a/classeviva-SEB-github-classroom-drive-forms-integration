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
$require(str_contains($review, 'col.col-comment { width: 0; }'), 'colonna commento non ridotta a zero');
$require(str_contains($review, 'rubric-panel-open'), 'layout dinamico della colonna commento assente');
$require(str_contains($review, 'comment-col" aria-hidden="true"'), 'colonna commento non mantenuta vuota');
$require(!str_contains($review, 'name="commento['), 'input commento ancora presente nella Review');
$require(str_contains($review, 'col-student-vote'), 'colonna accorpata Studente/Voto assente');
$require(str_contains($review, '<th class="student-vote-col">Studente/Voto</th>'), 'intestazione Studente/Voto non rinominata');
$require(!str_contains($review, '<td class="vote-col">'), 'vecchia colonna voto ancora presente');
$require(substr_count($review, 'name="id_studente[') === 1, 'campo id studente duplicato dopo l’accorpamento');
$require(str_contains($review, 'col.col-student-vote { width: 14%; }'), 'larghezza colonna Studente/Voto non impostata');
$require(str_contains($review, 'col.col-repo { width: 86%; }'), 'spazio residuo non assegnato alla colonna Repo');
$require(str_contains($review, 'body.rubric-panel-open table.github-grades-table col.col-repo { width: 36%; }'), 'larghezza Repo a pannello aperto non impostata');
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
$require(str_contains($review, 'formatContributionMetric'), 'formatter n di m assente');
$require(str_contains($review, 'data-student-owned'), 'attributo ownership assente');
$require(str_contains($review, 'commit-other'), 'stile commit altri autori assente');
$require(str_contains($review, "action: 'repo_contributions'"), 'client contributi assente');
$require(str_contains($review, 'student_additions'), 'metriche studente assenti');
$require(str_contains($review, 'githubMetadataCache'), 'cache globale repository assente');
$require(str_contains($review, 'compareCommits'), 'origine branch non analizzata');

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
