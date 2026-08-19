<?php
/**
 * Importazione Massiva Domande
 * Supporta: JSON, CSV, Excel
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\QuestionImporter;
use Google\Client;
use Google\Service\Forms;
use Smalot\PdfParser\Parser as PdfParser;
use App\Utils\QuestionImportParser;
use App\Utils\LocalReturnUrl;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$udaId = $_GET['id'] ?? ($_GET['id_uda'] ?? null);

if (!$udaId) {
    die("ID UDA mancante");
}

$udaComplete = $udaManager->getUDAComplete($udaId);
$isTempUda = false;
if (!$udaComplete) {
    // Consenti UDA temporanee per il wizard (es. UDA_TMP_...)
    if (str_starts_with($udaId, 'UDA_TMP')) {
        $isTempUda = true;
        $uda = (object)[
            'id_uda' => $udaId,
            'titolo' => 'Import domande (temporaneo)',
            'argomento' => 'Import temporaneo'
        ];
    } else {
        die("UDA non trovata");
    }
} else {
    $uda = $udaComplete['uda'];
}

$action = $_POST['action'] ?? null;
$modalita = $_POST['modalita'] ?? 'json';
$existingSource = $_POST['existing_source'] ?? 'google_forms';
$formsUrlInput = trim((string)($_POST['forms_url'] ?? ''));
$socrativeSourceFormat = mb_strtolower(trim((string)($_POST['socrative_source_format'] ?? 'excel')));
if (!in_array($socrativeSourceFormat, ['excel', 'pdf'], true)) {
    $socrativeSourceFormat = 'excel';
}
$successMessage = null;
$errorMessage = null;
$importResult = null;
$previewQuestions = [];
$jsonTemplatePath = ROOT_PATH . '/database/templates/template_domande.json';
$jsonTemplateContent = is_file($jsonTemplatePath) ? (string)file_get_contents($jsonTemplatePath) : "{\n  \"domande\": []\n}";
$jsonTextareaContent = (string)($_POST['json_content'] ?? $jsonTemplateContent);
$returnTo = LocalReturnUrl::sanitize(
    $_GET['return_to'] ?? $_POST['return_to'] ?? null,
    'uda_questions.php?id=' . rawurlencode((string)$udaId)
);
$importReturnUrl = app_url('public/import_questions.php?id=' . rawurlencode((string)$udaId));
$classroomCourseId = trim((string)($_GET['course_id'] ?? ''));
$googleIntegrationUrl = 'user_integrations.php?return_to=' . rawurlencode($importReturnUrl) . '#google-section';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if ($action === 'preview_ai') {
            $aiJson = $_POST['ai_json'] ?? '';
            $parseResult = parseQuestionsFromAI($aiJson);
            if ($parseResult['success']) {
                $previewQuestions = $parseResult['questions'];
                $successMessage = "Bozza AI caricata. Controlla l'anteprima prima di importare.";
                $modalita = 'json';
            } else {
                $errorMessage = $parseResult['error'] ?? "Errore durante la lettura del contenuto AI";
            }
        }

        if ($action === 'preview') {
            if ($modalita === 'json') {
                $parseResult = buildPreviewFromJSONContent($jsonTextareaContent);
            } else {
                $fileKey = $modalita === 'csv' ? 'file_csv' : 'file_excel';
                $parseResult = parseQuestionsFromFile($modalita, $_FILES[$fileKey] ?? null);
            }

            if ($parseResult['success']) {
                $previewQuestions = $parseResult['questions'];
                $successMessage = "File caricato. Controlla l'anteprima prima di importare.";
            } else {
                $errorMessage = $parseResult['error'] ?? "Errore durante la lettura del file";
            }
        }

        if ($action === 'preview_existing_test') {
            switch ($existingSource) {
                case 'google_forms':
                    $parseResult = buildPreviewFromGoogleFormsUrl($formsUrlInput, $config);
                    break;
                case 'kahoot':
                    $parseResult = buildPreviewFromExistingQuizExcel($_FILES['file_existing_kahoot'] ?? null, 'kahoot');
                    break;
                case 'socrative':
                    if ($socrativeSourceFormat === 'pdf') {
                        $parseResult = buildPreviewFromSocrativeQuizPdf($_FILES['file_existing_socrative_pdf'] ?? null);
                    } else {
                        $parseResult = buildPreviewFromExistingQuizExcel($_FILES['file_existing_socrative'] ?? null, 'socrative');
                    }
                    break;
                default:
                    $parseResult = ['success' => false, 'error' => 'Sorgente test non supportata'];
                    break;
            }

            if ($parseResult['success']) {
                $previewQuestions = $parseResult['questions'];
                $sourceLabelMap = [
                    'google_forms' => 'Google Forms',
                    'kahoot' => 'Kahoot',
                    'socrative' => 'Socrative'
                ];
                $sourceLabel = $sourceLabelMap[$existingSource] ?? $existingSource;
                $successMessage = "Domande caricate da {$sourceLabel}. Controlla l'anteprima prima di importare.";
            } else {
                $errorMessage = $parseResult['error'] ?? "Errore durante la lettura del test esistente";
            }
        }

        if ($action === 'import_selected') {
            $questions = $_POST['questions'] ?? [];
            $importResult = importSelectedQuestions($dbAdapter, $udaId, $questions);

            if ($importResult['success']) {
                $successMessage = "Importazione completata con successo!";
            } else {
                $errorMessage = $importResult['error'] ?? "Errore durante l'importazione";
            }
        }
    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

$importCompleted = $action === 'import_selected'
    && is_array($importResult)
    && !empty($importResult['success']);

/**
 * Importa domande da JSON
 */
function importFromJSON($dbAdapter, $udaId, $file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File non caricato correttamente'];
    }

    $jsonContent = file_get_contents($file['tmp_name']);
    $data = json_decode($jsonContent, true);

    if (json_last_error() !== JSON_ERROR_NONE) {
        return ['success' => false, 'error' => 'JSON non valido: ' . json_last_error_msg()];
    }

    $domande = $data['domande'] ?? [];
    if (empty($domande)) {
        return ['success' => false, 'error' => 'Nessuna domanda trovata nel JSON'];
    }

    $importate = 0;
    $errori = 0;
    $warnings = [];
    $dettagli = [];

    foreach ($domande as $index => $d) {
        try {
            // Validazione campi obbligatori
            if (empty($d['argomento']) || empty($d['domanda'])) {
                $errori++;
                $dettagli[] = [
                    'index' => $index + 1,
                    'status' => 'error',
                    'message' => 'Campi obbligatori mancanti (argomento o domanda)'
                ];
                continue;
            }

            // Prepara dati per database
            $domandaData = [
                'id_domanda' => 'DOM_' . uniqid(),
                'id_uda' => $udaId,
                'argomento' => trim($d['argomento']),
                'domanda' => trim($d['domanda']),
                'risposta_attesa' => convertRispostaAttesa($d),
                'parole_chiave' => convertParoleChiave($d['parole_chiave'] ?? []),
                'difficolta' => $d['difficolta'] ?? 3,
                'tempo_risposta_min' => $d['tempo_risposta_min'] ?? 3,
                'ordine_consigliato' => $d['ordine_consigliato'] ?? ($index + 1),
                'note' => $d['note'] ?? '',
                'tipo_domanda' => $d['tipo'] ?? 'aperta',
                'data_creazione' => date('Y-m-d H:i:s')
            ];

            // Validazioni opzionali
            if ($domandaData['difficolta'] < 1 || $domandaData['difficolta'] > 5) {
                $warnings[] = "Domanda " . ($index + 1) . ": Difficoltà fuori range (1-5), impostata a 3";
                $domandaData['difficolta'] = 3;
            }

            // Inserisci nel database
            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
            $importate++;

            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'success',
                'domanda' => substr($d['domanda'], 0, 50) . '...'
            ];

        } catch (Exception $e) {
            $errori++;
            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            $logMsg = "[import_json] Errore idx {$index}: " . $e->getMessage() .
                " | dati=" . json_encode($domandaData) . "\n";
            @file_put_contents(__DIR__ . '/../storage/logs/import_questions_debug.log', $logMsg, FILE_APPEND);
        }
    }

    return [
        'success' => $importate > 0,
        'importate' => $importate,
        'errori' => $errori,
        'warnings' => $warnings,
        'dettagli' => $dettagli,
        'totale' => count($domande)
    ];
}

/**
 * Importa domande da Google Forms
 */
function importFromGoogleForms($dbAdapter, $udaId, $formsUrl, $config) {
    if (empty($formsUrl)) {
        return ['success' => false, 'error' => 'URL Google Forms mancante'];
    }

    // Estrai Form ID dall'URL
    preg_match('/\/forms\/d\/([a-zA-Z0-9_-]+)/', $formsUrl, $matches);
    if (!isset($matches[1])) {
        return ['success' => false, 'error' => 'URL Google Forms non valido'];
    }

    $formId = $matches[1];

    try {
        // Configura Google Client
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([
            Forms::FORMS_BODY,
            Forms::FORMS_RESPONSES_READONLY
        ]);
        $client->setAuthConfig(ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json'));

        $tokenData = GoogleTokenProvider::getToken($config);
        if (empty($tokenData)) {
            return ['success' => false, 'error' => 'Token Google non trovato. Autorizza l\'applicazione.'];
        }
        $client->setAccessToken($tokenData);

        // Crea Forms service
        $formsService = new Forms($client);

        // Ottieni il form
        $form = $formsService->forms->get($formId);

        $domande = [];
        $items = $form->getItems();

        foreach ($items as $index => $item) {
            $questionItem = $item->getQuestionItem();
            if (!$questionItem) continue;

            $question = $questionItem->getQuestion();
            $title = $item->getTitle();

            $domandaData = [
                'argomento' => 'Importato da Google Forms',
                'domanda' => $title,
                'difficolta' => 3,
                'tempo_risposta_min' => 3,
                'ordine_consigliato' => $index + 1,
                'note' => 'Importato da: ' . ($form->getInfo()->getTitle() ?? 'Google Form')
            ];

            // Determina tipo e risposta
            if ($question->getChoiceQuestion()) {
                $choiceQuestion = $question->getChoiceQuestion();
                $options = $choiceQuestion->getOptions();

                $risposte = [];
                foreach ($options as $opt) {
                    $risposte[] = $opt->getValue();
                }
                $domandaData['risposta_attesa'] = 'Scelta multipla: ' . implode(', ', $risposte);
                $domandaData['parole_chiave'] = implode(',', array_slice($risposte, 0, 3));

            } elseif ($question->getTextQuestion()) {
                $domandaData['risposta_attesa'] = '[Risposta aperta]';
                $domandaData['parole_chiave'] = '';
            }

            $domande[] = $domandaData;
        }

        // Inserisci nel database
        $importate = 0;
        foreach ($domande as $d) {
            $d['id_domanda'] = 'DOM_' . uniqid();
            $d['id_uda'] = $udaId;
            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $d);
            $importate++;
        }

        return [
            'success' => true,
            'importate' => $importate,
            'errori' => 0,
            'warnings' => [],
            'totale' => count($domande),
            'dettagli' => []
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore Google Forms API: ' . $e->getMessage()];
    }
}

/**
 * Legge il file e costruisce l'anteprima delle domande senza importarle
 */
function parseQuestionsFromFile($modalita, $file)
{
    if (!$file) {
        return ['success' => false, 'error' => 'Nessun file ricevuto. Seleziona un file prima di procedere.'];
    }
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errMap = [
            UPLOAD_ERR_INI_SIZE => 'Il file supera la dimensione massima del server (upload_max_filesize).',
            UPLOAD_ERR_FORM_SIZE => 'Il file supera la dimensione massima del form (MAX_FILE_SIZE).',
            UPLOAD_ERR_PARTIAL => 'Caricamento parziale: ritenta.',
            UPLOAD_ERR_NO_FILE => 'Nessun file selezionato.',
            UPLOAD_ERR_NO_TMP_DIR => 'Cartella temporanea mancante sul server.',
            UPLOAD_ERR_CANT_WRITE => 'Impossibile scrivere il file su disco.',
            UPLOAD_ERR_EXTENSION => 'Caricamento bloccato da un\'estensione PHP.'
        ];
        $msg = $errMap[$file['error']] ?? ('Errore upload (code ' . $file['error'] . ')');
        return ['success' => false, 'error' => $msg];
    }

    switch ($modalita) {
        case 'json':
            return buildPreviewFromJSON($file['tmp_name']);

        case 'csv':
            return buildPreviewFromCSV($file['tmp_name']);

        case 'excel':
            return buildPreviewFromExcel($file['tmp_name']);

        default:
            return ['success' => false, 'error' => 'Formato non supportato'];
    }
}

