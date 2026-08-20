# Google Forms Score Normalization Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Calcolare punteggi classici e CBM sulla somma dei punti configurati nel Google Form, mantenendo coerenti importazione, anteprima, persistenza e analisi.

**Architecture:** Introdurre un servizio puro `GoogleFormScoreNormalizer` che estrae i `pointValue` dagli item Forms e calcola le percentuali su un unico denominatore autorevole. Le pagine esistenti continueranno a orchestrare API e database, ma non inferiranno più i pesi dalle risposte osservate; per il CBM i pesi saranno persistiti in `TEST_CBM_MAPPING.max_score` e riutilizzati dall'analisi.

**Tech Stack:** PHP 8.2, Google Forms API PHP client, database adapter esistente, test PHP senza PHPUnit tramite `tests/run.php`.

---

## File e responsabilità

- Create `src/Core/GoogleFormScoreNormalizer.php`: estrazione dei pesi configurati, validazione del totale e normalizzazione classica/CBM.
- Create `tests/domain/google_form_score_normalizer.php`: test unitari completi con fake degli oggetti Forms.
- Create `tests/public/forms_score_normalization_integration.php`: contratto statico che impedisce il ritorno ai massimi osservati e verifica l'integrazione delle tre pagine.
- Modify `tests/run.php`: registrazione dei due test.
- Modify `public/import_form_results.php`: uso del normalizzatore, sincronizzazione del massimo e persistenza dei pesi CBM.
- Modify `public/import_form_results_step_preview_grades.php`: uso del massimo autorevole delle risposte invece della somma ricostruita dai dettagli.
- Modify `public/test_cbm_analysis.php`: lettura dei pesi dal mapping e recupero dalla struttura Forms quando mancano.
- Modify `.ai/current_state.md`, `.ai/tasks_todo.md`, `.ai/session_handoff.md` nella repository principale: chiusura documentata di BUG-001 e test aggiunti.

### Task 1: Servizio puro per punti e percentuali

**Files:**
- Create: `src/Core/GoogleFormScoreNormalizer.php`
- Create: `tests/domain/google_form_score_normalizer.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Scrivere il test fallente del modello di punteggio**

Creare fake minimi con i metodi effettivamente usati dalla Forms API (`getQuestionItem`, `getQuestion`, `getQuestionId`, `getGrading`, `getPointValue`) e verificare:

```php
$items15 = [];
for ($i = 1; $i <= 15; $i++) {
    $items15[] = FakeFormItem::graded('q' . $i, 1.0);
}
$weights15 = GoogleFormScoreNormalizer::extractQuestionWeights($items15, []);
assertSame(15.0, GoogleFormScoreNormalizer::totalPoints($weights15), 'totale 15');

$score15 = GoogleFormScoreNormalizer::normalize(3.0, 3.0, $weights15);
assertSame(20.0, $score15['classic_percent'], '3/15 classico');
assertSame(20.0, $score15['cbm_percent'], '3/15 CBM');

$items32 = [
    FakeFormItem::graded('q1', 10.0),
    FakeFormItem::graded('q2', 10.0),
    FakeFormItem::graded('q3', 12.0),
];
$weights32 = GoogleFormScoreNormalizer::extractQuestionWeights($items32, []);
assertSame(6.25, GoogleFormScoreNormalizer::normalize(2.0, 0.0, $weights32)['classic_percent'], '2/32');
assertSame(28.125, GoogleFormScoreNormalizer::normalize(9.0, 0.0, $weights32)['classic_percent'], '9/32');
```

Aggiungere inoltre questi casi:

```php
$withConfidence = [
    FakeFormItem::graded('main', 5.0),
    FakeFormItem::graded('confidence', 1.0),
];
assertSame(
    ['main' => 5.0],
    GoogleFormScoreNormalizer::extractQuestionWeights($withConfidence, ['confidence']),
    'domanda confidenza esclusa'
);

$cbmRange = GoogleFormScoreNormalizer::normalize(2.0, -6.0, ['q' => 2.0]);
assertSame(-300.0, $cbmRange['cbm_percent'], 'CBM negativo non troncato');
assertSame(300.0, GoogleFormScoreNormalizer::normalize(2.0, 6.0, ['q' => 2.0])['cbm_percent'], 'CBM oltre 100 non troncato');

