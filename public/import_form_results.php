<?php
/**
 * Importazione Risultati Google Forms e Salvataggio Voti
 *
 * Workflow:
 * 1. Seleziona test Google Forms dal DB
 * 2. Leggi risposte dal form
 * 3. Calcola voti per ogni studente
 * 4. Anteprima voti prima dell'importazione
 * 5. Salva i voti nel sistema (pubblicazione da Gestione Voti)
 * 6. Salva nel DB con tracciabilità
 * 7. Invia email riepilogativa unica
 */

error_reporting(E_ALL);

// Avvia sessione
session_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Integration\ClasseVivaAPI;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Google\Client;
use Google\Service\Forms;
use Google\Service\Forms\Form;
use Google\Service\Forms\FormResponse;
use Google\Service\Forms\ResponseAnswer;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$cv = new ClasseVivaAPI($config);

$step = $_GET['step'] ?? 'select_test';
$testId = $_GET['test_id'] ?? $_POST['test_id'] ?? null;
$udaId = $_GET['uda_id'] ?? $_POST['uda_id'] ?? null;

function loadCbmFeedbackMap(string $filePath): array {
    if (!file_exists($filePath)) {
        return [];
    }
    $spreadsheet = IOFactory::load($filePath);
    $sheet = $spreadsheet->getSheetByName('Punti')
        ?? $spreadsheet->getSheetByName('Mappa')
        ?? $spreadsheet->getActiveSheet();
    $rows = $sheet->toArray(null, true, true, true);
    if (empty($rows)) {
        return [];
    }
    $header = array_shift($rows);
    $headerMap = [];
    foreach ($header as $col => $name) {
        $key = strtolower(trim((string)$name));
        if ($key !== '') {
            $headerMap[$key] = $col;
        }
    }

    $points = [];
    foreach ($rows as $row) {
        $accCol = $headerMap['a_%'] ?? $headerMap['accuratezza (%)'] ?? null;
        $cbmCol = $headerMap['cbm_%_rappresentativo'] ?? $headerMap['cbm%'] ?? $headerMap['cbm %'] ?? null;
        if (!$accCol || !$cbmCol) {
            continue;
        }
        $acc = $row[$accCol] ?? null;
        $cbm = $row[$cbmCol] ?? null;
        if ($acc === null || $cbm === null) {
            continue;
        }
        $points[] = [
            'accuracy_pct' => floatval($acc),
            'cbm_pct' => floatval($cbm),
            'livello' => (string)($row[$headerMap['livello'] ?? ''] ?? ''),
            'codice' => (string)($row[$headerMap['codice'] ?? ''] ?? ''),
            'label' => (string)($row[$headerMap['etichetta'] ?? ''] ?? ''),
            'feedback' => (string)($row[$headerMap['feedback'] ?? ''] ?? ''),
            'azione1' => (string)($row[$headerMap['azione 1'] ?? ''] ?? ''),
            'azione2' => (string)($row[$headerMap['azione 2'] ?? ''] ?? ''),
        ];
    }
    return $points;
}

function findClosestCbmFeedback(float $accuracyPct, float $cbmPct, array $points): ?array {
    $best = null;
    $bestDist = null;
    foreach ($points as $p) {
        $dx = $accuracyPct - ($p['accuracy_pct'] ?? 0);
        $dy = $cbmPct - ($p['cbm_pct'] ?? 0);
        $dist = ($dx * $dx) + ($dy * $dy);
        if ($bestDist === null || $dist < $bestDist) {
            $bestDist = $dist;
            $best = $p;
        }
    }
    return $best;
}

/**
 * Estrae l'ID di un Google Form da URL o valore già normalizzato.
 */
function extractGoogleFormIdFromValue(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
        return $value;
    }

    $patterns = [
        '/forms\/d\/e\/([a-zA-Z0-9_-]+)\/viewform/i',
        '/forms\/d\/([a-zA-Z0-9_-]+)\/edit/i',
        '/forms\/d\/(?!e\/)([a-zA-Z0-9_-]+)/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value, $matches)) {
            return $matches[1] ?? null;
        }
    }

    return null;
}

/**
 * Restituisce tutti i possibili formId ricavati da un valore.
 *
 * @return array<int, string>
 */
function extractGoogleFormIdCandidatesFromValue(string $value): array
{
    $value = trim($value);
    if ($value === '') {
        return [];
    }

    $ids = [];
    $patterns = [
        '/forms\/d\/([a-zA-Z0-9_-]+)\/edit/i',
        '/forms\/d\/e\/([a-zA-Z0-9_-]+)\/viewform/i',
        '/forms\/d\/(?!e\/)([a-zA-Z0-9_-]+)/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value, $matches)) {
            $id = trim((string)($matches[1] ?? ''));
            if ($id !== '') {
                $ids[] = $id;
            }
        }
    }

    if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
        $ids[] = $value;
    }

    $unique = [];
    foreach ($ids as $id) {
        if (!in_array($id, $unique, true)) {
            $unique[] = $id;
        }
    }
    return $unique;
}

$errorMessage = null;
$successMessage = null;
$formResponses = [];
$calculatedGrades = [];
$test = null;
$uda = null;
$currentUserId = $_SESSION['user_id'] ?? '';
$cbmMappingWarning = null;
$cbmParams = [
    'c1_correct' => 1,
    'c1_wrong' => 0,
    'c2_correct' => 2,
    'c2_wrong' => -2,
    'c3_correct' => 3,
    'c3_wrong' => -6,
    'bonus_max' => 1,
    'malus_max' => -2,
    'adj_on_min' => -0.5,
    'pass_threshold' => 4.99,
];
$cbmEnabled = false;
$cbmFeedbackMap = loadCbmFeedbackMap(__DIR__ . '/../Materiale/CBM_mappa_feedback_v2.xlsx');

/**
 * Calcola il punteggio massimo per ogni domanda (escludendo le domande di confidenza)
 * usando tutte le risposte disponibili. Restituisce array questionId => maxScore.
 */
