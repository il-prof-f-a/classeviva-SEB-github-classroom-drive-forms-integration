<?php

declare(strict_types=1);

// Regressione: la review GitHub deve mostrare i nomi degli studenti anche quando il
// gruppo didattico è collegato solo a GitHub Classroom (senza membership interne),
// facendo combaciare il roster del resolver centrale con le righe dello studentMap
// tramite il login GitHub (in aggiunta all'id interno).
$file = dirname(__DIR__, 2) . '/public/github_assignment_review.php';
$source = file_get_contents($file);
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere github_assignment_review.php
");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require(str_contains($source, 'resolveGroupStudents'), 'nomi non risolti via resolveGroupStudents');
$require(str_contains($source, 'strtolower($studentKey)'), 'manca la chiave secondaria per il login GitHub');
$require(str_contains($source, 'runtimeNamesByStudent[$lowerUser]'), 'manca il fallback del nome per username GitHub');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}
");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: review GitHub risolve i nomi dal roster con fallback username.
");