assertThrows(
    static fn() => GoogleFormScoreNormalizer::totalPoints([]),
    RuntimeException::class,
    'nessuna domanda valutata'
);
assertThrows(
    static fn() => GoogleFormScoreNormalizer::extractQuestionWeights([FakeFormItem::graded('q0', 0.0)], []),
    RuntimeException::class,
    'pointValue non positivo'
);
```

- [ ] **Step 2: Registrare e avviare il test per verificare RED**

Aggiungere a `$architectureTests` in `tests/run.php`:

```php
'Google Forms score normalizer' => __DIR__ . '/domain/google_form_score_normalizer.php',
```

Eseguire:

```powershell
docker compose exec -T app php tests/domain/google_form_score_normalizer.php
```

Expected: FAIL perché `App\Core\GoogleFormScoreNormalizer` non esiste.

- [ ] **Step 3: Implementare il servizio minimo**

Creare `src/Core/GoogleFormScoreNormalizer.php` con questa API:

```php
<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

final class GoogleFormScoreNormalizer
{
    /** @return array<string, float> */
    public static function extractQuestionWeights(iterable $items, array $confidenceQuestionIds): array
    {
        $confidenceLookup = array_fill_keys(array_map('strval', $confidenceQuestionIds), true);
        $weights = [];

        foreach ($items as $item) {
            $questionItem = is_object($item) && method_exists($item, 'getQuestionItem')
                ? $item->getQuestionItem()
                : null;
            $question = is_object($questionItem) && method_exists($questionItem, 'getQuestion')
                ? $questionItem->getQuestion()
                : null;
            if (!is_object($question) || !method_exists($question, 'getQuestionId')) {
                continue;
            }

            $questionId = trim((string)$question->getQuestionId());
            if ($questionId === '' || isset($confidenceLookup[$questionId])) {
                continue;
            }

            $grading = method_exists($question, 'getGrading') ? $question->getGrading() : null;
            if (!is_object($grading) || !method_exists($grading, 'getPointValue')) {
                continue;
            }

            $pointValue = (float)$grading->getPointValue();
            if ($pointValue <= 0.0) {
                throw new RuntimeException("La domanda {$questionId} non ha un punteggio positivo configurato.");
            }
            $weights[$questionId] = $pointValue;
        }

        self::totalPoints($weights);
        return $weights;
    }

    public static function totalPoints(array $weights): float
    {
        $total = array_sum(array_map('floatval', $weights));
        if ($weights === [] || $total <= 0.0) {
            throw new RuntimeException('Il Google Form non contiene domande valutate con punti positivi.');
        }
        return $total;
    }

    /** @return array{form_max: float, classic_percent: float, cbm_percent: float} */
    public static function normalize(float $classicTotal, float $cbmTotal, array $weights): array
    {
        $formMax = self::totalPoints($weights);
        return [
            'form_max' => $formMax,
            'classic_percent' => ($classicTotal / $formMax) * 100.0,
            'cbm_percent' => ($cbmTotal / $formMax) * 100.0,
        ];
    }
}
```

- [ ] **Step 4: Eseguire il test per verificare GREEN**

```powershell
docker compose exec -T app php tests/domain/google_form_score_normalizer.php
```

Expected: `PASS: normalizzazione punteggi Google Forms.`

- [ ] **Step 5: Commit del servizio**

```powershell
git add src/Core/GoogleFormScoreNormalizer.php tests/domain/google_form_score_normalizer.php tests/run.php
git commit -m "fix: normalize Forms scores from configured points"
```

### Task 2: Importazione classica e CBM

**Files:**
- Create: `tests/public/forms_score_normalization_integration.php`
- Modify: `public/import_form_results.php`
- Modify: `tests/run.php`

- [ ] **Step 1: Scrivere il test di integrazione fallente**

Creare un test statico che carichi i sorgenti e verifichi i contratti essenziali:

```php
$import = file_get_contents($root . '/public/import_form_results.php') ?: '';
$preview = file_get_contents($root . '/public/import_form_results_step_preview_grades.php') ?: '';
$analysis = file_get_contents($root . '/public/test_cbm_analysis.php') ?: '';

