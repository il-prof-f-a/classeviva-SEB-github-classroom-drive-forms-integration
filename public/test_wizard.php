<?php
/**
 * Wizard di creazione test end-to-end per una UDA.
 * Step: import domande -> genera Google Form -> crea/aggiorna test -> pubblica bozza su Classroom -> link finale.
 */

error_reporting(E_ALL);

session_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\GoogleClassroomAPI;
use App\Integration\GoogleDriveAPI;
use Google\Client;
use App\Integration\GoogleFormsBuilder;
use Google\Service\Forms;
use Google\Service\Drive;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$udaId = $_GET['id'] ?? null;

if (!$udaId) {
    die("ID UDA mancante");
}

$udaComplete = $udaManager->getUDAComplete($udaId);
if (!$udaComplete) {
    die("UDA non trovata");
}

$uda = $udaComplete['uda'];
$classiAssegnate = $udaComplete['classi_assegnate'] ?? [];

// Ricava il primo courseId da eventuale classroom_url delle classi assegnate
$defaultCourseId = null;
foreach ($classiAssegnate as $classe) {
    $url = $classe['classroom_url'] ?? '';
    if (preg_match('#/c/([a-zA-Z0-9_-]+)#', $url, $m)) {
        $defaultCourseId = $m[1];
        break;
    }
}

$wizardKey = 'test_wizard_' . $udaId;
if (!isset($_SESSION[$wizardKey])) {
    $_SESSION[$wizardKey] = [];
}
$wizardState = &$_SESSION[$wizardKey];

$action = $_POST['action'] ?? null;
$successMessage = null;
$errorMessage = null;
$infoMessage = null;
$templateWarning = null;

/**
 * Utility: carica tutte le domande di una UDA ordinate.
 */
function loadDomandePerUda($dbAdapter, $udaId): array
{
    $all = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
    $domande = array_values(array_filter($all, fn($d) => ($d['id_uda'] ?? '') === $udaId));

    usort($domande, function ($a, $b) {
        $orderA = $a['ordine_consigliato'] ?? 999;
        $orderB = $b['ordine_consigliato'] ?? 999;
        return $orderA <=> $orderB;
    });

    return $domande;
}

/**
 * Utility: trova un test per ID.
 */
function findTestById($dbAdapter, string $testId): ?array
{
    $all = $dbAdapter->findAll('TEST');
    foreach ($all as $t) {
        if (($t['id_test'] ?? '') === $testId) {
            return $t;
        }
    }
    return null;
}
/**
 * Import da JSON.
 */
function importFromJSONWizard($dbAdapter, string $udaId, ?array $file): array
{
    if (!$file) {
        return ['success' => false, 'error' => 'Nessun file selezionato'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Errore upload file'];
    }

    $jsonContent = file_get_contents($file['tmp_name']);
    $data = json_decode($jsonContent, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'JSON non valido: ' . json_last_error_msg()];
    }

    $domande = $data['domande'] ?? [];
    $importate = 0;
    $errori = 0;
    $ids = [];

    foreach ($domande as $index => $d) {
        try {
            if (empty($d['domanda'])) {
                $errori++;
                continue;
            }

            $idDomanda = 'DOM_' . uniqid();
            $extra = buildExtraNote($d);

            $domandaData = [
                'id_domanda' => $idDomanda,
                'id_uda' => $udaId,
                'argomento' => trim($d['argomento'] ?? 'Generale'),
                'domanda' => trim($d['domanda']),
                'risposta_attesa' => convertRispostaAttesaWizard($d),
                'parole_chiave' => convertParoleChiaveWizard($d['parole_chiave'] ?? []),
                'difficolta' => $d['difficolta'] ?? 3,
                'tempo_risposta_min' => $d['tempo_risposta_min'] ?? 3,
                'collegata_a' => $d['collegata_a'] ?? '',
                'ordine_consigliato' => $d['ordine_consigliato'] ?? ($index + 1),
                'note' => $extra ?? ($d['note'] ?? ''),
                'tipo_domanda' => $d['tipo'] ?? ($d['tipo_domanda'] ?? 'aperta'),
                'data_creazione' => date('Y-m-d')
            ];

            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
            $importate++;
            $ids[] = $idDomanda;
        } catch (Exception $e) {
            $errori++;
        }
    }

    return [
        'success' => $importate > 0,
        'importate' => $importate,
        'errori' => $errori,
        'inserted_ids' => $ids
    ];
}

/**
 * Import da CSV.
 */
function importFromCSVWizard($dbAdapter, string $udaId, ?array $file): array
{
    if (!$file) {
        return ['success' => false, 'error' => 'Nessun file selezionato'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'Errore upload file'];
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Impossibile leggere il CSV'];
    }

    $headers = fgetcsv($handle);
    if (!$headers || !in_array('domanda', $headers)) {
        fclose($handle);
        return ['success' => false, 'error' => 'CSV non valido: manca colonna "domanda"'];
    }

    $importate = 0;
    $errori = 0;
    $ids = [];
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        try {
            $data = array_combine($headers, $row);
            if (empty($data['domanda'])) {
                $errori++;
                continue;
            }

            $extra = buildExtraNote($data);

            $idDomanda = 'DOM_' . uniqid();
            $domandaData = [
                'id_domanda' => $idDomanda,
                'id_uda' => $udaId,
                'argomento' => $data['argomento'] ?? 'Generale',
                'domanda' => trim($data['domanda']),
                'risposta_attesa' => $data['risposta_attesa'] ?? '',
                'parole_chiave' => $data['parole_chiave'] ?? '',
                'difficolta' => intval($data['difficolta'] ?? 3),
                'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                'collegata_a' => $data['collegata_a'] ?? '',
                'ordine_consigliato' => intval($data['ordine_consigliato'] ?? $lineNumber),
                'note' => $extra ?? ($data['note'] ?? ''),
                'tipo_domanda' => $data['tipo'] ?? ($data['tipo_domanda'] ?? 'aperta'),
                'data_creazione' => date('Y-m-d')
            ];

            if (isset($data['risposte']) && !empty($data['risposte'])) {
                $parts = explode('|', $data['risposte']);
                $risposte = [];
                foreach ($parts as $r) {
                    if (strpos($r, '*') !== false) {
                        $risposte[] = '[CORRETTA] ' . str_replace('*', '', $r);
                    } else {
                        $risposte[] = $r;
                    }
                }
                $domandaData['risposta_attesa'] = implode('; ', $risposte);
            }

            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
            $importate++;
            $ids[] = $idDomanda;
        } catch (Exception $e) {
            $errori++;
        }
    }

    fclose($handle);

    return [
        'success' => $importate > 0,
        'importate' => $importate,
        'errori' => $errori,
        'inserted_ids' => $ids
    ];
}
/**
 * Import da Excel.
 */
