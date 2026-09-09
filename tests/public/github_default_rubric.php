<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$rubricPage = file_get_contents($root . '/public/github_rubriche.php');
$reviewPage = file_get_contents($root . '/public/github_assignment_review.php');
if ($rubricPage === false || $reviewPage === false) {
    fwrite(STDERR, "FAIL: impossibile leggere le pagine delle rubriche GitHub\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$defaultIndicatorPos = strpos($rubricPage, "'nome' => 'Integrazione e collaborazione'");
$defaultIndicatorBlock = $defaultIndicatorPos === false ? '' : substr($rubricPage, $defaultIndicatorPos, 1800);
$require($defaultIndicatorPos !== false, 'indicatore Integrazione e collaborazione assente nella rubrica default');
$require((bool)preg_match("/'peso'\s*=>\s*'15'/", $defaultIndicatorBlock), 'peso default Integrazione e collaborazione diverso da 15');
$require(str_contains($rubricPage, 'Carica Rubrica di default'), 'etichetta pulsante rubrica default non aggiornata');

$reviewIndicatorPos = strpos($reviewPage, "'nome_indicatore' => 'Integrazione e collaborazione'");
$reviewIndicatorBlock = $reviewIndicatorPos === false ? '' : substr($reviewPage, $reviewIndicatorPos, 1800);
$require((bool)preg_match("/'peso'\s*=>\s*'15'/", $reviewIndicatorBlock), 'peso default review GitHub diverso da 15');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: rubrica GitHub default con peso collaborazione 15.\n");
