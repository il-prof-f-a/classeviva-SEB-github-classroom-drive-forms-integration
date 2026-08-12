<?php
/**
 * Generazione Automatica Google Forms dalle Domande
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\GoogleFormsBuilder;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$udaId = $_GET['id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$udaId) {
    die("ID UDA mancante");
}

$udaComplete = $udaManager->getUDAComplete($udaId);
if (!$udaComplete) {
    die("UDA non trovata");
}

$uda = $udaComplete['uda'];

// Carica domande
$allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
$domande = array_filter($allDomande, function($d) use ($udaId) {
    return ($d['id_uda'] ?? '') === $udaId;
});

// Ordina per ordine_consigliato
usort($domande, function($a, $b) {
    $orderA = $a['ordine_consigliato'] ?? 999;
    $orderB = $b['ordine_consigliato'] ?? 999;
    return $orderA <=> $orderB;
});

// Raggruppa per argomento
$domandePerArgomento = [];
foreach ($domande as $domanda) {
    $arg = $domanda['argomento'] ?? 'Generale';
    if (!isset($domandePerArgomento[$arg])) {
        $domandePerArgomento[$arg] = [];
    }
    $domandePerArgomento[$arg][] = $domanda;
}

$successMessage = null;
$errorMessage = null;
$mappingWarning = null;
$generatedFormUrl = null;
$googleIntegrationsUrl = app_url('public/user_integrations.php#google-section');

/**
 * Ricava il mapping domanda/confidenza leggendo gli item del form (domanda seguita da item con "sicuro")
 */
function rebuildCbmMappingFromForm(array $config, string $testId, string $formId, array $cbmLevels = []): array
{
    try {
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([Forms::FORMS_BODY_READONLY]);
        $credentialsPath = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
        $client->setAuthConfig($credentialsPath);
        $tokenData = \App\Core\GoogleTokenProvider::getToken($config);
        if (empty($tokenData)) {
            return [];
        }
        $client->setAccessToken($tokenData);
        $service = new Forms($client);
        $form = $service->forms->get($formId);
        $items = $form->getItems() ?? [];
        $mapping = [];
        $db = DatabaseFactory::createWithInitialization($config, true);

        $prevQuestionId = null;
        $keywords = ['sicuro', 'sure', 'conf', 'fiducia', 'confidence'];
        foreach ($items as $item) {
            $itemId = $item->getItemId();
            $title = strtolower($item->getTitle() ?? '');
            $questionItem = $item->getQuestionItem();
            $questionObj = $questionItem ? $questionItem->getQuestion() : null;
            $questionId = $questionObj ? $questionObj->getQuestionId() : $itemId;
            $isConfidence = false;
            foreach ($keywords as $kw) {
                if (strpos($title, $kw) !== false) {
                    $isConfidence = true;
                    break;
                }
            }

            if ($questionItem && $questionObj) {
                if ($isConfidence && $prevQuestionId) {
                    $mapping[$prevQuestionId] = $questionId;
                    $prevQuestionId = null;
                    continue;
                }
                $prevQuestionId = $questionId;
            } else {
                if ($prevQuestionId && $isConfidence) {
                    $mapping[$prevQuestionId] = $questionId;
                    $prevQuestionId = null;
                }
            }
        }

        if (!empty($mapping)) {
            // pulisci e salva mapping
            $existing = $db->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]);
            foreach ($existing as $m) {
                if (!empty($m['id_mapping'])) {
                    $db->deleteRow('TEST_CBM_MAPPING', $m['id_mapping'], 'id_mapping');
                }
            }
            foreach ($mapping as $q => $c) {
                $db->insertRow('TEST_CBM_MAPPING', [
                    'id_mapping' => 'CBMMAP_' . uniqid(),
                    'id_test' => $testId,
                    'id_domanda' => $q,
                    'form_item_id' => $q,
                    'confidence_item_id' => $c,
                    'config_json' => json_encode($cbmLevels),
                ]);
            }
        }
        return $mapping;
    } catch (\Throwable $e) {
        @file_put_contents(ROOT_PATH . '/storage/logs/generate_google_form.log', '[' . date('c') . "] mapping rebuild error: " . $e->getMessage() . "\n", FILE_APPEND);
        return [];
    }
}