function buildPreviewFromJSON(string $path): array
{
    return buildPreviewFromJSONContent((string)(file_get_contents($path) ?: ''));
    /* Legacy file parser retained below for reference; JSON now flows through the textarea parser. */
    /*
    $jsonContent = file_get_contents($path);
    // Rimuovi BOM se presente
    $jsonContent = preg_replace('/^\xEF\xBB\xBF/', '', $jsonContent);
    // Decodifica tentando la sostituzione caratteri non validi
    $data = json_decode($jsonContent, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);

    if (json_last_error() !== JSON_ERROR_NONE) {
        // Riprova convertendo in UTF-8 (file spesso ISO-8859-1/Windows-1252)
        $jsonContentUtf8 = @mb_convert_encoding($jsonContent, 'UTF-8', 'UTF-8,ISO-8859-1,WINDOWS-1252');
        $data = json_decode($jsonContentUtf8, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        if (json_last_error() === JSON_ERROR_NONE) {
            // ok dopo conversione
            $jsonContent = $jsonContentUtf8;
        } else {
            // Fallback: prova a interpretare più oggetti JSON consecutivi (incollati uno dopo l'altro)
            $wrapped = '[' . preg_replace('/}\\s*{/', '},{', $jsonContent) . ']';
            $data = json_decode($wrapped, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
            if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                // Unifica domande da tutti gli oggetti
                $mergedDomande = [];
                foreach ($data as $obj) {
                    if (isset($obj['domande']) && is_array($obj['domande'])) {
                        $mergedDomande = array_merge($mergedDomande, $obj['domande']);
                    }
                }
                $data = [
                    'domande' => $mergedDomande
                ];
                // Procedi con questi dati
            } else {
                // Log diagnostico minimo
            $logMsg = "IMPORT JSON ERROR: " . json_last_error_msg() . "\nFirst 200 chars: " . substr($jsonContent, 0, 200) . "\n\n";
            @file_put_contents(__DIR__ . '/../storage/logs/import_questions_debug.log', $logMsg, FILE_APPEND);
            return ['success' => false, 'error' => 'JSON non valido: ' . json_last_error_msg() . '. Verifica che il file sia in UTF-8 senza BOM e rispetti il template.'];
        }
    }
    }

    $domande = $data['domande'] ?? [];
    if (empty($domande)) {
        return ['success' => false, 'error' => 'Nessuna domanda trovata nel file'];
    }

    $questions = [];
    $seen = [];
    foreach ($domande as $index => $d) {
        $keyDomanda = trim($d['domanda'] ?? '');
        $keyRisposta = trim($d['risposta_attesa'] ?? '');
        $dupKey = $keyDomanda . '|' . $keyRisposta;
        if (!empty($keyDomanda) && isset($seen[$dupKey])) {
            // salta duplicati con stessa domanda+risposta
            continue;
        }
        if (!empty($keyDomanda)) {
            $seen[$dupKey] = true;
        }
        $questions[] = [
            'argomento' => trim($d['argomento'] ?? 'Generale'),
            'tipo' => $d['tipo'] ?? 'aperta',
            'domanda' => trim($d['domanda'] ?? ''),
            'risposta_attesa' => convertRispostaAttesa($d),
            'parole_chiave' => convertParoleChiave($d['parole_chiave'] ?? ''),
            'difficolta' => intval($d['difficolta'] ?? 3),
            'tempo_risposta_min' => intval($d['tempo_risposta_min'] ?? 3),
            'ordine_consigliato' => intval($d['ordine_consigliato'] ?? ($index + 1)),
            'note' => trim($d['note'] ?? ''),
            'data_creazione' => date('Y-m-d H:i:s')
        ];
    }

    return ['success' => true, 'questions' => $questions];
}

    */
}

function buildPreviewFromJSONContent(string $jsonContent): array
{
    try {
        $domande = QuestionImportParser::parseJsonContent($jsonContent);
    } catch (\InvalidArgumentException $e) {
        @file_put_contents(
            __DIR__ . '/../storage/logs/import_questions_debug.log',
            "IMPORT JSON ERROR: {$e->getMessage()}\nFirst 200 chars: " . substr($jsonContent, 0, 200) . "\n\n",
            FILE_APPEND
        );
        return ['success' => false, 'error' => $e->getMessage() . '. Verifica che il contenuto rispetti il template.'];
    }

    $questions = [];
    foreach ($domande as $d) {
        $questions[] = [
            'argomento' => trim((string)($d['argomento'] ?? 'Generale')),
            'tipo' => $d['tipo'] ?? 'aperta',
            'domanda' => trim((string)($d['domanda'] ?? '')),
            'risposta_attesa' => convertRispostaAttesa($d),
            'parole_chiave' => convertParoleChiave($d['parole_chiave'] ?? ''),
            'difficolta' => intval($d['difficolta'] ?? 3),
            'tempo_risposta_min' => intval($d['tempo_risposta_min'] ?? 3),
            'ordine_consigliato' => intval($d['ordine_consigliato'] ?? 1),
            'note' => trim((string)($d['note'] ?? '')),
            'opzioni' => is_array($d['risposte'] ?? null) ? $d['risposte'] : [],
            'data_creazione' => date('Y-m-d H:i:s'),
        ];
    }
    return ['success' => true, 'questions' => $questions];
}

function buildPreviewFromCSV(string $path): array
{
    $handle = fopen($path, 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Impossibile leggere il file CSV'];
    }

    $headers = fgetcsv($handle);
    if (!$headers || !in_array('domanda', $headers)) {
        fclose($handle);
        return ['success' => false, 'error' => 'CSV non valido: manca colonna "domanda"'];
    }

    $questions = [];
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;
        $data = array_combine($headers, $row);

        if (empty($data['domanda'])) {
            continue;
        }

        $questions[] = [
            'argomento' => trim($data['argomento'] ?? 'Generale'),
            'tipo' => $data['tipo'] ?? 'aperta',
            'domanda' => trim($data['domanda']),
            'risposta_attesa' => $data['risposta_attesa'] ?? '',
            'parole_chiave' => $data['parole_chiave'] ?? '',
            'difficolta' => intval($data['difficolta'] ?? 3),
            'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
            'ordine_consigliato' => intval($data['ordine_consigliato'] ?? $lineNumber),
            'note' => $data['note'] ?? ''
        ];
    }

    fclose($handle);
    return ['success' => true, 'questions' => $questions];
}

function buildPreviewFromExcel(string $path): array
{
    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($path);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            return ['success' => false, 'error' => 'Excel vuoto'];
        }

        $headers = $rows[0];
        $questions = [];

        for ($i = 1; $i < count($rows); $i++) {
            $data = array_combine($headers, $rows[$i]);

            if (empty($data['domanda'])) {
                continue;
            }

            $questions[] = [
                'argomento' => $data['argomento'] ?? 'Generale',
                'tipo' => $data['tipo'] ?? 'aperta',
                'domanda' => trim($data['domanda']),
                'risposta_attesa' => convertRispostaExcel($data),
            'parole_chiave' => $data['parole_chiave'] ?? '',
            'difficolta' => intval($data['difficolta'] ?? 3),
            'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
            'ordine_consigliato' => intval($data['ordine_consigliato'] ?? ($i + 1)),
            'note' => $data['note'] ?? ''
        ];
        }

        return ['success' => true, 'questions' => $questions];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore lettura Excel: ' . $e->getMessage()];
    }
}

function importSelectedQuestions($dbAdapter, string $udaId, array $questions): array
{
    if (empty($questions)) {
        return ['success' => false, 'error' => 'Nessuna domanda da importare'];
    }

    $importate = 0;
    $errori = 0;
    $skippate = 0;
    $dettagli = [];

    foreach ($questions as $index => $q) {
        $shouldImport = isset($q['import']) && $q['import'] === '1';

        if (!$shouldImport) {
            $skippate++;
            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'skipped',
                'message' => 'Esclusa dall\'utente'
            ];
            continue;
        }

        $argomento = trim($q['argomento'] ?? '');
        $domanda = trim($q['domanda'] ?? '');
        // Ignora eventuali id_uda/id_domanda provenienti dal file: si usa sempre l'UDA corrente
        unset($q['id_uda'], $q['id_domanda']);

        if (empty($argomento) || empty($domanda)) {
            $errori++;
            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'error',
                'message' => 'Argomento o domanda mancanti'
            ];
            continue;
        }

        $tipo = $q['tipo'] ?? ($q['tipo_domanda'] ?? 'aperta');

        $domandaData = [
            'id_domanda' => 'DOM_' . uniqid(),
            'id_uda' => $udaId,
            'argomento' => $argomento,
            'domanda' => $domanda,
            'tipo_domanda' => $tipo,
            'risposta_attesa' => trim($q['risposta_attesa'] ?? ''),
            'parole_chiave' => convertParoleChiave($q['parole_chiave'] ?? ''),
            'difficolta' => intval($q['difficolta'] ?? 3),
            'tempo_risposta_min' => intval($q['tempo_risposta_min'] ?? 3),
            'ordine_consigliato' => intval($q['ordine_consigliato'] ?? ($index + 1)),
            'note' => trim($q['note'] ?? ''),
            'data_creazione' => date('Y-m-d H:i:s')
        ];

        try {
            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
            $importate++;
            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'success',
                'message' => 'Importata'
            ];
        } catch (Exception $e) {
            $errori++;
            $dettagli[] = [
                'index' => $index + 1,
                'status' => 'error',
                'message' => $e->getMessage()
            ];
            // log dettagliato su errori di inserimento
            $logMsg = "[import_selected] Errore inserimento idx {$index}: " . $e->getMessage() .
                " | dati=" . json_encode($domandaData) . "\n";
            @file_put_contents(__DIR__ . '/../storage/logs/import_questions_debug.log', $logMsg, FILE_APPEND);
        }
    }

    return [
        'success' => $importate > 0,
        'importate' => $importate,
        'errori' => $errori,
        'skippate' => $skippate,
        'totale' => count($questions),
        'dettagli' => $dettagli
    ];
}

/**
 * Importa domande da CSV
 */