function computeMaxScoresPerQuestion(array $responses, array $confidenceIds = []): array
{
    $questionMaxScores = [];
    foreach ($responses as $response) {
        $answers = $response->getAnswers();
        if (!$answers) {
            continue;
        }
        foreach ($answers as $questionId => $answer) {
            if (!empty($confidenceIds) && in_array($questionId, $confidenceIds, true)) {
                continue;
            }
            $grade = $answer->getGrade();
            $score = $grade ? ($grade->getScore() ?? 0.0) : 0.0;
            if (!isset($questionMaxScores[$questionId]) || $score > $questionMaxScores[$questionId]) {
                $questionMaxScores[$questionId] = $score;
            }
        }
    }
    return $questionMaxScores;
}

// Carica test se specificato
if ($testId) {
    $allTests = $dbAdapter->findAll('TEST');
    foreach ($allTests as $t) {
        if ($t['id_test'] === $testId) {
            $test = $t;
            $udaId = $test['id_uda'];
            $cbmEnabled = !empty($test['cbm_enabled']) && strtolower((string)$test['cbm_enabled']) !== 'no';
            break;
        }
    }
}

// Carica UDA se specificato
if ($udaId) {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    $uda = $udaComplete['uda'] ?? null;
}

// STEP intermedio: configurazione CBM (solo se il test ha cbm_enabled)
if ($step === 'cbm_config' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    // Aggiorna parametri CBM da form
    $cbmParams['c1_correct'] = floatval($_POST['c1_correct'] ?? $cbmParams['c1_correct']);
    $cbmParams['c1_wrong'] = floatval($_POST['c1_wrong'] ?? $cbmParams['c1_wrong']);
    $cbmParams['c2_correct'] = floatval($_POST['c2_correct'] ?? $cbmParams['c2_correct']);
    $cbmParams['c2_wrong'] = floatval($_POST['c2_wrong'] ?? $cbmParams['c2_wrong']);
    $cbmParams['c3_correct'] = floatval($_POST['c3_correct'] ?? $cbmParams['c3_correct']);
    $cbmParams['c3_wrong'] = floatval($_POST['c3_wrong'] ?? $cbmParams['c3_wrong']);
    $cbmParams['bonus_max'] = floatval($_POST['bonus_max'] ?? $cbmParams['bonus_max']);
    $cbmParams['malus_max'] = floatval($_POST['malus_max'] ?? $cbmParams['malus_max']);
    $cbmParams['adj_on_min'] = floatval($_POST['adj_on_min'] ?? $cbmParams['adj_on_min']);
    $cbmParams['pass_threshold'] = floatval($_POST['pass_threshold'] ?? $cbmParams['pass_threshold']);

    $_SESSION['cbm_params'] = $cbmParams;
    $step = 'preview_grades';
}