function parseExtraDataForPreview(array $domanda): array
{
    $note = $domanda['note'] ?? '';
    if (!empty($note) && is_string($note)) {
        $decoded = json_decode($note, true);
        if (is_array($decoded)) {
            return $decoded;
        }
        $marker = 'EXTRA_JSON:';
        $pos = strpos($note, $marker);
        if ($pos !== false) {
            $jsonPart = substr($note, $pos + strlen($marker));
            $decoded = json_decode($jsonPart, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    $rispostaAttesa = $domanda['risposta_attesa'] ?? '';
    if (!empty($rispostaAttesa) && is_string($rispostaAttesa)) {
        $decoded = json_decode($rispostaAttesa, true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

function parseRispostaAttesaOptions(string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [];
    }
    $parts = preg_split('/\s*;\s*/', $text);
    $items = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $correct = false;
        if (stripos($part, '[CORRETTA]') === 0) {
            $correct = true;
            $part = trim(str_ireplace('[CORRETTA]', '', $part));
        }
        if ($part === '') {
            continue;
        }
        $items[] = ['text' => $part, 'correct' => $correct];
    }
    return $items;
}

function buildAnswerPreviewItems(array $domanda): array
{
    $tipo = strtolower($domanda['tipo_domanda'] ?? ($domanda['tipo'] ?? 'aperta'));
    $extra = parseExtraDataForPreview($domanda);
    $items = [];

    switch ($tipo) {
        case 'multipla':
        case 'multipla_multi':
            if (!empty($extra['risposte']) && is_array($extra['risposte'])) {
                foreach ($extra['risposte'] as $opt) {
                    $text = trim((string)($opt['testo'] ?? ''));
                    if ($text === '') {
                        continue;
                    }
                    $items[] = [
                        'text' => $text,
                        'correct' => !empty($opt['corretta'])
                    ];
                }
            } else {
                $items = parseRispostaAttesaOptions((string)($domanda['risposta_attesa'] ?? ''));
            }
            break;

        case 'vero_falso':
            $isTrue = null;
            if (array_key_exists('risposta_corretta', $extra)) {
                $isTrue = filter_var($extra['risposta_corretta'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
            }
            if ($isTrue === null) {
                $ra = strtoupper(trim((string)($domanda['risposta_attesa'] ?? '')));
                if ($ra === 'VERO' || $ra === 'TRUE') {
                    $isTrue = true;
                } elseif ($ra === 'FALSO' || $ra === 'FALSE') {
                    $isTrue = false;
                }
            }
            $items[] = ['text' => 'VERO', 'correct' => $isTrue === true];
            $items[] = ['text' => 'FALSO', 'correct' => $isTrue === false];
            break;

        case 'breve':
            $accepted = $extra['risposte_accettate'] ?? [];
            if (empty($accepted)) {
                $ra = trim((string)($domanda['risposta_attesa'] ?? ''));
                if (preg_match('/^Risposte accettate:\\s*(.+)$/i', $ra, $match)) {
                    $accepted = array_map('trim', explode(',', $match[1]));
                } elseif ($ra !== '') {
                    $accepted = [$ra];
                }
            }
            foreach ((array)$accepted as $acc) {
                $acc = trim((string)$acc);
                if ($acc === '') {
                    continue;
                }
                $items[] = ['text' => $acc, 'correct' => true];
            }
            break;

        case 'numerica':
            $val = $extra['valore_corretto'] ?? null;
            if ($val !== null && $val !== '') {
                $text = (string)$val;
                $tol = $extra['tolleranza'] ?? null;
                if ($tol !== null && $tol !== '') {
                    $text .= ' +/- ' . $tol;
                }
                $unit = trim((string)($extra['unita_misura'] ?? ''));
                if ($unit !== '') {
                    $text .= ' ' . $unit;
                }
                $items[] = ['text' => $text, 'correct' => true];
            } else {
                $ra = trim((string)($domanda['risposta_attesa'] ?? ''));
                if ($ra !== '') {
                    $items[] = ['text' => $ra, 'correct' => true];
                }
            }
            break;

        case 'aperta':
        default:
            $ra = trim((string)($domanda['risposta_attesa'] ?? ''));
            if ($ra !== '') {
                $items[] = ['text' => $ra, 'correct' => true];
            }
            break;
    }

    return $items;
}
// Gestione generazione form
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($action === 'generate_form' || $action === 'generate_form_cbm')) {
    try {
        // Domande selezionate
        $selectedQuestions = $_POST['questions'] ?? [];

        if (empty($selectedQuestions)) {
            throw new Exception("Seleziona almeno una domanda!");
        }

        // Titolo e descrizione del form
        $formTitle = $_POST['form_title'] ?? "Test: " . $uda->titolo;
        $formDescription = $_POST['form_description'] ?? "Domande per la verifica dell'UDA: " . $uda->titolo;

        $isCbm = ($action === 'generate_form_cbm');
        $cbmLevels = [
            'c1' => ['label' => 'Poco sicuro', 'correct' => 1, 'wrong' => 0],
            'c2' => ['label' => 'Abbastanza sicuro', 'correct' => 2, 'wrong' => -2],
            'c3' => ['label' => 'Molto sicuro', 'correct' => 3, 'wrong' => -6],
        ];
        $cbmScoringModel = 'default_c1_1_0_c2_2_-2_c3_3_-6';

        // Prepara domande selezionate
        $domandeSelezionate = [];
        foreach ($domande as $d) {
            if (in_array($d['id_domanda'], $selectedQuestions, true)) {
                $d['tipo'] = $d['tipo_domanda'] ?? ($d['tipo'] ?? 'aperta');
                $domandeSelezionate[] = $d;
            }
        }

        $rootFolderId = $config['google']['drive']['root_folder_id'] ?? '';

        $builder = new GoogleFormsBuilder($config);
        $result = $isCbm
            ? $builder->createFormWithConfidence($domandeSelezionate, $formTitle, $formDescription, $rootFolderId ?: null, $cbmLevels)
            : $builder->createForm($domandeSelezionate, $formTitle, $formDescription, $rootFolderId ?: null);
        $formId = $result['formId'];
        $generatedFormUrl = $result['editUrl'];

        // Salva il form nel database TEST
        $cbmConfig = $result['cbm_config'] ?? [];
        $testData = [
            'id_test' => 'TEST_' . uniqid(),
            'id_uda' => $udaId,
            'tipo_test' => $_POST['tipo_test'] ?? 'finale',
            'nome' => $formTitle,
            'descrizione' => $formDescription,
            'piattaforma' => 'google-forms',
            'url' => $result['responderUrl'],
            'url_docente' => $result['editUrl'],
            'url_studenti' => $result['responderUrl'],
            'id_esterno' => $formId,
            'num_domande' => count($selectedQuestions),
            'durata_minuti' => $_POST['durata_minuti'] ?? 30,
            'punteggio_max' => count($selectedQuestions) * 10,
            'soglia_sufficienza' => (count($selectedQuestions) * 10) * 0.6,
            'data_creazione' => date('d/m/Y'),
            'pubblicato' => 'NO',
            'risultati_importati' => 'NO',
            'note' => 'Generato automaticamente dal sistema',
            'cbm_enabled' => $isCbm ? 'YES' : 'NO',
            'cbm_levels_json' => $isCbm ? json_encode($cbmLevels) : null,
            'cbm_scoring_model' => $isCbm ? $cbmScoringModel : null,
            'cbm_form_config_json' => $isCbm ? json_encode($cbmConfig) : null,
        ];

        $dbAdapter->insertRow('TEST', $testData);

        // Se CBM, salva mapping domanda/confidenza
        if ($isCbm && !empty($result['cbm_config']['mapping'])) {
            foreach ($result['cbm_config']['mapping'] as $questionItemId => $confItemId) {
                $dbAdapter->insertRow('TEST_CBM_MAPPING', [
                    'id_mapping' => 'CBMMAP_' . uniqid(),
                    'id_test' => $testData['id_test'],
                    'id_domanda' => $questionItemId,
                    'form_item_id' => $questionItemId,
                    'confidence_item_id' => $confItemId,
                    'ordine' => null,
                    'punteggio_domanda' => null,
                    'max_score' => null,
                    'config_json' => json_encode($result['cbm_config']['levels'] ?? []),
                ]);
            }
        }

        // riallinea mapping con gli item reali del form (pair domanda/confidenza successiva)
        if ($isCbm) {
            $liveMapping = rebuildCbmMappingFromForm($config, $testData['id_test'], $formId, $cbmLevels);
            if (!empty($liveMapping)) {
                // aggiorna config JSON con mapping effettivo
                $dbAdapter->updateRow('TEST', 'id_test', $testData['id_test'], [
                    'cbm_form_config_json' => json_encode(['mapping' => $liveMapping, 'levels' => $cbmLevels])
                ]);
            } else {
                $mappingWarning = "Form CBM creato ma mapping domanda/confidenza non ricostruito. Verifica la struttura del form prima di usarlo.";
            }
        }

        $successMessage = "Google Form creato con successo! Test salvato nel database.";

    } catch (\Throwable $e) {
        $errorMessage = "Errore durante la creazione del form: " . $e->getMessage();
        @file_put_contents(
            ROOT_PATH . '/storage/logs/generate_google_form.log',
            '[' . date('c') . "] " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n\n",
            FILE_APPEND
        );
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genera Google Form - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .question-item {
            transition: all 0.3s;
            cursor: pointer;
        }
        .question-item:hover {
            background-color: #f8f9fa;
        }
        .question-item.selected {
            background-color: #d1e7dd;
            border-left: 4px solid #198754;
        }
        .answer-preview {
            margin-top: 0.5rem;
            font-size: 0.9rem;
            color: #495057;
        }
        .answer-line {
            display: flex;
            align-items: flex-start;
            gap: 0.5rem;
        }
        .answer-icon {
            line-height: 1.2;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-file-earmark-text"></i> Genera Google Form';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a class="nav-link" href="uda_questions.php?id=' . urlencode($udaId) . '"><i class="bi bi-arrow-left"></i> Torna alle Domande</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-4">
        <div class="d-flex justify-content-end align-items-center mb-4">
<?php if (!empty($domande)): ?>
                <div class="btn-group">
                    <button type="submit" form="generateFormForm" name="action" value="generate_form" class="btn btn-success">
                        <i class="bi bi-file-earmark-text"></i> Genera Google Form
                    </button>
                    <button type="submit" form="generateFormForm" name="action" value="generate_form_cbm" class="btn btn-warning">
                        <i class="bi bi-activity"></i> Genera Form CBM
                    </button>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                <?php if ($generatedFormUrl): ?>
                    <hr>
                    <h5>Form Generato:</h5>
                    <a href="<?= htmlspecialchars($generatedFormUrl) ?>" target="_blank" class="btn btn-success">
                        <i class="bi bi-box-arrow-up-right"></i> Apri Google Form (Editor)
                    </a>
                    <a href="uda_tests.php?id=<?= urlencode($udaId) ?>" class="btn btn-primary">
                        <i class="bi bi-clipboard-check"></i> Vai ai Test
                    </a>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($mappingWarning): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-info-circle"></i> <?= htmlspecialchars($mappingWarning) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (empty($domande)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Non ci sono domande per questa UDA.
                <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="alert-link">Aggiungi prima alcune domande</a>.
            </div>
        <?php else: ?>
            <form method="POST" id="generateFormForm">

                <!-- Configurazione Form -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-gear"></i> Configurazione Form
                        </h5>
                    </div>
                    <div class="card-body">
                        <div class="row g-3">
                            <div class="col-md-8">
                                <label class="form-label">Titolo Form *</label>
                                <input type="text" name="form_title" class="form-control" required
                                       value="Verifica: <?= htmlspecialchars($uda->titolo) ?>">
                            </div>

                            <div class="col-md-4">
                                <label class="form-label">Tipo Test</label>
                                <select name="tipo_test" class="form-select">
                                    <option value="prerequisiti">Prerequisiti</option>
                                    <option value="intermedio">Intermedio</option>
                                    <option value="finale" selected>Finale</option>
                                </select>
                            </div>

                            <div class="col-md-10">
                                <label class="form-label">Descrizione</label>
                                <textarea name="form_description" class="form-control" rows="2"><?= htmlspecialchars("Verifica delle conoscenze per l'UDA: " . $uda->titolo) ?></textarea>
                            </div>

                            <div class="col-md-2">
                                <label class="form-label">Durata (min)</label>
                                <input type="number" name="durata_minuti" class="form-control" value="30" min="1">
                            </div>
                            <div class="col-12">
                                <div class="form-text">
                                    Se configurati, i template Google Forms (standard e CBM) verranno usati automaticamente.
                                    <a href="<?= htmlspecialchars($googleIntegrationsUrl) ?>">Configura in Integrazioni Google</a>.
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Selezione Domande -->
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-check"></i> Seleziona Domande
                            </h5>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-light" onclick="selectAllQuestions()">
                                    <i class="bi bi-check-all"></i> Seleziona/Deseleziona Tutte
                                </button>
                                <button type="button" class="btn btn-outline-light" onclick="selectQuestionsByType('aperta')">
                                    Aperte
                                </button>
                                <button type="button" class="btn btn-outline-light" onclick="selectQuestionsByType('chiusa')">
                                    Chiuse
                                </button>
                                <button type="button" class="btn btn-outline-light" onclick="selectQuestionsByType('vero_falso')">
                                    V/F
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Clicca sulle domande per selezionarle/deselezionarle. Le domande selezionate saranno aggiunte al Google Form.
                        </p>

                        <?php foreach ($domandePerArgomento as $argomento => $questList): ?>
                            <h5 class="mt-4 mb-3 d-flex align-items-center justify-content-between">
                                <span>
                                    <i class="bi bi-folder"></i> <?= htmlspecialchars($argomento) ?>
                                </span>
                                <button type="button"
                                        class="btn btn-sm btn-outline-secondary"
                                        onclick="selectQuestionsByArgomento('<?= htmlspecialchars($argomento) ?>')">
                                    <i class="bi bi-check2-square"></i> Seleziona tutte
                                </button>
                            </h5>

                            <?php foreach ($questList as $q): ?>
                                <?php
                                    $tipo = strtolower($q['tipo_domanda'] ?? ($q['tipo'] ?? 'aperta'));
                                    $previewItems = buildAnswerPreviewItems($q);
                                ?>
                                <div class="card question-item mb-2"
                                     data-question-id="<?= htmlspecialchars($q['id_domanda']) ?>"
                                     data-question-type="<?= htmlspecialchars($tipo) ?>"
                                     data-argomento="<?= htmlspecialchars($argomento) ?>"
                                     onclick="toggleQuestion(this, '<?= htmlspecialchars($q['id_domanda']) ?>')">
                                    <div class="card-body">
                                        <div class="form-check">
                                            <input class="form-check-input question-checkbox"
                                                   type="checkbox"
                                                   name="questions[]"
                                                   value="<?= htmlspecialchars($q['id_domanda']) ?>"
                                                   id="q_<?= htmlspecialchars($q['id_domanda']) ?>">
                                            <label class="form-check-label w-100" for="q_<?= htmlspecialchars($q['id_domanda']) ?>">
                                                <strong><?= htmlspecialchars($q['domanda']) ?></strong>
                                                <?php if (!empty($previewItems)): ?>
                                                    <div class="answer-preview">
                                                        <?php foreach ($previewItems as $ans): ?>
                                                            <?php $isCorrect = !empty($ans['correct']); ?>
                                                            <div class="answer-line">
                                                                <i class="bi <?= $isCorrect ? 'bi-check-square-fill text-success' : 'bi-square text-muted' ?> answer-icon"></i>
                                                                <span><?= htmlspecialchars($ans['text'] ?? '') ?></span>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                                <div class="mt-2">
                                                    <span class="badge bg-info">
                                                        Difficoltà: <?= $q['difficolta'] ?? 3 ?>/5
                                                    </span>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-clock"></i> <?= $q['tempo_risposta_min'] ?? 3 ?> min
                                                    </span>
                                                </div>
                                            </label>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Anteprima -->
                <div class="card mb-4">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="bi bi-eye"></i> Anteprima
                        </h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2">
                            <strong>Domande selezionate:</strong> <span id="selectedCount">0</span>
                        </p>
                        <p class="mb-2">
                            <strong>Tempo stimato:</strong> <span id="estimatedTime">0</span> minuti
                        </p>
                        <p class="mb-0">
                            <strong>Punteggio massimo:</strong> <span id="maxScore">0</span> punti (10 punti per domanda)
                        </p>
                    </div>
                </div>

                <!-- Azioni -->
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-5">
                    <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Annulla
                    </a>
                    <button type="submit" name="action" value="generate_form" class="btn btn-success btn-lg">
                        <i class="bi bi-file-earmark-text"></i> Genera Google Form
                    </button>
                    <button type="submit" name="action" value="generate_form_cbm" class="btn btn-warning btn-lg">
                        <i class="bi bi-activity"></i> Genera Form CBM
                    </button>
                </div>
            </form>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function setCardSelection(card, checked) {
            const checkbox = card.querySelector('.question-checkbox');
            if (!checkbox) return;
            checkbox.checked = checked;
            if (checked) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        }

        function toggleQuestion(card, questionId) {
            const checkbox = card.querySelector('.question-checkbox');
            const newState = !checkbox.checked;
            setCardSelection(card, newState);
            updatePreview();
        }

        function selectAllQuestions() {
            const cards = document.querySelectorAll('.question-item');
            const allSelected = Array.from(cards).every(card => {
                const cb = card.querySelector('.question-checkbox');
                return cb && cb.checked;
            });

            cards.forEach(card => {
                setCardSelection(card, !allSelected);
            });

            updatePreview();
        }

        function selectQuestionsByType(group) {
            const cards = document.querySelectorAll('.question-item');
            cards.forEach(card => {
                const tipo = (card.dataset.questionType || 'aperta').toLowerCase();
                let mapped;
                switch (tipo) {
                    case 'multipla':
                    case 'multipla_multi':
                        mapped = 'chiusa';
                        break;
                    case 'vero_falso':
                        mapped = 'vero_falso';
                        break;
                    default:
                        mapped = 'aperta';
                }
                if (mapped === group) {
                    setCardSelection(card, true);
                }
            });

            updatePreview();
        }

        function selectQuestionsByArgomento(argomento) {
            const cards = document.querySelectorAll('.question-item');
            cards.forEach(card => {
                if (card.dataset.argomento === argomento) {
                    setCardSelection(card, true);
                }
            });
            updatePreview();
        }

        function updatePreview() {
            const checkboxes = document.querySelectorAll('.question-checkbox:checked');
            const count = checkboxes.length;

            document.getElementById('selectedCount').textContent = count;
            document.getElementById('estimatedTime').textContent = count * 3; // Stima 3 min per domanda
            document.getElementById('maxScore').textContent = count * 10;
        }

        // Inizializza al caricamento
        document.addEventListener('DOMContentLoaded', updatePreview);
    </script>
</body>
</html>