function importFromCSV($dbAdapter, $udaId, $file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File non caricato correttamente'];
    }

    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        return ['success' => false, 'error' => 'Impossibile leggere il file CSV'];
    }

    // Leggi intestazioni
    $headers = fgetcsv($handle);
    if (!$headers || !in_array('domanda', $headers)) {
        fclose($handle);
        return ['success' => false, 'error' => 'CSV non valido: manca colonna "domanda"'];
    }

    $importate = 0;
    $errori = 0;
    $lineNumber = 1;

    while (($row = fgetcsv($handle)) !== false) {
        $lineNumber++;

        try {
            $data = array_combine($headers, $row);

            if (empty($data['domanda'])) {
                $errori++;
                continue;
            }

            $domandaData = [
                'id_domanda' => 'DOM_' . uniqid(),
                'id_uda' => $udaId,
                'argomento' => $data['argomento'] ?? 'Generale',
                'domanda' => trim($data['domanda']),
                'risposta_attesa' => $data['risposta_attesa'] ?? '',
                'parole_chiave' => $data['parole_chiave'] ?? '',
                'difficolta' => intval($data['difficolta'] ?? 3),
                'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                'ordine_consigliato' => intval($data['ordine_consigliato'] ?? $lineNumber),
                'note' => $data['note'] ?? '',
                'tipo_domanda' => $data['tipo'] ?? 'aperta',
                'data_creazione' => date('Y-m-d H:i:s')
            ];

            // Gestione risposte multiple per CSV
            if (isset($data['risposte']) && !empty($data['risposte'])) {
                $risposte = explode('|', $data['risposte']);
                $risposteText = [];
                foreach ($risposte as $r) {
                    if (strpos($r, '*') !== false) {
                        $risposteText[] = '[CORRETTA] ' . str_replace('*', '', $r);
                    } else {
                        $risposteText[] = $r;
                    }
                }
                $domandaData['risposta_attesa'] = implode('; ', $risposteText);
            }

            $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
            $importate++;

        } catch (Exception $e) {
            $errori++;
            $logMsg = "[import_csv] Errore riga {$lineNumber}: " . $e->getMessage() .
                " | dati=" . json_encode($domandaData) . "\n";
            @file_put_contents(__DIR__ . '/../storage/logs/import_questions_debug.log', $logMsg, FILE_APPEND);
        }
    }

    fclose($handle);

    return [
        'success' => $importate > 0,
        'importate' => $importate,
        'errori' => $errori,
        'warnings' => [],
        'totale' => $importate + $errori
    ];
}

/**
 * Importa domande da Excel
 */
function importFromExcel($dbAdapter, $udaId, $file) {
    if (!$file || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File non caricato correttamente'];
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();

        if (empty($rows)) {
            return ['success' => false, 'error' => 'Excel vuoto'];
        }

        $headers = $rows[0];
        $importate = 0;
        $errori = 0;

        for ($i = 1; $i < count($rows); $i++) {
            try {
                $data = array_combine($headers, $rows[$i]);

                if (empty($data['domanda'])) {
                    $errori++;
                    continue;
                }

                $domandaData = [
                    'id_domanda' => 'DOM_' . uniqid(),
                    'id_uda' => $udaId,
                'argomento' => $data['argomento'] ?? 'Generale',
                'domanda' => trim($data['domanda']),
                'risposta_attesa' => convertRispostaExcel($data),
                'parole_chiave' => $data['parole_chiave'] ?? '',
                'difficolta' => intval($data['difficolta'] ?? 3),
                'tempo_risposta_min' => intval($data['tempo_risposta_min'] ?? 3),
                'ordine_consigliato' => intval($data['ordine_consigliato'] ?? ($i + 1)),
                'note' => $data['note'] ?? '',
                'tipo_domanda' => $data['tipo'] ?? 'aperta',
                'data_creazione' => date('Y-m-d H:i:s')
            ];

                $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
                $importate++;

            } catch (Exception $e) {
                $errori++;
                $logMsg = "[import_excel] Errore riga {$i}: " . $e->getMessage() .
                    " | dati=" . json_encode($domandaData) . "\n";
                @file_put_contents(__DIR__ . '/../storage/logs/import_questions_debug.log', $logMsg, FILE_APPEND);
            }
        }

        return [
            'success' => $importate > 0,
            'importate' => $importate,
            'errori' => $errori,
            'warnings' => [],
            'totale' => $importate + $errori
        ];

    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore lettura Excel: ' . $e->getMessage()];
    }
}

/**
 * Converte risposta attesa da vari formati JSON
 */
function convertRispostaAttesa($domanda) {
    $tipo = $domanda['tipo'] ?? 'aperta';

    switch ($tipo) {
        case 'aperta':
            return $domanda['risposta_attesa'] ?? '';

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
            $corretta = $domanda['risposta_corretta'] ?? false;
            return $corretta ? 'VERO' : 'FALSO';

        case 'breve':
            $accettate = $domanda['risposte_accettate'] ?? [];
            return 'Risposte accettate: ' . implode(', ', $accettate);

        case 'numerica':
            $valore = $domanda['valore_corretto'] ?? 0;
            $tolleranza = $domanda['tolleranza'] ?? 0;
            $unita = $domanda['unita_misura'] ?? '';
            return "$valore ± $tolleranza $unita";

        default:
            return $domanda['risposta_attesa'] ?? '';
    }
}

/**
 * Converte risposta da formato Excel
 */
function convertRispostaExcel($data) {
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

/**
 * Converte array parole chiave in stringa CSV
 */
function convertParoleChiave($parole) {
    if (is_array($parole)) {
        return implode(',', $parole);
    }
    return $parole ?? '';
}

/**
 * Parsing JSON generato da AI (anteprima)
 */
function parseQuestionsFromAI(string $rawJson): array
{
    $rawJson = trim($rawJson);
    if ($rawJson === '') {
        return ['success' => false, 'error' => 'Nessun contenuto AI fornito'];
    }

    // Tenta di estrarre eventuale blocco code ```json ... ``` se inviato grezzo
    if (preg_match('/```json\\s*([\\s\\S]*?)\\s*```/i', $rawJson, $m)) {
        $rawJson = trim($m[1]);
    }
    // Rimuovi eventuali caratteri di controllo fuori UTF8
    $rawJson = preg_replace('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/u', '', $rawJson);

    $data = json_decode($rawJson, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_array($data)) {
        // prova a estrarre blocco JSON principale
        $start = strpos($rawJson, '{');
        $end = strrpos($rawJson, '}');
        if ($start !== false && $end !== false && $end > $start) {
            $snippet = substr($rawJson, $start, $end - $start + 1);
            $data = json_decode($snippet, true, 512, JSON_INVALID_UTF8_SUBSTITUTE);
        }
    }

    if (!is_array($data)) {
        $preview = mb_substr($rawJson, 0, 400);
        return ['success' => false, 'error' => 'Formato AI non riconosciuto (JSON non valido). Anteprima contenuto: ' . $preview];
    }

    $domande = $data['domande'] ?? (is_numeric(array_key_first($data ?? [])) ? $data : []);
    if (empty($domande) || !is_array($domande)) {
        return ['success' => false, 'error' => 'Nessuna domanda trovata nel contenuto AI'];
    }

    $preview = [];
    foreach ($domande as $idx => $d) {
        $preview[] = [
            'argomento' => trim($d['argomento'] ?? ($d['topic'] ?? '')) ?: 'Argomento',
            'domanda' => trim($d['domanda'] ?? ($d['testo'] ?? '')),
            'tipo' => $d['tipo'] ?? 'aperta',
            'risposta_attesa' => convertRispostaAttesa($d),
            'parole_chiave' => convertParoleChiave($d['parole_chiave'] ?? []),
            'difficolta' => $d['difficolta'] ?? 3,
            'tempo_risposta_min' => $d['tempo_risposta_min'] ?? 3,
            'ordine_consigliato' => $d['ordine_consigliato'] ?? ($idx + 1),
            'note' => $d['note'] ?? ''
        ];
    }

    return ['success' => true, 'questions' => $preview];
}

function extractGoogleFormId(string $formsUrl): ?string
{
    $formsUrl = trim($formsUrl);
    if ($formsUrl === '') {
        return null;
    }

    $patterns = [
        '/forms\/d\/([a-zA-Z0-9_-]+)\/edit/i',
        '/forms\/d\/e\/([a-zA-Z0-9_-]+)\/viewform/i',
        '/forms\/d\/([a-zA-Z0-9_-]+)/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $formsUrl, $matches)) {
            return $matches[1] ?? null;
        }
    }

    return null;
}

function normalizeImportedText($value): string
{
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }
    $text = preg_replace('/\x{00A0}/u', ' ', $text);
    $text = preg_replace('/\s+/u', ' ', $text);
    return trim($text);
}

function appendUniqueNormalized(array &$list, string $value): void
{
    $value = normalizeImportedText($value);
    if ($value === '') {
        return;
    }
    $valueKey = mb_strtolower($value);
    foreach ($list as $existing) {
        if (mb_strtolower((string)$existing) === $valueKey) {
            return;
        }
    }
    $list[] = $value;
}

function parseCorrectAnswerValuesFromGoogleQuestion($question): array
{
    $correct = [];
    if (!$question || !method_exists($question, 'getGrading')) {
        return $correct;
    }
    $grading = $question->getGrading();
    if (!$grading || !method_exists($grading, 'getCorrectAnswers')) {
        return $correct;
    }
    $correctAnswers = $grading->getCorrectAnswers();
    if (!$correctAnswers || !method_exists($correctAnswers, 'getAnswers')) {
        return $correct;
    }
    $answers = $correctAnswers->getAnswers();
    if (!is_array($answers)) {
        return $correct;
    }
    foreach ($answers as $answer) {
        if (is_object($answer) && method_exists($answer, 'getValue')) {
            $value = normalizeImportedText($answer->getValue());
            if ($value !== '') {
                appendUniqueNormalized($correct, $value);
            }
        }
    }
    return $correct;
}

function normalizeTrueFalseValue(string $value): ?string
{
    $value = mb_strtolower(normalizeImportedText($value));
    if ($value === '') {
        return null;
    }
    $trueValues = ['true', 't', 'vero', 'v', 'yes', 'si', 's'];
    $falseValues = ['false', 'f', 'falso', 'no', 'n'];
    if (in_array($value, $trueValues, true)) {
        return 'VERO';
    }
    if (in_array($value, $falseValues, true)) {
        return 'FALSO';
    }
    return null;
}

function isTrueFalseOptions(array $options): bool
{
    if (empty($options)) {
        return false;
    }
    foreach ($options as $option) {
        if (normalizeTrueFalseValue((string)$option) === null) {
            return false;
        }
    }
    return true;
}

function countMatches(array $items, callable $predicate): int
{
    $count = 0;
    foreach ($items as $item) {
        if ($predicate($item)) {
            $count++;
        }
    }
    return $count;
}

function isLikelyLabelledOption(string $value): bool
{
    return (bool)preg_match('/^[A-H][\.\)]\s+/i', trim($value));
}

function isLikelyShortFragment(string $value): bool
{
    $value = normalizeImportedText($value);
    if ($value === '') {
        return false;
    }
    $wordCount = count(array_filter(preg_split('/\s+/u', $value) ?: []));
    return $wordCount <= 4 && mb_strlen($value) <= 30;
}

function isLikelyNumericValue(string $value): bool
{
    $value = normalizeImportedText($value);
    if ($value === '') {
        return false;
    }
    $normalized = str_replace([' ', ','], ['', '.'], $value);
    return is_numeric($normalized);
}

function questionLooksOpenEnded(string $question): bool
{
    $question = mb_strtolower(normalizeImportedText($question));
    if ($question === '') {
        return false;
    }
    return (bool)preg_match('/\b(spiega|descrivi|argomenta|commenta|motiva|riassumi|racconta|perch[eÃ©]|spiegane)\b/u', $question);
}

function questionLooksChoiceBased(string $question): bool
{
    $question = mb_strtolower(normalizeImportedText($question));
    if ($question === '') {
        return false;
    }
    return (bool)preg_match('/\b(scegli|seleziona|indica|quale tra|multiple choice|true\/false|vero\/falso)\b/u', $question);
}

function buildCorrectAnswerMap(array $correctAnswers, array $options): array
{
    $correctMap = [];
    foreach ($correctAnswers as $ca) {
        $raw = normalizeImportedText((string)$ca);
        if ($raw === '') {
            continue;
        }
        $lowerRaw = mb_strtolower($raw);
        $correctMap[$lowerRaw] = true;

        if (preg_match('/^\d+$/', $raw)) {
            $index = intval($raw) - 1;
            if ($index >= 0 && isset($options[$index])) {
                $correctMap[mb_strtolower((string)$options[$index])] = true;
            }
            continue;
        }

        if (preg_match('/^[A-H]$/i', $raw)) {
            foreach ($options as $opt) {
                if (preg_match('/^\s*([A-H])[\.\)]?\s+/i', (string)$opt, $m) && mb_strtolower($m[1]) === $lowerRaw) {
                    $correctMap[mb_strtolower((string)$opt)] = true;
                }
            }
        }
    }

    return $correctMap;
}