// STEP 2: Lettura risultati Google Forms
// Permetti sia POST che GET (quando arriva da uda_tests.php)
if ($step === 'read_responses' && $testId) {
    try {
        // Configurazione Google Client
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([Forms::FORMS_RESPONSES_READONLY, Forms::FORMS_BODY_READONLY]);
        $client->setAuthConfig(ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json'));

        $tokenData = GoogleTokenProvider::getToken($config);
        if (empty($tokenData)) {
            throw new Exception("Token Google non trovato per l'utente corrente. Autorizza l'applicazione da google_auth.php.");
        }
        $client->setAccessToken($tokenData);

        $tokenScopesRaw = $tokenData['scope'] ?? '';
        $tokenScopes = is_array($tokenScopesRaw)
            ? $tokenScopesRaw
            : preg_split('/\s+/', trim((string)$tokenScopesRaw));
        $tokenScopes = array_values(array_filter(array_map('strval', $tokenScopes ?: [])));
        if (!empty($tokenScopes)) {
            $hasResponsesScope = in_array('https://www.googleapis.com/auth/forms.responses.readonly', $tokenScopes, true)
                || in_array('https://www.googleapis.com/auth/forms.responses', $tokenScopes, true);
            if (!$hasResponsesScope) {
                throw new Exception(
                    "Il token Google attuale non include lo scope richiesto per leggere le risposte " .
                    "(forms.responses.readonly). Apri Integrazioni Google e riautorizza."
                );
            }
        }

        $formsService = new Forms($client);

        $candidateSources = [
            'url_docente' => (string)($test['url_docente'] ?? ''),
            'url' => (string)($test['url'] ?? ''),
            'url_studenti' => (string)($test['url_studenti'] ?? ''),
            'id_esterno' => (string)($test['id_esterno'] ?? ''),
        ];
        $candidateList = [];
        foreach ($candidateSources as $sourceName => $sourceValue) {
            foreach (extractGoogleFormIdCandidatesFromValue($sourceValue) as $candidateId) {
                $candidateKey = strtolower($candidateId);
                if (!isset($candidateList[$candidateKey])) {
                    $candidateList[$candidateKey] = [
                        'id' => $candidateId,
                        'source' => $sourceName
                    ];
                }
            }
        }

        if (empty($candidateList)) {
            throw new Exception(
                "Nessun formId valido trovato nei campi del test (url_docente/url/url_studenti/id_esterno). " .
                "Apri il test e salva un link Google Forms docente."
            );
        }

        $form = null;
        $formId = '';
        $resolvedSource = '';
        $attemptErrors = [];
        foreach ($candidateList as $candidate) {
            $candidateId = $candidate['id'];
            try {
                $candidateForm = $formsService->forms->get($candidateId);
                if (!($candidateForm instanceof Form)) {
                    continue;
                }

                // Usa sempre l'ID canonico ritornato dalle API se disponibile.
                $candidateFormId = trim((string)$candidateForm->getFormId());
                $responseIdCandidates = [];
                if ($candidateFormId !== '') {
                    $responseIdCandidates[] = $candidateFormId;
                }
                if (!in_array($candidateId, $responseIdCandidates, true)) {
                    $responseIdCandidates[] = $candidateId;
                }

                foreach ($responseIdCandidates as $responseIdCandidate) {
                    try {
                        // Probe leggero: verifica che l'endpoint risposte sia raggiungibile
                        // per questo ID prima di selezionarlo come definitivo.
                        $formsService->forms_responses->listFormsResponses($responseIdCandidate, ['pageSize' => 1]);
                        $form = $candidateForm;
                        $formId = $responseIdCandidate;
                        $resolvedSource = (string)($candidate['source'] ?? '');
                        break 2;
                    } catch (\Throwable $responsesEx) {
                        $attemptErrors[] = ($candidate['source'] ?? 'unknown') . ':responses:' . $responseIdCandidate . ' -> ' . $responsesEx->getMessage();
                    }
                }
            } catch (\Throwable $candidateEx) {
                $attemptErrors[] = ($candidate['source'] ?? 'unknown') . ':get:' . $candidateId . ' -> ' . $candidateEx->getMessage();
            }
        }

        if (!$form instanceof Form || $formId === '') {
            $attemptPreview = implode(' | ', array_slice($attemptErrors, 0, 3));
            if ($attemptPreview === '') {
                $attemptPreview = 'nessun dettaglio disponibile';
            }
            throw new Exception(
                "Impossibile leggere le risposte del Google Form con gli identificativi salvati. " .
                "Verifica che il token appartenga all'account con accesso al form e che il test usi un link docente " .
                "del tipo /forms/d/{ID}/edit (non solo /forms/d/e/.../viewform). " .
                "Tentativi: " . $attemptPreview
            );
        }

        if (!empty($testId) && (string)($test['id_esterno'] ?? '') !== $formId) {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, ['id_esterno' => $formId]);
            $test['id_esterno'] = $formId;
            $test['note'] = trim((string)($test['note'] ?? ''));
            if ($resolvedSource !== '') {
                $fixNote = "FormId normalizzato da {$resolvedSource}";
                if ($test['note'] === '') {
                    $test['note'] = $fixNote;
                } elseif (stripos($test['note'], $fixNote) === false) {
                    $test['note'] .= " | {$fixNote}";
                }
                $dbAdapter->updateRow('TEST', 'id_test', $testId, ['note' => $test['note']]);
            }
        }

        // Leggi form per ottenere struttura domande
        $formItems = [];
        if ($form instanceof Form && $form->getItems()) {
            foreach ($form->getItems() as $item) {
                $title = $item->getTitle();
                $formItems[$item->getItemId()] = $title;
                $questionObj = $item->getQuestionItem()?->getQuestion();
                if ($questionObj && $questionObj->getQuestionId()) {
                    $formItems[$questionObj->getQuestionId()] = $title;
                }
            }
        }

        // Leggi risposte
        $responsesObj = $formsService->forms_responses->listFormsResponses($formId);
        $responses = $responsesObj->getResponses();

        if (empty($responses)) {
            throw new Exception("Nessuna risposta trovata per questo form.");
        }

        // Parametri di valutazione
        $punteggioMax = floatval($test['punteggio_max'] ?? 100);
        $sogliaMinima = floatval($test['soglia_sufficienza'] ?? 60);
        $cbmParams = $_SESSION['cbm_params'] ?? $cbmParams;

        // Mapping CBM domanda/confidenza (se presente)
        $cbmMappingRows = $cbmEnabled ? $dbAdapter->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]) : [];
        $cbmMappingByQuestion = [];
        foreach ($cbmMappingRows as $row) {
            if (!empty($row['form_item_id'])) {
                $cbmMappingByQuestion[$row['form_item_id']] = $row;
            }
        }
        if ($cbmEnabled && empty($cbmMappingByQuestion)) {
            // Manca mapping domanda/confidenza: disabilita CBM e avvisa
            $cbmEnabled = false;
            $cbmMappingWarning = "CBM era abilitato sul test ma non è presente un mapping domanda/confidenza. Import eseguito in modalità classica.";
        }

        // Ricava i punteggi massimi per domanda (escludendo le domande di confidenza)
        $confidenceIds = array_column($cbmMappingByQuestion, 'confidence_item_id');
        $questionMaxScores = computeMaxScoresPerQuestion($responses, $confidenceIds);
        $classicMax = max(1, array_sum($questionMaxScores));
        $numQuestions = max(1, count($questionMaxScores));
        $maxCbmPerQuestion = max($cbmParams['c1_correct'], $cbmParams['c2_correct'], $cbmParams['c3_correct']);
        // Aggiorna il punteggio massimo del test sulla base dei pesi reali
        $dbAdapter->updateRow('TEST', 'id_test', $testId, ['punteggio_max' => $classicMax]);

        // Calcola voti per ogni risposta
        foreach ($responses as $response) {
            /** @var FormResponse $response */
            $respondentEmail = $response->getRespondentEmail();
            $totalScore = 0.000000001;
            $answersData = [];
            $cbmTotal = 0;
            $cbmDetails = [];
            $cbmLevelCounts = [
                'c1' => ['correct' => 0, 'wrong' => 0],
                'c2' => ['correct' => 0, 'wrong' => 0],
                'c3' => ['correct' => 0, 'wrong' => 0],
            ];

            $answers = $response->getAnswers();
            if ($answers) {
                /** @var ResponseAnswer $answer */
                foreach ($answers as $questionId => $answer) {
                    if (!empty($confidenceIds) && in_array($questionId, $confidenceIds, true)) {
                        continue;
                    }
                    $grade = $answer->getGrade();
                    $score = 0;
                    if ($grade) {
                        $score = $grade->getScore() ?? 0.0;
                        $totalScore += $score;
                        $answersData[$questionId] = [
                            'score' => $score,
                            'feedback' => $grade->getFeedback() ?? null
                        ];
                    }

                    // Calcolo CBM per domande con mapping
                    if ($cbmEnabled && isset($cbmMappingByQuestion[$questionId])) {
                        $map = $cbmMappingByQuestion[$questionId];
                        $confidenceId = $map['confidence_item_id'] ?? null;
                        $confidenceAnswer = $confidenceId && isset($answers[$confidenceId]) ? $answers[$confidenceId] : null;

                        // Estrai livello confidenza (default livello 1 se mancante)
                        $confValue = null;
                        if ($confidenceAnswer) {
                            $textAnswers = $confidenceAnswer->getTextAnswers();
                            if ($textAnswers && $textAnswers->getAnswers()) {
                                $confValue = $textAnswers->getAnswers()[0]->getValue();
                            }
                        }

                        $confLevel = 1;
                        if ($confValue !== null) {
                            $valLower = strtolower((string)$confValue);
                            if (in_array($valLower, ['3', 'c3', 'molto sicuro', 'molto'], true)) {
                                $confLevel = 3;
                            } elseif (in_array($valLower, ['2', 'c2', 'abbastanza sicuro', 'abbastanza'], true)) {
                                $confLevel = 2;
                            } else {
                                $confLevel = 1;
                            }
                        }

                        // Determina punteggio CBM
                        $maxQ = $questionMaxScores[$questionId] ?? 0.0;
                        $isCorrect = ($maxQ > 0) ? ($score / $maxQ >= 0.75) : false;
                        $cbmScore = 0;
                        switch ($confLevel) {
                            case 3:
                                $cbmScore = $isCorrect ? $cbmParams['c3_correct'] : $cbmParams['c3_wrong'];
                                break;
                            case 2:
                                $cbmScore = $isCorrect ? $cbmParams['c2_correct'] : $cbmParams['c2_wrong'];
                                break;
                            default:
                                $cbmScore = $isCorrect ? $cbmParams['c1_correct'] : $cbmParams['c1_wrong'];
                                break;
                        }

                        $w = $questionMaxScores[$questionId] ?? 1.0;
                        $cbmTotal += $cbmScore * $w;
                        $cbmDetails[$questionId] = [
                            'conf_level' => $confLevel,
                            'conf_value' => $confValue,
                            'cbm_score' => $cbmScore,
                            'classic_score' => $score,
                            'weight' => $w,
                            'question_label' => $formItems[$questionId] ?? '',
                        ];
                        if ($score > 0) {
                            $cbmLevelCounts['c' . $confLevel]['correct']++;
                        } else {
                            $cbmLevelCounts['c' . $confLevel]['wrong']++;
                        }
                    }
                }
            }

            // Calcola voto numerico (scala 1-10) usando la base dei punteggi massimi
            $percentuale = ($classicMax > 0) ? (($totalScore - 0.000000001) / $classicMax) * 100 : 0;
            if ($percentuale < 0) $percentuale = 0;
            if ($percentuale > 100) $percentuale = 100;
            $votoNumerico = round(($percentuale / 100) * 10, 1);
            if ($votoNumerico <= 0) {
                $votoNumerico = 1;
            }
            $votoNumerico = min(10, $votoNumerico);

            // Calcolo CBM aggregato se disponibile (coerente con analisi: base = #domande)
            $cbmPercentuale = null;
            $cbmVotoNumerico = null;
            $cbmBonus = null;
            $cbmAdj = null;
            $cbmVotoFinale = null;
            $cbmFeedback = null;
            $sufficiente = false;
            if ($cbmEnabled && !empty($cbmDetails)) {
                // Normalizza CBM su scala 0..300 usando min/max teorici pesati per domanda (w_i = punteggio massimo domanda)
                $minWrong = min($cbmParams['c1_wrong'], $cbmParams['c2_wrong'], $cbmParams['c3_wrong']);
                $maxCorrect = max($cbmParams['c1_correct'], $cbmParams['c2_correct'], $cbmParams['c3_correct']);
                $cbmMinRaw = 0;
                $cbmMaxRaw = 0;
                foreach ($questionMaxScores as $w) {
                    $cbmMinRaw += $minWrong * $w;
                    $cbmMaxRaw += $maxCorrect * $w;
                }
                $cbmRange = ($cbmMaxRaw - $cbmMinRaw);
                if (abs($cbmRange) < 1e-9) {
                    $cbmRange = 1; // fallback per evitare divisione per zero
                }
                $cbmPercentuale = $cbmTotal / $totalScore * 100.0;

                // Voto CBM puro (solo per riferimento)
                $cbmVotoNumerico = round(($cbmPercentuale / 100) * 10, 1);
                $cbmVotoNumerico = max(1, min(10, $cbmVotoNumerico));

                // Parametri bonus/malus
                $Bmax = $cbmParams['bonus_max'] ?? 1;
                $Mmax = $cbmParams['malus_max'] ?? -2;
                $AdjOnMin = $cbmParams['adj_on_min'] ?? -0.5;
                $passThreshold = $cbmParams['pass_threshold'] ?? 5.0;

                // Accuratezza percentuale e voto
                $A_percent = $percentuale;
                $V_acc = $votoNumerico;

                if ($V_acc < $passThreshold) {
                    // CBM non incide: voto = accuratezza
                    $cbmAdj = 0;
                    $cbmVotoFinale = max(0, min(10, $V_acc));
                } else {
                    // Curve di riferimento
                    $y_max = 3 * $A_percent;
                    if ($A_percent <= 66.6) {
                        $y_min = $A_percent;
                    } elseif ($A_percent <= 80) {
                        $y_min = 4 * $A_percent - 200;
                    } else {
                        $y_min = 9 * $A_percent - 600;
                    }
                    $y_low = $A_percent - 60;

                    // Calcolo adj con interpolazione
                    if ($cbmPercentuale >= $y_min) {
                        $den = max(1e-9, $y_max - $y_min);
                        $t = ($cbmPercentuale - $y_min) / $den;
                        $cbmAdj = $AdjOnMin + ($Bmax - $AdjOnMin) * $t;
                    } else {
                        if ($cbmPercentuale <= $y_low) {
                            $cbmAdj = $Mmax;
                        } else {
                            $den = max(1e-9, $y_min - $y_low);
                            $t = ($y_min - $cbmPercentuale) / $den;
                            $cbmAdj = $AdjOnMin + ($Mmax - $AdjOnMin) * $t;
                        }
                    }
                    $cbmAdj = max($Mmax, min($Bmax, $cbmAdj));
                    $cbmVotoFinale = max(0, min(10, $V_acc + $cbmAdj));
                }

                $cbmBonus = $cbmAdj;
                $sufficiente = $cbmVotoFinale >= ($sogliaMinima / 10);
            } else {
                $sufficiente = $percentuale >= ($sogliaMinima / $classicMax * 100);
            }

            if ($cbmPercentuale !== null && !empty($cbmFeedbackMap)) {
                $cbmFeedback = findClosestCbmFeedback($percentuale, $cbmPercentuale, $cbmFeedbackMap);
            }

            $formResponses[] = [
                'response_id' => $response->getResponseId(),
                'email' => $respondentEmail,
                'timestamp' => $response->getLastSubmittedTime() ?? $response->getCreateTime(),
                'total_score' => ($totalScore - 0.000000001),
                'max_score' => $classicMax,
                'percentuale' => $percentuale,
                'voto_numerico' => $votoNumerico,
                'sufficiente' => $sufficiente,
                'answers' => $answersData,
                'cbm_total_score' => $cbmTotal,
                'cbm_percentuale' => $cbmPercentuale,
                'cbm_voto_numerico' => $cbmVotoNumerico,
                'cbm_bonus' => $cbmBonus,
                'cbm_adj' => $cbmAdj,
                'cbm_voto_finale' => $cbmVotoFinale,
                'cbm_details' => $cbmDetails,
                'cbm_level_counts' => $cbmLevelCounts,
                'cbm_feedback' => $cbmFeedback,
                'cbm_feedback_label' => $cbmFeedback['label'] ?? '',
                'cbm_feedback_text' => $cbmFeedback['feedback'] ?? '',
                'cbm_feedback_action1' => $cbmFeedback['azione1'] ?? '',
                'cbm_feedback_action2' => $cbmFeedback['azione2'] ?? '',
            ];
        }

        // Recupera mapping classroom per auto-selezione classe/materia
        $classroomMapping = null;
        $autoClassId = null;
        $autoSubjectId = null;

        // STRATEGIA INTELLIGENTE: Rileva automaticamente il corso Google Classroom dal test
        // Verifica se il test ha già un google_course_id salvato
        $googleCourseId = $test['google_course_id'] ?? null;

        $allMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');

        // Se il test ha un google_course_id, usa quello per trovare il mapping
        if ($googleCourseId) {
            foreach ($allMappings as $mapping) {
                $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
                if (($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1') &&
                    (($mapping['id_corso_gc'] ?? '') == $googleCourseId)) {
                    $classroomMapping = $mapping;
                    $autoClassId = $mapping['id_classe_cv'] ?? null;
                    $autoSubjectId = $mapping['id_materia_cv'] ?? null;
                    break;
                }
            }
        }

        // Se non trovato, cerca basandosi sugli studenti che hanno risposto (fallback intelligente)
        if (!$classroomMapping) {
            $allStudentMappings = $dbAdapter->findAll('MAPPATURA_STUDENTI');

            // Raccogli email degli studenti che hanno risposto
            $respondentEmails = array_map(function($r) {
                return strtolower($r['email']);
            }, $formResponses);

            // Cerca il mapping con il maggior numero di studenti in comune
            $bestMapping = null;
            $bestMatchCount = 0;

            foreach ($allMappings as $mapping) {
                $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
                if (!($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1')) {
                    continue;
                }

                $mappingId = $mapping['id_mapping'] ?? null;
                if (!$mappingId) {
                    continue;
                }

                // Conta quanti studenti del form appartengono a questo mapping
                $matchCount = 0;
                foreach ($allStudentMappings as $studentMap) {
                    if (($studentMap['id_mapping_materia'] ?? '') === $mappingId) {
                        $studentEmail = strtolower($studentMap['email_google'] ?? '');
                        if ($studentEmail && in_array($studentEmail, $respondentEmails)) {
                            $matchCount++;
                        }
                    }
                }

                if ($matchCount > $bestMatchCount) {
                    $bestMatchCount = $matchCount;
                    $bestMapping = $mapping;
                }
            }

            if ($bestMapping && $bestMatchCount > 0) {
                $classroomMapping = $bestMapping;
                $autoClassId = $bestMapping['id_classe_cv'] ?? null;
                $autoSubjectId = $bestMapping['id_materia_cv'] ?? null;
            } else {
                // Ultimo fallback: usa il primo mapping attivo
                foreach ($allMappings as $mapping) {
                    $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
                    if ($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1') {
                        $classroomMapping = $mapping;
                        $autoClassId = $mapping['id_classe_cv'] ?? null;
                        $autoSubjectId = $mapping['id_materia_cv'] ?? null;
                        break;
                    }
                }
            }
        }

        // Salva in sessione per step successivo
        $_SESSION['form_responses'] = $formResponses;
        $_SESSION['test_id'] = $testId;
        $_SESSION['uda_id'] = $udaId;
        $_SESSION['auto_class_id'] = $autoClassId;
        $_SESSION['auto_subject_id'] = $autoSubjectId;
        $_SESSION['classroom_mapping'] = $classroomMapping;
        $_SESSION["class_students"] = [];
        $_SESSION['cbm_params'] = $cbmParams;
        $_SESSION['cbm_enabled'] = $cbmEnabled;
        $_SESSION['cbm_mapping_warning'] = $cbmMappingWarning;
        // Statistiche aggregate per livelli CBM
        $cbmTotals = ['c1' => ['correct' => 0, 'wrong' => 0], 'c2' => ['correct' => 0, 'wrong' => 0], 'c3' => ['correct' => 0, 'wrong' => 0]];
        foreach ($formResponses as $r) {
            foreach (['c1','c2','c3'] as $lvl) {
                $cbmTotals[$lvl]['correct'] += $r['cbm_level_counts'][$lvl]['correct'] ?? 0;
                $cbmTotals[$lvl]['wrong'] += $r['cbm_level_counts'][$lvl]['wrong'] ?? 0;
            }
        }
        $_SESSION['cbm_level_totals'] = $cbmTotals;

        $step = $cbmEnabled ? 'cbm_config' : 'preview_grades';

    } catch (Exception $e) {
        $errorMessage = "Errore durante la lettura delle risposte: " . $e->getMessage();
        $step = 'select_test';
    }
}

