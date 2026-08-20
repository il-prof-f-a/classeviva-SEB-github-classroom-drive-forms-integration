<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$import = file_get_contents($root . '/public/import_form_results.php') ?: '';
$preview = file_get_contents($root . '/public/import_form_results_step_preview_grades.php') ?: '';
$analysis = file_get_contents($root . '/public/test_cbm_analysis.php') ?: '';
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
checkFormsScoreContract(
    str_contains($preview, "\$response['max_score']"),
    'anteprima non usa il massimo autorevole delle risposte'
);
checkFormsScoreContract(
    !str_contains($preview, 'array_sum($questionWeights)'),
    'anteprima ricostruisce ancora il denominatore dai dettagli osservati'
);
checkFormsScoreContract(
    !str_contains($preview, "['classic_total'] += floatval(\$detail['classic_score']) * \$w"),
    'anteprima moltiplica due volte i punti classici per il peso della domanda'
);
checkFormsScoreContract(
    str_contains($analysis, 'use App\Core\GoogleFormScoreNormalizer;'),
    'analisi non usa il normalizzatore condiviso'
);
checkFormsScoreContract(
    str_contains($analysis, 'GoogleFormScoreNormalizer::extractPersistedWeights($mappingRows)'),
    'analisi non legge i pesi persistiti nel mapping'
);
checkFormsScoreContract(
    str_contains($analysis, 'GoogleFormScoreNormalizer::extractQuestionWeights'),
    'analisi non recupera i pesi mancanti dalla struttura Forms'
);
checkFormsScoreContract(
    str_contains($analysis, '$needsFormMetadata = empty($rows) || empty($mapping)'),
    'analisi non tenta il recupero Forms quando il mapping storico manca del tutto'
);
checkFormsScoreContract(
    !str_contains($analysis, '$scoreClassic > $questionWeights'),
    'analisi inferisce ancora il peso massimo dai risultati osservati'
);
checkFormsScoreContract(
    !str_contains($analysis, '$questionWeights[$qId] = 1.0'),
    'analisi inventa ancora un peso unitario per le domande senza risposte corrette'
);
checkFormsScoreContract(
    str_contains($analysis, 'GoogleFormScoreNormalizer::totalPoints($questionWeights)'),
    'analisi non usa il totale autorevole dei pesi configurati'
);
checkFormsScoreContract(
    str_contains($analysis, '$punteggioMax = floatval($test['),
    'analisi non inizializza il punteggio massimo mostrato nel riepilogo'
);
checkFormsScoreContract(
    str_contains($analysis, 'Impossibile completare l’analisi CBM:')
        && str_contains($analysis, 'alert alert-warning'),
    'analisi non mostra gli errori sui pesi anche quando esistono risposte salvate'
);

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

fwrite(STDOUT, "PASS: import Google Forms usa i punti configurati.\n");