function buildQuestionPreviewItem(
    string $argomento,
    string $domanda,
    array $options,
    array $correctAnswers,
    int $ordine,
    string $note,
    string $source = ''
): array {
    $argomento = trim($argomento) !== '' ? trim($argomento) : 'Importato da test esistente';
    $domanda = normalizeImportedText($domanda);
    $observedOptions = array_values(array_filter(array_map('normalizeImportedText', $options), fn($v) => $v !== ''));
    $optionFrequency = [];
    $options = [];
    foreach ($observedOptions as $opt) {
        $key = mb_strtolower($opt);
        if (!isset($optionFrequency[$key])) {
            $optionFrequency[$key] = ['value' => $opt, 'count' => 0];
            $options[] = $opt;
        }
        $optionFrequency[$key]['count']++;
    }
    $correctAnswers = array_values(array_filter(array_map('normalizeImportedText', $correctAnswers), fn($v) => $v !== ''));

    $tipo = 'aperta';
    $rispostaAttesa = '[Risposta aperta]';
    $paroleChiave = '';
    $previewOptions = [];
    $source = mb_strtolower(trim($source));
    $openEndedHint = questionLooksOpenEnded($domanda);
    $choiceHint = questionLooksChoiceBased($domanda);

    if (!empty($options)) {
        $distinctCount = count($options);
        $observedCount = count($observedOptions);
        $labelledCount = countMatches($options, fn($v) => isLikelyLabelledOption((string)$v));
        $shortCount = countMatches($options, fn($v) => isLikelyShortFragment((string)$v));
        $numericCount = countMatches($options, fn($v) => isLikelyNumericValue((string)$v));
        $letterTokenCount = countMatches($options, fn($v) => (bool)preg_match('/^[A-H]$/i', trim((string)$v)));
        $mostlyShort = $distinctCount > 0 ? ($shortCount / $distinctCount) >= 0.7 : false;
        $mostlyNumeric = $distinctCount > 0 ? ($numericCount / $distinctCount) >= 0.75 : false;
        $maxObservedFrequency = 0;
        foreach ($optionFrequency as $freq) {
            $maxObservedFrequency = max($maxObservedFrequency, (int)($freq['count'] ?? 0));
        }
        $hasCorrectAnswers = !empty($correctAnswers);
        $distributionSuggestsClosed = $observedCount >= 6
            && $distinctCount > 0
            && (($observedCount / $distinctCount) >= 2.2 || ($maxObservedFrequency / max(1, $observedCount)) >= 0.35);

        if (isTrueFalseOptions($options)) {
            $tipo = 'vero_falso';
            $correctNorm = [];
            foreach ($correctAnswers as $ca) {
                $n = normalizeTrueFalseValue($ca);
                if ($n !== null) {
                    $correctNorm[] = $n;
                }
            }
            if (!empty($correctNorm)) {
                $rispostaAttesa = $correctNorm[0];
                $paroleChiave = implode(',', array_unique($correctNorm));
            } else {
                $rispostaAttesa = 'VERO/FALSO';
                $paroleChiave = 'VERO,FALSO';
            }
            $correctMap = buildCorrectAnswerMap($correctAnswers, $options);
            foreach ($options as $opt) {
                $previewOptions[] = [
                    'text' => $opt,
                    'correct' => isset($correctMap[mb_strtolower((string)$opt)]),
                ];
            }
        } else {
            $isLikelyChoice = false;

            if ($source === 'google_forms' || $source === 'kahoot' || $source === 'socrative_pdf') {
                $isLikelyChoice = $distinctCount >= 2;
            } elseif ($source === 'socrative') {
                $isLikelyChoice = $distinctCount >= 2
                    && $distinctCount <= 8
                    && (
                        $hasCorrectAnswers
                        || $labelledCount >= 2
                        || $letterTokenCount >= 2
                        || $choiceHint
                        || $distributionSuggestsClosed
                    );
            } else {
                $isLikelyChoice = $distinctCount >= 2
                    && $distinctCount <= 8
                    && (
                        $hasCorrectAnswers
                        || $labelledCount >= 2
                        || $letterTokenCount >= 2
                        || ($mostlyShort && !$openEndedHint && $distinctCount <= 5)
                    );
            }

            if ($isLikelyChoice && !$openEndedHint) {
                $correctMap = buildCorrectAnswerMap($correctAnswers, $options);
                $markedOptions = [];
                foreach ($options as $opt) {
                    $isCorrect = isset($correctMap[mb_strtolower((string)$opt)]);
                    $previewOptions[] = ['text' => $opt, 'correct' => $isCorrect];
                    $markedOptions[] = ($isCorrect ? '[CORRETTA] ' : '') . $opt;
                }
                $tipo = count($correctAnswers) > 1 ? 'multipla_multi' : 'multipla';
                $rispostaAttesa = implode('; ', $markedOptions);
                $keywordsSource = !empty($correctAnswers) ? $correctAnswers : $options;
                $paroleChiave = implode(',', array_slice($keywordsSource, 0, 3));
            } else {
                $samplePool = array_values($optionFrequency);
                usort($samplePool, function ($a, $b) {
                    return ($b['count'] ?? 0) <=> ($a['count'] ?? 0);
                });
                $sample = array_map(
                    fn($row) => (string)($row['value'] ?? ''),
                    array_slice($samplePool, 0, 5)
                );
                $sample = array_values(array_filter($sample, fn($v) => $v !== ''));
                if (empty($sample)) {
                    $sample = array_slice($options, 0, 5);
                }

                if ($mostlyNumeric && !$openEndedHint && $distinctCount >= 2) {
                    $tipo = 'numerica';
                    $rispostaAttesa = 'Valori osservati: ' . implode(' | ', $sample);
                } elseif ($openEndedHint || (!$mostlyShort && $distinctCount >= 4) || $distinctCount >= 8) {
                    $tipo = 'aperta';
                    $rispostaAttesa = 'Esempi risposte osservate: ' . implode(' | ', $sample);
                } else {
                    $tipo = 'breve';
                    $rispostaAttesa = 'Risposte osservate: ' . implode(' | ', $sample);
                }

                $paroleChiave = implode(',', array_slice($sample, 0, 3));
            }
        }
    } elseif (!empty($correctAnswers)) {
        $allNumeric = countMatches($correctAnswers, fn($v) => isLikelyNumericValue((string)$v)) === count($correctAnswers);
        $allTrueFalse = countMatches($correctAnswers, fn($v) => normalizeTrueFalseValue((string)$v) !== null) === count($correctAnswers);

        if ($allTrueFalse) {
            $normalized = array_values(array_unique(array_filter(array_map('normalizeTrueFalseValue', $correctAnswers))));
            $tipo = 'vero_falso';
            $rispostaAttesa = $normalized[0] ?? 'VERO/FALSO';
            $paroleChiave = implode(',', !empty($normalized) ? $normalized : ['VERO', 'FALSO']);
        } elseif ($allNumeric) {
            $tipo = 'numerica';
            $rispostaAttesa = 'Valori accettati: ' . implode(', ', $correctAnswers);
            $paroleChiave = implode(',', array_slice($correctAnswers, 0, 3));
        } else {
            $tipo = 'breve';
            $rispostaAttesa = 'Risposte accettate: ' . implode(', ', $correctAnswers);
            $paroleChiave = implode(',', array_slice($correctAnswers, 0, 3));
        }
    }

    return [
        'argomento' => $argomento,
        'domanda' => $domanda,
        'tipo' => $tipo,
        'risposta_attesa' => $rispostaAttesa,
        'parole_chiave' => $paroleChiave,
        'difficolta' => 3,
        'tempo_risposta_min' => 3,
        'ordine_consigliato' => $ordine,
        'note' => $note,
        'opzioni' => $previewOptions,
    ];
}

function buildPreviewFromGoogleFormsUrl(string $formsUrl, array $config): array
{
    if ($formsUrl === '') {
        return ['success' => false, 'error' => 'Inserisci il link docente di Google Forms'];
    }

    $formId = extractGoogleFormId($formsUrl);
    if (!$formId) {
        return ['success' => false, 'error' => 'URL Google Forms non valido'];
    }

    try {
        $client = new Client();
        $client->setApplicationName('UDA System');
        $client->setScopes([Forms::FORMS_BODY_READONLY, Forms::FORMS_RESPONSES_READONLY]);
        $credentialsFile = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
        $client->setAuthConfig($credentialsFile);

        $tokenData = GoogleTokenProvider::getToken($config);
        if (empty($tokenData['access_token']) && empty($tokenData)) {
            return ['success' => false, 'error' => 'Token Google non trovato. Autorizza l\'integrazione Google prima di importare.'];
        }
        $client->setAccessToken($tokenData);

        $formsService = new Forms($client);
        $form = $formsService->forms->get($formId);
        $formTitle = normalizeImportedText($form->getInfo()->getTitle() ?? 'Google Form');

        $questions = [];
        $items = $form->getItems();
        if (!is_array($items)) {
            return ['success' => false, 'error' => 'Nessuna domanda trovata nel modulo'];
        }

        $order = 1;
        foreach ($items as $item) {
            if (!$item || !$item->getQuestionItem()) {
                continue;
            }
            $domanda = normalizeImportedText($item->getTitle() ?? '');
            if ($domanda === '') {
                continue;
            }

            $question = $item->getQuestionItem()->getQuestion();
            $correctAnswers = parseCorrectAnswerValuesFromGoogleQuestion($question);
            $options = [];

            if ($question && method_exists($question, 'getChoiceQuestion') && $question->getChoiceQuestion()) {
                $choiceQuestion = $question->getChoiceQuestion();
                $choiceOptions = $choiceQuestion->getOptions();
                if (is_array($choiceOptions)) {
                    foreach ($choiceOptions as $option) {
                        if (is_object($option) && method_exists($option, 'getValue')) {
                            appendUniqueNormalized($options, (string)$option->getValue());
                        }
                    }
                }
            }

            $previewRow = buildQuestionPreviewItem(
                'Importato da Google Forms',
                $domanda,
                $options,
                $correctAnswers,
                $order,
                'Importato da Google Forms: ' . $formTitle,
                'google_forms'
            );

            $questions[] = $previewRow;
            $order++;
        }

        if (empty($questions)) {
            return ['success' => false, 'error' => 'Nessuna domanda importabile trovata nel modulo'];
        }

        return ['success' => true, 'questions' => $questions];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore Google Forms API: ' . $e->getMessage()];
    }
}

function buildPreviewFromExistingQuizExcel($file, string $platform): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File Excel non caricato correttamente'];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if (!in_array($ext, ['xlsx', 'xls'], true)) {
        return ['success' => false, 'error' => 'Formato non valido: carica un file Excel (.xlsx o .xls)'];
    }

    try {
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file['tmp_name']);
        if ($platform === 'kahoot') {
            return buildPreviewFromKahootResultsSpreadsheet($spreadsheet);
        }
        if ($platform === 'socrative') {
            return buildPreviewFromSocrativeResultsSpreadsheet($spreadsheet);
        }
        return ['success' => false, 'error' => 'Piattaforma non supportata'];
    } catch (Exception $e) {
        return ['success' => false, 'error' => 'Errore lettura Excel: ' . $e->getMessage()];
    }
}

function parseArgbToRgb(string $argb): ?array
{
    $argb = strtoupper(trim($argb));
    if ($argb === '') {
        return null;
    }
    if (!preg_match('/^[0-9A-F]{6}([0-9A-F]{2})?$/', $argb)) {
        return null;
    }

    $rgb = strlen($argb) === 8 ? substr($argb, 2) : $argb;
    if (strlen($rgb) !== 6) {
        return null;
    }

    return [
        hexdec(substr($rgb, 0, 2)),
        hexdec(substr($rgb, 2, 2)),
        hexdec(substr($rgb, 4, 2))
    ];
}