// STEP 3: Importa voti
if ($step === 'publish_grades' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $formResponses = $_SESSION['form_responses'] ?? [];
        $selectedResponses = $_POST['selected_responses'] ?? [];
        $gradeType = $_POST['grade_type'] ?? 'S'; // S=Scritto, O=Orale, P=Pratico
        $voteSource = 'classic'; // sorgente voto fissa (classico) ora
        $votiFinali = $_POST['voto_finale'] ?? []; // Voti selezionati manualmente
        $studentMapping = $_POST["student_mapping"] ?? []; // Mapping studente da tendina

        if (empty($selectedResponses)) {
            throw new Exception("Seleziona almeno uno studente per importare i voti.");
        }

        $pubblicati = [];
        $errori = [];

        $classId = $_POST['class_id'] ?? null;
        $subjectId = $_POST['subject_id'] ?? null;

        if (!$classId || !$subjectId) {
            throw new Exception("Classe e materia sono obbligatorie.");
        }

        // Mappa tipo voto per descrizione
        $gradeTypeLabels = [
            'S' => 'Scritto',
            'O' => 'Orale',
            'P' => 'Pratico'
        ];
        $gradeTypeLabel = $gradeTypeLabels[$gradeType] ?? 'Scritto';
        $linkOrigine = app_url('public/import_form_results.php?test_id=' . urlencode((string)$testId));
        $testDateRaw = $test['data_somministrazione'] ?? ($test['data_creazione'] ?? null);

        foreach ($formResponses as $responseData) {
            if (!in_array($responseData['response_id'], $selectedResponses)) {
                continue;
            }

            $studentEmail = $responseData['email'];
            $responseId = $responseData['response_id'];

            // Usa l'ID studente selezionato dalla tendina (o quello pre-mappato come fallback)
            $studentId = $studentMapping[$responseId] ?? $responseData['student_cv_id'] ?? null;

            if (!$studentId) {
                $errori[] = "Email $studentEmail: studente non selezionato (selezionare dalla tendina)";
                continue;
            }

            // Determina se usare il punteggio CBM
            // Usa CBM come default; se mancante, fallback al classico
            $votoCalcolato = isset($responseData['cbm_voto_finale'])
                ? $responseData['cbm_voto_finale']
                : $responseData['voto_numerico'];
            $percentualeCalcolata = isset($responseData['cbm_percentuale']) ? $responseData['cbm_percentuale'] : $responseData['percentuale'];

            // Usa il voto selezionato manualmente dall'utente (o calcolato se non presente)
            $votoSelezionato = $votiFinali[$responseId] ?? null;
            $voto = $votoSelezionato !== null && $votoSelezionato !== '' ? floatval($votoSelezionato) : floatval($votoCalcolato);
            // clamp voto 1..10 con bordo 300% -> 10, <10% -> 1
            if ($percentualeCalcolata >= 300) {
                $voto = 10;
            } elseif ($percentualeCalcolata <= 10) {
                $voto = max(1, $voto);
            }
            $percentuale = $percentualeCalcolata;

            // Prepara dati voto
            $votoId = 'VOTO_' . uniqid();

            $noteVoto = "Verifica: " . $test['nome'] . " (Google Forms)\n";
            $noteVoto .= "Tipo: {$gradeTypeLabel}\n";
            $noteVoto .= "Punteggio: {$responseData['total_score']}/{$responseData['max_score']} ({$percentuale}%)\n";
            if (isset($responseData['cbm_percentuale'])) {
                $noteVoto .= "CBM%: {$responseData['cbm_total_score']}/{$responseData['max_score']} (" . round($responseData['cbm_percentuale'], 1) . "%)\n";
            }
            $giudizioText = '';
            if (!empty($responseData['cbm_feedback_label']) || !empty($responseData['cbm_feedback_text'])) {
                $noteVoto .= "Valutazione CBM: " . ($responseData['cbm_feedback_label'] ?? '') . "\n";
                $giudizioParts = [];
                if (!empty($responseData['cbm_feedback_label'])) {
                    $giudizioParts[] = $responseData['cbm_feedback_label'];
                }
                if (!empty($responseData['cbm_feedback_text'])) {
                    $noteVoto .= ($responseData['cbm_feedback_text'] ?? '') . "\n";
                    $giudizioParts[] = $responseData['cbm_feedback_text'];
                }
                $actions = array_filter([
                    $responseData['cbm_feedback_action1'] ?? '',
                    $responseData['cbm_feedback_action2'] ?? ''
                ]);
                if (!empty($actions)) {
                    $noteVoto .= "Azioni: " . implode(' | ', $actions) . "\n";
                    $giudizioParts[] = "Azioni: " . implode(' | ', $actions);
                }
                $noteVoto .= "Stato: " . ($sufficiente ? 'Sufficiente' : 'Insufficiente') . "\n";
                $giudizioText = implode("\n", $giudizioParts);
            }
            if ($voto != $votoCalcolato) {
            }
            $noteVoto .= "<{$votoId}>";

            // Converti tipo voto da S/O/P a scritto/orale/pratico
            $gradeTypeMap = [
                'S' => 'scritto',
                'O' => 'orale',
                'P' => 'pratico'
            ];
            $gradeTypeFull = $gradeTypeMap[$gradeType] ?? 'scritto';

            // Recupera nome materia per il voto (tollerante a errori API)
            $subjectName = 'Materia';
            try {
                $allSubjects = $cv->getSubjects();
                foreach ($allSubjects as $subject) {
                    if (($subject['id'] ?? '') == $subjectId) {
                        $subjectName = $subject['nome'] ?? $subject['description'] ?? 'Materia';
                        break;
                    }
                }
            } catch (Exception $e) {
                // fallback silenzioso, evita blocco su cURL error 3
                $subjectName = 'Materia';
            }

            $idAnnotCv = null;
            $pubblicatoFlag = 0;

            // Salva nel database interno
            $dataValutazione = null;
            $rawDate = $responseData['timestamp'] ?? null;
            if (!empty($rawDate)) {
                $ts = strtotime((string)$rawDate);
                if ($ts) {
                    $dataValutazione = date('Y-m-d', $ts);
                }
            }
            if (!$dataValutazione && !empty($testDateRaw)) {
                $ts = strtotime((string)$testDateRaw);
                if ($ts) {
                    $dataValutazione = date('Y-m-d', $ts);
                }
            }
            if (!$dataValutazione) {
                $dataValutazione = date('Y-m-d');
            }

            $dbVoto = [
                'id_voto' => $votoId,
                'id_uda' => $udaId,
                'id_studente_cv' => $studentId,
                'id_classe_cv' => $classId,
                'id_materia_cv' => $subjectId,
                'tipo_voto' => '' . strtolower($gradeTypeLabel), // es: scritto
                'voto' => $voto,
                'giudizio' => $giudizioText,
                'descrizione' => "Verifica su google forms: " . $test['nome'] . " ({$gradeTypeLabel})",
                'data_valutazione' => $dataValutazione,
                'data_creazione' => date('Y-m-d H:i:s'),
                'pubblicato' => $pubblicatoFlag,
                'id_annotazione_cv' => $idAnnotCv,
                'num_evidenze_positive' => null,
                'num_evidenze_negative' => null,
                'num_evidenze_totali' => null,
                'link_origine' => $linkOrigine
            ];

            // Salvataggio locale (usa insertRow compatibile con SQLite/Excel)
            try {
                $dbAdapter->insertRow('VOTI', $dbVoto);
            } catch (Exception $exDb) {
                $errori[] = "Email $studentEmail: errore salvataggio DB VOTI - " . $exDb->getMessage();
            }

            // Persisti risposte CBM per analisi, se disponibili
            if (!empty($responseData['cbm_details'])) {
                foreach ($responseData['cbm_details'] as $questionId => $detail) {
                    $cbmRow = [
                        'id_risposta' => 'CBMRISP_' . uniqid(),
                        'id_test' => $testId,
                        'id_domanda' => $questionId,
                        'id_studente_cv' => $studentId,
                        'id_classe_cv' => $classId,
                        'id_materia_cv' => $subjectId,
                        'google_response_id' => $responseId,
                        'domanda_label' => $detail['question_label'] ?? '',
                        'confidenza_livello' => $detail['conf_level'] ?? null,
                        'confidenza_valore' => $detail['conf_value'] ?? null,
                        'corretta' => ($detail['classic_score'] ?? 0) > 0 ? 'SI' : 'NO',
                        'score_cba' => $detail['cbm_score'] ?? null,
                        'score_classico' => $detail['classic_score'] ?? null,
                        'penalita' => ($detail['cbm_score'] ?? 0) < 0 ? abs($detail['cbm_score']) : 0,
                        'punteggio_normalizzato' => $responseData['cbm_percentuale'] ?? null,
                        'timestamp_risposta' => $responseData['timestamp'] ?? null,
                        'raw_json' => json_encode($detail),
                        'id_utente' => $currentUserId
                    ];
                    try {
                        $dbAdapter->insertRow('TEST_CBM_RISPOSTE', $cbmRow);
                    } catch (Exception $exCbm) {
                        // Non bloccare il flusso: annota l'errore
                        $errori[] = "CBM {$studentEmail}/{$questionId}: " . $exCbm->getMessage();
                    }
                }
            }

            $pubblicati[] = [
                'email' => $studentEmail,
                'nome' => $responseData['student_cv_name'] ?? $studentEmail,
                'voto' => $voto,
                'percentuale' => $percentuale,
                'tipo_voto' => $gradeTypeLabel
            ];
        }

        // Aggiorna test come "risultati importati"
        try {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, ['risultati_importati' => 'SI']);
        } catch (\Throwable $ex) {
        // Non bloccare il salvataggio se il flag non si aggiorna
        }

        // Invia email riepilogativa
        $emailSent = false;
        if (!empty($pubblicati)) {
            $emailSent = inviaEmailRiepilogativa($pubblicati, $test, $uda, $errori, $config);
            if (!$emailSent) {
                $errori[] = 'Email riepilogativa non inviata: controlla la configurazione SMTP.';
            }
        }

        $_SESSION['pubblicati'] = $pubblicati;
        $_SESSION['errori'] = $errori;
        $_SESSION['email_sent'] = $emailSent;
        $step = 'result';

    } catch (Exception $e) {
        $errorMessage = "Errore durante il salvataggio: " . $e->getMessage();
        $step = 'preview_grades';
    }
}

