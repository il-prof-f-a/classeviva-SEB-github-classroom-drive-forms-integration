<?php

declare(strict_types=1);

// Verifica UI e sincronizzazione per la selezione esplicita dei voti GitHub.
$root = dirname(__DIR__, 2);
$source = file_get_contents($root . '/public/github_assignment_review.php');
if ($source === false) {
    fwrite(STDERR, "FAIL: impossibile leggere public/github_assignment_review.php\n");
    exit(1);
}

$failures = [];
$require = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$require((bool)preg_match('/id="review-show-names"[^>]*checked/', $source), 'flag mostra nomi non attivo di default');
$require(str_contains($source, 'name="salva_voto['), 'checkbox di selezione voto assente');
$require(str_contains($source, 'id="saveVotesModal"'), 'popup riepilogo voti assente');
$require(str_contains($source, 'Salva voti selezionati'), 'dicitura Salva voti selezionati assente');
$require(str_contains($source, 'save-vote-checkbox'), 'classe checkbox voto assente');
$require(str_contains($source, 'syncSaveVoteCheckbox'), 'sincronizzazione checkbox non definita');
$require(str_contains($source, 'rubricApplyBtn') && str_contains($source, 'saveVoteCheckbox.checked = true'), 'applicazione rubrica non seleziona il voto');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: selezione voti e riepilogo review GitHub.\n");