function isSocrativeCorrectAnswerCell($sheet, int $col, int $row): bool
{
    try {
        $style = $sheet->getStyleByColumnAndRow($col, $row);
    } catch (Throwable $e) {
        return false;
    }

    $fillType = mb_strtolower((string)$style->getFill()->getFillType());
    if ($fillType === '' || $fillType === 'none') {
        return false;
    }

    $rgb = parseArgbToRgb((string)$style->getFill()->getStartColor()->getARGB());
    if ($rgb === null) {
        return false;
    }

    [$r, $g, $b] = $rgb;

    // Nei report Socrative le risposte corrette sono evidenziate in verde.
    // Esempio tipico: DAF0DE. La condizione evita i grigi dell'header.
    return $g >= 140 && $g >= ($r + 14) && $g >= ($b + 4);
}

function buildPreviewFromSocrativeResultsSpreadsheet($spreadsheet): array
{
    $sheet = $spreadsheet->getActiveSheet();
    $highestRow = $sheet->getHighestRow();
    $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestColumn());

    $headerRow = null;
    for ($r = 1; $r <= min(40, $highestRow); $r++) {
        $a = normalizeImportedText($sheet->getCellByColumnAndRow(1, $r)->getValue());
        $c = normalizeImportedText($sheet->getCellByColumnAndRow(3, $r)->getValue());
        if (stripos($a, 'student name') !== false && stripos($c, 'score') !== false) {
            $headerRow = $r;
            break;
        }
    }
    if ($headerRow === null) {
        return ['success' => false, 'error' => 'Formato Socrative non riconosciuto (intestazioni non trovate)'];
    }

    $quizTitle = normalizeImportedText($sheet->getCellByColumnAndRow(1, 1)->getValue());
    if ($quizTitle === '') {
        $quizTitle = 'Importato da Socrative';
    }

    $studentStartRow = $headerRow + 2; // header + row punti
    $questions = [];
    $order = 1;

    for ($col = 5; $col <= $highestColIndex; $col++) {
        $questionText = normalizeImportedText($sheet->getCellByColumnAndRow($col, $headerRow)->getValue());
        if ($questionText === '') {
            continue;
        }

        $correctObserved = [];
        for ($r = $studentStartRow; $r <= $highestRow; $r++) {
            $studentLabel = normalizeImportedText($sheet->getCellByColumnAndRow(1, $r)->getValue());
            if ($studentLabel === '') {
                continue;
            }
            if (stripos($studentLabel, 'class scoring') !== false || stripos($studentLabel, 'report generated') !== false) {
                break;
            }
            $answer = normalizeImportedText($sheet->getCellByColumnAndRow($col, $r)->getValue());
            if ($answer === '' || $answer === '-') {
                continue;
            }
            if (isSocrativeCorrectAnswerCell($sheet, $col, $r)) {
                appendUniqueNormalized($correctObserved, $answer);
            }
        }

        $rispostaAttesa = '';
        if (!empty($correctObserved)) {
            $rispostaAttesa = implode('; ', $correctObserved);
        }

        $questions[] = [
            'argomento' => $quizTitle,
            'domanda' => $questionText,
            'tipo' => 'aperta',
            'risposta_attesa' => $rispostaAttesa,
            'parole_chiave' => !empty($correctObserved) ? implode(',', array_slice($correctObserved, 0, 3)) : '',
            'difficolta' => 3,
            'tempo_risposta_min' => 3,
            'ordine_consigliato' => $order,
            'note' => 'Importato da report Socrative'
        ];
        $order++;
    }

    if (empty($questions)) {
        return ['success' => false, 'error' => 'Nessuna domanda trovata nel file Socrative'];
    }

    return ['success' => true, 'questions' => $questions];
}

function normalizeSocrativePdfLine(string $line): string
{
    // Corregge artefatti frequenti dell'estrazione PDF Socrative:
    // - U+FFFD spesso al posto della lettera "r"
    // - \0 usato in modo anomalo in mezzo ai testi.
    $line = str_replace("\u{FFFD}", 'r', $line);
    // Esempio frequente: \0PCB\0 -> (PCB)
    $line = preg_replace('/\x00([A-Z]{2,})\x00/u', '($1)', $line);
    // Se \0 compare in mezzo a due lettere, in molti report rappresenta una "r".
    $line = preg_replace_callback('/(?<=\p{L})\x00+(?=\p{L})/u', function ($m) {
        return str_repeat('r', strlen((string)$m[0]));
    }, $line);
    // Gli \0 residui vengono rimossi.
    $line = str_replace("\0", ' ', $line);
    $line = preg_replace('/\x{00A0}/u', ' ', $line);
    $line = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', ' ', $line);
    $line = str_replace(["\t", "\r"], ' ', (string)$line);
    $line = preg_replace('/\s+/u', ' ', trim($line));
    return trim((string)$line);
}

function parseSocrativePdfTrailingOptionLine(string $line): ?array
{
    $line = normalizeSocrativePdfLine($line);
    if ($line === '') {
        return null;
    }

    if (!preg_match('/^(.+?)\s*([A-E])$/u', $line, $m)) {
        return null;
    }

    $text = normalizeImportedText((string)($m[1] ?? ''));
    $letter = mb_strtoupper((string)($m[2] ?? ''));
    if ($text === '' || $letter === '') {
        return null;
    }

    if (preg_match('/\?$/u', $text)) {
        return null;
    }
    if (preg_match('/^(?:question\s*)?\d+\s*[\.\):\-]/iu', $text)) {
        return null;
    }

    return [
        'letter' => $letter,
        'text' => $text
    ];
}

function assignSocrativePdfOptionGroupsToRecentQuestions(array &$parsedQuestions, array $optionGroups): void
{
    if (empty($optionGroups) || empty($parsedQuestions)) {
        return;
    }

    $groupCount = count($optionGroups);
    $questionCount = count($parsedQuestions);
    $startQuestionIndex = max(0, $questionCount - $groupCount);

    for ($g = 0; $g < $groupCount; $g++) {
        $targetQuestionIndex = $startQuestionIndex + $g;
        if (!isset($parsedQuestions[$targetQuestionIndex])) {
            continue;
        }

        $groupOptions = $optionGroups[$g] ?? [];
        if (empty($groupOptions)) {
            continue;
        }

        $existingByLetter = [];
        foreach ((array)($parsedQuestions[$targetQuestionIndex]['options'] ?? []) as $existing) {
            $existingLetter = mb_strtoupper((string)($existing['letter'] ?? ''));
            if ($existingLetter !== '') {
                $existingByLetter[$existingLetter] = true;
            }
        }

        foreach ($groupOptions as $opt) {
            $letter = mb_strtoupper((string)($opt['letter'] ?? ''));
            $text = normalizeImportedText((string)($opt['text'] ?? ''));
            if ($letter === '' || $text === '') {
                continue;
            }
            if (isset($existingByLetter[$letter])) {
                continue;
            }

            $parsedQuestions[$targetQuestionIndex]['options'][] = [
                'letter' => $letter,
                'text' => $text
            ];
            $existingByLetter[$letter] = true;
        }
    }
}

function isLikelySocrativePdfMetaLine(string $line): bool
{
    $line = mb_strtolower(normalizeSocrativePdfLine($line));
    if ($line === '') {
        return true;
    }

    if (preg_match('/^(page\s+\d+|socrative|quiz\s+print|print\s+quiz|date\s*:|time\s*:|room\s*:|teacher\s*:|student\s*:|class\s*:|report\s+generated|generated\s+on)/u', $line)) {
        return true;
    }

    if (preg_match('/^\d+\s*points?$/u', $line)) {
        return true;
    }

    return false;
}

function extractSocrativePdfQuizTitle(array $lines): string
{
    foreach (array_slice($lines, 0, 40) as $line) {
        if (preg_match('/^quiz(?:\s+name)?\s*[:\-]\s*(.+)$/iu', $line, $m)) {
            $title = normalizeImportedText($m[1] ?? '');
            if ($title !== '') {
                return $title;
            }
        }
    }

    foreach (array_slice($lines, 0, 25) as $line) {
        $line = normalizeImportedText($line);
        if ($line === '') {
            continue;
        }
        if (isLikelySocrativePdfMetaLine($line)) {
            continue;
        }
        if (preg_match('/^(?:question\s*)?\d+\s*[\.\):\-]\s+/iu', $line)) {
            continue;
        }
        if (mb_strlen($line) >= 4 && mb_strlen($line) <= 160) {
            return $line;
        }
    }

    return 'Importato da stampa test Socrative';
}

function parseSocrativePdfAnswerTokens(string $raw): array
{
    $raw = normalizeSocrativePdfLine($raw);
    if ($raw === '') {
        return [];
    }

    $raw = preg_replace('/^(answers?|correct answers?)\s*[:\-]?\s*/iu', '', $raw);
    $parts = preg_split('/\s*(?:,|;|\/|&|\||\+|\band\b)\s*/iu', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
        $parts = [$raw];
    }

    $tokens = [];
    foreach ($parts as $part) {
        $part = trim((string)$part);
        $part = trim($part, " \t\n\r\0\x0B.:-()[]");
        if ($part === '') {
            continue;
        }

        if (preg_match('/^[A-E]$/iu', $part)) {
            $tokens[] = mb_strtoupper($part);
            continue;
        }

        $tf = normalizeTrueFalseValue($part);
        if ($tf !== null) {
            $tokens[] = $tf;
            continue;
        }

        if (mb_strlen($part) <= 120) {
            $tokens[] = $part;
        }
    }

    return array_values(array_unique($tokens));
}

function extractSocrativePdfAnswerKeyMap(array $lines): array
{
    $map = [];
    $startIndex = null;
    foreach ($lines as $i => $line) {
        if (preg_match('/\b(answer key|correct answers?)\b/iu', $line)) {
            $startIndex = $i;
            break;
        }
    }

    if ($startIndex === null) {
        return $map;
    }

    for ($i = $startIndex; $i < count($lines); $i++) {
        $line = normalizeSocrativePdfLine($lines[$i] ?? '');
        if ($line === '') {
            continue;
        }

        if (!preg_match_all('/(?:question\s*)?(\d+)\s*[\.\):\-]/iu', $line, $heads, PREG_OFFSET_CAPTURE)) {
            continue;
        }

        $headCount = count($heads[0]);
        for ($h = 0; $h < $headCount; $h++) {
            $qNum = intval($heads[1][$h][0] ?? 0);
            if ($qNum <= 0) {
                continue;
            }

            $fullHead = (string)($heads[0][$h][0] ?? '');
            $start = intval($heads[0][$h][1] ?? 0) + strlen($fullHead);
            $nextStart = $h + 1 < $headCount ? intval($heads[0][$h + 1][1] ?? strlen($line)) : strlen($line);
            $segment = trim(substr($line, $start, max(0, $nextStart - $start)));
            if ($segment === '') {
                continue;
            }

            $tokens = parseSocrativePdfAnswerTokens($segment);
            if (empty($tokens)) {
                continue;
            }

            if (!isset($map[$qNum])) {
                $map[$qNum] = [];
            }
            foreach ($tokens as $token) {
                appendUniqueNormalized($map[$qNum], $token);
            }
        }
    }

    return $map;
}

function mapSocrativePdfKeyToCorrectAnswers(array $optionsByLetter, array $tokens): array
{
    $correct = [];
    foreach ($tokens as $token) {
        $token = normalizeImportedText((string)$token);
        if ($token === '') {
            continue;
        }

        if (preg_match('/^[A-E]$/iu', $token)) {
            $letter = mb_strtoupper($token);
            if (isset($optionsByLetter[$letter])) {
                appendUniqueNormalized($correct, (string)$optionsByLetter[$letter]);
            }
            continue;
        }

        $tfToken = normalizeTrueFalseValue($token);
        if ($tfToken !== null) {
            $matched = false;
            foreach ($optionsByLetter as $opt) {
                if (normalizeTrueFalseValue((string)$opt) === $tfToken) {
                    appendUniqueNormalized($correct, (string)$opt);
                    $matched = true;
                }
            }
            if (!$matched && empty($optionsByLetter)) {
                appendUniqueNormalized($correct, $tfToken);
            }
            continue;
        }

        $matchedText = false;
        foreach ($optionsByLetter as $opt) {
            if (mb_strtolower(normalizeImportedText((string)$opt)) === mb_strtolower($token)) {
                appendUniqueNormalized($correct, (string)$opt);
                $matchedText = true;
                break;
            }
        }

        if (!$matchedText && empty($optionsByLetter)) {
            appendUniqueNormalized($correct, $token);
        }
    }

    return $correct;
}

