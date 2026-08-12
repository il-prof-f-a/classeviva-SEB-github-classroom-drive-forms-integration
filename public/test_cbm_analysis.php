<?php
/**
 * Analisi Confidence-Based Assessment (CBM) per un test
 */
require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GoogleTokenProvider;
use App\Integration\ClasseVivaAPI;
use Google\Client;
use Google\Service\Forms;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$testId = $_GET['test_id'] ?? null;

if (!$testId) {
    http_response_code(400);
    echo "test_id mancante";
    exit;
}

$test = $dbAdapter->findOne('TEST', 'id_test', $testId);
if (!$test) {
    http_response_code(404);
    echo "Test non trovato";
    exit;
}

$cbmEnabled = !empty($test['cbm_enabled']) && strtolower((string)$test['cbm_enabled']) !== 'no';
if (!$cbmEnabled) {
    echo "CBM non abilitato per questo test.";
    exit;
}

$punteggioMax = floatval($test['punteggio_max'] ?? 100);
$cbmParams = [
    1 => ['correct' => 1, 'wrong' => 0],
    2 => ['correct' => 2, 'wrong' => -2],
    3 => ['correct' => 3, 'wrong' => -6],
];

$rows = $dbAdapter->findWhere('TEST_CBM_RISPOSTE', ['id_test' => $testId]);
$cbmFetchError = null;
$cbmFetchInfo = null;
$responsesCount = 0;
$confidenceIds = [];
$itemTitles = [];

/**
 * Se non ci sono risposte salvate, prova a leggere le risposte dal Google Form
 * e calcolare CBM al volo (senza salvare) per permettere l'analisi anche prima dell'import.
 */