/**
 * Invia email riepilogativa con tutti i voti importati
 */
function inviaEmailRiepilogativa($pubblicati, $test, $uda, $errori, $config) {
    $emailDocente = $config['notifications']['email_docente'] ?? null;

    if (!$emailDocente) {
        return false;
    }

    $emailBody = "<html><body>";
    $emailBody .= "<h2>Voti Importati da Google Forms</h2>";
    $emailBody .= "<p><strong>Test:</strong> " . htmlspecialchars($test['nome']) . "</p>";
    $emailBody .= "<p><strong>UDA:</strong> " . htmlspecialchars($uda->titolo ?? 'N/D') . "</p>";
    $emailBody .= "<p><strong>Data:</strong> " . date('d/m/Y H:i') . "</p>";

    // Mostra tipo voto nella sezione header
    $tipoVoto = !empty($pubblicati) ? ($pubblicati[0]['tipo_voto'] ?? 'Scritto') : 'Scritto';
    $emailBody .= "<p><strong>Tipo Voto:</strong> " . htmlspecialchars($tipoVoto) . "</p>";

    $emailBody .= "<h3>Voti Importati (" . count($pubblicati) . ")</h3>";
    $emailBody .= "<table border='1' cellpadding='5' cellspacing='0' style='border-collapse:collapse;'>";
    $emailBody .= "<tr style='background-color:#f0f0f0;'>";
    $emailBody .= "<th>Studente</th><th>Voto</th><th>Percentuale</th><th>Esito</th>";
    $emailBody .= "</tr>";

    foreach ($pubblicati as $p) {
        $bgColor = $p['voto'] >= 6 ? '#d4edda' : '#f8d7da';
        $emailBody .= "<tr style='background-color:$bgColor;'>";
        $emailBody .= "<td>" . htmlspecialchars($p['nome']) . "</td>";
        $emailBody .= "<td style='text-align:center;font-weight:bold;'>" . $p['voto'] . "</td>";
        $emailBody .= "<td style='text-align:center;'>" . round($p['percentuale'], 1) . "%</td>";
        $emailBody .= "<td>" . ($p['voto'] >= 6 ? '✓ Sufficiente' : '✗ Insufficiente') . "</td>";
        $emailBody .= "</tr>";
    }

    $emailBody .= "</table>";

    // Statistiche
    $totale = count($pubblicati);
    $sufficienti = count(array_filter($pubblicati, fn($p) => $p['voto'] >= 6));
    $insufficienti = $totale - $sufficienti;
    $mediaVoti = $totale > 0 ? array_sum(array_column($pubblicati, 'voto')) / $totale : 0;

    $emailBody .= "<h3>Statistiche</h3>";
    $emailBody .= "<ul>";
    $emailBody .= "<li><strong>Totale voti:</strong> $totale</li>";
    $emailBody .= "<li><strong>Sufficienti:</strong> $sufficienti (" . round($sufficienti/$totale*100, 1) . "%)</li>";
    $emailBody .= "<li><strong>Insufficienti:</strong> $insufficienti (" . round($insufficienti/$totale*100, 1) . "%)</li>";
    $emailBody .= "<li><strong>Media classe:</strong> " . round($mediaVoti, 2) . "</li>";
    $emailBody .= "</ul>";

    if (!empty($errori)) {
        $emailBody .= "<h3>Errori (" . count($errori) . ")</h3>";
        $emailBody .= "<ul>";
        foreach ($errori as $errore) {
            $emailBody .= "<li style='color:red;'>" . htmlspecialchars($errore) . "</li>";
        }
        $emailBody .= "</ul>";
    }

    $emailBody .= "<hr><p style='font-size:12px;color:#666;'>";
    $emailBody .= "Email generata automaticamente dal Sistema UDA<br>";
    $emailBody .= "Data: " . date('d/m/Y H:i:s');
    $emailBody .= "</p></body></html>";

    // Invia email
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type: text/html; charset=utf-8\r\n";
    $headers .= "From: Sistema UDA <email@email.it>\r\n";

    $subject = "Voti Importati: " . $test['nome'] . " ($totale studenti)";

    return mail($emailDocente, $subject, $emailBody, $headers);
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importa Risultati Google Forms - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .grade-preview {
            transition: all 0.3s;
        }
        .grade-preview:hover {
            background-color: #f8f9fa;
        }
        .grade-preview .cbm-feedback-details {
            display: none;
        }
        .grade-preview.cbm-expanded .cbm-feedback-details {
            display: block;
        }
        .grade-sufficient {
            background-color: #d4edda;
        }
        .grade-insufficient {
            background-color: #f8d7da;
        }
        .stats-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 10px;
            padding: 20px;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-download"></i> Importa Risultati Google Forms';
    $headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Home</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <!-- Progress Steps -->
        <?php
        $cbmActive = $cbmEnabled || (!empty($_SESSION['cbm_enabled']));
        $progressSteps = $cbmActive
            ? [
                ['key' => 'select_test', 'label' => 'Seleziona Test'],
                ['key' => 'cbm_config', 'label' => 'Configura CBM'],
                ['key' => 'preview_grades', 'label' => 'Anteprima Voti'],
                ['key' => 'publish_grades', 'label' => 'Importa voti']
            ]
            : [
                ['key' => 'select_test', 'label' => 'Seleziona Test'],
                ['key' => 'preview_grades', 'label' => 'Anteprima Voti'],
                ['key' => 'publish_grades', 'label' => 'Importa voti']
            ];

        $currentIndex = 0;
        foreach ($progressSteps as $idx => $s) {
            if ($step === $s['key'] || ($step === 'result' && $s['key'] === 'publish_grades')) {
                $currentIndex = $idx;
                break;
            }
        }
        $progressPercent = round((($currentIndex) / (count($progressSteps) - 1)) * 100);
        ?>

        <div class="mb-4">
            <div class="d-flex justify-content-between">
                <?php foreach ($progressSteps as $idx => $s): ?>
                    <?php
                    $active = ($step === $s['key']) || ($step === 'result' && $s['key'] === 'publish_grades');
                    $iconIndex = $idx + 1;
                    ?>
                    <div class="text-center <?= $active ? 'text-primary fw-bold' : 'text-muted' ?>">
                        <i class="bi bi-<?= $iconIndex ?>-circle<?= $active ? '-fill' : '' ?> fs-3"></i>
                        <div><?= htmlspecialchars($s['label']) ?></div>
                    </div>
                <?php endforeach; ?>
            </div>
            <div class="progress mt-2" style="height: 5px;">
                <div class="progress-bar" style="width: <?= $progressPercent ?>%"></div>
            </div>
        </div>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php
        // Include step templates
        $stepFile = __DIR__ . "/import_form_results_step_{$step}.php";
        if (file_exists($stepFile)) {
            include $stepFile;
        } else {
            // Default: STEP 1 - Selezione Test
            include __DIR__ . '/import_form_results_step_select_test.php';
        }
        ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
