<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$page = file_get_contents($root . '/public/github_assignment_review.php') ?: '';
$bootstrap = file_get_contents($root . '/bootstrap.php') ?: '';
$failures = [];

$pageGate = strpos($page, "define('REQUIRES_CLASSEVIVA_FOR_TEST'");
$bootstrapRequire = strpos($page, "require_once '../bootstrap.php'");
if ($pageGate === false || $bootstrapRequire === false || $pageGate > $bootstrapRequire) {
    $failures[] = 'la review deve passare il test al bootstrap prima di caricarlo';
}
if (!str_contains($bootstrap, 'REQUIRES_CLASSEVIVA_FOR_TEST')) {
    $failures[] = 'il bootstrap deve risolvere l’UDA associata al test per attivare il gate ClasseViva';
}
if (!str_contains($page, "external_ids")) {
    $failures[] = 'la review deve associare anche gli alias esterni del resolver ai nomi runtime';
}

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: gate ClasseViva condizionale nella review GitHub verificato.\n");