if (empty($rows) && $cbmEnabled && !empty($test['id_esterno'])) {
    try {
        // Mapping da tabella o da config JSON
        $mappingRows = $dbAdapter->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]);
        $mapping = [];
        foreach ($mappingRows as $row) {
            if (!empty($row['form_item_id']) && !empty($row['confidence_item_id'])) {
                $mapping[$row['form_item_id']] = $row['confidence_item_id'];
            }
        }
        if (empty($mapping) && !empty($test['cbm_form_config_json'])) {
            $cfg = json_decode($test['cbm_form_config_json'], true);
            if (is_array($cfg['mapping'] ?? null)) {
                foreach ($cfg['mapping'] as $q => $c) {
                    if ($q && $c) {
                        $mapping[$q] = $c;
                    }
                }
            }
        }
        // Fallback: deduci mapping dal form (domanda seguita da confidenza)
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([Forms::FORMS_RESPONSES_READONLY, Forms::FORMS_BODY_READONLY]);
        $client->setAuthConfig(ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json'));
        $tokenData = GoogleTokenProvider::getToken($config);
        if (empty($tokenData)) {
            throw new Exception("Token Google mancante per lettura form");
        }
        $client->setAccessToken($tokenData);
        $service = new Forms($client);

        // Ricostruisci sempre il mapping dal form per evitare disallineamenti (cloni/dupliche)
        $form = $service->forms->get($test['id_esterno']);
        $items = $form->getItems() ?? [];
        $mappingFromForm = [];
        $prevQuestionId = null;
        $keywords = ['sicuro', 'sure', 'conf', 'fiducia', 'confidence'];
        foreach ($items as $item) {
            $itemId = $item->getItemId();
            $title = strtolower($item->getTitle() ?? '');
            $questionItem = $item->getQuestionItem();
            $questionObj = $questionItem ? $questionItem->getQuestion() : null;
            $questionId = $questionObj ? $questionObj->getQuestionId() : $itemId;
            $itemTitles[$questionId] = $item->getTitle() ?? '';
            $isConfidence = false;
            foreach ($keywords as $kw) {
                if (strpos($title, $kw) !== false) {
                    $isConfidence = true;
                    break;
                }
            }

            if ($questionItem && $questionObj) {
                if ($isConfidence && $prevQuestionId) {
                    $mappingFromForm[$prevQuestionId] = $questionId;
                    $prevQuestionId = null;
                    continue;
                }
                $prevQuestionId = $questionId;
            } else {
                if ($prevQuestionId && $isConfidence) {
                    $mappingFromForm[$prevQuestionId] = $questionId;
                    $prevQuestionId = null;
                }
            }
        }

        // Usa mapping dal form se trovato, altrimenti quello salvato
        if (!empty($mappingFromForm)) {
            $mapping = $mappingFromForm;
            // Persisti per le analisi future
            $cbmLevelsPersist = [
                'c1' => ['label' => 'Poco sicuro', 'correct' => 1, 'wrong' => 0],
                'c2' => ['label' => 'Abbastanza sicuro', 'correct' => 2, 'wrong' => -2],
                'c3' => ['label' => 'Molto sicuro', 'correct' => 3, 'wrong' => -6],
            ];
            $existingMap = $dbAdapter->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]);
            foreach ($existingMap as $row) {
                if (!empty($row['id_mapping'])) {
                    $dbAdapter->deleteRow('TEST_CBM_MAPPING', $row['id_mapping'], 'id_mapping');
                }
            }
            foreach ($mapping as $q => $c) {
                $dbAdapter->insertRow('TEST_CBM_MAPPING', [
                    'id_test' => $testId,
                    'form_item_id' => $q,
                    'confidence_item_id' => $c,
                    'config_json' => json_encode($cbmLevelsPersist),
                    'id_utente' => $test['id_utente'] ?? null,
                ]);
            }
            $newCfg = [
                'mapping' => $mapping,
                'levels' => $cbmLevelsPersist,
            ];
            $dbAdapter->updateRow('TEST', 'id_test', $testId, ['cbm_form_config_json' => json_encode($newCfg)]);
        }
        $confidenceIds = array_values($mapping);

        if (!empty($mapping)) {
            $cbmLevels = json_decode($test['cbm_levels_json'] ?? '[]', true);
            if (!is_array($cbmLevels) || empty($cbmLevels)) {
                $cfgLevels = json_decode($test['cbm_form_config_json'] ?? '[]', true);
                $cbmLevels = is_array($cfgLevels['levels'] ?? null) ? $cfgLevels['levels'] : [];
            }
            if (!is_array($cbmLevels) || empty($cbmLevels)) {
                $cbmLevels = [
                    'c1' => ['label' => 'Poco sicuro', 'correct' => 1, 'wrong' => 0],
                    'c2' => ['label' => 'Abbastanza sicuro', 'correct' => 2, 'wrong' => -2],
                    'c3' => ['label' => 'Molto sicuro', 'correct' => 3, 'wrong' => -6],
                ];
            }
            $cbmParams = [
                1 => ['correct' => $cbmLevels['c1']['correct'] ?? 1, 'wrong' => $cbmLevels['c1']['wrong'] ?? 0],
                2 => ['correct' => $cbmLevels['c2']['correct'] ?? 2, 'wrong' => $cbmLevels['c2']['wrong'] ?? -2],
                3 => ['correct' => $cbmLevels['c3']['correct'] ?? 3, 'wrong' => $cbmLevels['c3']['wrong'] ?? -6],
            ];

            $responsesObj = $service->forms_responses->listFormsResponses($test['id_esterno']);
        $responses = $responsesObj->getResponses() ?? [];
        foreach ($responses as $response) {
            $answers = $response->getAnswers();
            if (!$answers) {
                continue;
            }
            $responsesCount++;
            foreach ($answers as $qId => $answer) {
                // Salta le risposte alle domande di confidenza: verranno lette tramite la domanda principale
                if (!empty($confidenceIds) && in_array($qId, $confidenceIds, true)) {
                    continue;
                }

                $confId = $mapping[$qId] ?? null;
                $confLevel = 1;
                $confVal = null;
                if ($confId && isset($answers[$confId])) {
                    $textAns = $answers[$confId]->getTextAnswers();
                    if ($textAns && $textAns->getAnswers()) {
                        $confVal = $textAns->getAnswers()[0]->getValue();
                        $v = strtolower((string)$confVal);
                        if (in_array($v, ['3','c3','molto sicuro','molto'], true)) $confLevel = 3;
                        elseif (in_array($v, ['2','c2','abbastanza sicuro','abbastanza'], true)) $confLevel = 2;
                    }
                }
                $scoreClassic = ($answer->getGrade()?->getScore()) ?? 0;
                // se manca confidenza, considera livello minimo
                $scoreCbm = $scoreClassic > 0
                    ? ($cbmParams[$confLevel]['correct'] ?? 1)
                    : ($cbmParams[$confLevel]['wrong'] ?? 0);

                $rows[] = [
                    'id_test' => $testId,
                    'id_domanda' => $qId,
                    'id_studente_cv' => $response->getRespondentEmail() ?? 'resp',
                    'google_response_id' => $response->getResponseId(),
                    'domanda_label' => $itemTitles[$qId] ?? '',
                    'confidenza_livello' => $confLevel,
                    'confidenza_valore' => $confVal,
                    'corretta' => $scoreClassic > 0 ? 'SI' : 'NO',
                    'score_cba' => $scoreCbm,
                    'score_classico' => $scoreClassic,
                        'penalita' => $scoreCbm < 0 ? abs($scoreCbm) : 0,
                        'punteggio_normalizzato' => null,
                        'timestamp_risposta' => $response->getLastSubmittedTime() ?? $response->getCreateTime(),
                        'raw_json' => null,
                        'id_utente' => ''
                    ];
                }
            }
            $cbmFetchInfo = "Letti {$responsesCount} invii direttamente dal form (non salvati).";
        } else {
            $cbmFetchError = "Mapping domanda/confidenza non disponibile. Completa l'import CBM o rigenera il Form CBM.";
        }
    } catch (Exception $e) {
        $cbmFetchError = $e->getMessage();
    }
}

