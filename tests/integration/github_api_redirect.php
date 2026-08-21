<?php

declare(strict_types=1);

// Regressione: l'API GitHub deve seguire i redirect 301/302 (rinomina/trasferimento
// di repo od organizzazione) restando su HTTPS e sullo stesso host api.github.com,
// altrimenti listRepoCommits/getCommit falliscono con "Moved Permanently".
$file = dirname(__DIR__, 2) . '/src/Integration/GitHubIntegration.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere GitHubIntegration.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($source, 'CURLOPT_FOLLOWLOCATION, true'), 'apiRequest non segue i redirect');
$require(str_contains($source, 'CURLOPT_MAXREDIRS'), 'apiRequest senza limite redirect');
$require(str_contains($source, 'CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS'), 'apiRequest non limita i redirect a HTTPS');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: API GitHub segue i redirect HTTPS interni (repo rinominate).
");
