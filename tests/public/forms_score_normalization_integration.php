<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$import = file_get_contents($root . '/public/import_form_results.php') ?: '';
$failures = [];

function checkFormsScoreContract(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

checkFormsScoreContract(
    str_contains($import, 'use App\Core\GoogleFormScoreNormalizer;'),
    'import non carica GoogleFormScoreNormalizer'
);
checkFormsScoreContract(
    str_contains($import, 'GoogleFormScoreNormalizer::extractQuestionWeights'),
    'import non legge i pointValue configurati'
);
checkFormsScoreContract(
    str_contains($import, 'GoogleFormScoreNormalizer::normalize'),
    'import non usa la normalizzazione condivisa'
);
checkFormsScoreContract(
    !str_contains($import, 'computeMaxScoresPerQuestion'),
    'import inferisce ancora i massimi dalle risposte osservate'
);
checkFormsScoreContract(
    !str_contains($import, '$cbmTotal / $totalScore'),
    'CBM ancora diviso per il punteggio ottenuto dallo studente'
);
checkFormsScoreContract(
    str_contains($import, "['punteggio_max' => \$classicMax]"),
    'punteggio massimo del test non sincronizzato dal form'
);
checkFormsScoreContract(
    str_contains($import, "'max_score' => \$weight")
        && str_contains($import, "'punteggio_domanda' => \$weight"),
    'pesi configurati non persistiti nel mapping CBM'
);
checkFormsScoreContract(
    str_contains($import, 'foreach ($normalizedCbmMappingRows as $mappingRow)'),
    'import non aggiorna tutte le righe di mapping CBM, incluse le duplicate storiche'
);
checkFormsScoreContract(
    str_contains($import, '$w = $maxQ;')
        && str_contains($import, "'weight' => \$w"),
    'dettagli CBM non conservano il peso configurato'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: import Google Forms usa i punti configurati.\n");