$studentStats = [];
$questionStats = [];
$questionWeights = [];
$studentNameMap = [];

// Prova a risolvere i nomi studenti (ClasseViva) per mostrare nomi invece di ID
if (!empty($rows)) {
    $uniqueStudentIds = [];
    foreach ($rows as $row) {
        $sid = $row['id_studente_cv'] ?: ($row['google_response_id'] ?? '');
        if ($sid !== '') {
            $uniqueStudentIds[(string)$sid] = true;
        }
    }
    $uniqueStudentIds = array_keys($uniqueStudentIds);

    // Fallback locale: usa MAPPATURA_STUDENTI se disponibile
    try {
        $allStudentMappings = $dbAdapter->findAll('MAPPATURA_STUDENTI');
        foreach ($allStudentMappings as $mapRow) {
            $cvId = (string)($mapRow['id_studente_cv'] ?? '');
            if ($cvId === '' || !in_array($cvId, $uniqueStudentIds, true)) {
                continue;
            }
            $name = trim(($mapRow['cognome_studente'] ?? '') . ' ' . ($mapRow['nome_studente'] ?? ''));
            if ($name !== '') {
                $studentNameMap[$cvId] = $name;
            }
        }
    } catch (Exception $e) {
        // ignora errori locali
    }

    $classevivaClassId = null;
    foreach ($rows as $row) {
        if (!empty($row['id_classe_cv'])) {
            $classevivaClassId = $row['id_classe_cv'];
            break;
        }
    }
    if (empty($classevivaClassId) && !empty($test['classroom_course_id'])) {
        $mapping = $dbAdapter->findWhere('CLASSROOM_MAPPINGS', ['id_corso_gc' => $test['classroom_course_id']]);
        if (empty($mapping)) {
            $mapping = $dbAdapter->findWhere('CLASSROOM_MAPPINGS', ['google_course_id' => $test['classroom_course_id']]);
        }
        if (!empty($mapping)) {
            $map = $mapping[0];
            $classevivaClassId = $map['classeviva_class_id'] ?? $map['id_classe_cv'] ?? null;
        }
    }

    try {
        $cvApi = new ClasseVivaAPI($config);
        if (!empty($classevivaClassId)) {
            $studentsCv = $cvApi->getStudentiClasse((string)$classevivaClassId);
            foreach ($studentsCv as $st) {
                $id = (string)($st['id'] ?? '');
                if ($id === '') {
                    continue;
                }
                $name = trim(($st['cognome'] ?? '') . ' ' . ($st['nome'] ?? ''));
                $studentNameMap[$id] = $name !== '' ? $name : ('Studente ' . $id);
            }
        } else {
            // fallback: prova a risolvere per singolo ID numerico
            foreach ($uniqueStudentIds as $sid) {
                if (!preg_match('/^\d+$/', (string)$sid)) {
                    continue;
                }
                try {
                    $st = $cvApi->getStudente((string)$sid);
                    $name = trim(($st['cognome'] ?? '') . ' ' . ($st['nome'] ?? ''));
                    if ($name !== '') {
                        $studentNameMap[(string)$sid] = $name;
                    }
                } catch (Exception $e) {
                    // ignore singolo errore
                }
            }
        }
    } catch (Exception $e) {
        // Se API CV non disponibile, si mantiene l'ID come fallback
    }
}