function importFromExcelWizard($dbAdapter, string $udaId, ?array $file): array
{
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File non caricato correttamente'];
    }

    try {
        \App\Core\Security\UploadPolicy::assertValid((string)($file['name'] ?? ''), (string)$file['tmp_name'], 'spreadsheet');
        \App\Core\Security\SpreadsheetPolicy::assertWithinLimits((string)$file['tmp_name']);
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            return ['success' => false, 'error' => 'Excel vuoto'];
        }

        $headers = $rows[0];
        $importate = 0;
        $errori = 0;
        $ids = [];

        for ($i = 1; $i < count($rows); $i++) {
            try {
                $data = array_combine($headers, $rows[$i]);
                if (empty($data['domanda'])) {
                    $errori++;
                    continue;
                }

                $extra = buildExtraNote($data);

                $idDomanda = 'DOM_' . uniqid();
                $domandaData = [
                    'id_domanda' => $idDomanda,
                    'id_uda' => $udaId,
                    'argomento' => $data['argomento'] ?? 'Generale',
                    'domanda' => trim($data['domanda']),
                    'risposta_attesa' => convertRispostaExcelWizard($data),
                    'parole_chiave' => $data['parole_chiave'] ?? '',
                    'difficolta' => intval($data['difficolta'] ?? 3),
                    'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                    'collegata_a' => $data['collegata_a'] ?? '',
                    'ordine_consigliato' => intval($data['ordine_consigliato'] ?? ($i + 1)),
                    'note' => $extra ?? ($data['note'] ?? ''),
                    'tipo_domanda' => $data['tipo'] ?? ($data['tipo_domanda'] ?? 'aperta'),
                    'data_creazione' => date('Y-m-d')
                ];

                $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
                $importate++;
                $ids[] = $idDomanda;
            } catch (Exception $e) {
                $errori++;
            }
        }

        return [
            'success' => $importate > 0,
            'importate' => $importate,
            'errori' => $errori,
            'inserted_ids' => $ids
        ];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore lettura Excel: ' . $e->getMessage()];
    }
}

/**
 * Helpers di conversione.
 */
function convertRispostaAttesaWizard(array $domanda): string
{
    $tipo = $domanda['tipo'] ?? 'aperta';

    switch ($tipo) {
        case 'multipla':
        case 'multipla_multi':
            $risposte = $domanda['risposte'] ?? [];
            $text = [];
            foreach ($risposte as $r) {
                if ($r['corretta'] ?? false) {
                    $text[] = '[CORRETTA] ' . $r['testo'];
                } else {
                    $text[] = $r['testo'];
                }
            }
            return implode('; ', $text);

        case 'vero_falso':
            return ($domanda['risposta_corretta'] ?? false) ? 'VERO' : 'FALSO';

        case 'breve':
            $accettate = $domanda['risposte_accettate'] ?? [];
            return 'Risposte accettate: ' . implode(', ', $accettate);

        case 'numerica':
            $valore = $domanda['valore_corretto'] ?? 0;
            $tolleranza = $domanda['tolleranza'] ?? 0;
            $unita = $domanda['unita_misura'] ?? '';
            return $valore . ' +/- ' . $tolleranza . ' ' . $unita;

        default:
            return $domanda['risposta_attesa'] ?? '';
    }
}

function convertRispostaExcelWizard(array $data): string
{
    $tipo = $data['tipo'] ?? 'aperta';
    if ($tipo === 'multipla') {
        $opzioni = [];
        $corretta = strtoupper($data['risposta_corretta'] ?? 'A');

        foreach (['A' => 'opzione_a', 'B' => 'opzione_b', 'C' => 'opzione_c', 'D' => 'opzione_d', 'E' => 'opzione_e'] as $letter => $key) {
            if (!empty($data[$key])) {
                $prefix = ($letter === $corretta) ? '[CORRETTA] ' : '';
                $opzioni[] = $prefix . $data[$key];
            }
        }

        return implode('; ', $opzioni);
    }

    return $data['risposta_attesa'] ?? '';
}

function convertParoleChiaveWizard($parole): string
{
    if (is_array($parole)) {
        return implode(',', $parole);
    }
    return $parole ?? '';
}

/**
 * Serializza dati extra (risposte, spiegazioni, numerica) in JSON nella nota.
 */
function buildExtraNote(array $domanda): ?string
{
    $fields = [
        'risposte' => $domanda['risposte'] ?? null,
        'risposte_accettate' => $domanda['risposte_accettate'] ?? null,
        'risposta_corretta' => $domanda['risposta_corretta'] ?? null,
        'spiegazione' => $domanda['spiegazione'] ?? null,
        'valore_corretto' => $domanda['valore_corretto'] ?? null,
        'tolleranza' => $domanda['tolleranza'] ?? null,
        'unita_misura' => $domanda['unita_misura'] ?? null,
        'case_sensitive' => $domanda['case_sensitive'] ?? null
    ];

    // Rimuove chiavi null per non sporcare la nota
    $clean = array_filter($fields, fn($v) => $v !== null && $v !== '');
    if (empty($clean)) {
        return null;
    }

    return json_encode($clean, JSON_UNESCAPED_UNICODE);
}

