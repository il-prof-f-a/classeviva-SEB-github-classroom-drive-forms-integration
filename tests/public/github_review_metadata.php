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
$require(str_contains($review, 'data-sort-key="student"') && str_contains($review, 'Studente/Voto'), 'intestazione Studente/Voto non predisposta all’ordinamento');
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
$require(str_contains($review, 'usort($rawCommits'), 'commit non ordinati cronologicamente lato server');
$require(str_contains($review, 'usort($rawIssues'), 'issue non ordinate cronologicamente lato server');
$require(str_contains($review, 'strtotime($leftDate)'), 'ordinamento cronologico non basato sulla data');
$require(str_contains($review, 'metadataIdentities'), 'metadati review senza attribuzione anticipata dello studente');
$require(str_contains($review, 'attributeCommits'), 'commit metadati non colorati prima del rendering');
$require(str_contains($review, 'attributeBranches'), 'branch metadati non colorati prima del rendering');
$require(str_contains($review, 'attributeIssues'), 'issue metadati non colorate prima del rendering');
$require(str_contains($normalizer, 'extractIssueReferences'), 'parser riferimenti issue assente');
$require(str_contains($normalizer, 'mapTagsBySha'), 'mapping tag SHA assente');
$require(str_contains($review, 'formatContributionMetric'), 'formatter n di m assente');
$require(str_contains($review, 'data-student-owned'), 'attributo ownership assente');
$require(str_contains($review, 'commit-other'), 'stile commit altri autori assente');
$require(str_contains($review, "action: 'repo_contributions'"), 'client contributi assente');
$require(str_contains($review, 'student_additions'), 'metriche studente assenti');
$require(str_contains($review, 'githubMetadataCache'), 'cache globale repository assente');
$require(str_contains($review, 'compareCommits'), 'origine branch non analizzata');
$require(str_contains($review, 'github-issue-toggle'), 'titolo issue non espandibile');
$require(str_contains($review, 'github-issue-details'), 'dettagli issue collassabili assenti');
$require(str_contains($review, 'data-metadata-collapse="issues"'), 'pannello issue senza contenitore collassabile');
$require(str_contains($review, 'github-issue-panel-footer'), 'barra inferiore issue assente');
$require(str_contains($review, 'github-issue-panel-toggle-bottom'), 'pulsante inferiore per comprimere le issue assente');
$require(str_contains($review, 'justify-content: flex-start;'), 'pulsante inferiore issue non allineato a sinistra');
$require(str_contains($review, 'scrollFirstCommitIntoView'), 'scroll sul primo commit dopo la compressione issue assente');
$require(str_contains($review, 'firstCommit.scrollIntoView'), 'scroll fluido sul primo commit assente');
$require(str_contains($review, 'metadata_totals'), 'totali metadati non conservati durante lo streaming');
$require(str_contains($review, 'countStudentOwnedMetadata'), 'conteggio contributi studente nel riepilogo assente');
$require(str_contains($review, "Commit: ' + escapeHtml(commitOwnedCount) + ' di '"), 'riepilogo commit senza forma studente di totale');
$require(str_contains($review, "Branch: ' + escapeHtml(branchOwnedCount) + ' di '"), 'riepilogo branch senza forma studente di totale');
$require(str_contains($review, "Issue: ' + escapeHtml(issueOwnedCount) + ' di '"), 'riepilogo issue senza forma studente di totale');
$require(str_contains($review, "'total' => count(\$rawCommits)"), 'totale commit non inviato nello stream');
$require(str_contains($review, "'metadata_totals' =>"), 'totali metadati assenti dalla risposta finale');
$require(str_contains($review, 'github-metadata-panel-loading'), 'pannello issue senza stato lampeggiante di caricamento');
$require(str_contains($review, 'github-meta-toggle'), 'titolo commit non espandibile');
$require(str_contains($review, 'github-meta-details'), 'dettagli commit collassabili assenti');
$require(str_contains($review, "const expandedClass = studentOwned ? ' show' : '';"), 'stato iniziale commit non legato all\'attribuzione');
$require(str_contains($review, "const issueExpandedClass = issueOwned ? ' show' : '';"), 'stato iniziale issue non legato all\'attribuzione');
$require(str_contains($review, 'github-worktree-graph'), 'contenitore grafo worktree assente');
$require(str_contains($review, 'renderWorktreeGraph'), 'renderer grafo worktree assente');
$require(str_contains($review, 'github-graph-node'), 'nodo grafo worktree assente');
$require(str_contains($review, 'github-graph-node-pending'), 'nodi DAG senza attribuzione non marcati come in corso');
$require(str_contains($review, "row.querySelector('.github-worktree-graph')"), 'il grafo non viene cercato sulla riga della tabella');
$require(str_contains($review, 'buildWorktreeGraphSvg'), 'renderer SVG DAG assente');
$require(str_contains($review, 'github-worktree-graph-svg'), 'SVG worktree assente');
$require(str_contains($review, 'github-graph-edge'), 'archi DAG assenti');
$require(str_contains($review, 'github-graph-edge-pending'), 'archi DAG senza attribuzione non marcati come in corso');
$require(str_contains($review, 'github-graph-lane-main'), 'corsia main assente');
$require(!str_contains($review, 'max-height: 26rem'), 'altezza fissa del pannello worktree ancora presente');
$require(str_contains($review, "'parents'"), 'parent commit non incluso nei metadati');
$require(str_contains($review, "'head_sha'"), 'head branch non incluso nei metadati');

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