check(str_contains($import, 'GoogleFormScoreNormalizer::extractQuestionWeights'), 'import usa i punti configurati');
check(str_contains($import, 'GoogleFormScoreNormalizer::normalize'), 'import usa normalizzazione condivisa');
check(!str_contains($import, 'computeMaxScoresPerQuestion'), 'rimossa inferenza dai risultati');
check(!str_contains($import, '$cbmTotal / $totalScore'), 'CBM non diviso per punteggio studente');
check(str_contains($import, "'max_score' => \$questionMaxScores[\$questionId]"), 'dettagli conservano il peso configurato');
check(str_contains($import, "['punteggio_max' => \$classicMax]"), 'massimo test sincronizzato dal form');
check(str_contains($import, "'max_score' => \$weight"), 'mapping CBM conserva il peso');
```

Registrare in `tests/run.php`:

```php
'forms score normalization integration' => __DIR__ . '/public/forms_score_normalization_integration.php',
```

- [ ] **Step 2: Avviare il test per verificare RED**

```powershell
docker compose exec -T app php tests/public/forms_score_normalization_integration.php
```

Expected: FAIL sui riferimenti al normalizzatore e sulla funzione legacy ancora presente.

- [ ] **Step 3: Sostituire il calcolo dei massimi nell'import**

In `public/import_form_results.php`:

```php
use App\Core\GoogleFormScoreNormalizer;
```

Eliminare `computeMaxScoresPerQuestion()`. Dopo il caricamento del mapping CBM:

```php
$confidenceIds = array_values(array_filter(array_map(
    static fn(array $row): string => (string)($row['confidence_item_id'] ?? ''),
    $cbmMappingByQuestion
)));
$questionMaxScores = GoogleFormScoreNormalizer::extractQuestionWeights(
    $form->getItems() ?? [],
    $confidenceIds
);
$classicMax = GoogleFormScoreNormalizer::totalPoints($questionMaxScores);
$dbAdapter->updateRow('TEST', 'id_test', $testId, ['punteggio_max' => $classicMax]);
```

Per ogni mapping CBM risolvere il `questionId`, quindi aggiornare la riga esistente:

```php
foreach ($cbmMappingByQuestion as $questionId => $mappingRow) {
    $weight = $questionMaxScores[$questionId] ?? null;
    if ($weight === null || empty($mappingRow['id_mapping'])) {
        continue;
    }
    $dbAdapter->updateRow(
        'TEST_CBM_MAPPING',
        'id_mapping',
        (string)$mappingRow['id_mapping'],
        ['max_score' => $weight, 'punteggio_domanda' => $weight]
    );
}
```

- [ ] **Step 4: Usare il denominatore condiviso per ogni risposta**

Dopo aver costruito `$totalScore`, `$cbmTotal` e i dettagli:

```php
$normalized = GoogleFormScoreNormalizer::normalize(
    $totalScore - 0.000000001,
    $cbmTotal,
    $questionMaxScores
);
$percentuale = max(0.0, min(100.0, $normalized['classic_percent']));
$cbmPercentuale = $cbmEnabled && $cbmDetails !== []
    ? $normalized['cbm_percent']
    : null;