/**
 * Estrae i dati extra (risposte, spiegazione, valori numerici) dalla nota o da un payload JSON.
 */
function parseExtraData(array $domanda): array
{
    $note = $domanda['note'] ?? '';

    // Se la nota è un JSON valido
    if (!empty($note)) {
        $decoded = json_decode($note, true);
        if (is_array($decoded)) {
            return $decoded;
        }

        // Gestisce il formato prefissato EXTRA_JSON:
        if (str_contains($note, 'EXTRA_JSON:')) {
            $jsonPart = substr($note, strpos($note, 'EXTRA_JSON:') + strlen('EXTRA_JSON:'));
            $decoded = json_decode($jsonPart, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    // Fallback: se risposta_attesa è un JSON
    if (!empty($domanda['risposta_attesa'])) {
        $decoded = json_decode($domanda['risposta_attesa'], true);
        if (is_array($decoded)) {
            return $decoded;
        }
    }

    return [];
}

/**
 * Costruisce una richiesta di creazione item per Google Forms con supporto quiz.
 */
function buildGoogleFormRequest(array $domanda): ?Forms\Request
{
    $tipo = strtolower($domanda['tipo_domanda'] ?? $domanda['tipo'] ?? 'aperta');
    $extra = parseExtraData($domanda);
    $isParagraph = ($tipo === 'aperta');

    $feedbackText = $extra['spiegazione'] ?? ($domanda['risposta_attesa'] ?? '');
    $question = new Forms\Question();
    $question->setRequired(true);

    $grading = new Forms\Grading();
    $grading->setPointValue(1);

    $correctValues = [];
    $choices = [];

    switch ($tipo) {
        case 'multipla':
        case 'multipla_multi':
            $choiceQuestion = new Forms\ChoiceQuestion();
            $choiceQuestion->setType($tipo === 'multipla_multi' ? 'CHECKBOX' : 'RADIO');

            $risposte = $extra['risposte'] ?? [];
            foreach ($risposte as $opt) {
                if (!isset($opt['testo'])) {
                    continue;
                }
                $option = new Forms\Option();
                $option->setValue($opt['testo']);
                $choices[] = $option;
                if (!empty($opt['corretta'])) {
                    $correctValues[] = $opt['testo'];
                }
            }

            // Fallback se non ci sono opzioni
            if (empty($choices)) {
                return null;
            }

            $choiceQuestion->setOptions($choices);
            $choiceQuestion->setShuffle(false);
            $question->setChoiceQuestion($choiceQuestion);
            break;

        case 'vero_falso':
            $choiceQuestion = new Forms\ChoiceQuestion();
            $choiceQuestion->setType('RADIO');
            $optionsVF = ['Vero', 'Falso'];
            $isTrue = filter_var($extra['risposta_corretta'] ?? false, FILTER_VALIDATE_BOOLEAN);
            foreach ($optionsVF as $text) {
                $option = new Forms\Option();
                $option->setValue($text);
                $choices[] = $option;
            }
            $correctValues[] = $isTrue ? 'Vero' : 'Falso';
            $choiceQuestion->setOptions($choices);
            $question->setChoiceQuestion($choiceQuestion);
            break;

        case 'breve':
        case 'numerica':
            $textQuestion = new Forms\TextQuestion();
            $textQuestion->setParagraph(false);
            $question->setTextQuestion($textQuestion);

            $accepted = $extra['risposte_accettate'] ?? [];
            if (empty($accepted) && isset($domanda['risposta_attesa'])) {
                $accepted = [$domanda['risposta_attesa']];
            }
            $correctValues = $accepted;
            break;

        case 'aperta':
        default:
            $textQuestion = new Forms\TextQuestion();
            $textQuestion->setParagraph($isParagraph);
            $question->setTextQuestion($textQuestion);
            $correctValues = [];
            break;
    }

    if (!empty($correctValues)) {
        $answers = [];
        foreach ($correctValues as $val) {
            $ca = new Forms\CorrectAnswer();
            $ca->setValue($val);
            $answers[] = $ca;
        }
        $correctAnswers = new Forms\CorrectAnswers();
        $correctAnswers->setAnswers($answers);
        $grading->setCorrectAnswers($correctAnswers);
    }

    // Evita errore Google: general feedback solo per domande non autovalutate
    if (empty($correctValues) && !empty($feedbackText)) {
        $feedback = new Forms\Feedback();
        $feedback->setText($feedbackText);
        $grading->setGeneralFeedback($feedback);
    }
    $question->setGrading($grading);

    $questionItem = new Forms\QuestionItem();
    $questionItem->setQuestion($question);

    $item = new Forms\Item();
    $item->setTitle($domanda['domanda']);
    // Evita di mostrare la risposta attesa nel sottotitolo per le aperte
    $item->setQuestionItem($questionItem);

    $createItem = new Forms\CreateItemRequest();
    $location = new Forms\Location();
    $location->setIndex(0);
    $createItem->setItem($item);
    $createItem->setLocation($location);

    $req = new Forms\Request();
    $req->setCreateItem($createItem);
    return $req;
}
/**
 * Gestione azioni del wizard.
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action) {
    try {
        switch ($action) {
            case 'reset_wizard':
                $_SESSION[$wizardKey] = [];
                $wizardState = &$_SESSION[$wizardKey];
                $successMessage = "Wizard azzerato.";
                break;

            case 'import_questions':
                $modalita = $_POST['modalita'] ?? 'excel';
                $file = $_FILES['file_domande'] ?? null;
                if (!in_array($modalita, ['json', 'csv', 'excel'], true)) {
                    throw new Exception("Modalita non supportata");
                }

                $result = match ($modalita) {
                    'json' => importFromJSONWizard($dbAdapter, $udaId, $file),
                    'csv' => importFromCSVWizard($dbAdapter, $udaId, $file),
                    'excel' => importFromExcelWizard($dbAdapter, $udaId, $file),
                    default => ['success' => false, 'error' => 'Modalita non valida']
                };

                if ($result['success']) {
                    $wizardState['import'] = [
                        'count' => $result['importate'],
                        'errori' => $result['errori'] ?? 0,
                        'ids' => $result['inserted_ids'] ?? [],
                        'modalita' => $modalita,
                        'ts' => time()
                    ];
                    $wizardState['selected_questions'] = $result['inserted_ids'] ?? [];
                    $successMessage = "Import completato: {$result['importate']} domande caricate.";
                    if (!empty($result['errori'])) {
                        $infoMessage = "Sono state ignorate {$result['errori']} righe non valide.";
                    }
                } else {
                    $errorMessage = $result['error'] ?? 'Errore durante import.';
                }
                break;
                        case 'generate_form':
                $domande = loadDomandePerUda($dbAdapter, $udaId);
                $selectedQuestions = $_POST['questions'] ?? ($wizardState['selected_questions'] ?? []);
                if (empty($selectedQuestions)) {
                    throw new Exception("Seleziona almeno una domanda.");
                }

                $formTitle = trim($_POST['form_title'] ?? ("Verifica: " . $uda->titolo));
                $formDescription = trim($_POST['form_description'] ?? ("Verifica UDA: " . $uda->titolo));
                $tipoTest = $_POST['tipo_test'] ?? 'finale';
                $durata = intval($_POST['durata_minuti'] ?? 30);

                $domandeSelezionate = [];
                foreach ($domande as $d) {
                    if (in_array($d['id_domanda'], $selectedQuestions, true)) {
                        $d['tipo'] = $d['tipo_domanda'] ?? ($d['tipo'] ?? 'aperta');
                        $domandeSelezionate[] = $d;
                    }
                }

                $rootFolderId = $config['google']['drive']['root_folder_id'] ?? '';
                $builder = new GoogleFormsBuilder($config);
                $result = $builder->createForm($domandeSelezionate, $formTitle, $formDescription, !empty($rootFolderId) ? $rootFolderId : null);
                $formId = $result['formId'];
                $formEditUrl = $result['editUrl'];
                $formViewUrl = $result['responderUrl'];
                if (!empty($result['templateCopyError'])) {
                    $templateWarning = 'Il form è stato creato SENZA usare il template: ' . $result['templateCopyError'];
                }

                // Upsert test
                $existingTestId = $wizardState['test_id'] ?? null;
                $testId = $existingTestId ?: ('TEST_' . uniqid());
                $testData = [
                    'id_test' => $testId,
                    'id_uda' => $udaId,
                    'tipo_test' => $tipoTest,
                    'nome' => $formTitle,
                    'descrizione' => $formDescription,
                    'piattaforma' => 'google-forms',
                    'url' => $formViewUrl,
                    'url_docente' => $formEditUrl,
                    'url_studenti' => $formViewUrl,
                    'id_esterno' => $formId,
                    'num_domande' => count($selectedQuestions),
                    'durata_minuti' => $durata,
                    'punteggio_max' => max(1, count($selectedQuestions)) * 10,
                    'soglia_sufficienza' => intval(round(max(1, count($selectedQuestions)) * 10 * 0.6)),
                    'data_creazione' => date('d/m/Y'),
                    'pubblicato' => 'NO',
                    'risultati_importati' => 'NO',
                    'note' => 'Creato dal wizard test'
                ];

                $existingTest = $existingTestId ? findTestById($dbAdapter, $existingTestId) : null;
                if ($existingTest) {
                    $dbAdapter->updateRow('TEST', 'id_test', $testId, array_merge($existingTest, $testData));
                } else {
                    $dbAdapter->insertRow('TEST', $testData);
                }

                $wizardState['test_id'] = $testId;
                $wizardState['form'] = [
                    'form_id' => $formId,
                    'edit_url' => $formEditUrl,
                    'view_url' => $formViewUrl,
                    'question_count' => count($selectedQuestions)
                ];
                $wizardState['selected_questions'] = $selectedQuestions;

                $successMessage = "Google Form creato e test salvato.";
                break;
            case 'save_test':
                $testId = $_POST['test_id'] ?? ($wizardState['test_id'] ?? null);
                if (!$testId) {
                    $testId = 'TEST_' . uniqid();
                }

                $existingTest = findTestById($dbAdapter, $testId);

                $testData = [
                    'id_test' => $testId,
                    'id_uda' => $udaId,
                    'tipo_test' => $_POST['tipo_test'] ?? ($existingTest['tipo_test'] ?? 'finale'),
                    'nome' => $_POST['nome'] ?? ($existingTest['nome'] ?? 'Nuovo Test'),
                    'descrizione' => $_POST['descrizione'] ?? ($existingTest['descrizione'] ?? ''),
                    'piattaforma' => $_POST['piattaforma'] ?? ($existingTest['piattaforma'] ?? 'google-forms'),
                    'url' => $_POST['url_studenti'] ?? ($_POST['url'] ?? ($existingTest['url'] ?? '')),
                    'url_docente' => $_POST['url_gestione'] ?? ($_POST['url_docente'] ?? ($existingTest['url_docente'] ?? '')),
                    'url_studenti' => $_POST['url_studenti'] ?? ($existingTest['url_studenti'] ?? ''),
                    'id_esterno' => $_POST['id_esterno'] ?? ($existingTest['id_esterno'] ?? ''),
                    'num_domande' => intval($_POST['num_domande'] ?? ($existingTest['num_domande'] ?? 0)),
                    'durata_minuti' => intval($_POST['durata_minuti'] ?? ($existingTest['durata_minuti'] ?? 0)),
                    'punteggio_max' => floatval($_POST['punteggio_max'] ?? ($existingTest['punteggio_max'] ?? 100)),
                    'soglia_sufficienza' => floatval($_POST['soglia_sufficienza'] ?? ($existingTest['soglia_sufficienza'] ?? 60)),
                    'data_somministrazione' => $_POST['data_somministrazione'] ?? ($existingTest['data_somministrazione'] ?? ''),
                    'ora_consegna' => $_POST['ora_consegna'] ?? ($existingTest['ora_consegna'] ?? ''),
                    'pubblicato' => $existingTest['pubblicato'] ?? 'NO',
                    'note' => $_POST['note'] ?? ($existingTest['note'] ?? '')
                ];

                if ($existingTest) {
                    $dbAdapter->updateRow('TEST', 'id_test', $testId, array_merge($existingTest, $testData));
                } else {
                    $dbAdapter->insertRow('TEST', $testData);
                }

                $wizardState['test_id'] = $testId;
                $successMessage = "Test salvato/aggiornato correttamente.";
                break;

            case 'publish_classroom':
                $testId = $wizardState['test_id'] ?? null;
                if (!$testId) {
                    throw new Exception("Crea prima il test.");
                }
                $test = findTestById($dbAdapter, $testId);
                if (!$test) {
                    throw new Exception("Test non trovato.");
                }

                $courseId = $_POST['course_id'] ?? '';
                if (empty($courseId)) {
                    throw new Exception("Seleziona un corso Classroom.");
                }
                $topicName = $_POST['topic_name'] ?? $uda->titolo;
                $publishMode = $_POST['publish_mode'] ?? 'link';
                $dueDate = $_POST['due_date'] ?? '';
                $dueTime = $_POST['due_time'] ?? '';
                $draftOrPublish = 'draft'; // sempre bozza per il wizard

                $testUrl = $test['url_studenti'] ?? $test['url'] ?? '';
                if (empty($testUrl)) {
                    throw new Exception("URL test mancante.");
                }

                $classroomAPI = new GoogleClassroomAPI($config);
                $topic = $classroomAPI->findOrCreateTopic($courseId, $topicName);
                $topicId = $topic['id'] ?? null;

                $assignmentData = [
                    'title' => $test['nome'] ?? 'Test',
                    'description' => $test['descrizione'] ?? '',
                    'topicId' => $topicId,
                    'workType' => 'ASSIGNMENT',
                    'state' => strtoupper($draftOrPublish) === 'PUBLISH' ? 'PUBLISHED' : 'DRAFT',
                    'maxPoints' => floatval($test['punteggio_max'] ?? 100)
                ];

                if (!empty($dueDate)) {
                    $dateParts = explode('-', $dueDate);
                    $assignmentData['dueDate'] = [
                        'year' => intval($dateParts[0]),
                        'month' => intval($dateParts[1]),
                        'day' => intval($dateParts[2])
                    ];
                    if (!empty($dueTime)) {
                        $timeParts = explode(':', $dueTime);
                        $assignmentData['dueTime'] = [
                            'hours' => intval($timeParts[0]),
                            'minutes' => intval($timeParts[1])
                        ];
                    }
                }

                if ($publishMode === 'link') {
                    $assignmentData['materials'] = [$testUrl];
                } else {
                    $sebTemplateFile = $_FILES['seb_template'] ?? null;
                    if (!$sebTemplateFile || $sebTemplateFile['error'] !== UPLOAD_ERR_OK) {
                        throw new Exception("File .seb non caricato correttamente.");
                    }

                    $sebContent = file_get_contents($sebTemplateFile['tmp_name']);
                    $sebContent = str_replace('{{link_test}}', $testUrl, $sebContent);

                    $tempSebPath = sys_get_temp_dir() . '/' . uniqid('seb_') . '.seb';
                    file_put_contents($tempSebPath, $sebContent);

                    $driveAPI = new GoogleDriveAPI($config);
                    $sebFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $test['nome']) . '.seb';
                    $driveUploadResult = $driveAPI->uploadFile(
                        $tempSebPath,
                        $sebFileName,
                        $config['google']['drive']['root_folder_id'] ?? '',
                        'application/octet-stream'
                    );
                    @unlink($tempSebPath);

                    $assignmentData['materials'] = [
                        [
                            'driveFile' => [
                                'driveFile' => [
                                    'id' => $driveUploadResult['id'],
                                    'title' => $sebFileName
                                ],
                                'shareMode' => 'VIEW'
                            ]
                        ]
                    ];
                }

                $createdAssignment = $classroomAPI->createAssignment($courseId, $assignmentData);
                $assignmentLink = $createdAssignment['link'] ?? ($createdAssignment['alternateLink'] ?? '');

                $testUpdate = array_merge($test, [
                    'classroom_course_id' => $courseId,
                    'classroom_assignment_id' => $createdAssignment['id'] ?? ($test['classroom_assignment_id'] ?? ''),
                    'classroom_topic_id' => $topicId ?? ($test['classroom_topic_id'] ?? ''),
                    'classroom_url' => $assignmentLink ?: ($test['classroom_url'] ?? ''),
                    'url_docente' => $assignmentLink ?: ($test['url_docente'] ?? ''),
                    'pubblicato' => 'NO'
                ]);
                $dbAdapter->updateRow('TEST', 'id_test', $testId, $testUpdate);

                $wizardState['publish'] = [
                    'assignment_id' => $createdAssignment['id'] ?? null,
                    'course_id' => $courseId,
                    'course_link' => 'https://classroom.google.com/c/' . $courseId,
                    'link' => $assignmentLink,
                    'mode' => $publishMode
                ];

                $successMessage = "Compito creato in bozza su Classroom.";
                break;
        }
    } catch (Throwable $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

$domande = loadDomandePerUda($dbAdapter, $udaId);
$currentTest = null;
if (!empty($wizardState['test_id'])) {
    $currentTest = findTestById($dbAdapter, $wizardState['test_id']);
}

// Carica corsi Classroom per il dropdown
$classroomCourses = [];
try {
    $classroomAPI = new GoogleClassroomAPI($config);
    $classroomCourses = $classroomAPI->getCourses();
    if ($defaultCourseId) {
        $wizardState['default_course_id'] = $defaultCourseId;
    }
} catch (Throwable $e) {
    $infoMessage = $infoMessage ?? "Impossibile caricare i corsi Classroom: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Wizard Test - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        body { background-color: #f5f7fb; }
        .step-card { border-left: 5px solid #6f42c1; }
        .step-header { background: #6f42c1; color: white; }
        .badge-status { font-size: 0.8rem; }
    </style>
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-dark bg-primary">
        <div class="container">
            <a class="navbar-brand" href="index.php">
                <i class="bi bi-collection"></i> Sistema UDA
            </a>
            <div class="navbar-nav ms-auto">
                <a class="nav-link" href="uda_view.php?id=<?= urlencode($udaId) ?>">
                    <i class="bi bi-arrow-left"></i> Torna alla UDA
                </a>
                <a class="nav-link" href="uda_tests.php?id=<?= urlencode($udaId) ?>">
                    <i class="bi bi-list-ul"></i> Lista Test
                </a>
            </div>
        </div>
    </nav>

    <div class="container my-4">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <div>
                <h1 class="h3 mb-1"><i class="bi bi-magic"></i> Wizard Google Forms per '<?= htmlspecialchars($uda->titolo) ?>'</h1>
                <p class="text-muted mb-0">Flusso guidato: import domande &rarr; Google Form &rarr; Test &rarr; Bozza Classroom.</p>
            </div>
            <form method="POST" class="d-inline">
                <input type="hidden" name="action" value="reset_wizard">
                <button type="submit" class="btn btn-outline-danger btn-sm">
                    <i class="bi bi-arrow-counterclockwise"></i> Reset Wizard
                </button>
            </form>
        </div>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($templateWarning): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($templateWarning) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php if ($infoMessage): ?>
            <div class="alert alert-info alert-dismissible fade show">
                <i class="bi bi-info-circle"></i> <?= htmlspecialchars($infoMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Step 1 -->
        <div class="card step-card mb-4">
            <div class="card-header step-header d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0"><i class="bi bi-upload"></i> Step 1 - Importa domande dai template</h5>
                    <small>Usa i file di esempio in <code>database/templates/template_domande.*</code></small>
                </div>
                <?php if (!empty($wizardState['import']['count'])): ?>
                    <span class="badge bg-success badge-status">
                        <?= $wizardState['import']['count'] ?> importate
                    </span>
                <?php else: ?>
                    <span class="badge bg-secondary badge-status">Da completare</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="mb-2 fw-semibold">Template rapidi</div>
                        <div class="d-grid gap-2">
                            <a href="download_template_domande.php?format=xlsx" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-file-earmark-spreadsheet"></i> Scarica .xlsx
                            </a>
                            <a href="download_template_domande.php?format=csv" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-filetype-csv"></i> Scarica .csv
                            </a>
                            <a href="download_template_domande.php?format=json" class="btn btn-outline-primary btn-sm">
                                <i class="bi bi-filetype-json"></i> Scarica .json
                            </a>
                        </div>
                        <p class="text-muted small mt-2 mb-0">
                            Colonne minime: argomento, domanda, risposta_attesa, difficolta (1-5).
                        </p>
                    </div>
                    <div class="col-md-8">
                        <form method="POST" enctype="multipart/form-data" class="row g-3 align-items-end">
                            <input type="hidden" name="action" value="import_questions">
                            <div class="col-md-4">
                                <label class="form-label">Formato *</label>
                                <select name="modalita" class="form-select" required>
                                    <option value="excel">Excel</option>
                                    <option value="csv">CSV</option>
                                    <option value="json">JSON</option>
                                </select>
                            </div>
                            <div class="col-md-5">
                                <label class="form-label">File domande *</label>
                                <input type="file" name="file_domande" class="form-control" required>
                            </div>
                            <div class="col-md-3 d-grid">
                                <button type="submit" class="btn btn-success">
                                    <i class="bi bi-cloud-upload"></i> Importa
                                </button>
                            </div>
                        </form>
                        <?php if (!empty($wizardState['import'])): ?>
                            <div class="alert alert-success mt-3 mb-0">
                                <strong>Ultimo import:</strong> <?= $wizardState['import']['count'] ?> domande (<?= strtoupper($wizardState['import']['modalita'] ?? '') ?>).
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <!-- Step 2 -->
        <div class="card step-card mb-4">
            <div class="card-header step-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-google"></i> Step 2 - Genera Google Form</h5>
                <?php if (!empty($wizardState['form']['form_id'])): ?>
                    <span class="badge bg-success badge-status">Form creato</span>
                <?php else: ?>
                    <span class="badge bg-secondary badge-status">Da completare</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($domande)): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-exclamation-triangle"></i> Aggiungi prima alcune domande per questa UDA.
                    </div>
                <?php else: ?>
                    <form method="POST">
                        <input type="hidden" name="action" value="generate_form">
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Titolo Form *</label>
                                <input type="text" class="form-control" name="form_title" required
                                       value="<?= htmlspecialchars($wizardState['form']['title'] ?? ('Verifica: ' . $uda->titolo)) ?>">
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Tipo Test</label>
                                <select name="tipo_test" class="form-select">
                                    <option value="prerequisiti">Prerequisiti</option>
                                    <option value="intermedio">Intermedio</option>
                                    <option value="finale" selected>Finale</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Durata (min)</label>
                                <input type="number" class="form-control" name="durata_minuti" value="<?= htmlspecialchars($currentTest['durata_minuti'] ?? 30) ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Descrizione</label>
                                <textarea class="form-control" name="form_description" rows="2"><?= htmlspecialchars($wizardState['form']['description'] ?? ("Verifica delle conoscenze per l'UDA: " . $uda->titolo)) ?></textarea>
                            </div>
                        </div>

                        <div class="border rounded p-3 mb-3" style="max-height: 300px; overflow-y: auto;">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <div class="fw-semibold"><i class="bi bi-list-check"></i> Domande disponibili (<?= count($domande) ?>)</div>
                                <div class="btn-group btn-group-sm" role="group">
                                    <button type="button" class="btn btn-outline-primary" onclick="toggleQuestions(true)">Seleziona tutte</button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="toggleQuestions(false)">Deseleziona</button>
                                </div>
                            </div>
                            <div class="row">
                                <?php
                                $defaultSelected = $wizardState['selected_questions'] ?? [];
                                foreach ($domande as $q):
                                    $checked = in_array($q['id_domanda'], $defaultSelected, true);
                                ?>
                                    <div class="col-md-6 mb-2">
                                        <div class="form-check">
                                            <input class="form-check-input question-check" type="checkbox"
                                                   name="questions[]" value="<?= htmlspecialchars($q['id_domanda']) ?>"
                                                   id="q_<?= htmlspecialchars($q['id_domanda']) ?>" <?= $checked ? 'checked' : '' ?>>
                                            <label class="form-check-label" for="q_<?= htmlspecialchars($q['id_domanda']) ?>">
                                                <strong><?= htmlspecialchars($q['domanda']) ?></strong>
                                                <div class="small text-muted">Argomento: <?= htmlspecialchars($q['argomento'] ?? 'Generale') ?></div>
                                            </label>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-file-earmark-text"></i> Genera Google Form e salva test
                            </button>
                        </div>
                    </form>
                    <?php if (!empty($wizardState['form']['edit_url'])): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-link-45deg"></i>
                            <a href="<?= htmlspecialchars($wizardState['form']['edit_url']) ?>" target="_blank">Apri Google Form (Editor)</a>
                            &middot;
                            <a href="<?= htmlspecialchars($wizardState['form']['view_url']) ?>" target="_blank">Link studenti</a>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- Step 3 -->
        <div class="card step-card mb-4">
            <div class="card-header step-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-clipboard-check"></i> Step 3 - Salva test UDA</h5>
                <?php if (!empty($wizardState['test_id'])): ?>
                    <span class="badge bg-success badge-status">Test pronto</span>
                <?php else: ?>
                    <span class="badge bg-secondary badge-status">Da completare</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="action" value="save_test">
                    <input type="hidden" name="test_id" value="<?= htmlspecialchars($wizardState['test_id'] ?? '') ?>">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="nome" class="form-control" required
                                   value="<?= htmlspecialchars($currentTest['nome'] ?? ($wizardState['form']['title'] ?? '')) ?>">
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Tipo Test</label>
                            <select name="tipo_test" class="form-select">
                                <option value="prerequisiti" <?= (($currentTest['tipo_test'] ?? '') === 'prerequisiti') ? 'selected' : '' ?>>Prerequisiti</option>
                                <option value="intermedio" <?= (($currentTest['tipo_test'] ?? '') === 'intermedio') ? 'selected' : '' ?>>Intermedio</option>
                                <option value="finale" <?= (($currentTest['tipo_test'] ?? 'finale') === 'finale') ? 'selected' : '' ?>>Finale</option>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Piattaforma</label>
                            <input type="text" class="form-control" name="piattaforma" value="<?= htmlspecialchars($currentTest['piattaforma'] ?? 'google-forms') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Descrizione</label>
                            <textarea name="descrizione" class="form-control" rows="2"><?= htmlspecialchars($currentTest['descrizione'] ?? '') ?></textarea>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Link studenti *</label>
                            <input type="url" name="url_studenti" class="form-control" required
                                   value="<?= htmlspecialchars($currentTest['url_studenti'] ?? ($wizardState['form']['view_url'] ?? '')) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Link docente / gestione</label>
                            <input type="url" name="url_gestione" class="form-control"
                                   value="<?= htmlspecialchars($currentTest['url_docente'] ?? ($wizardState['form']['edit_url'] ?? '')) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Numero domande</label>
                            <input type="number" name="num_domande" class="form-control" value="<?= htmlspecialchars($currentTest['num_domande'] ?? ($wizardState['form']['question_count'] ?? 0)) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Durata (min)</label>
                            <input type="number" name="durata_minuti" class="form-control" value="<?= htmlspecialchars($currentTest['durata_minuti'] ?? 30) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Punteggio max</label>
                            <input type="number" step="0.1" name="punteggio_max" class="form-control" value="<?= htmlspecialchars($currentTest['punteggio_max'] ?? 100) ?>">
                        </div>
                        <div class="col-md-3">
                            <label class="form-label">Soglia (%)</label>
                            <input type="number" step="0.1" name="soglia_sufficienza" class="form-control" value="<?= htmlspecialchars($currentTest['soglia_sufficienza'] ?? 60) ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Data consegna</label>
                            <input type="date" name="data_somministrazione" class="form-control" value="<?= htmlspecialchars($currentTest['data_somministrazione'] ?? '') ?>">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Ora consegna</label>
                            <input type="time" name="ora_consegna" class="form-control" value="<?= htmlspecialchars($currentTest['ora_consegna'] ?? '') ?>">
                        </div>
                        <div class="col-12">
                            <label class="form-label">Note interne</label>
                            <textarea name="note" class="form-control" rows="2"><?= htmlspecialchars($currentTest['note'] ?? '') ?></textarea>
                        </div>
                    </div>
                    <div class="d-flex justify-content-end mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Salva test
                        </button>
                    </div>
                </form>
            </div>
        </div>
        <!-- Step 4 -->
        <div class="card step-card mb-4">
            <div class="card-header step-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0"><i class="bi bi-google"></i> Step 4 - Pubblica bozza su Classroom</h5>
                <?php if (!empty($wizardState['publish']['assignment_id'])): ?>
                    <span class="badge bg-success badge-status">Bozza creata</span>
                <?php else: ?>
                    <span class="badge bg-secondary badge-status">Da completare</span>
                <?php endif; ?>
            </div>
            <div class="card-body">
                <?php if (empty($wizardState['test_id'])): ?>
                    <div class="alert alert-warning mb-0">
                        <i class="bi bi-info-circle"></i> Completa prima i passi precedenti per generare il test.
                    </div>
                <?php else: ?>
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="publish_classroom">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Corso Classroom *</label>
                                <?php
                                $preselectedCourse = $wizardState['publish']['course_id']
                                    ?? $wizardState['default_course_id']
                                    ?? $defaultCourseId
                                    ?? '';
                                ?>
                                <select name="course_id" class="form-select" required>
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($classroomCourses as $course): ?>
                                        <option value="<?= htmlspecialchars($course['id']) ?>" <?= ($preselectedCourse === $course['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($course['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Argomento (topic)</label>
                                <input type="text" name="topic_name" class="form-control" value="<?= htmlspecialchars($uda->titolo) ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Data consegna</label>
                                <input type="date" name="due_date" class="form-control" value="<?= htmlspecialchars($currentTest['data_somministrazione'] ?? '') ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Ora consegna</label>
                                <input type="time" name="due_time" class="form-control" value="<?= htmlspecialchars($currentTest['ora_consegna'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="mt-3">
                            <label class="form-label">Modalita di allegato *</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="publish_mode" id="mode_link" value="link" checked onclick="toggleSeb(false)">
                                <label class="form-check-label" for="mode_link">
                                    <i class="bi bi-link-45deg"></i> Link diretto al Google Form
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="publish_mode" id="mode_seb" value="seb" onclick="toggleSeb(true)">
                                <label class="form-check-label" for="mode_seb">
                                    <i class="bi bi-file-earmark-lock"></i> File .seb con placeholder <code>{{link_test}}</code>
                                </label>
                            </div>
                            <div id="sebUpload" class="mt-2" style="display:none;">
                                <input type="file" name="seb_template" class="form-control" accept=".seb">
                            </div>
                        </div>

                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-send"></i> Crea bozza su Classroom
                            </button>
                        </div>
                    </form>
                    <?php if (!empty($wizardState['publish']['link'])): ?>
                        <div class="alert alert-info mt-3 mb-0">
                            <i class="bi bi-box-arrow-up-right"></i>
                            <a href="<?= htmlspecialchars($wizardState['publish']['link']) ?>" target="_blank">Apri bozza in Classroom</a>
                            <?php if (!empty($wizardState['publish']['course_link'])): ?>
                                <span class="ms-2 text-muted">|</span>
                                <a href="<?= htmlspecialchars($wizardState['publish']['course_link']) ?>" target="_blank" class="ms-2">Vai al corso</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <!-- Step 5 -->
        <div class="card step-card mb-4">
            <div class="card-header step-header">
                <h5 class="mb-0"><i class="bi bi-flag"></i> Step 5 - Riepilogo e link finali</h5>
            </div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <div class="card h-100 border-success">
                            <div class="card-body">
                                <h6><i class="bi bi-file-earmark-text"></i> Google Form</h6>
                                <?php if (!empty($wizardState['form']['edit_url'])): ?>
                                    <p class="mb-2"><a href="<?= htmlspecialchars($wizardState['form']['edit_url']) ?>" target="_blank">Modifica form</a></p>
                                    <p class="mb-0"><a href="<?= htmlspecialchars($wizardState['form']['view_url']) ?>" target="_blank">Link studenti</a></p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Crea prima il form.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100 border-primary">
                            <div class="card-body">
                                <h6><i class="bi bi-clipboard-check"></i> Test UDA</h6>
                                <?php if (!empty($wizardState['test_id'])): ?>
                                    <p class="mb-2"><strong>ID:</strong> <?= htmlspecialchars($wizardState['test_id']) ?></p>
                                    <p class="mb-2">
                                        <a href="uda_tests.php?id=<?= urlencode($udaId) ?>" class="link-primary">Apri gestione test</a>
                                    </p>
                                    <p class="mb-0">
                                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="link-secondary">Torna alla UDA</a>
                                    </p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Nessun test salvato.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="card h-100 border-warning">
                            <div class="card-body">
                                <h6><i class="bi bi-google"></i> Classroom</h6>
                                <?php if (!empty($wizardState['publish']['link'])): ?>
                                    <div class="d-grid gap-2">
                                        <a href="<?= htmlspecialchars($wizardState['publish']['link']) ?>" target="_blank" class="btn btn-success btn-sm">
                                            <i class="bi bi-box-arrow-up-right"></i> Apri bozza in Classroom
                                        </a>
                                        <?php if (!empty($wizardState['publish']['course_link'])): ?>
                                            <a href="<?= htmlspecialchars($wizardState['publish']['course_link']) ?>" target="_blank" class="btn btn-outline-primary btn-sm">
                                                <i class="bi bi-google"></i> Vai al corso Classroom
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                    <p class="mb-0 text-muted small mt-2">Da Classroom puoi pubblicare definitivamente.</p>
                                <?php else: ?>
                                    <p class="text-muted mb-0">Crea la bozza per ottenere il link.</p>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function toggleQuestions(value) {
            document.querySelectorAll('.question-check').forEach(cb => cb.checked = value);
        }
        function toggleSeb(show) {
            const box = document.getElementById('sebUpload');
            box.style.display = show ? 'block' : 'none';
        }
    </script>
</body>
</html>