function buildPreviewFromSocrativeQuizPdf($file): array
{
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        return ['success' => false, 'error' => 'File PDF non caricato correttamente'];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    if ($ext !== 'pdf') {
        return ['success' => false, 'error' => 'Formato non valido: carica un file PDF'];
    }

    if (!class_exists(PdfParser::class)) {
        return ['success' => false, 'error' => 'Parser PDF non disponibile sul server'];
    }

    try {
        $parser = new PdfParser();
        $pdf = $parser->parseFile($file['tmp_name']);
        $rawText = (string)$pdf->getText();
    } catch (Throwable $e) {
        return ['success' => false, 'error' => 'Errore lettura PDF Socrative: ' . $e->getMessage()];
    }

    if (trim($rawText) === '') {
        return ['success' => false, 'error' => 'Il PDF non contiene testo leggibile'];
    }

    $rawLines = preg_split('/\r\n|\r|\n/u', $rawText) ?: [];
    $lines = [];
    foreach ($rawLines as $line) {
        $norm = normalizeSocrativePdfLine((string)$line);
        if ($norm !== '') {
            $lines[] = $norm;
        }
    }

    if (empty($lines)) {
        return ['success' => false, 'error' => 'Impossibile estrarre righe utili dal PDF'];
    }

    $quizTitle = extractSocrativePdfQuizTitle($lines);
    $argomentoFromFile = normalizeImportedText(pathinfo((string)($file['name'] ?? ''), PATHINFO_FILENAME));
    if ($argomentoFromFile === '') {
        $argomentoFromFile = $quizTitle;
    }
    $answerKeyMap = extractSocrativePdfAnswerKeyMap($lines);

    $parsedQuestions = [];
    $current = null;
    $lastInlineOptionIndex = null;
    $currentTrailingGroup = [];
    $pendingOptionGroups = [];
    $inOptionsArea = false;

    $flushTrailingGroup = function () use (&$currentTrailingGroup, &$pendingOptionGroups): void {
        if (empty($currentTrailingGroup)) {
            return;
        }
        $pendingOptionGroups[] = $currentTrailingGroup;
        $currentTrailingGroup = [];
    };

    $flushCurrent = function () use (&$current, &$parsedQuestions): void {
        if (!$current) {
            return;
        }
        $current['question'] = normalizeImportedText((string)($current['question'] ?? ''));
        if ($current['question'] !== '') {
            $parsedQuestions[] = $current;
        }
        $current = null;
    };

    $assignPendingGroups = function () use (&$parsedQuestions, &$pendingOptionGroups): void {
        if (empty($pendingOptionGroups)) {
            return;
        }
        assignSocrativePdfOptionGroupsToRecentQuestions($parsedQuestions, $pendingOptionGroups);
        $pendingOptionGroups = [];
    };

    foreach ($lines as $line) {
        $line = normalizeSocrativePdfLine($line);
        if ($line === '') {
            continue;
        }

        if (preg_match('/\b(answer key|correct answers?)\b/iu', $line)) {
            // La sezione answer key viene gestita separatamente.
            $flushTrailingGroup();
            $flushCurrent();
            $assignPendingGroups();
            break;
        }

        if (preg_match('/^(?:question\s*)?(\d+)\s*[\.\):\-]\s*(.+)$/iu', $line, $m)) {
            $flushTrailingGroup();
            $flushCurrent();
            $assignPendingGroups();

            $current = [
                'number' => intval($m[1] ?? 0),
                'question' => normalizeImportedText((string)($m[2] ?? '')),
                'options' => [],
                'correct_letters' => []
            ];
            $lastInlineOptionIndex = null;
            $inOptionsArea = false;
            continue;
        }

        if (!$current) {
            continue;
        }

        if (preg_match('/^(?:[\[\(]?\s*(?:x|X|✓|✔)\s*[\]\)]\s*)?([A-E])[\.\)\-:]\s*(.+)$/u', $line, $m)) {
            $inOptionsArea = true;
            $hasMarker = (bool)preg_match('/^[\[\(]?\s*(?:x|X|✓|✔)\s*[\]\)]\s*/u', $line);
            $letter = mb_strtoupper((string)$m[1]);
            $text = normalizeImportedText((string)$m[2]);
            if ($text !== '') {
                $current['options'][] = ['letter' => $letter, 'text' => $text];
                if ($hasMarker) {
                    $current['correct_letters'][$letter] = true;
                }
                $lastInlineOptionIndex = count($current['options']) - 1;
            }
            continue;
        }

        $trailingOption = parseSocrativePdfTrailingOptionLine($line);
        if ($trailingOption !== null) {
            $inOptionsArea = true;
            $letter = (string)($trailingOption['letter'] ?? '');
            if (!empty($currentTrailingGroup) && $letter === 'A') {
                $flushTrailingGroup();
            }
            $currentTrailingGroup[] = $trailingOption;
            continue;
        }

        if ($inOptionsArea) {
            if ($lastInlineOptionIndex !== null && !isLikelySocrativePdfMetaLine($line)) {
                $current['options'][$lastInlineOptionIndex]['text'] .= ' ' . $line;
                continue;
            }

            if (!empty($currentTrailingGroup) && !isLikelySocrativePdfMetaLine($line)) {
                $lastTrailingIndex = count($currentTrailingGroup) - 1;
                $currentTrailingGroup[$lastTrailingIndex]['text'] .= ' ' . $line;
                continue;
            }
        }

        if (!isLikelySocrativePdfMetaLine($line)) {
            $current['question'] .= ' ' . $line;
        }
    }

    $flushTrailingGroup();
    $flushCurrent();
    $assignPendingGroups();

    if (empty($parsedQuestions)) {
        return ['success' => false, 'error' => 'Formato PDF Socrative non riconosciuto (domande non trovate)'];
    }

    usort($parsedQuestions, function ($a, $b) {
        return intval($a['number'] ?? 0) <=> intval($b['number'] ?? 0);
    });

    $questions = [];
    $order = 1;
    foreach ($parsedQuestions as $q) {
        $number = intval($q['number'] ?? 0);
        $optionsByLetter = [];
        foreach ((array)($q['options'] ?? []) as $option) {
            $letter = mb_strtoupper((string)($option['letter'] ?? ''));
            $text = normalizeImportedText((string)($option['text'] ?? ''));
            if ($letter !== '' && $text !== '') {
                $optionsByLetter[$letter] = $text;
            }
        }
        if (!empty($optionsByLetter)) {
            ksort($optionsByLetter);
        }

        $correctTokens = [];
        foreach (array_keys((array)($q['correct_letters'] ?? [])) as $letter) {
            $correctTokens[] = mb_strtoupper((string)$letter);
        }
        if ($number > 0 && isset($answerKeyMap[$number])) {
            foreach ((array)$answerKeyMap[$number] as $token) {
                $correctTokens[] = (string)$token;
            }
        }

        $correctAnswers = mapSocrativePdfKeyToCorrectAnswers($optionsByLetter, $correctTokens);
        $options = array_values($optionsByLetter);

        $row = buildQuestionPreviewItem(
            $argomentoFromFile,
            (string)($q['question'] ?? ''),
            $options,
            $correctAnswers,
            $order,
            'Importato da stampa test Socrative (PDF): ' . $quizTitle,
            'socrative_pdf'
        );

        if (empty($options)) {
            $row['tipo'] = 'aperta';
            $row['risposta_attesa'] = !empty($correctAnswers) ? implode('; ', $correctAnswers) : '';
            $row['parole_chiave'] = !empty($correctAnswers) ? implode(',', array_slice($correctAnswers, 0, 3)) : '';
        }

        $questions[] = $row;
        $order++;
    }

    if (empty($questions)) {
        return ['success' => false, 'error' => 'Nessuna domanda importabile trovata nel PDF'];
    }

    return ['success' => true, 'questions' => $questions];
}

function splitCorrectAnswersValue(string $raw): array
{
    $raw = normalizeImportedText($raw);
    if ($raw === '') {
        return [];
    }
    $parts = preg_split('/\s*(?:,|;|\||\r?\n)+\s*/u', $raw, -1, PREG_SPLIT_NO_EMPTY);
    if (empty($parts)) {
        return [$raw];
    }
    return array_values(array_unique(array_map('normalizeImportedText', $parts)));
}

function isKahootOptionHeader(string $headerName): bool
{
    $headerName = mb_strtolower(normalizeImportedText($headerName));
    if ($headerName === '') {
        return false;
    }

    // Opzioni vere: "Answer 1", "Answer 2", ...
    if (!preg_match('/^answer\s+\d+\b/u', $headerName)) {
        return false;
    }

    // Escludi colonne statistiche/metriche (es. "Answer 1 Correct %", "Answer Time ...").
    if (preg_match('/\b(correct|incorrect|time|second|sec|percent|percentage|score|streak|player|response)\b|%/u', $headerName)) {
        return false;
    }

    return true;
}