foreach ($rows as $row) {
    $sid = $row['id_studente_cv'] ?: ($row['google_response_id'] ?? 'resp_' . uniqid());
    $qLabel = trim($row['domanda_label'] ?? $row['id_domanda'] ?? '');
    if ($qLabel === '') {
        $qLabel = $row['id_domanda'] ?? '';
    }
    $qId = $row['id_domanda'] ?? $qLabel;
    // Registra il peso massimo per domanda (rank)
    $scoreClassic = floatval($row['score_classico'] ?? 0);
    if (!isset($questionWeights[$qId]) || $scoreClassic > $questionWeights[$qId]) {
        $questionWeights[$qId] = $scoreClassic;
    }
    if (($questionWeights[$qId] ?? 0) <= 0) {
        $questionWeights[$qId] = 1.0;
    }
    $w = $questionWeights[$qId];

    if (!isset($studentStats[$sid])) {
        $studentStats[$sid] = [
            'classic_total' => 0,
            'cbm_total' => 0,
            'malus' => 0,
            'questions' => 0,
            'correct' => 0,
            'avg_conf' => 0,
            'cbmScoreMax' => 0,
        ];
    }
    $studentStats[$sid]['classic_total'] += $scoreClassic;
    $studentStats[$sid]['cbm_total'] += floatval($row['score_cba'] ?? 0) * $w;
    $studentStats[$sid]['questions'] += 1;
    $studentStats[$sid]['avg_conf'] += intval($row['confidenza_livello'] ?? 1);
    $cbmScore = floatval($row['score_cba'] ?? 0);
    if ($cbmScore < 0) {
        $studentStats[$sid]['malus'] += abs($cbmScore);
    }

    if (($row['corretta'] ?? '') === 'SI' || floatval($row['score_classico'] ?? 0) > 0) {
        $studentStats[$sid]['correct'] += 1;
        $studentStats[$sid]['cbmScoreMax'] += $cbmParams[3]['correct'] * $w;
    }

    if ($qLabel !== '') {
        if (!isset($questionStats[$qLabel])) {
            $questionStats[$qLabel] = [
                'id' => $qId,
                'count' => 0,
                'correct' => 0,
                'avg_conf' => 0,
                'cbm_total' => 0,
                'classic_total' => 0,
            ];
        }
        $questionStats[$qLabel]['count'] += 1;
        $questionStats[$qLabel]['avg_conf'] += intval($row['confidenza_livello'] ?? 1);
        $questionStats[$qLabel]['cbm_total'] += floatval($row['score_cba'] ?? 0) * $w;
        $questionStats[$qLabel]['classic_total'] += $scoreClassic;
        if (($row['corretta'] ?? '') === 'SI' || floatval($row['score_classico'] ?? 0) > 0) {
            $questionStats[$qLabel]['correct'] += 1;
        }
    }
}