```

Rimuovere il calcolo inutilizzato di `$cbmMinRaw`, `$cbmMaxRaw` e `$cbmRange`. Mantenere il clamp soltanto sulla percentuale classica; il CBM resta libero.

- [ ] **Step 5: Eseguire test mirati e lint**

```powershell
docker compose exec -T app php tests/domain/google_form_score_normalizer.php
docker compose exec -T app php tests/public/forms_score_normalization_integration.php
docker compose exec -T app php -l public/import_form_results.php
```

Expected: entrambi i test PASS e lint senza errori.

- [ ] **Step 6: Commit dell'importazione**

```powershell
git add public/import_form_results.php tests/public/forms_score_normalization_integration.php tests/run.php
git commit -m "fix: import Forms totals from configured grading"
```

### Task 3: Anteprima e analisi CBM coerenti

**Files:**
- Modify: `public/import_form_results_step_preview_grades.php`
- Modify: `public/test_cbm_analysis.php`
- Modify: `tests/public/forms_score_normalization_integration.php`

- [ ] **Step 1: Estendere il test e verificare RED**

Aggiungere:

```php
check(str_contains($preview, "\$r['max_score']"), 'anteprima usa il massimo autorevole');
check(!str_contains($preview, 'array_sum($questionWeights)'), 'anteprima non ricostruisce il totale');
check(str_contains($analysis, "['max_score']"), 'analisi legge i pesi persistiti');
check(!str_contains($analysis, '$scoreClassic > $questionWeights'), 'analisi non inferisce il peso dai risultati');
check(!str_contains($analysis, '$questionWeights[$qId] = 1.0'), 'analisi non inventa peso unitario');
```

Eseguire:

```powershell
docker compose exec -T app php tests/public/forms_score_normalization_integration.php
```

Expected: FAIL sui contratti di anteprima e analisi ancora legacy.

- [ ] **Step 2: Correggere l'anteprima**

In `public/import_form_results_step_preview_grades.php`, determinare il massimo dalla risposta:

```php
$classicMaxGraph = 0.0;
foreach ($formResponses as $response) {
    $candidateMax = (float)($response['max_score'] ?? 0);
    if ($candidateMax > $classicMaxGraph) {
        $classicMaxGraph = $candidateMax;
    }
}
if ($classicMaxGraph <= 0.0) {
    throw new RuntimeException('Punteggio massimo del Google Form non disponibile.');
}
```

Conservare `$questionWeights` esclusivamente per le statistiche per domanda, popolandolo dai `cbm_details[*].weight`; non usarne la somma come denominatore degli studenti.

- [ ] **Step 3: Caricare i pesi persistiti nell'analisi**

All'inizio di `public/test_cbm_analysis.php`, caricare sempre il mapping CBM e costruire:

```php
$mappingRows = $dbAdapter->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]);
$questionWeights = [];
foreach ($mappingRows as $mappingRow) {
    $questionId = (string)($mappingRow['form_item_id'] ?? $mappingRow['id_domanda'] ?? '');
    $weight = (float)($mappingRow['max_score'] ?? $mappingRow['punteggio_domanda'] ?? 0);
    if ($questionId !== '' && $weight > 0.0) {
        $questionWeights[$questionId] = $weight;
    }
}
```

Quando il mapping è incompleto e il form viene letto, usare `GoogleFormScoreNormalizer::extractQuestionWeights()` e aggiornare `max_score`/`punteggio_domanda` sulle righe corrispondenti. Se né mapping né API producono pesi completi, valorizzare `$cbmFetchError` e non derivare i pesi da `score_classico`.

- [ ] **Step 4: Aggregare solo con pesi autorevoli**

Nel ciclo delle risposte:

```php
$qId = (string)($row['id_domanda'] ?? $qLabel);
$w = $questionWeights[$qId] ?? null;
if ($w === null || $w <= 0.0) {
    $cbmFetchError = 'Pesi configurati incompleti: impossibile calcolare correttamente l’analisi CBM.';
    continue;
}
```

Il denominatore studenti resta:

```php
$classicMax = GoogleFormScoreNormalizer::totalPoints($questionWeights);
$classicPct = ($s['classic_total'] / $classicMax) * 100.0;
$cbmPct = ($s['cbm_total'] / $classicMax) * 100.0;
```

- [ ] **Step 5: Eseguire test mirati e lint**

```powershell
docker compose exec -T app php tests/public/forms_score_normalization_integration.php
docker compose exec -T app php -l public/import_form_results_step_preview_grades.php
docker compose exec -T app php -l public/test_cbm_analysis.php
```

Expected: test PASS e due lint senza errori.

- [ ] **Step 6: Commit di anteprima e analisi**

```powershell
git add public/import_form_results_step_preview_grades.php public/test_cbm_analysis.php tests/public/forms_score_normalization_integration.php
git commit -m "fix: keep CBM analysis on configured form points"
```

### Task 4: Regressione completa e memoria AI

**Files:**
- Modify: `.ai/current_state.md` nella repository principale
- Modify: `.ai/tasks_todo.md` nella repository principale
- Modify: `.ai/session_handoff.md` nella repository principale

- [ ] **Step 1: Eseguire la suite mirata fresca**

```powershell
docker compose exec -T app php tests/domain/google_form_score_normalizer.php
docker compose exec -T app php tests/public/forms_score_normalization_integration.php
```

Expected: 2 PASS, 0 FAIL.

- [ ] **Step 2: Eseguire lint di tutti i file PHP modificati**

```powershell
docker compose exec -T app php -l src/Core/GoogleFormScoreNormalizer.php
docker compose exec -T app php -l public/import_form_results.php
docker compose exec -T app php -l public/import_form_results_step_preview_grades.php
docker compose exec -T app php -l public/test_cbm_analysis.php
docker compose exec -T app php -l tests/domain/google_form_score_normalizer.php
docker compose exec -T app php -l tests/public/forms_score_normalization_integration.php
```

Expected: nessun errore di sintassi.

- [ ] **Step 3: Eseguire la suite completa**

```powershell
docker compose exec -T app php tests/run.php
```

Expected: i nuovi test PASS; confrontare eventuali fallimenti ambientali con la baseline prima di attribuirli alla modifica.

- [ ] **Step 4: Verificare il diff e le formule vietate**

```powershell
git diff --check
rg -n "computeMaxScoresPerQuestion|cbmTotal / \\$totalScore|scoreClassic > \\$questionWeights" public tests src
git status --short
```

Expected: nessun whitespace error; nessuna formula legacy nei file produttivi; solo file previsti modificati.

- [ ] **Step 5: Aggiornare la memoria AI**

In `.ai/current_state.md`, spostare BUG-001 tra le funzionalità corrette con data, formula e test. In `.ai/tasks_todo.md`, spostare `[BUG-001]` da TODO a DONE. In `.ai/session_handoff.md`, registrare che import, preview e analisi usano i punti configurati Forms e che i vecchi valori `punteggio_max` vengono riparati al nuovo import.

- [ ] **Step 6: Commit della documentazione di stato**

Eseguire nella repository principale:

```powershell
git add .ai/current_state.md .ai/tasks_todo.md .ai/session_handoff.md
git commit -m "docs: record Forms score normalization fix"
```

- [ ] **Step 7: Verifica finale fresca**

Ripetere i test mirati e `git diff --check` dopo gli ultimi commit. Riportare esattamente conteggio dei test, eventuali limiti dell'ambiente autenticato e commit creati.