function buildPreviewFromKahootResultsSpreadsheet($spreadsheet): array
{
    $overviewSheet = $spreadsheet->getSheetByName('Overview');
    $quizTitle = $overviewSheet ? normalizeImportedText($overviewSheet->getCell('A1')->getValue()) : '';
    if ($quizTitle === '') {
        $quizTitle = 'Importato da Kahoot';
    }

    $rawSheet = $spreadsheet->getSheetByName('RawReportData Data');
    if ($rawSheet) {
        $highestRow = $rawSheet->getHighestRow();
        $highestColIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($rawSheet->getHighestColumn());

        $headers = [];
        for ($col = 1; $col <= $highestColIndex; $col++) {
            $headers[$col] = mb_strtolower(normalizeImportedText($rawSheet->getCellByColumnAndRow($col, 1)->getValue()));
        }

        $colQuestionNumber = array_search('question number', $headers, true);
        $colQuestion = array_search('question', $headers, true);
        $colCorrect = array_search('correct answers', $headers, true);
        if ($colQuestionNumber === false || $colQuestion === false) {
            return ['success' => false, 'error' => 'Formato Kahoot non riconosciuto (sheet RawReportData senza colonne attese)'];
        }

        $optionCols = [];
        // Priorita: colonne tra "Question" e "Correct Answers" (layout standard export Kahoot).
        if ($colCorrect !== false && $colCorrect > ($colQuestion + 1)) {
            for ($col = $colQuestion + 1; $col < $colCorrect; $col++) {
                $headerName = $headers[$col] ?? '';
                if (isKahootOptionHeader($headerName)) {
                    $optionCols[] = $col;
                }
            }
        }

        // Fallback: cerca comunque tutte le colonne opzione valide.
        if (empty($optionCols)) {
            foreach ($headers as $col => $headerName) {
                if (!isKahootOptionHeader($headerName)) {
                    continue;
                }
                $optionCols[] = $col;
            }
        }

        $groups = [];
        for ($r = 2; $r <= $highestRow; $r++) {
            $questionNumber = normalizeImportedText($rawSheet->getCellByColumnAndRow($colQuestionNumber, $r)->getValue());
            $questionText = normalizeImportedText($rawSheet->getCellByColumnAndRow($colQuestion, $r)->getValue());
            if ($questionText === '') {
                continue;
            }

            $key = mb_strtolower($questionNumber . '|' . $questionText);
            if (!isset($groups[$key])) {
                $order = is_numeric($questionNumber) ? intval($questionNumber) : (count($groups) + 1);
                $groups[$key] = [
                    'question' => $questionText,
                    'order' => $order,
                    'options' => [],
                    'correct' => []
                ];
            }

            foreach ($optionCols as $oc) {
                $optValue = normalizeImportedText($rawSheet->getCellByColumnAndRow($oc, $r)->getValue());
                if ($optValue !== '') {
                    appendUniqueNormalized($groups[$key]['options'], $optValue);
                }
            }

            if ($colCorrect !== false) {
                $correctRaw = normalizeImportedText($rawSheet->getCellByColumnAndRow($colCorrect, $r)->getValue());
                foreach (splitCorrectAnswersValue($correctRaw) as $ca) {
                    appendUniqueNormalized($groups[$key]['correct'], $ca);
                }
            }
        }

        if (!empty($groups)) {
            usort($groups, function ($a, $b) {
                return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
            });

            $questions = [];
            foreach ($groups as $idx => $g) {
                $questions[] = buildQuestionPreviewItem(
                    $quizTitle,
                    $g['question'] ?? '',
                    $g['options'] ?? [],
                    $g['correct'] ?? [],
                    $idx + 1,
                    'Importato da report Kahoot',
                    'kahoot'
                );
            }

            if (!empty($questions)) {
                return ['success' => true, 'questions' => $questions];
            }
        }
    }

    // Fallback: fogli "N Quiz"
    $questions = [];
    $sheets = $spreadsheet->getAllSheets();
    foreach ($sheets as $sheet) {
        $title = $sheet->getTitle();
        if (!preg_match('/^(\d+)\s+Quiz$/i', $title, $matches)) {
            continue;
        }
        $order = intval($matches[1]);
        $questionText = normalizeImportedText($sheet->getCell('B2')->getValue());
        if ($questionText === '') {
            continue;
        }
        $correctRaw = normalizeImportedText($sheet->getCell('C3')->getValue());
        $correctAnswers = splitCorrectAnswersValue($correctRaw);

        $options = [];
        foreach (['D', 'F', 'H', 'J', 'L', 'N'] as $colLetter) {
            $opt = normalizeImportedText($sheet->getCell($colLetter . '8')->getValue());
            if ($opt !== '') {
                appendUniqueNormalized($options, $opt);
            }
        }

        $questions[] = [
            'order' => $order,
            'row' => buildQuestionPreviewItem(
                $quizTitle,
                $questionText,
                $options,
                $correctAnswers,
                $order,
                'Importato da report Kahoot',
                'kahoot'
            )
        ];
    }

    if (empty($questions)) {
        return ['success' => false, 'error' => 'Nessuna domanda trovata nel file Kahoot'];
    }

    usort($questions, function ($a, $b) {
        return ($a['order'] ?? 0) <=> ($b['order'] ?? 0);
    });

    return ['success' => true, 'questions' => array_values(array_column($questions, 'row'))];
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importa Domande - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/question-card.css?v=<?= @filemtime(__DIR__ . '/assets/css/question-card.css') ?>">
    <style>
        .modalita-card {
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .modalita-card:hover {
            border-color: #0d6efd;
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .modalita-card.selected {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
        .format-example {
            background-color: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 15px;
            margin: 10px 0;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
        }
        #formsCatalogList {
            max-height: 320px;
            overflow-y: auto;
        }
        /* Evidenziazione tenue (azzurrino trasparente) per i form già pubblicati in Classroom. */
        #formsCatalogList .list-group-item-info {
            background-color: rgba(13, 110, 253, 0.14);
            color: inherit;
        }
        .form-legend-swatch {
            display: inline-block;
            width: 0.8em;
            height: 0.8em;
            border-radius: 3px;
            background-color: rgba(13, 110, 253, 0.14);
            border: 1px solid rgba(13, 110, 253, 0.30);
            vertical-align: -0.05em;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-download"></i> Importa Domande';
    $headerActions = '<a class="nav-link" href="' . htmlspecialchars($returnTo) . '">'
        . '<i class="bi bi-arrow-left"></i> Torna al contesto</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4" data-preview-mode="<?= !empty($previewQuestions) ? 'true' : 'false' ?>" data-import-completed="<?= $importCompleted ? 'true' : 'false' ?>">
        <?php if ($isTempUda && $importCompleted): ?>
            <div class="alert alert-success d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-info-circle"></i> Import su UDA temporanea. Le domande saranno riassegnate al termine del wizard.
                </div>
                <button class="btn btn-outline-success btn-sm" onclick="if (window.opener && !window.opener.closed) { try { window.opener.dispatchEvent(new Event('uda-questions-imported')); } catch (e) {} } window.close();">
                    Chiudi e torna al wizard
                </button>
            </div>
        <?php elseif (!$isTempUda): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                <strong>UDA:</strong> <?= htmlspecialchars($uda->titolo) ?>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>

                <?php if ($importResult): ?>
                    <hr>
                    <h5>Risultato Importazione:</h5>
                    <ul class="mb-0">
                        <li><strong>Totale domande:</strong> <?= $importResult['totale'] ?? 0 ?></li>
                        <li><strong>Importate con successo:</strong> <?= $importResult['importate'] ?? 0 ?></li>
                        <?php if (($importResult['errori'] ?? 0) > 0): ?>
                            <li><strong>Errori:</strong> <?= $importResult['errori'] ?></li>
                        <?php endif; ?>
                        <?php if (!empty($importResult['warnings'])): ?>
                            <li><strong>Warning:</strong> <?= count($importResult['warnings']) ?></li>
                        <?php endif; ?>
                        <?php if (isset($importResult['skippate'])): ?>
                            <li><strong>Escluse:</strong> <?= $importResult['skippate'] ?></li>
                        <?php endif; ?>
                    </ul>

                    <?php if (!$isTempUda): ?>
                    <div class="mt-3">
                        <a href="<?= htmlspecialchars($returnTo) ?>" class="btn btn-primary">
                            <i class="bi bi-list-ul"></i> Vai alle Domande
                        </a>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php include __DIR__ . '/partials/import_preview.php'; ?>

        <?php $selectionHiddenClass = ($importCompleted || !empty($previewQuestions)) ? ' d-none' : ''; ?>
        <form method="POST" enctype="multipart/form-data" id="existingTestForm" class="mb-4<?= $selectionHiddenClass ?>">
            <input type="hidden" name="action" value="preview_existing_test">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
            <input type="hidden" name="existing_source" id="existingSourceInput" value="<?= htmlspecialchars($existingSource) ?>">

            <div class="card">
                <div class="card-header bg-dark text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-1-circle"></i> Importa da un test esistente
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3 mb-3">
                        <div class="col-md-4">
                            <div class="card modalita-card h-100 existing-source-card" data-existing-source="google_forms" onclick="selectExistingSource('google_forms')">
                                <div class="card-body text-center">
                                    <i class="bi bi-google display-5 text-success"></i>
                                    <h5 class="mt-3 mb-1">Google Forms</h5>
                                    <p class="text-muted small mb-0">Import da link docente del modulo</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card modalita-card h-100 existing-source-card" data-existing-source="kahoot" onclick="selectExistingSource('kahoot')">
                                <div class="card-body text-center">
                                    <i class="bi bi-stars display-5" style="color:#46178f;"></i>
                                    <h5 class="mt-3 mb-1">Kahoot</h5>
                                    <p class="text-muted small mb-0">Import risultati da file Excel</p>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card modalita-card h-100 existing-source-card" data-existing-source="socrative" onclick="selectExistingSource('socrative')">
                                <div class="card-body text-center">
                                    <i class="bi bi-clipboard-check display-5 text-warning"></i>
                                    <h5 class="mt-3 mb-1">Socrative</h5>
                                    <p class="text-muted small mb-0">Import risultati da Excel o PDF stampa test</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div id="existing_input_google_forms" class="existing-input">
                        <label class="form-label">Link docente Google Forms</label>
                        <input type="url"
                               name="forms_url"
                               id="formsUrlInput"
                               class="form-control"
                               placeholder="https://docs.google.com/forms/d/.../edit"
                               value="<?= htmlspecialchars($formsUrlInput) ?>">
                        <div class="form-text">Usa il link di modifica del modulo (docente).</div>
                        <div id="googleFormsCatalogStatus" class="alert alert-info mt-3 mb-2 d-none" role="status"></div>
                        <div id="googleFormsAuthorizationNotice" class="alert alert-warning mt-3 mb-2 d-none">
                            <i class="bi bi-shield-lock"></i>
                            Autorizzazione Google Drive e Forms non presente o insufficiente.
                            <a class="btn btn-sm btn-warning ms-2" href="<?= htmlspecialchars($googleIntegrationUrl, ENT_QUOTES) ?>">
                                <i class="bi bi-box-arrow-in-right"></i> Vai alle Integrazioni Google
                            </a>
                        </div>
                        <div id="googleFormsCatalog" class="mt-3 d-none" data-course-id="<?= htmlspecialchars($classroomCourseId) ?>">
                            <div class="d-flex justify-content-between align-items-center">
                                <label for="formsCatalogSearch" class="form-label mb-0">Forms disponibili nel Drive</label>
                                <span id="formsClassroomLegend" class="small text-muted d-none">
                                    <span class="form-legend-swatch me-1"></span>Pubblicato nella Classroom
                                </span>
                            </div>
                            <input type="search" id="formsCatalogSearch" class="form-control mb-2" placeholder="Cerca per titolo, autore o data..." autocomplete="off">
                            <div id="formsCatalogList" class="list-group" role="listbox" aria-label="Google Forms disponibili"></div>
                        </div>
                    </div>

                    <div id="existing_input_kahoot" class="existing-input" style="display:none;">
                        <label class="form-label">File Excel risultati Kahoot</label>
                        <input type="file" name="file_existing_kahoot" class="form-control" accept=".xlsx,.xls">
                        <div class="form-text">Usa il file delle statistiche esportato da Kahoot: vai sul test, apri "Statistiche" e clicca "Esporta statistiche".</div>
                    </div>

                                        <div id="existing_input_socrative" class="existing-input" style="display:none;">
                        <label class="form-label d-block">Formato sorgente Socrative</label>
                        <div class="mb-2">
                            <div class="form-check form-check-inline">
                                <input class="form-check-input"
                                       type="radio"
                                       name="socrative_source_format"
                                       id="socrativeSourceExcel"
                                       value="excel"
                                       <?= $socrativeSourceFormat === 'excel' ? 'checked' : '' ?>
                                       onclick="toggleSocrativeSourceFormat('excel')">
                                <label class="form-check-label" for="socrativeSourceExcel">Excel risultati</label>
                            </div>
                            <div class="form-check form-check-inline">
                                <input class="form-check-input"
                                       type="radio"
                                       name="socrative_source_format"
                                       id="socrativeSourcePdf"
                                       value="pdf"
                                       <?= $socrativeSourceFormat === 'pdf' ? 'checked' : '' ?>
                                       onclick="toggleSocrativeSourceFormat('pdf')">
                                <label class="form-check-label" for="socrativeSourcePdf">PDF stampa test</label>
                            </div>
                        </div>

                        <div id="socrative_source_excel_input">
                            <label class="form-label">File Excel risultati Socrative</label>
                            <input type="file" name="file_existing_socrative" class="form-control" accept=".xlsx,.xls">
                            <div class="form-text">Vai su "Report" in Socrative, seleziona il test e clicca su "Export results", poi scarica il report del quiz in Excel (per account gratuiti il report e disponibile entro 30 giorni).</div>
                        </div>

                        <div id="socrative_source_pdf_input" style="display:none;">
                            <label class="form-label mt-2">File PDF stampa test Socrative</label>
                            <input type="file" name="file_existing_socrative_pdf" class="form-control" accept=".pdf">
                            <div class="form-text">Carica la stampa PDF del quiz. Su "Library" premi sul pulsante di download [⬇️] in corrispondenza del quiz.<br>ATTENZIONE! Nel pdf non sono definite le risposte corrette, ricordati di definirle una volta importate!</div>
                        </div>
                    </div>
                </div>
                <div class="card-footer d-flex justify-content-end">
                    <button type="submit" class="btn btn-dark">
                        <i class="bi bi-eye"></i> Carica test e mostra anteprima
                    </button>
                </div>
            </div>
        </form>
        <form method="POST" enctype="multipart/form-data" id="previewForm" class="<?= trim($selectionHiddenClass) ?>">
            <input type="hidden" name="action" value="preview">
            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
            <input type="hidden" name="modalita" id="modalitaInput" value="<?= htmlspecialchars($modalita) ?>">

            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-2-circle"></i> Import da un file strutturato
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-md-4">
                            <div class="card modalita-card h-100" data-modalita="json" onclick="selectModalita('json')">
                                <div class="card-body text-center">
                                    <i class="bi bi-file-earmark-code display-4 text-primary"></i>
                                    <h5 class="mt-3 mb-1">JSON</h5>
                                    <p class="text-muted small">Formato strutturato completo</p>
                                    <span class="badge bg-success">Consigliato</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card modalita-card h-100" data-modalita="csv" onclick="selectModalita('csv')">
                                <div class="card-body text-center">
                                    <i class="bi bi-file-earmark-spreadsheet display-4 text-warning"></i>
                                    <h5 class="mt-3 mb-1">CSV</h5>
                                    <p class="text-muted small">Import veloce da tabella</p>
                                    <span class="badge bg-warning text-dark">Tabellare</span>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <div class="card modalita-card h-100" data-modalita="excel" onclick="selectModalita('excel')">
                                <div class="card-body text-center">
                                    <i class="bi bi-file-earmark-excel display-4 text-success"></i>
                                    <h5 class="mt-3 mb-1">Excel</h5>
                                    <p class="text-muted small">Foglio di calcolo compatibile</p>
                                    <span class="badge bg-success">XLSX</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <?php $templateCollapsed = !empty($successMessage); ?>
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white d-flex justify-content-between align-items-center">
                    <button class="btn btn-link text-white text-decoration-none p-0 d-flex align-items-center"
                            type="button"
                            data-bs-toggle="collapse"
                            data-bs-target="#templateCollapse"
                            aria-expanded="<?= $templateCollapsed ? 'false' : 'true' ?>">
                        <h5 class="mb-0">
                            <i class="bi bi-3-circle"></i> Scarica il template e carica il file
                        </h5>
                    </button>
                    <div class="d-flex gap-2">
                        <a id="templateDownload" href="download_template_domande.php?format=json" class="btn btn-outline-light btn-sm" download>
                            <i class="bi bi-download"></i> Template selezionato
                        </a>
                    </div>
                </div>
                <div id="templateCollapse" class="collapse <?= $templateCollapsed ? '' : 'show' ?>">
                    <div class="card-body">
                    <div id="input_json" class="import-input">
                        <div class="mb-3">
                            <label class="form-label">Carica file JSON</label>
                            <div class="input-group">
                                <input type="file" id="jsonFileLoader" class="form-control" accept=".json">
                                <button type="button" class="btn btn-outline-primary" id="loadJsonFileButton">
                                    <i class="bi bi-upload"></i> Carica nella textarea
                                </button>
                            </div>
                            <div class="form-text">Usa il template JSON per compilare le domande. Il file viene letto nel browser e non viene caricato direttamente.</div>
                        </div>

                        <div class="mb-3">
                            <label for="jsonContent" class="form-label">Contenuto JSON modificabile</label>
                            <textarea id="jsonContent" name="json_content" class="form-control font-monospace" rows="22" spellcheck="false"><?= htmlspecialchars($jsonTextareaContent) ?></textarea>
                        </div>

                        <div class="format-example d-none" aria-hidden="true">
  <strong>Struttura di riferimento</strong>
  <pre><code>{
  "id_uda": "UDA_XXX",
  "metadata": {
    "titolo": "Titolo della banca domande",
    "descrizione": "Descrizione opzionale",
    "autore": "Nome Docente",
    "data_creazione": "2025-01-15",
    "versione": "1.0"
  },
  "domande": [
    {
      "argomento": "Nome Argomento",
      "tipo": "aperta",
      "domanda": "Testo della domanda a risposta aperta?",
      "risposta_attesa": "Risposta che ti aspetti dallo studente. Più dettagliata possibile per valutazione oggettiva.",
      "parole_chiave": ["parola1", "parola2", "concetto3"],
      "difficolta": 3,
      "tempo_risposta_min": 5,
      "ordine_consigliato": 1,
      "note": "Note opzionali per l'insegnante"
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "multipla",
      "domanda": "Domanda a scelta multipla (una sola risposta corretta)?",
      "risposte": [
        {
          "testo": "Prima opzione (errata)",
          "corretta": false
        },
        {
          "testo": "Seconda opzione (corretta)",
          "corretta": true
        },
        {
          "testo": "Terza opzione (errata)",
          "corretta": false
        },
        {
          "testo": "Quarta opzione (errata)",
          "corretta": false
        }
      ],
      "spiegazione": "Spiegazione del perché la risposta corretta è quella giusta",
      "parole_chiave": ["concetto", "tema"],
      "difficolta": 2,
      "tempo_risposta_min": 2,
      "ordine_consigliato": 2
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "multipla_multi",
      "domanda": "Domanda con più risposte corrette. Quali delle seguenti affermazioni sono vere?",
      "risposte": [
        {
          "testo": "Affermazione 1 (vera)",
          "corretta": true
        },
        {
          "testo": "Affermazione 2 (falsa)",
          "corretta": false
        },
        {
          "testo": "Affermazione 3 (vera)",
          "corretta": true
        },
        {
          "testo": "Affermazione 4 (falsa)",
          "corretta": false
        }
      ],
      "spiegazione": "Spiegazione delle risposte corrette",
      "difficolta": 4,
      "tempo_risposta_min": 4,
      "ordine_consigliato": 3
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "vero_falso",
      "domanda": "Affermazione da valutare come vera o falsa",
      "risposta_corretta": true,
      "spiegazione": "Spiegazione del perché è vero/falso",
      "parole_chiave": ["concetto"],
      "difficolta": 1,
      "tempo_risposta_min": 1,
      "ordine_consigliato": 4
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "breve",
      "domanda": "Domanda che richiede una risposta breve (una parola o frase corta)?",
      "risposte_accettate": ["risposta1", "sinonimo1", "variante1"],
      "case_sensitive": false,
      "parole_chiave": ["termine", "definizione"],
      "difficolta": 2,
      "tempo_risposta_min": 2,
      "ordine_consigliato": 5
    },
    {
      "argomento": "Nome Argomento",
      "tipo": "numerica",
      "domanda": "Domanda che richiede un valore numerico come risposta?",
      "valore_corretto": 42.5,
      "tolleranza": 0.5,
      "unita_misura": "secondi",
      "spiegazione": "Spiegazione del calcolo",
      "difficolta": 3,
      "tempo_risposta_min": 5,
      "ordine_consigliato": 6
    }
  ]
}</code></pre>
</div>

                    </div>

                    <div id="input_csv" class="import-input" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Carica file CSV</label>
                            <input type="file" name="file_csv" class="form-control" accept=".csv">
                            <div class="form-text">Formato: UTF-8 con intestazioni del template.</div>
                        </div>

                        <div class="format-example">
                            <strong>Esempio CSV</strong><br>
                            argomento,tipo,domanda,risposta_attesa,parole_chiave,difficolta<br>
                            Topic,aperta,"Domanda?","Risposta","key1;key2",3
                        </div>
                    </div>

                    <div id="input_excel" class="import-input" style="display:none;">
                        <div class="mb-3">
                            <label class="form-label">Carica file Excel</label>
                            <input type="file" name="file_excel" class="form-control" accept=".xlsx,.xls">
                            <div class="form-text">Usa lo stesso schema del template CSV e salva in formato .xlsx.</div>
                        </div>

                        <div class="alert alert-info mb-0">
                            <i class="bi bi-info-circle"></i> Le colonne richieste sono: argomento, tipo, domanda, risposta_attesa, parole_chiave, difficolta, tempo_risposta_min, ordine_consigliato, note.
                        </div>
                    </div>
                </div>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-4">
                <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                    <i class="bi bi-x-circle"></i> Annulla
                </a>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-eye"></i> Mostra anteprima
                </button>
            </div>
        </form>
    </div>

    <div class="modal fade" id="importQuestionEditModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-pencil"></i> Modifica domanda importata</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Chiudi"></button>
                </div>
                <div class="modal-body">
                    <?php $questionEditorId = 'import-question-editor'; include __DIR__ . '/partials/question_editor.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="importQuestionEditSave"><i class="bi bi-check-circle"></i> Conferma modifica</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="assets/js/uda-editor-utils.js?v=<?= @filemtime(__DIR__ . '/assets/js/uda-editor-utils.js') ?>"></script>
    <script src="assets/js/question-card.js?v=<?= @filemtime(__DIR__ . '/assets/js/question-card.js') ?>"></script>
    <script src="assets/js/question-editor.js?v=<?= @filemtime(__DIR__ . '/assets/js/question-editor.js') ?>"></script>
    <script src="assets/js/catalog-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/catalog-picker.js') ?>"></script>
    <script src="assets/js/google-forms-catalog.js?v=<?= @filemtime(__DIR__ . '/assets/js/google-forms-catalog.js') ?>"></script>
    <script src="assets/js/import-questions.js?v=<?= @filemtime(__DIR__ . '/assets/js/import-questions.js') ?>"></script>
    <script>
        const templateLinks = {
            json: 'download_template_domande.php?format=json',
            csv: 'download_template_domande.php?format=csv',
            excel: 'download_template_domande.php?format=xlsx'
        };

        function selectModalita(modalita) {
            document.querySelectorAll('.modalita-card[data-modalita]').forEach(card => card.classList.remove('selected'));
            const selected = document.querySelector(`[data-modalita="${modalita}"]`);
            if (selected) selected.classList.add('selected');

            document.getElementById('modalitaInput').value = modalita;

            document.querySelectorAll('.import-input').forEach(input => input.style.display = 'none');
            const target = document.getElementById(`input_${modalita}`);
            if (target) target.style.display = 'block';

            const templateButton = document.getElementById('templateDownload');
            if (templateButton && templateLinks[modalita]) {
                templateButton.href = templateLinks[modalita];
                templateButton.innerHTML = '<i class="bi bi-download"></i> Template ' + modalita.toUpperCase();
            }
        }

        function selectExistingSource(source) {
            document.querySelectorAll('.existing-source-card').forEach(card => card.classList.remove('selected'));
            const selected = document.querySelector(`[data-existing-source="${source}"]`);
            if (selected) selected.classList.add('selected');

            const sourceInput = document.getElementById('existingSourceInput');
            if (sourceInput) {
                sourceInput.value = source;
            }

            document.querySelectorAll('.existing-input').forEach(input => input.style.display = 'none');
            const target = document.getElementById(`existing_input_${source}`);
            if (target) {
                target.style.display = 'block';
            }

            if (source === 'socrative') {
                const selectedFormat = document.querySelector('input[name="socrative_source_format"]:checked');
                toggleSocrativeSourceFormat(selectedFormat ? selectedFormat.value : 'excel');
            }
            if (source === 'google_forms' && typeof window.loadGoogleFormsCatalog === 'function') {
                window.loadGoogleFormsCatalog();
            }
        }

        function toggleSocrativeSourceFormat(format) {
            const excelBox = document.getElementById('socrative_source_excel_input');
            const pdfBox = document.getElementById('socrative_source_pdf_input');
            if (!excelBox || !pdfBox) {
                return;
            }
            if (format === 'pdf') {
                excelBox.style.display = 'none';
                pdfBox.style.display = 'block';
            } else {
                excelBox.style.display = 'block';
                pdfBox.style.display = 'none';
            }
        }

        selectModalita('<?= htmlspecialchars($modalita) ?>');
        selectExistingSource('<?= htmlspecialchars($existingSource) ?>');
    </script>
</body>
</html>