// Calcola percentuali per studenti usando rank totale (somma pesi)
$studentSeries = [];
$classicMax = max(1, array_sum($questionWeights));
$maxCbmPerQuestion = max(
    $cbmParams[1]['correct'] ?? 1,
    $cbmParams[2]['correct'] ?? 2,
    $cbmParams[3]['correct'] ?? 3
);
foreach ($studentStats as $sid => $s) {
    // accuracy = punteggio classico / rank totale
    $classicPct = $classicMax > 0 ? ($s['classic_total'] / $classicMax) * 100 : 0;
    // cbm_total va pesato coi pesi per domanda
    //$cbmPct = $classicMax > 0 ? ($s['cbm_total'] / $classicMax) * 100 : 0;
    $cbmPct = $classicMax > 0 ? ($s['cbm_total'] / $classicMax) * 100 : 0;
    //if ($cbmPct < 0) $cbmPct = 0;
    //if ($cbmPct > 300) $cbmPct = 300;
    $bonus = $cbmPct - $classicPct;
    $malus = round($s['malus'] ?? 0, 2);
    $acc = $classicPct;
    $avgConf = $s['questions'] > 0 ? $s['avg_conf'] / $s['questions'] : 1;
    $displayName = $studentNameMap[$sid] ?? $sid;
    $studentSeries[] = [
        'id' => $sid,
        'name' => $displayName,
        'classic_pct' => round($classicPct, 2),
        'cbm_pct' => round($cbmPct, 2),
        'bonus' => round($bonus, 2),
        'accuracy_pct' => round($acc, 2),
        'avg_conf' => round($avgConf, 2),
    ];
}

