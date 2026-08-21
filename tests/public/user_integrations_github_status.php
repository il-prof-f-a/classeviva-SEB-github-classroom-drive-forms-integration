<?php

declare(strict_types=1);

// Verifica statica che GitHub sia "warning" (giallo) quando ci sono Client ID e
// Client Secret ma nessun token valido, e "valid" solo quando c'e' anche il
// token. Verifica inoltre la label aggiornata del tab Google.
$file = dirname(__DIR__, 2) . '/public/user_integrations.php';
$source = file_get_contents($file);
$failures = [];
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere public/user_integrations.php\n");
    exit(1);
}
$required = [
    "'github' => (\$statusGithubOk && \$githubIsAuthenticated)",
    "'Google (Classroom, Drive e Forms)'",
];
foreach ($required as $marker) {
    if (strpos($source, $marker) === false) {
        $failures[] = "marker assente: " . $marker;
    }
}
if (strpos($source, "'Google (Drive / Forms)'") !== false) {
    $failures[] = "vecchia label 'Google (Drive / Forms)' ancora presente";
}
if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: " . $failure . "\n");
    }
    exit(1);
}
fwrite(STDOUT, "PASS: stato GitHub warning/valid e label Google aggiornata.\n");