// Calcola stats domande
$questionSeries = [];
foreach ($questionStats as $label => $q) {
    $w = $questionWeights[$q['id']] ?? 1;
    $count = max(1, $q['count']);
    $accuracy = ($w > 0) ? ($q['classic_total'] / ($count * $w)) * 100 : 0;
    $avgConf = $q['count'] > 0 ? $q['avg_conf'] / $q['count'] : 1;
    $questionSeries[] = [
        'label' => $label,
        'accuracy' => round($accuracy, 2),
        'avg_conf' => round($avgConf, 2),
        'cbm_avg' => round($q['cbm_total'] / max(1, $q['count'] * $w), 2),
        'classic_avg' => round($q['classic_total'] / max(1, $q['count'] * $w), 2),
    ];
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Analisi CBM - <?= htmlspecialchars($test['nome'] ?? 'Test') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
    <style>
        .card { box-shadow: 0 2px 6px rgba(0,0,0,0.05); }
        .metric { font-size: 1.4rem; font-weight: 600; }
        .table-sm td, .table-sm th { padding: 0.4rem; }
        .chart-box { height: 360px; max-height: 440px; }
        .student-row { cursor: pointer; }
    </style>
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-dark">
    <div class="container-fluid">
        <a class="navbar-brand" href="index.php">UDA System</a>
        <span class="navbar-text">Analisi CBM</span>
    </div>
</nav>

<div class="container my-4">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h3 class="mb-0">Analisi CBM</h3>
            <small class="text-muted"><?= htmlspecialchars($test['nome'] ?? '') ?> (<?= htmlspecialchars($test['piattaforma'] ?? '') ?>)</small>
        </div>
        <a class="btn btn-outline-secondary" href="uda_view.php?id=<?= urlencode($test['id_uda'] ?? '') ?>">
            <- Torna alla UDA
        </a>
    </div>

    <?php if (empty($rows)): ?>
        <div class="alert alert-info">
            Non ci sono risposte CBM registrate per questo test.
            <?php if (!empty($cbmFetchError)): ?>
                <br><small class="text-danger">Tentativo di lettura dal form fallito: <?= htmlspecialchars($cbmFetchError) ?></small>
            <?php elseif (!empty($cbmFetchInfo)): ?>
                <br><small class="text-muted"><?= htmlspecialchars($cbmFetchInfo) ?></small>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <div class="row g-3 mb-3">
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Risposte</div>
                        <div class="metric"><?= count($rows) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Studenti</div>
                        <div class="metric"><?= count($studentSeries) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Domande</div>
                        <div class="metric"><?= count($questionSeries) ?></div>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card">
                    <div class="card-body">
                        <div class="text-muted small">Punteggio max</div>
                        <div class="metric"><?= $punteggioMax ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="row g-4">
            <div class="col-lg-7">
                <div class="card">
                    <div class="card-header bg-primary text-white">
                        <i class="bi bi-graph-up"></i> Accuracy vs CBM
                    </div>
                    <div class="card-body">
                        <div class="chart-box">
                            <canvas id="scatterAccCbm"></canvas>
                        </div>
                        <small class="text-muted">Bonus CBM = CBM% - Accuracy%.</small>
                    </div>
                </div>

                <div class="card mt-4">
                    <div class="card-header bg-info text-white">
                        <i class="bi bi-people"></i> Dettaglio studenti
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-hover">
                            <thead>
                            <tr>
                                <th>Studente</th>
                                <th class="text-center">Accuracy%</th>
                                <th class="text-center">CBM%</th>
                                <th class="text-center">Bonus/Malus</th>
                                <th class="text-center">Conf. media</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($studentSeries as $s): ?>
                                <tr class="student-row" data-student-id="<?= htmlspecialchars($s['id']) ?>">
                                    <td><?= htmlspecialchars($s['name'] ?? $s['id']) ?></td>
                                    <td class="text-center"><?= $s['classic_pct'] ?></td>
                                    <td class="text-center"><?= $s['cbm_pct'] ?></td>
                                    <td class="text-center"><?= $s['bonus'] ?></td>
                                    <td class="text-center"><?= $s['avg_conf'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <div class="col-lg-5">
                <div class="card">
                    <div class="card-header bg-secondary text-white">
                        <i class="bi bi-list-check"></i> Domande (accuratezza e CBM medio)
                    </div>
                    <div class="card-body">
                        <table class="table table-sm table-striped mb-0">
                            <thead>
                            <tr>
                                <th>Domanda</th>
                                <th class="text-center">Accuracy%</th>
                                <th class="text-center">CBM avg</th>
                                <th class="text-center">Conf.</th>
                            </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($questionSeries as $q): ?>
                                <tr>
                                    <td><?= htmlspecialchars($q['label']) ?></td>
                                    <td class="text-center"><?= $q['accuracy'] ?></td>
                                    <td class="text-center"><?= $q['cbm_avg'] ?></td>
                                    <td class="text-center"><?= $q['avg_conf'] ?></td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
const studentSeries = <?= json_encode($studentSeries) ?>;
const ctx = document.getElementById('scatterAccCbm').getContext('2d');
const datasetPoints = studentSeries.map(s => ({
    x: s.classic_pct,
    y: s.cbm_pct,
    id: String(s.id ?? ''),
    name: s.name || s.id
}));
const studentIndexById = new Map(datasetPoints.map((p, idx) => [String(p.id ?? ''), idx]));

// Spezzata consapevolezza minima:
// 0 < x <= 66.6% : y = x
// 66.6% < x <= 80% : y = 4x - 200
// 80% < x <= 100% : y = 9x - 600
const yMaxAxis = 300;
const piecewiseY = (x) => {
  if (x <= (2 / 3) * 100) return x;
  if (x <= 80) return 4 * x - 200;
  return 9 * x - 600;
};
const piecewisePoints = [];
for (let x = 0; x <= 100; x += 1) {
  piecewisePoints.push({ x, y: Math.min(yMaxAxis, piecewiseY(x)) });
}

// Retta della consapevolezza: y = 3x (disegnata tratteggiata)
const awarenessPoints = [];
for (let x = 0; x <= 100; x += 1) {
  awarenessPoints.push({ x, y: Math.min(yMaxAxis, 3 * x) });
}

// Colore dei punti in base alla distanza dalla spezzata e dalla retta y=3x
const colorForPoint = (x, y) => {
  const yp = piecewiseY(x);
  const ya = 3 * x;
  const diffP = y - yp;
  const diffA = y - ya;

  if (y >= ya) {
    return diffA > 30 ? '#1b5e20' : '#43a047'; // verdi sopra la retta consapevolezza
  }
  if (y >= yp) {
    return '#fbc02d'; // giallo tra spezzata e retta 3x
  }
  if (diffP >= -20) {
    return '#fb8c00'; // arancione vicino alla spezzata ma sotto
  }
  return '#e53935'; // rosso ben sotto la spezzata
};

let selectedStudentId = null;
let selectedStudentIndex = null;
const chart = new Chart(ctx, {
  type: 'scatter',
  data: {
    datasets: [
      {
        label: 'Studenti',
        data: datasetPoints,
        backgroundColor: datasetPoints.map(p => colorForPoint(p.x, p.y)),
        pointRadius: ctx => (ctx.dataIndex === selectedStudentIndex ? 10 : 4),
        pointHoverRadius: ctx => (ctx.dataIndex === selectedStudentIndex ? 12 : 6),
        pointBorderWidth: ctx => (ctx.dataIndex === selectedStudentIndex ? 3 : 1),
        pointBorderColor: ctx => (ctx.dataIndex === selectedStudentIndex ? '#000' : '#fff')
      },
      {
        label: 'Spezzata consapevolezza assente',
        data: piecewisePoints,
        type: 'line',
        borderColor: '#ff9900',
        borderWidth: 2,
        pointRadius: 0,
        fill: false
      },
      {
        label: 'Retta della consapevolezza (y = 3x)',
        data: awarenessPoints,
        type: 'line',
        borderColor: '#555',
        borderWidth: 1,
        pointRadius: 0,
        borderDash: [6, 4],
        fill: false
      }
    ]
  },
  options: {
    maintainAspectRatio: false,
    onClick: (evt, elements) => {
      if (!elements || elements.length === 0) return;
      const el = elements[0];
      if (el.datasetIndex !== 0) return;
      const idx = el.index;
      const sid = datasetPoints[idx]?.id;
      if (sid) {
        selectStudent(String(sid), idx);
      }
    },
    scales: {
      x: { title: { display: true, text: 'Accuracy %' }, min: 0, max: 100 },
      y: { title: { display: true, text: 'CBM %' }, min: -200, max: yMaxAxis }
    },
    plugins: {
      tooltip: {
        callbacks: {
          label: ctx => `${ctx.raw.name}: acc ${ctx.raw.x}% / cbm ${ctx.raw.y}%`
        }
      },
      legend: { display: true }
    }
  }
});

const selectStudent = (studentId, index = null) => {
  const sid = String(studentId ?? '');
  selectedStudentId = sid;
  selectedStudentIndex = index !== null && index !== undefined
    ? index
    : (studentIndexById.get(sid) ?? null);
  document.querySelectorAll('tr.student-row').forEach(row => {
    row.classList.toggle('table-active', row.dataset.studentId === sid);
  });
  if (selectedStudentIndex !== null) {
    chart.setActiveElements([{ datasetIndex: 0, index: selectedStudentIndex }]);
  } else {
    chart.setActiveElements([]);
  }
  chart.update();
};

document.querySelectorAll('tr.student-row').forEach(row => {
  row.addEventListener('click', () => {
    selectStudent(String(row.dataset.studentId ?? ''));
  });
});
</script>
</body>
</html>






