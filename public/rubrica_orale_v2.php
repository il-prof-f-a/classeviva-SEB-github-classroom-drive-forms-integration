<?php

/**
 * Rubrica Valutazione Orale - Versione 2 (DATABASE-FIRST, NO SESSIONS)
 *
 * Workflow:
 * 0. Import o carica rubrica (associata a UDA)
 * 1. Seleziona Classe (GET params: id_uda, id_classe)
 * 2. Valuta studenti uno per uno (dropdown)
 * 3. Salva nel DATABASE ogni valutazione
 * 4. Carica valutazioni salvate automaticamente al reload
 * 5. Salva i voti nel sistema (pubblicazione da Gestione Voti)
 */

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\GroupStudentRepository;
use App\Core\StudentIdentityRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\UDAManager;
use App\Core\UdaGroupRepository;
use App\Core\RubricManager;
use App\Core\RuntimeStudentNameResolver;
use App\Core\RuntimeStudentNameService;
use App\Core\Security\PublicError;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleDriveAPI;

$config = require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);

$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classeVivaState['ready'];
$cvNotice = $classeVivaState['notice'] ?? 'Token ClasseViva non disponibile.';
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

$action = $_POST['action'] ?? 'step0';
$message = null;
$error = null;
$rubrica = null;
$udas = [];
$classi = [];
$studenti = [];
$valutazioniSalvate = [];
$valutazioniPerStudente = [];
$statisticheClassi = [];
$materieDisponibili = [];
$domandeUDA = [];
$subjectNameById = [];
$classiUda = [];
$studentiProvider = 'classeviva';
$runtimeNames = [];
$idUdaDaGet = $_GET['id_uda'] ?? null;
$idClasseDaGet = $_GET['id_classe'] ?? null;
$idGruppoDaGet = trim((string)($_GET['id_gruppo'] ?? ''));
$templateDownloadUrl = app_url('Materiale/' . rawurlencode('Rubrica valutazione orale VUOTA.xlsx'));
$hasVotoNumerico = false;
$hasVotoFinale = false;

function decodeValutazioneExtra(array $val): array {
    $extra = [];
    if (empty($val['dati_json']) && !empty($val['note'])) {
        $decoded = json_decode($val['note'], true);
        if (is_array($decoded)) {
            $extra = $decoded;
        }
    }
    $datiJsonRaw = $val['dati_json'] ?? ($extra['dati_json'] ?? '{}');
    $datiJson = json_decode($datiJsonRaw, true);
    if (!is_array($datiJson)) {
        $datiJson = [];
    }
    return [$extra, $datiJson];
}

function resolveVotoOriginale(array $val, array $datiJson): ?string {
    global $hasVotoNumerico, $hasVotoFinale, $rubrica;
    $candidates = [];

    if (!empty($hasVotoNumerico) && isset($val['voto_numerico'])) {
        $candidates[] = $val['voto_numerico'];
    }
    if (isset($datiJson['voto_originale'])) {
        $candidates[] = $datiJson['voto_originale'];
    }
    if (isset($val['voto'])) {
        $candidates[] = $val['voto'];
    }
    // fallback per schema legacy senza voto_numerico
    if (empty($hasVotoNumerico) && !empty($hasVotoFinale) && isset($val['voto_finale'])) {
        $candidates[] = $val['voto_finale'];
    }

    foreach ($candidates as $candidate) {
        if ($candidate !== null && $candidate !== '') {
            return $candidate;
        }
    }
    // Fallback legacy: se non esistono altri valori, usa voto_finale per non mostrare "-"
    if (isset($val['voto_finale']) && $val['voto_finale'] !== null && $val['voto_finale'] !== '') {
        return $val['voto_finale'];
    }
    if (isset($datiJson['voto_finale']) && $datiJson['voto_finale'] !== null && $datiJson['voto_finale'] !== '') {
        return $datiJson['voto_finale'];
    }
    $computed = computeVotoOriginaleFromLivelli($rubrica ?? null, $datiJson);
    if ($computed !== null) {
        return number_format($computed, 2, '.', '');
    }
    return null;
}

function computeVotoOriginaleFromLivelli($rubrica, array $datiJson): ?float {
    if (!$rubrica || empty($rubrica->indicatori) || !is_array($rubrica->indicatori)) {
        return null;
    }

    $somma = 0.0;
    $sommaPesi = 0.0;

    // Indicatori fissi: ind_0..2 -> indicatori[0..2]
    for ($i = 0; $i < 3; $i++) {
        $livelloKey = 'livello_ind_' . $i;
        $livello = isset($datiJson[$livelloKey]) ? intval($datiJson[$livelloKey]) : null;
        if ($livello === null || $livello <= 0) {
            continue;
        }
        $indicatore = $rubrica->indicatori[$i] ?? null;
        if (!$indicatore || empty($indicatore['livelli'][$livello])) {
            continue;
        }
        $punteggio = floatval($indicatore['livelli'][$livello]['punteggio'] ?? 0);
        $peso = floatval($indicatore['peso'] ?? 1);
        $somma += $punteggio * $peso;
        $sommaPesi += $peso;
    }

    // Indicatori contenuto: dom_1..3 -> indicatori[3..5]
    for ($j = 1; $j <= 3; $j++) {
        $livelloKey = 'livello_dom_' . $j;
        $livello = isset($datiJson[$livelloKey]) ? intval($datiJson[$livelloKey]) : null;
        if ($livello === null || $livello <= 0) {
            continue;
        }
        $idx = $j + 2;
        $indicatore = $rubrica->indicatori[$idx] ?? null;
        if (!$indicatore || empty($indicatore['livelli'][$livello])) {
            continue;
        }
        $punteggio = floatval($indicatore['livelli'][$livello]['punteggio'] ?? 0);
        $peso = floatval($indicatore['peso'] ?? 1);
        $somma += $punteggio * $peso;
        $sommaPesi += $peso;
    }

    if ($sommaPesi <= 0) {
        return null;
    }

    $voto = ($somma / $sommaPesi) * 10;
    return round($voto, 2);
}

function resolveVotoFinale(array $val, array $datiJson, ?string $votoOriginale): ?string {
    $manualFinale = !empty($datiJson['voto_finale_manual']);
    $votoFinale = $datiJson['voto_finale'] ?? null;

    if ($manualFinale && $votoFinale !== null && $votoFinale !== '') {
        return $votoFinale;
    }

    return normalizeVotoFinaleDefault($votoOriginale);
}

function normalizeVotoFinaleDefault($votoOriginale): ?string {
    if ($votoOriginale === null || $votoOriginale === '') {
        return null;
    }
    if (is_string($votoOriginale)) {
        $votoOriginale = str_replace(',', '.', $votoOriginale);
    }
    if (!is_numeric($votoOriginale)) {
        return (string)$votoOriginale;
    }
    $v = floatval($votoOriginale);
    $rounded = round($v * 2) / 2; // step 0.5
    if ($rounded < 1) {
        $rounded = 1;
    } elseif ($rounded > 10) {
        $rounded = 10;
    }
    return number_format($rounded, 1, '.', '');
}

function isValidVotoFinale($voto): bool {
    if ($voto === null || $voto === '') {
        return false;
    }
    if ($voto === 'a' || $voto === 'i') {
        return true;
    }
    if (!is_numeric($voto)) {
        return false;
    }
    $v = floatval($voto);
    if ($v < 1 || $v > 10) {
        return false;
    }
    return abs($v * 2 - round($v * 2)) < 0.001;
}

function formatVotoLabel($voto, int $decimals = 2): string {
    if ($voto === null || $voto === '') {
        return '-';
    }
    if ($voto === 'a') {
        return 'a (Assente)';
    }
    if ($voto === 'i') {
        return 'i (Impreparato)';
    }
    if (is_numeric($voto)) {
        return number_format((float)$voto, $decimals, '.', '');
    }
    return (string)$voto;
}

try {
    // La generazione del voto non dipende da ClasseViva: la pagina funziona anche
    // con una classe mappata su un altro provider. Solo la pubblicazione richiede CV.
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $udaGroupRepository = new UdaGroupRepository($dbAdapter, $userId);
    $teachingGroupRepository = new TeachingGroupRepository($dbAdapter, $userId);
    $teachingGroupIntegrationRepository = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
    $groupStudentRepository = new GroupStudentRepository($dbAdapter, $userId);
    $studentIdentityRepository = new StudentIdentityRepository($dbAdapter, $userId);
    $udaManager = new UDAManager($config);
    $rubricManager = new RubricManager($dbAdapter, $config);
    $colsValRubrica = [];
    try {
        $colsValRubrica = getTableColumns($dbAdapter, 'VALUTAZIONI_RUBRICA');
    } catch (\Throwable $e) {
        $colsValRubrica = [];
    }
    $hasVotoNumerico = in_array('voto_numerico', $colsValRubrica, true);
    $hasVotoFinale = in_array('voto_finale', $colsValRubrica, true);

// Utility: leggi colonne tabella e mappa dati compatibili con schema legacy
function getTableColumns($dbAdapter, string $table): array {
    try {
        $all = $dbAdapter->findAll($table);
        if (!empty($all[0])) {
            return array_keys($all[0]);
        }
    } catch (\Throwable $e) {
        // ignore
    }
    return [];
}

function packValutazioneData($dbAdapter, array $data): array {
    $cols = getTableColumns($dbAdapter, 'VALUTAZIONI_RUBRICA');
    // Un foglio/tavola vuota non restituisce colonne da findAll: mantieni il
    // contratto provider-neutral e lascia al gateway il filtro sullo schema
    // reale, altrimenti il primo voto perderebbe id_gruppo/id_studente.
    if (empty($cols)) {
        $cols = [
            'id_valutazione', 'id_uda', 'id_gruppo', 'id_studente',
            'id_classe_cv', 'id_materia_cv', 'id_studente_cv', 'id_studente_gc',
            'nome_studente', 'id_rubrica', 'voto_numerico', 'voto_finale',
            'giudizio', 'valutazione_testuale', 'data_valutazione',
            'pubblicato_cv', 'pubblicato', 'dati_json', 'note'
        ];
    }
    $isLegacy = empty($cols) || !in_array('voto_numerico', $cols, true);

    $payload = [];
    // Se colonne esistono, usa quelle
    foreach ($data as $k => $v) {
        if (in_array($k, $cols, true)) {
            $payload[$k] = $v;
        }
    }
    // Mappature per schema legacy
    if ($isLegacy) {
        // voto
        if (isset($data['voto_numerico'])) {
            $payload['voto_finale'] = $data['voto_numerico'];
        }
        // giudizio
        if (isset($data['valutazione_testuale'])) {
            $payload['giudizio'] = $data['valutazione_testuale'];
        }
        // pubblicato
        if (isset($data['pubblicato_cv'])) {
            $payload['pubblicato'] = $data['pubblicato_cv'];
        }
        // note: serializza dati extra (nome studente, id_rubrica, dati_json)
        $extra = [
            'nome_studente' => $data['nome_studente'] ?? null,
            'id_rubrica' => $data['id_rubrica'] ?? null,
            'dati_json' => $data['dati_json'] ?? null
        ];
        $payload['note'] = json_encode($extra);
    } else {
        // Se colonne moderne mancano in payload, aggiungile
        foreach (['voto_numerico','valutazione_testuale','dati_json','pubblicato_cv','nome_studente','id_rubrica'] as $k) {
            if (isset($data[$k]) && !isset($payload[$k])) {
                $payload[$k] = $data[$k];
            }
        }
    }

    // Campi obbligatori fallback
    if (!isset($payload['data_valutazione'])) {
        $payload['data_valutazione'] = $data['data_valutazione'] ?? date('Y-m-d H:i:s');
    }
    if (!isset($payload['id_valutazione']) && isset($data['id_valutazione'])) {
        $payload['id_valutazione'] = $data['id_valutazione'];
    }
    if (!isset($payload['id_uda']) && isset($data['id_uda'])) {
        $payload['id_uda'] = $data['id_uda'];
    }
    if (!isset($payload['id_studente_cv']) && isset($data['id_studente_cv'])) {
        $payload['id_studente_cv'] = $data['id_studente_cv'];
    }
    if (!isset($payload['id_classe_cv']) && isset($data['id_classe_cv'])) {
        $payload['id_classe_cv'] = $data['id_classe_cv'];
    }
    if (!isset($payload['id_materia_cv']) && isset($data['id_materia_cv'])) {
        $payload['id_materia_cv'] = $data['id_materia_cv'];
    }

    return $payload;
}

function extractDriveFileIdFromUrl(string $url): ?string {
    $url = trim($url);
    if ($url === '') {
        return null;
    }
    if (preg_match('~/d/([a-zA-Z0-9_-]+)~', $url, $match)) {
        return $match[1];
    }
    $parts = parse_url($url);
    if ($parts && !empty($parts['query'])) {
        parse_str($parts['query'], $query);
        if (!empty($query['id'])) {
            return $query['id'];
        }
    }
    if (preg_match('/^[a-zA-Z0-9_-]{10,}$/', $url)) {
        return $url;
    }
    return null;
}


// Inizializza ClasseViva API
$cvAPI = new ClasseVivaAPI($config);

// ============================================
// GESTIONE AZIONI
// ============================================

// ==== ACTION: Import rubrica da template ====
// Importa rubrica e la associa all'UDA nel database (SENZA SESSIONE)
if ($action === 'import_template') {
    $udaId = $_POST['uda_id'] ?? $idUdaDaGet;
    $arg1 = trim((string)($_POST['argomento_1'] ?? ''));
    $arg2 = trim((string)($_POST['argomento_2'] ?? ''));
    $arg3 = trim((string)($_POST['argomento_3'] ?? ''));
    $argomenti = [
        $arg1 !== '' ? $arg1 : 'Argomento 1',
        $arg2 !== '' ? $arg2 : 'Argomento 2',
        $arg3 !== '' ? $arg3 : 'Argomento 3'
    ];

    $templatePath = ROOT_PATH . '/Materiale/Rubrica valutazione orale VUOTA.xlsx';
    if (file_exists($templatePath) && $udaId) {
        // Importa rubrica nel database - viene automaticamente associata all'UDA
        $rubrica = $rubricManager->importRubricaDaTemplate($templatePath, $udaId, $argomenti);

        $message = "Rubrica importata e associata all'UDA! (ID: {$rubrica->id_rubrica})";
        $idUdaDaGet = $udaId;
        // Redirect per ricaricare con id_uda nell'URL
        header("Location: rubrica_orale_v2.php?id_uda=$udaId");
        exit;
    } else {
        $error = "Template non trovato o UDA non specificata";
    }
}

// ==== ACTION: Import rubrica da file personalizzato ====
if ($action === 'import_file') {
    $udaId = $_POST['uda_id'] ?? $idUdaDaGet;
    $sheetUrl = trim((string)($_POST['rubrica_sheet_url'] ?? ''));

    if ($sheetUrl !== '') {
        $driveFileId = extractDriveFileIdFromUrl($sheetUrl);
        if (!$driveFileId) {
            $error = "Link Google Sheet non valido.";
        } else {
            try {
                $uploadDir = ROOT_PATH . '/storage/uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $newFileName = 'rubrica_drive_' . uniqid() . '.xlsx';
                $newFilePath = $uploadDir . '/' . $newFileName;

                $driveApi = new GoogleDriveAPI($config);
                $meta = $driveApi->downloadFileAsXlsx($driveFileId, $newFilePath);
                $mimeType = $meta['mimeType'] ?? '';
                $allowedMime = [
                    'application/vnd.google-apps.spreadsheet',
                    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                    'application/vnd.ms-excel'
                ];

                if (!in_array($mimeType, $allowedMime, true)) {
                    $error = "Il file su Drive non e' un Google Sheet o un file Excel.";
                    if (file_exists($newFilePath)) {
                        @unlink($newFilePath);
                    }
                } else {
                    $rubrica = $rubricManager->importRubricaDaTemplate($newFilePath, $udaId, []);

                    $message = "Rubrica importata da Google Sheet e associata all'UDA! (ID: {$rubrica->id_rubrica})";
                    $idUdaDaGet = $udaId;

                    @unlink($newFilePath);

                    header("Location: rubrica_orale_v2.php?id_uda=$udaId");
                    exit;
                }
            } catch (Exception $e) {
                $error = "Errore durante l'importazione: " . $e->getMessage();
                if (isset($newFilePath) && file_exists($newFilePath)) {
                    @unlink($newFilePath);
                }
            }
        }
    }
    // Verifica upload file
    elseif (isset($_FILES['rubrica_file']) && $_FILES['rubrica_file']['error'] === UPLOAD_ERR_OK) {
        $uploadedFile = $_FILES['rubrica_file'];
        $tmpPath = $uploadedFile['tmp_name'];
        $originalName = $uploadedFile['name'];

        // Verifica estensione
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            $error = "Formato file non valido. Accettati solo file .xlsx";
        } else {
            try {
                \App\Core\Security\UploadPolicy::assertValid((string)$originalName, (string)$tmpPath, 'rubric');
                \App\Core\Security\SpreadsheetPolicy::assertWithinLimits((string)$tmpPath);
                // Salva il file temporaneamente in storage/uploads
                $uploadDir = ROOT_PATH . '/storage/uploads';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }

                $newFileName = 'rubrica_' . uniqid() . '.xlsx';
                $newFilePath = $uploadDir . '/' . $newFileName;

                if (move_uploaded_file($tmpPath, $newFilePath)) {
                    $rubrica = $rubricManager->importRubricaDaTemplate($newFilePath, $udaId, []);

                    $message = "Rubrica personalizzata caricata e associata all'UDA! (ID: {$rubrica->id_rubrica})";
                    $idUdaDaGet = $udaId;

                    @unlink($newFilePath);

                    header("Location: rubrica_orale_v2.php?id_uda=$udaId");
                    exit;
                } else {
                    $error = "Errore durante il salvataggio del file";
                }
            } catch (Exception $e) {
                $error = "Errore durante l'importazione: " . $e->getMessage();
                if (isset($newFilePath) && file_exists($newFilePath)) {
                    @unlink($newFilePath);
                }
            }
        }
    } else {
        $error = "Nessun file caricato o link Google Sheet fornito.";
    }
}
// ==== AUTO-CARICA RUBRICA dall'UDA (senza sessioni) ====
if ($idUdaDaGet && !$rubrica) {
    $rubricheEsistenti = $dbAdapter->findWhere('RUBRICA', ['id_uda' => $idUdaDaGet]);

    if (!empty($rubricheEsistenti)) {
        $rubricaId = $rubricheEsistenti[0]['id_rubrica'];
        $rubrica = $rubricManager->getRubrica($rubricaId);
    }
}

// ==== ACTION: Reset rubrica (elimina tutte le valutazioni) ====
	if ($action === 'reset_rubrica') {
	    $idUda = $_POST['id_uda'] ?? $idUdaDaGet;

	    if ($idUda) {
	        // Elimina la rubrica (tutte le righe indicatori legate all'UDA)
	        $rubriche = $dbAdapter->findWhere('RUBRICA', ['id_uda' => $idUda]);
	        $idRubriche = array_values(array_unique(array_filter(array_map(function($rub) {
	            return $rub['id_rubrica'] ?? null;
	        }, $rubriche))));

	        // Elimina valutazioni SOLO per le rubriche dell'UDA (evita di toccare eventuali rubriche di altri contesti)
	        foreach ($idRubriche as $idRubrica) {
	            $valutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', [
	                'id_uda' => $idUda,
	                'id_rubrica' => $idRubrica
	            ]);
	            foreach ($valutazioni as $val) {
	                $idValutazione = $val['id_valutazione'] ?? null;
	                if ($idValutazione !== null && $idValutazione !== '') {
	                    $dbAdapter->deleteRow('VALUTAZIONI_RUBRICA', $idValutazione, 'id_valutazione');
	                }
	            }
	        }

	        foreach ($idRubriche as $idRubrica) {
	            $dbAdapter->deleteRow('RUBRICA', $idRubrica, 'id_rubrica');
	        }

        $message = "Rubrica e valutazioni eliminate. Puoi importarne una nuova.";
        header("Location: rubrica_orale_v2.php?id_uda=$idUda");
        exit;
    }
}

// ==== ACTION: Salva valutazione studente DIRETTAMENTE NEL DATABASE ====
if ($action === 'salva_valutazione_studente') {
    $idStudente = $_POST['id_studente'];
    $nomeStudente = $_POST['nome_studente'] ?? '';
    $voto = $_POST['voto'] ?? null;
    $valutazioneTestuale = $_POST['valutazione_testuale'] ?? '';
    $idUda = $_POST['id_uda'] ?? $idUdaDaGet;
    $idRubrica = $_POST['id_rubrica'] ?? null;
    $idClasseCV = $_POST['id_classe_cv'] ?? $idClasseDaGet;
    $idStudenteProvider = $_POST['id_studente_provider'] ?? 'classeviva';
    $idStudenteField = ($idStudenteProvider === 'google_classroom') ? 'id_studente_gc' : 'id_studente_cv';
    $idGruppo = trim((string)($_POST['id_gruppo'] ?? ''));
    $idStudenteInterno = trim((string)($_POST['id_studente_internal'] ?? ''));
    $idMateriaCvPost = trim((string)($_POST['id_materia_cv'] ?? ''));

    // Il gruppo interno è la chiave primaria per il salvataggio locale. Se il
    // vecchio link contiene solo id_classe_cv, ricaviamo il gruppo dalla
    // mappatura UDA (o dall'unica assegnazione disponibile).
    if ($idGruppo === '' && $idUda !== '') {
        foreach ($udaGroupRepository->listForUda((string)$idUda) as $assignment) {
            $candidateGroup = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($candidateGroup === '') {
                continue;
            }
            $cvIntegration = $teachingGroupIntegrationRepository->findForGroupProvider($candidateGroup, 'classeviva');
            if ($cvIntegration !== null && (string)($cvIntegration['external_context_id'] ?? '') === (string)$idClasseCV) {
                $idGruppo = $candidateGroup;
                break;
            }
            if ($idGruppo === '' && count($udaGroupRepository->listForUda((string)$idUda)) === 1) {
                $idGruppo = $candidateGroup;
            }
        }
    }

    if ($idGruppo !== '' && $idStudenteProvider !== 'classeviva' && $idStudenteInterno === '') {
        $identity = $studentIdentityRepository->findByExternal($idStudenteProvider, (string)$idStudente);
        $idStudenteInterno = trim((string)($identity['id_studente'] ?? ''));
    }
    if ($idGruppo !== '' && $idStudenteInterno === '') {
        $idStudenteInterno = (string)$idStudente;
    }

    // Prepara i dati JSON con tutti i livelli selezionati
    $datiJsonArray = [
        'domanda_1' => $_POST['domanda_1'] ?? '',
        'domanda_2' => $_POST['domanda_2'] ?? '',
        'domanda_3' => $_POST['domanda_3'] ?? '',
        'livello_ind_0' => $_POST['livello_ind_0'] ?? null,
        'livello_ind_1' => $_POST['livello_ind_1'] ?? null,
        'livello_ind_2' => $_POST['livello_ind_2'] ?? null,
        'livello_dom_1' => $_POST['livello_dom_1'] ?? null,
        'livello_dom_2' => $_POST['livello_dom_2'] ?? null,
        'livello_dom_3' => $_POST['livello_dom_3'] ?? null,
    ];

    // Controlla se esiste già una valutazione per questo studente/UDA/classe
    $lookup = [
        'id_uda' => $idUda,
        'id_rubrica' => $idRubrica
    ];
    if ($idGruppo !== '' && $idStudenteInterno !== '') {
        $lookup['id_gruppo'] = $idGruppo;
        $lookup['id_studente'] = $idStudenteInterno;
    } else {
        $lookup['id_classe_cv'] = $idClasseCV;
        $lookup[$idStudenteField] = $idStudente;
    }
	    $esistente = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $lookup);

    $votoOriginale = $voto;
    $existingFinale = null;
    $existingOriginale = null;
    $existingManual = false;
    if (!empty($esistente)) {
        [$extraExisting, $datiJsonExisting] = decodeValutazioneExtra($esistente[0]);
        $existingFinale = $datiJsonExisting['voto_finale'] ?? null;
        $existingOriginale = $datiJsonExisting['voto_originale'] ?? null;
        $existingManual = !empty($datiJsonExisting['voto_finale_manual']);
    }
    if (($votoOriginale === null || $votoOriginale === '') && $existingOriginale !== null) {
        $votoOriginale = $existingOriginale;
    }
    $votoFinale = ($existingManual && $existingFinale !== null && $existingFinale !== '')
        ? $existingFinale
        : normalizeVotoFinaleDefault($votoOriginale);
    $datiJsonArray['voto_originale'] = $votoOriginale;
    $datiJsonArray['voto_finale'] = $votoFinale;
    $datiJsonArray['voto_finale_manual'] = ($existingManual && $existingFinale !== null && $existingFinale !== '');
    $datiJson = json_encode($datiJsonArray);
    $storeFinaleColumn = $hasVotoNumerico && $hasVotoFinale;

    if (!empty($esistente)) {
        // Aggiorna la valutazione esistente usando updateRow
        $idValutazione = $esistente[0]['id_valutazione'];
        $payload = [
            'id_valutazione' => $idValutazione,
            'voto_numerico' => $votoOriginale,
            'giudizio' => $valutazioneTestuale,
            'data_valutazione' => date('Y-m-d H:i:s'),
            'dati_json' => $datiJson,
            'pubblicato_cv' => 0,
            'nome_studente' => $nomeStudente,
            'id_rubrica' => $idRubrica,
            'id_uda' => $idUda,
            'id_gruppo' => $idGruppo !== '' ? $idGruppo : ($esistente[0]['id_gruppo'] ?? null),
            'id_studente' => $idStudenteInterno !== '' ? $idStudenteInterno : ($esistente[0]['id_studente'] ?? null),
            'id_classe_cv' => $idClasseCV,
            $idStudenteField => $idStudente,
            'id_materia_cv' => $idMateriaCvPost !== '' ? $idMateriaCvPost : ($esistente[0]['id_materia_cv'] ?? ($materiaSelezionataId ?? null))
        ];
        if ($storeFinaleColumn && $votoFinale !== null && $votoFinale !== '') {
            $payload['voto_finale'] = $votoFinale;
        }
        $updateData = packValutazioneData($dbAdapter, $payload);
        $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', 'id_valutazione', $idValutazione, $updateData);
        $message = "Valutazione aggiornata per $nomeStudente (Voto: $voto)";
    } else {
        // Inserisci nuova valutazione
        $toInsert = [
            'id_valutazione' => 'VAL_RUB_' . uniqid(),
            'id_uda' => $idUda,
            'id_gruppo' => $idGruppo !== '' ? $idGruppo : null,
            'id_studente' => $idStudenteInterno !== '' ? $idStudenteInterno : null,
            'id_classe_cv' => $idClasseCV,
            $idStudenteField => $idStudente,
            'nome_studente' => $nomeStudente,
            'id_rubrica' => $idRubrica,
            'voto_numerico' => $votoOriginale,
            'giudizio' => $valutazioneTestuale,
            'data_valutazione' => date('Y-m-d H:i:s'),
            'pubblicato_cv' => 0,
            'dati_json' => $datiJson,
            'id_materia_cv' => $idMateriaCvPost !== '' ? $idMateriaCvPost : ($materiaSelezionataId ?? null)
        ];
        if ($storeFinaleColumn && $votoFinale !== null && $votoFinale !== '') {
            $toInsert['voto_finale'] = $votoFinale;
        }
        $dbAdapter->insertRow('VALUTAZIONI_RUBRICA', packValutazioneData($dbAdapter, $toInsert));
        $message = "Valutazione salvata per $nomeStudente (Voto: $voto)";
    }

    // Auto-salva nuove domande nel foglio DOMANDE_INTERROGAZIONE
    try {
        $allQuestions = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');

        foreach (['domanda_1', 'domanda_2', 'domanda_3'] as $domandaField) {
            $domandaText = $_POST[$domandaField] ?? '';

            // Salta se la domanda è vuota o è una delle domande di default
            if (empty($domandaText) ||
                $domandaText === 'Domanda 1' ||
                $domandaText === 'Domanda 2' ||
                $domandaText === 'Domanda 3') {
                continue;
            }

            // Controlla se la domanda esiste già per questa UDA
            $exists = false;
            foreach ($allQuestions as $q) {
                if (($q['domanda'] ?? '') === $domandaText && ($q['id_uda'] ?? '') === $idUda) {
                    $exists = true;
                    break;
                }
            }

            // Se non esiste, salvala
            if (!$exists) {
                $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', [
                    'id_domanda' => 'DOM_' . uniqid(),
                    'id_uda' => $idUda,
                    'domanda' => $domandaText,
                    'argomento' => 'Orale',
                    'difficolta' => 3,
                    'tempo_risposta_min' => 5
                ]);
            }
        }
    } catch (Exception $e) {
        // Se il foglio non esiste o c'è un errore, ignoriamo l'auto-save
        // La valutazione è comunque salvata
    }

    // Redirect per ricaricare la pagina con i dati aggiornati
    $redirectGroup = $idGruppo !== '' ? '&id_gruppo=' . rawurlencode($idGruppo) : '';
    header("Location: rubrica_orale_v2.php?id_uda=$idUda&id_classe=$idClasseCV{$redirectGroup}");
    exit;
}

// ==== ACTION: Elimina valutazione studente ====
	if ($action === 'elimina_valutazione') {
	    $idStudente = $_POST['id_studente'] ?? null;
	    $idUda = $_POST['id_uda'] ?? $idUdaDaGet;
	    $idClasse = $_POST['id_classe'] ?? $idClasseDaGet;
	    $idRubrica = $_POST['id_rubrica'] ?? null;

	    if ($idStudente && $idUda && $idClasse) {
	        // Trova la valutazione nel database
	        $where = [
	            'id_uda' => $idUda,
	            'id_classe_cv' => $idClasse,
	            'id_studente_cv' => $idStudente
	        ];
    if (!empty($idRubrica)) {
        $where['id_rubrica'] = $idRubrica;
	    }
	    $valutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);

        if (!empty($valutazioni)) {
            $idValutazione = $valutazioni[0]['id_valutazione'];
            $dbAdapter->deleteRow('VALUTAZIONI_RUBRICA', $idValutazione, 'id_valutazione');
            $message = "Valutazione eliminata con successo";
        } else {
            $error = "Valutazione non trovata";
        }
    } else {
        $error = "Dati insufficienti per eliminare la valutazione";
    }

    // Redirect per ricaricare la pagina con i dati aggiornati
    header("Location: rubrica_orale_v2.php?id_uda=$idUda&id_classe=$idClasse");
    exit;
}

// ==== ACTION: Aggiorna voto finale (solo DB) ====
if ($action === 'aggiorna_voto_finale') {
    header('Content-Type: application/json');

    try {
        $idStudente = $_POST['id_studente'] ?? null;
    $idUda = $_POST['id_uda'] ?? null;
    $idClasse = $_POST['id_classe'] ?? null;
    $votiPayload = $_POST['voti'] ?? null;
    $idRubrica = $_POST['id_rubrica'] ?? null;
    $votiPayload = $_POST['voti'] ?? null;
        $votoFinale = $_POST['voto_finale'] ?? null;

        if (!$idStudente || !$idUda || !$idClasse) {
            echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
            exit;
        }

        if (!isValidVotoFinale($votoFinale)) {
            echo json_encode(['success' => false, 'message' => 'Voto finale non valido']);
            exit;
        }

        $where = [
            'id_uda' => $idUda,
            'id_classe_cv' => $idClasse,
            'id_studente_cv' => $idStudente
        ];
        if (!empty($idRubrica)) {
            $where['id_rubrica'] = $idRubrica;
        }

        $valutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
        if (empty($valutazioni)) {
            echo json_encode(['success' => false, 'message' => 'Valutazione non trovata']);
            exit;
        }

        $val = $valutazioni[0];
        [$extraExisting, $datiJsonExisting] = decodeValutazioneExtra($val);
        if (!isset($datiJsonExisting['voto_originale'])) {
            $datiJsonExisting['voto_originale'] = resolveVotoOriginale($val, $datiJsonExisting);
        }
        $votoOriginale = $datiJsonExisting['voto_originale'] ?? null;
    $datiJsonExisting['voto_finale'] = $votoFinale;
    $datiJsonExisting['voto_finale_manual'] = true;
    $datiJsonRaw = json_encode($datiJsonExisting);

    $updateData = [];
    if (empty($colsValRubrica) || in_array('dati_json', $colsValRubrica, true)) {
        $updateData['dati_json'] = $datiJsonRaw;
    }
    if (empty($colsValRubrica) || in_array('voto_finale', $colsValRubrica, true)) {
        $updateData['voto_finale'] = $votoFinale;
    }
    if ((!$hasVotoNumerico && in_array('note', $colsValRubrica, true)) || empty($colsValRubrica)) {
        $notePayload = [
            'nome_studente' => $val['nome_studente'] ?? ($extraExisting['nome_studente'] ?? null),
            'id_rubrica' => $val['id_rubrica'] ?? ($extraExisting['id_rubrica'] ?? null),
            'dati_json' => $datiJsonRaw
        ];
        $updateData['note'] = json_encode($notePayload);
    }

        if (empty($updateData)) {
            echo json_encode(['success' => false, 'message' => 'Nessun dato da aggiornare']);
            exit;
        }

        $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', 'id_valutazione', $val['id_valutazione'], $updateData);
        $registratoRubrica = ($val['pubblicato_cv'] ?? $val['pubblicato'] ?? 0) == 1;
        if ($registratoRubrica) {
            try {
                $votiRows = $dbAdapter->findWhere('VOTI', [
                    'id_uda' => $idUda,
                    'id_classe_cv' => $idClasse,
                    'id_studente_cv' => $idStudente
                ]);
                $linkOrigine = app_url(
                    'public/rubrica_orale_v2.php?id_uda=' . urlencode((string)$idUda)
                    . '&id_classe=' . urlencode((string)$idClasse)
                );
                $candidates = array_filter($votiRows, function($row) use ($linkOrigine) {
                    $link = $row['link_origine'] ?? '';
                    if ($link !== '' && $link === $linkOrigine) {
                        return true;
                    }
                    return $link !== '' && strpos($link, 'rubrica_orale_v2.php') !== false;
                });
                if (empty($candidates)) {
                    $candidates = $votiRows;
                }
                if (!empty($candidates)) {
                    usort($candidates, function($a, $b) {
                        $da = $a['data_creazione'] ?? ($a['data_valutazione'] ?? '');
                        $db = $b['data_creazione'] ?? ($b['data_valutazione'] ?? '');
                        return strcmp($db, $da);
                    });
                    $target = $candidates[0] ?? null;
                    if ($target && (($target['pubblicato'] ?? 0) == 0)) {
                        $descrizioneBase = $val['valutazione_testuale'] ?? ($val['giudizio'] ?? '');
                        $noteLines = [];
                        if (trim($descrizioneBase) !== '') {
                            $noteLines[] = $descrizioneBase;
                        }
                        $noteLines[] = 'Voto originale (rubrica): ' . formatVotoLabel($votoOriginale, 2);
                        $noteLines[] = 'Voto finale: ' . formatVotoLabel($votoFinale, 1);
                        $descrizioneAggiornata = trim(implode("\n", $noteLines));

                        $dbAdapter->updateRow('VOTI', 'id_voto', $target['id_voto'], [
                            'voto' => $votoFinale,
                            'giudizio' => $descrizioneAggiornata,
                            'descrizione' => $descrizioneAggiornata
                        ]);
                    }
                }
            } catch (Exception $e) {
                error_log("Aggiornamento VOTI da rubrica fallito: " . $e->getMessage());
            }
        }
        echo json_encode(['success' => true]);
        exit;
    } catch (\Throwable $e) {
        error_log("Errore aggiornamento voto finale: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => 'Errore durante aggiornamento voto finale']);
        exit;
    }
}

// ==== ACTION: Salva singolo voto nel sistema ====
	if ($action === 'carica_registro') {
	    header('Content-Type: application/json');

	    $idStudente = $_POST['id_studente'] ?? null;
	    $idUda = $_POST['id_uda'] ?? null;
	    $idClasse = $_POST['id_classe'] ?? null;
	    $idRubrica = $_POST['id_rubrica'] ?? null;
	    $idGruppo = trim((string)($_POST['id_gruppo'] ?? ''));

	    if (!$idStudente || !$idUda || !$idClasse) {
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    // In modalità provider-neutral l'id ricevuto dalla pagina può essere
    // direttamente quello del gruppo didattico. Risolviamo comunque anche i
    // vecchi link che contengono solo id_classe.
    if ($idGruppo === '') {
        foreach ($udaGroupRepository->listForUda((string)$idUda) as $assignment) {
            $candidateGroup = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($candidateGroup === '') {
                continue;
            }
            if ($candidateGroup === (string)$idClasse) {
                $idGruppo = $candidateGroup;
                break;
            }
            $integration = $teachingGroupIntegrationRepository->findForGroupProvider($candidateGroup, 'classeviva');
            if ($integration !== null && (string)($integration['external_context_id'] ?? '') === (string)$idClasse) {
                $idGruppo = $candidateGroup;
                break;
            }
        }
    }

	    // Trova la valutazione nel database
	    $where = [
	        'id_uda' => $idUda,
	    ];
	    if ($idGruppo !== '') {
	        $where['id_gruppo'] = $idGruppo;
	        $where['id_studente'] = $idStudente;
	    } else {
	        $where['id_classe_cv'] = $idClasse;
	        $where['id_studente_cv'] = $idStudente;
	    }
	    if (!empty($idRubrica)) {
	        $where['id_rubrica'] = $idRubrica;
	    }
	    $valutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
    // Compatibilità con valutazioni legacy non ancora migrate al gruppo.
    if (empty($valutazioni) && $idGruppo !== '') {
        $legacyWhere = [
            'id_uda' => $idUda,
            'id_classe_cv' => $idClasse,
            'id_studente_cv' => $idStudente
        ];
        if (!empty($idRubrica)) {
            $legacyWhere['id_rubrica'] = $idRubrica;
        }
        $valutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $legacyWhere);
    }

    if (empty($valutazioni)) {
        echo json_encode(['success' => false, 'message' => 'Valutazione non trovata']);
        exit;
    }

    $val = $valutazioni[0];
    [$extraVal, $datiJsonVal] = decodeValutazioneExtra($val);
    $votoOriginale = resolveVotoOriginale($val, $datiJsonVal);
    $votoFinale = resolveVotoFinale($val, $datiJsonVal, $votoOriginale);
    if ($votoFinale === null || $votoFinale === '') {
        echo json_encode(['success' => false, 'message' => 'Voto finale mancante (grade_value).']);
        exit;
    }
    $subjectId = $val['id_materia_cv'] ?? $materiaSelezionataId ?? '';

    try {
        // Salva il voto ORALE nel sistema (pubblicazione da Gestione Voti)
        $descrizioneBase = $val['valutazione_testuale'] ?? ($val['giudizio'] ?? '');
        $noteLines = [];
        if (trim($descrizioneBase) !== '') {
            $noteLines[] = $descrizioneBase;
        }
        $noteLines[] = 'Voto originale (rubrica): ' . formatVotoLabel($votoOriginale, 2);
        $noteLines[] = 'Voto finale: ' . formatVotoLabel($votoFinale, 1);
        $descrizione = trim(implode("\n", $noteLines));
        $votoId = 'VOTO_' . uniqid();
        $notes = trim($descrizione . ' <' . $votoId . '>');
        $linkOrigine = app_url(
            'public/rubrica_orale_v2.php?id_uda=' . urlencode((string)$idUda)
            . '&id_classe=' . urlencode((string)$idClasse)
        );
        $dataValutazione = $val['data_valutazione'] ?? date('Y-m-d');

        // Inserisci il voto nel registro locale VOTI con lo stesso id
        $votoPayload = [
            'id_voto' => $votoId,
            'id_uda' => $idUda,
            'id_gruppo' => $idGruppo !== '' ? $idGruppo : null,
            'id_studente' => $idGruppo !== '' ? $idStudente : null,
            'id_studente_cv' => $idGruppo === '' ? $idStudente : null,
            'id_classe_cv' => $idGruppo === '' ? $idClasse : null,
            'id_materia_cv' => ($subjectId !== '' && $subjectId !== null) ? $subjectId : null,
            'tipo_voto' => 'orale',
            'voto' => $votoFinale,
            'giudizio' => $descrizione,
            'descrizione' => $descrizione,
            'data_valutazione' => $dataValutazione,
            'data_creazione' => $dataValutazione,
            'pubblicato' => 0,
            'num_evidenze_positive' => null,
            'num_evidenze_negative' => null,
            'num_evidenze_totali' => null,
            'link_origine' => $linkOrigine
        ];
        $dbAdapter->insertRow('VOTI', $votoPayload);

        // Aggiorna il flag pubblicato_cv nel database dopo il salvataggio
        $idValutazione = $val['id_valutazione'];
        $updateData = ['pubblicato_cv' => 1];
        if (in_array('data_pubblicazione', $colsValRubrica, true)) {
            $updateData['data_pubblicazione'] = date('Y-m-d H:i:s');
        }
        $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', 'id_valutazione', $idValutazione, $updateData);

        echo json_encode(['success' => true, 'message' => 'Voto salvato nel sistema']);
        exit;

    } catch (Exception $e) {
        error_log("Errore salvataggio voto rubrica: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => PublicError::message($e, 'oral rubric grade save')]);
        exit;
    }
}

// ==== ACTION: Salva tutti i voti nel sistema ====
	if ($action === 'carica_registro_blocco') {
	    header('Content-Type: application/json');

	    $idUda = $_POST['id_uda'] ?? null;
	    $idClasse = $_POST['id_classe'] ?? null;
	    $idRubrica = $_POST['id_rubrica'] ?? null;
	    $idGruppo = trim((string)($_POST['id_gruppo'] ?? ''));
        $votiPayload = $_POST['voti'] ?? null;

    if (!$idUda || !$idClasse) {
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    if ($idGruppo === '') {
        foreach ($udaGroupRepository->listForUda((string)$idUda) as $assignment) {
            $candidateGroup = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($candidateGroup === '') {
                continue;
            }
            if ($candidateGroup === (string)$idClasse) {
                $idGruppo = $candidateGroup;
                break;
            }
            $integration = $teachingGroupIntegrationRepository->findForGroupProvider($candidateGroup, 'classeviva');
            if ($integration !== null && (string)($integration['external_context_id'] ?? '') === (string)$idClasse) {
                $idGruppo = $candidateGroup;
                break;
            }
        }
    }

    // Trova tutte le valutazioni (filtriamo dopo)
	    $where = [
	        'id_uda' => $idUda
	    ];
	    if ($idGruppo !== '') {
	        $where['id_gruppo'] = $idGruppo;
	    } else {
	        $where['id_classe_cv'] = $idClasse;
	    }
	    if (!empty($idRubrica)) {
	        $where['id_rubrica'] = $idRubrica;
	    }
	    $tutteValutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
    // Compatibilità con dati legacy salvati per la coppia classe/studente CV.
    if (empty($tutteValutazioni) && $idGruppo !== '') {
        $legacyWhere = [
            'id_uda' => $idUda,
            'id_classe_cv' => $idClasse
        ];
        if (!empty($idRubrica)) {
            $legacyWhere['id_rubrica'] = $idRubrica;
        }
        $tutteValutazioni = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $legacyWhere);
    }

    $selectedIds = [];
    if (!empty($votiPayload)) {
        $decoded = json_decode($votiPayload, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $isSelected = $item['selected'] ?? true;
                if (!$isSelected) {
                    continue;
                }
                $idStud = $item['id_studente'] ?? null;
                if ($idStud !== null && $idStud !== '') {
                    $selectedIds[] = (string)$idStud;
                }
            }
        }
    }

    if (!empty($selectedIds)) {
        $votiDaCaricare = array_filter($tutteValutazioni, function($val) use ($selectedIds) {
            return in_array((string)($val['id_studente'] ?? $val['id_studente_cv'] ?? ''), $selectedIds, true);
        });
    } else {
        // default: solo non pubblicati
        $votiDaCaricare = array_filter($tutteValutazioni, function($val) {
            return ($val['pubblicato_cv'] ?? 0) == 0;
        });
    }

    if (empty($votiDaCaricare)) {
        echo json_encode(['success' => false, 'message' => 'Nessun voto da salvare']);
        exit;
    }

    $successi = 0;
    $errori = [];
    $linkOrigine = app_url(
        'public/rubrica_orale_v2.php?id_uda=' . urlencode((string)$idUda)
        . '&id_classe=' . urlencode((string)$idClasse)
    );

    foreach ($votiDaCaricare as $val) {
        try {
            [$extraVal, $datiJsonVal] = decodeValutazioneExtra($val);
            $votoOriginale = resolveVotoOriginale($val, $datiJsonVal);
            $votoFinale = resolveVotoFinale($val, $datiJsonVal, $votoOriginale);
            if ($votoFinale === null || $votoFinale === '') {
                $errori[] = "Studente {$val['id_studente_cv']}: voto finale mancante";
                continue;
            }
            $subjectId = $val['id_materia_cv'] ?? $materiaSelezionataId ?? '';
            $studentIdForMessage = $val['id_studente'] ?? $val['id_studente_cv'] ?? '';

            $descrizioneBase = $val['valutazione_testuale'] ?? ($val['giudizio'] ?? '');
            $noteLines = [];
            if (trim($descrizioneBase) !== '') {
                $noteLines[] = $descrizioneBase;
            }
            $noteLines[] = 'Voto originale (rubrica): ' . formatVotoLabel($votoOriginale, 2);
            $noteLines[] = 'Voto finale: ' . formatVotoLabel($votoFinale, 1);
            $descrizione = trim(implode("\n", $noteLines));
            $votoId = 'VOTO_' . uniqid();
            $notes = trim($descrizione . ' <' . $votoId . '>');
            $dataValutazione = $val['data_valutazione'] ?? date('Y-m-d');

            // Inserisci nel registro locale VOTI
            $votoPayload = [
                'id_voto' => $votoId,
                'id_uda' => $idUda,
                'id_gruppo' => $idGruppo !== '' ? $idGruppo : null,
                'id_studente' => $idGruppo !== '' ? ($val['id_studente'] ?? $val['id_studente_cv'] ?? null) : null,
                'id_studente_cv' => $idGruppo === '' ? ($val['id_studente_cv'] ?? null) : null,
                'id_classe_cv' => $idGruppo === '' ? $idClasse : null,
                'id_materia_cv' => ($subjectId !== '' && $subjectId !== null) ? $subjectId : null,
                'tipo_voto' => 'orale',
                'voto' => $votoFinale,
                'giudizio' => $descrizione,
                'descrizione' => $descrizione,
                'data_valutazione' => $dataValutazione,
                'data_creazione' => $dataValutazione,
                'pubblicato' => 0,
                'num_evidenze_positive' => null,
                'num_evidenze_negative' => null,
                'num_evidenze_totali' => null,
                'link_origine' => $linkOrigine
            ];
            $dbAdapter->insertRow('VOTI', $votoPayload);

            $updateData = ['pubblicato_cv' => 1];
            if (in_array('data_pubblicazione', $colsValRubrica, true)) {
                $updateData['data_pubblicazione'] = date('Y-m-d H:i:s');
            }
            $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', 'id_valutazione', $val['id_valutazione'], $updateData);
            $successi++;
        } catch (Exception $e) {
            $errori[] = "Studente {$studentIdForMessage}: " . $e->getMessage();
        }
    }

    if (!empty($errori)) {
        echo json_encode(['success' => false, 'message' => "Salvati {$successi}, errori: " . implode(' | ', $errori)]);
    } else {
        echo json_encode(['success' => true, 'message' => "$successi voti salvati", 'caricati' => $successi]);
    }
    exit;
}

// ==== ACTION: Cancella voti registrati (solo DB) ====
if ($action === 'cancella_registrati_blocco') {
    header('Content-Type: application/json');

    $idUda = $_POST['id_uda'] ?? null;
    $idClasse = $_POST['id_classe'] ?? null;
    $votiPayload = $_POST['voti'] ?? null;

    if (!$idUda || !$idClasse) {
        echo json_encode(['success' => false, 'message' => 'Dati mancanti']);
        exit;
    }

    // Seleziona valutazioni già pubblicate (pubblicato_cv = 1) per questa UDA/classe
	    $where = [
	        'id_uda' => $idUda,
	        'id_classe_cv' => $idClasse
	    ];
	    if (!empty($idRubrica)) {
	        $where['id_rubrica'] = $idRubrica;
	    }
    $daCancellare = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);
    $selectedIds = [];
    if (!empty($votiPayload)) {
        $decoded = json_decode($votiPayload, true);
        if (is_array($decoded)) {
            foreach ($decoded as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $isSelected = $item['selected'] ?? true;
                if (!$isSelected) {
                    continue;
                }
                $idStud = $item['id_studente'] ?? null;
                if ($idStud !== null && $idStud !== '') {
                    $selectedIds[] = (string)$idStud;
                }
            }
        }
    }

    if (!empty($selectedIds)) {
        $daCancellare = array_filter($daCancellare, function($v) use ($selectedIds) {
            return in_array((string)($v['id_studente_cv'] ?? ''), $selectedIds, true);
        });
    } else {
        $daCancellare = array_filter($daCancellare, function($v) {
            return ($v['pubblicato_cv'] ?? 0) == 1;
        });
    }

    $count = 0;
    foreach ($daCancellare as $row) {
        if (!empty($row['id_valutazione'])) {
            $dbAdapter->deleteRow('VALUTAZIONI_RUBRICA', $row['id_valutazione'], 'id_valutazione');
            $count++;
        }
    }

    echo json_encode(['success' => true, 'message' => "Cancellati {$count} voti registrati (solo DB).", 'cancellati' => $count]);
    exit;
}

// ============================================
// FUNZIONI HELPER
// ============================================

function calcolaVoto($valutazione) {
    $pesi = [
        'esposizione' => 4,
        'espressione' => 4,
        'organizzazione' => 4,
        'dom1' => 8,
        'dom2' => 8,
        'dom3' => 8
    ];

    $punteggi = [
        1 => 0.0,
        2 => 0.25,
        3 => 0.5,
        4 => 0.75,
        5 => 1.0
    ];

    $somma = 0;
    $somma_pesi = 0;

    foreach (['esposizione', 'espressione', 'organizzazione', 'dom1', 'dom2', 'dom3'] as $ind) {
        $livello = $valutazione['livello_' . $ind] ?? null;
        if ($livello !== null && isset($punteggi[$livello])) {
            $somma += $punteggi[$livello] * $pesi[$ind];
            $somma_pesi += $pesi[$ind];
        }
    }

    if ($somma_pesi > 0) {
        $voto = ($somma / $somma_pesi) * 10;
        return round($voto * 2) / 2;
    }

    return null;
}

function generaValutazioneTestuale($valutazione) {
    $livelliDescrizioni = [
        'esposizione' => [
            1 => 'Presenta gravi difficoltà nell\'esposizione',
            2 => 'Espone in modo frammentario e poco chiaro',
            3 => 'Espone in modo semplice ma sostanzialmente corretto',
            4 => 'Espone in modo chiaro, corretto e fluido',
            5 => 'Espone in modo chiaro, fluido, ben organizzato e completo'
        ],
        'espressione' => [
            1 => 'Utilizza un linguaggio gravemente inadeguato',
            2 => 'Utilizza un linguaggio impreciso',
            3 => 'Utilizza un linguaggio semplice ma corretto',
            4 => 'Utilizza un linguaggio appropriato e corretto',
            5 => 'Utilizza un linguaggio ricco e tecnicamente corretto'
        ],
        'organizzazione' => [
            1 => 'Non riesce a organizzare il discorso',
            2 => 'Organizza il discorso in modo poco coerente',
            3 => 'Organizza il discorso in modo semplice ma coerente',
            4 => 'Organizza il discorso in modo logico e ben strutturato',
            5 => 'Organizza il discorso in modo eccellente'
        ]
    ];

    $livelliContenutiDescrizioni = [
        1 => 'Non conosce i contenuti richiesti',
        2 => 'Conosce i contenuti in modo frammentario',
        3 => 'Conosce i contenuti essenziali in modo sufficiente',
        4 => 'Conosce i contenuti in modo buono e completo',
        5 => 'Conosce i contenuti in modo approfondito ed eccellente'
    ];

    $testo = "VALUTAZIONE ORALE\n\n";
    $testo .= "COMPETENZE TRASVERSALI:\n\n";

    $livEsp = $valutazione['livello_esposizione'] ?? null;
    if ($livEsp) {
        $testo .= "Esposizione: " . ($livelliDescrizioni['esposizione'][$livEsp] ?? 'N/A') . "\n\n";
    }

    $livExpr = $valutazione['livello_espressione'] ?? null;
    if ($livExpr) {
        $testo .= "Modo di esprimersi: " . ($livelliDescrizioni['espressione'][$livExpr] ?? 'N/A') . "\n\n";
    }

    $livOrg = $valutazione['livello_organizzazione'] ?? null;
    if ($livOrg) {
        $testo .= "Organizzazione: " . ($livelliDescrizioni['organizzazione'][$livOrg] ?? 'N/A') . "\n\n";
    }

    $testo .= "CONTENUTI:\n\n";

    for ($i = 1; $i <= 3; $i++) {
        $domanda = $valutazione["domanda_$i"] ?? "Domanda $i";
        $livello = $valutazione["livello_dom$i"] ?? null;
        if ($livello) {
            $testo .= "$domanda: " . ($livelliContenutiDescrizioni[$livello] ?? 'N/A') . "\n\n";
        }
    }

    return $testo;
}

// ============================================
// CARICA DATI
// ============================================

$udas = $udaManager->getAllUDAs();
$classi = [];
$studenti = [];
$materieDisponibili = [];
$materiaSelezionataId = null;
$materiaSelezionataNome = null;
$classiUda = [];
$subjectNameById = [];
$gruppiUda = [];
$gruppoSelezionatoId = $idGruppoDaGet;

// Determina UDA selezionata
$udaSelezionata = null;
$idUdaSelezionata = $idUdaDaGet;

if ($idUdaSelezionata) {
    foreach ($udas as $u) {
        if ($u->id_uda === $idUdaSelezionata) {
            $udaSelezionata = $u;
            break;
        }
    }
}

// Carica classi CV quando disponibili e, in parallelo, i gruppi assegnati
// all'UDA. Il gruppo interno resta valido anche senza token/mapping CV.
try {
    if ($udaSelezionata) {
        foreach ($udaGroupRepository->listForUda((string)$udaSelezionata->id_uda) as $assignment) {
            $groupId = trim((string)($assignment['id_gruppo'] ?? ''));
            if ($groupId === '') {
                continue;
            }
            $group = $teachingGroupRepository->findById($groupId);
            if ($group === null) {
                continue;
            }
            $gruppiUda[] = ['assignment' => $assignment, 'group' => $group];
        }
    }

    if ($cvReady) {
        $tutteClassi = $cvAPI->getClasses();
        $classiAssegnate = $dbAdapter->findAll('CLASSI_ASSEGNATE');
        $classiUda = array_filter($classiAssegnate, static function (array $ca) use ($udaSelezionata): bool {
            return $udaSelezionata !== null && ($ca['id_uda'] ?? '') === $udaSelezionata->id_uda;
        });
        $idClassiUda = array_column($classiUda, 'id_classe');

        foreach ($tutteClassi as $classe) {
            if (!in_array($classe['classId'] ?? null, $idClassiUda, true)) {
                continue;
            }
            $classi[] = $classe;
            if ($idClasseDaGet && (string)$classe['classId'] === (string)$idClasseDaGet) {
                foreach ($classiUda as $ca) {
                    if ((string)($ca['id_classe'] ?? '') === (string)$idClasseDaGet) {
                        $materiaSelezionataId = $ca['id_materia_cv'] ?? null;
                        $materiaSelezionataNome = $ca['nome_materia'] ?? null;
                        $gruppoSelezionatoId = trim((string)($ca['id_gruppo'] ?? $gruppoSelezionatoId));
                        break;
                    }
                }
            }
        }
    }

    // Risolvi il gruppo dal vecchio id_classe_cv, dall'id_gruppo esplicito o
    // dall'unica assegnazione UDA. In assenza di CV mostriamo un corso sintetico.
    if ($gruppoSelezionatoId === '' && $idClasseDaGet !== null) {
        foreach ($gruppiUda as $entry) {
            $candidate = trim((string)($entry['assignment']['id_gruppo'] ?? ''));
            $integration = $teachingGroupIntegrationRepository->findForGroupProvider($candidate, 'classeviva');
            if ($integration !== null && (string)($integration['external_context_id'] ?? '') === (string)$idClasseDaGet) {
                $gruppoSelezionatoId = $candidate;
                break;
            }
        }
    }
    if ($gruppoSelezionatoId === '' && count($gruppiUda) === 1) {
        $gruppoSelezionatoId = (string)$gruppiUda[0]['assignment']['id_gruppo'];
    }
    if ($gruppoSelezionatoId !== '') {
        foreach ($gruppiUda as $entry) {
            if ((string)($entry['assignment']['id_gruppo'] ?? '') !== $gruppoSelezionatoId) {
                continue;
            }
            $group = $entry['group'];
            $alreadyListed = false;
            foreach ($classi as $classe) {
                if ((string)($classe['classId'] ?? '') === $gruppoSelezionatoId) {
                    $alreadyListed = true;
                    break;
                }
            }
            if (!$alreadyListed) {
                $classi[] = [
                    'classId' => $gruppoSelezionatoId,
                    'className' => (string)($group['nome_gruppo'] ?? $group['nome_classe'] ?? $gruppoSelezionatoId),
                    'students' => [],
                    'id_gruppo' => $gruppoSelezionatoId,
                    'provider' => 'provider-neutral',
                ];
            }
            if ($idClasseDaGet === null || $idClasseDaGet === '') {
                $idClasseDaGet = $gruppoSelezionatoId;
            }
            break;
        }
    }
} catch (Exception $e) {
    error_log("Errore caricamento classi/gruppi: " . $e->getMessage());
    if ($classi === []) {
        $error = "Impossibile caricare i gruppi didattici.";
    }
}

// Preseleziona la prima classe disponibile se non ne e' stata selezionata alcuna.
if (empty($idClasseDaGet) && !empty($classi)) {
    $primaClasse = (string)($classi[0]['classId'] ?? '');
    if ($primaClasse !== '') {
        $idClasseDaGet = $primaClasse;
    }
}
if ($gruppoSelezionatoId === '' && $idClasseDaGet !== null) {
    foreach ($gruppiUda as $entry) {
        if ((string)($entry['assignment']['id_gruppo'] ?? '') === (string)$idClasseDaGet) {
            $gruppoSelezionatoId = (string)$idClasseDaGet;
            break;
        }
    }
}

// Mappa nomi materie da ClasseViva per normalizzare le etichette
try {
    $subjectsCv = $cvReady ? $cvAPI->getSubjects() : [];
    foreach ($subjectsCv as $subject) {
        $subjectId = $subject['id'] ?? ($subject['subjectId'] ?? '');
        if ($subjectId === '') {
            continue;
        }
        $subjectName = $subject['nome']
            ?? ($subject['description'] ?? ($subject['subjectDesc'] ?? ($subject['name'] ?? '')));
        if ($subjectName !== '') {
            $subjectNameById[$subjectId] = $subjectName;
        }
    }
} catch (Exception $e) {
    $subjectNameById = [];
}

// Calcola statistiche voti per ogni classe
$statisticheClassi = [];
if ($idUdaSelezionata) {
    foreach ($classi as $classe) {
        $idClasse = $classe['classId'];
        $numStudenti = count($classe['students'] ?? []);

        // Conta voti per questa classe
	        $where = ['id_uda' => $idUdaSelezionata];
	        if ($gruppoSelezionatoId !== '' && (string)$idClasse === $gruppoSelezionatoId) {
	            $where['id_gruppo'] = $gruppoSelezionatoId;
	        } else {
	            $where['id_classe_cv'] = $idClasse;
	        }
	        if (isset($rubrica) && $rubrica && !empty($rubrica->id_rubrica)) {
	            $where['id_rubrica'] = $rubrica->id_rubrica;
	        }
	        $votiClasse = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);

        $votiTotali = count($votiClasse);
        $votiDaRegistrare = 0;

        foreach ($votiClasse as $voto) {
            if (($voto['pubblicato_cv'] ?? 0) == 0) {
                $votiDaRegistrare++;
            }
        }

        $statisticheClassi[$idClasse] = [
            'totale_studenti' => $numStudenti,
            'voti_totali' => $votiTotali,
            'voti_da_registrare' => $votiDaRegistrare
        ];
    }
}

// Carica domande esistenti per l'UDA selezionata
$domandeUDA = [];
if ($idUdaSelezionata) {
    try {
        $allQuestions = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
        $domandeUDA = array_filter($allQuestions, function($q) use ($idUdaSelezionata) {
            return ($q['id_uda'] ?? '') === $idUdaSelezionata;
        });
    } catch (Exception $e) {
        // Se il foglio non esiste o c'è un errore, continuiamo senza domande
        $domandeUDA = [];
    }
}

// Carica studenti dalla classe selezionata (da GET) - indipendente dalle domande precaricate
if ($idClasseDaGet) {
    // Materie disponibili per questa classe (da CLASSI_ASSEGNATE filtrate prima)
    $materieDisponibiliMap = [];
    foreach ($classiUda as $ca) {
        if (($ca['id_classe'] ?? '') == $idClasseDaGet && !empty($ca['id_materia_cv'])) {
            $materiaId = (string)$ca['id_materia_cv'];
            if (!isset($materieDisponibiliMap[$materiaId])) {
                $materiaNome = $subjectNameById[$materiaId] ?? ($ca['nome_materia'] ?? $materiaId);
                $materieDisponibiliMap[$materiaId] = [
                    'id_materia_cv' => $materiaId,
                    'nome_materia' => $materiaNome
                ];
            }
            if ($materiaSelezionataId === null) {
                $materiaSelezionataId = $materiaId;
                $materiaSelezionataNome = $materieDisponibiliMap[$materiaId]['nome_materia'] ?? $materiaId;
            }
        }
    }
    $materieDisponibili = array_values($materieDisponibiliMap);
    if ($materiaSelezionataId !== null && isset($materieDisponibiliMap[(string)$materiaSelezionataId])) {
        $materiaSelezionataNome = $materieDisponibiliMap[(string)$materiaSelezionataId]['nome_materia'] ?? $materiaSelezionataNome;
    }

    // Unica risoluzione runtime: il servizio legge il gruppo didattico e prova
    // i provider nell'ordine ClasseViva, Google Classroom, GitHub Classroom.
    if ($gruppoSelezionatoId !== '') {
        try {
            $runtimeStudents = (new RuntimeStudentNameService($dbAdapter, $userId, $config))
                ->resolveGroupStudents($gruppoSelezionatoId);
            foreach ($runtimeStudents as $runtimeStudent) {
                $studenti[] = [
                    'id' => $runtimeStudent['id_studente'],
                    'id_studente_internal' => $runtimeStudent['id_studente'],
                    'provider' => $runtimeStudent['provider'],
                    'nome_completo' => $runtimeStudent['nome_completo'],
                    'cognome' => $runtimeStudent['cognome'],
                    'nome' => $runtimeStudent['nome'],
                ];
            }
            $studentiProvider = 'internal';
        } catch (Throwable $exception) {
            error_log('Errore caricamento nomi studenti centralizzato: ' . $exception->getMessage());
            $error = 'Impossibile risolvere i nomi degli studenti del gruppo didattico.';
        }
    }

    // Roster provider-neutral: non richiede alcuna mappatura ClasseViva e usa
    // sempre l'id interno dello studente per le successive scritture.
    if (empty($studenti) && $gruppoSelezionatoId !== '') {
        try {
            $runtimeMemberships = $groupStudentRepository->listForGroup($gruppoSelezionatoId);
            $runtimeIdentitiesByStudent = [];
            foreach ($runtimeMemberships as $membership) {
                $internalId = trim((string)($membership['id_studente'] ?? ''));
                if ($internalId !== '') {
                    $runtimeIdentitiesByStudent[$internalId] = $studentIdentityRepository->listForStudent($internalId);
                }
            }

            // Il nome viene richiesto a runtime dal primo provider collegato
            // al gruppo, senza salvarlo nelle tabelle locali.
            $integrations = array_values(array_filter(
                $teachingGroupIntegrationRepository->listForGroup($gruppoSelezionatoId),
                static fn(array $integration): bool => ($integration['stato'] ?? 'attivo') !== 'disattivo'
            ));
            $providerOrder = array_flip(RuntimeStudentNameResolver::PROVIDER_PRIORITY);
            usort($integrations, static function (array $left, array $right) use ($providerOrder): int {
                $leftOrder = $providerOrder[(string)($left['provider'] ?? '')] ?? PHP_INT_MAX;
                $rightOrder = $providerOrder[(string)($right['provider'] ?? '')] ?? PHP_INT_MAX;
                return $leftOrder <=> $rightOrder;
            });

            $providerRosters = [];
            $runtimeNameService = new RuntimeStudentNameService($dbAdapter, $userId, $config);
            foreach ($integrations as $integration) {
                $provider = (string)($integration['provider'] ?? '');
                $contextId = trim((string)($integration['external_context_id'] ?? ''));
                if ($provider === '' || $contextId === '') {
                    continue;
                }
                try {
                    $providerRosters[$provider] = $runtimeNameService->providerRoster($provider, $contextId);
                } catch (Throwable $providerError) {
                    error_log('Errore roster runtime ' . $provider . ': ' . $providerError->getMessage());
                    continue;
                }
                if (!empty($providerRosters[$provider])) {
                    break;
                }
            }
            $runtimeNames = RuntimeStudentNameResolver::resolveNames(
                $runtimeMemberships,
                $runtimeIdentitiesByStudent,
                $providerRosters
            );

            foreach ($runtimeMemberships as $membership) {
                $internalId = trim((string)($membership['id_studente'] ?? ''));
                if ($internalId === '') {
                    continue;
                }
                $displayName = '';
                $provider = 'internal';
                foreach ($studentIdentityRepository->listForStudent($internalId) as $identity) {
                    $providerCandidate = trim((string)($identity['provider'] ?? ''));
                    $metadata = json_decode((string)($identity['metadata_json'] ?? '{}'), true);
                    if (!is_array($metadata)) {
                        $metadata = [];
                    }
                    $candidateName = trim((string)($metadata['display_name'] ?? ($metadata['name'] ?? '')));
                    if ($candidateName !== '') {
                        $displayName = $candidateName;
                    }
                    if ($providerCandidate !== '') {
                        $provider = $providerCandidate;
                    }
                    if ($displayName !== '') {
                        break;
                    }
                }
                if (isset($runtimeNames[$internalId])) {
                    $displayName = $runtimeNames[$internalId];
                }
                $studenti[] = [
                    'id' => $internalId,
                    'id_studente_internal' => $internalId,
                    'provider' => $provider,
                    'nome_completo' => $displayName !== '' ? $displayName : $internalId,
                    'cognome' => '',
                    'nome' => $displayName !== '' ? $displayName : $internalId,
                ];
            }
            if ($studenti !== []) {
                $studentiProvider = 'internal';
            }
        } catch (Exception $e) {
            error_log('Errore caricamento roster provider-neutral: ' . $e->getMessage());
        }
    }

}

// Carica valutazioni salvate dal DATABASE per questa UDA e classe
$valutazioniSalvate = [];
$valutazioniPerStudente = [];

// I nomi non sono persistiti (PII): ricostruiscili dalla lista studenti caricata.
$nomePerStudente = [];
foreach ($studenti as $st) {
    $sid = (string)($st['id'] ?? '');
    $nomeSt = trim((string)($st['nome_completo'] ?? ''));
    if ($sid !== '' && $nomeSt !== '') {
        $nomePerStudente[$sid] = $nomeSt;

        // Le valutazioni salvate usano l'id interno, mentre il roster puo'
        // essere indicizzato con l'id esterno del provider. Collega entrambi
        // gli alias al nome runtime senza persistere dati personali.
        $internalAlias = trim((string)($st['id_studente_internal'] ?? ''));
        if ($internalAlias === '' && ($st['provider'] ?? '') === 'internal') {
            $internalAlias = $sid;
        }
        if ($internalAlias === '') {
            foreach (RuntimeStudentNameResolver::PROVIDER_PRIORITY as $providerAlias) {
                $identityAlias = $studentIdentityRepository->findByExternal($providerAlias, $sid);
                $candidateInternal = trim((string)($identityAlias['id_studente'] ?? ''));
                if ($candidateInternal !== '') {
                    $internalAlias = $candidateInternal;
                    break;
                }
            }
        }
        if ($internalAlias !== '') {
            $nomePerStudente[$internalAlias] = $nomeSt;
            foreach ($studentIdentityRepository->listForStudent($internalAlias) as $identityAlias) {
                $externalAlias = trim((string)($identityAlias['external_user_id'] ?? ''));
                if ($externalAlias !== '') {
                    $nomePerStudente[$externalAlias] = $nomeSt;
                }
            }
        }
    }
}

if ($idUdaSelezionata && $idClasseDaGet) {
	    $where = ['id_uda' => $idUdaSelezionata];
	    if ($gruppoSelezionatoId !== '') {
	        $where['id_gruppo'] = $gruppoSelezionatoId;
	    } else {
	        $where['id_classe_cv'] = $idClasseDaGet;
	    }
	    if (isset($rubrica) && $rubrica && !empty($rubrica->id_rubrica)) {
	        $where['id_rubrica'] = $rubrica->id_rubrica;
	    }
	    $valutazioniSalvate = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', $where);

    // Converti le valutazioni in un array associativo per ID studente
    foreach ($valutazioniSalvate as $val) {
        $idStud = (string)($val['id_studente'] ?? $val['id_studente_cv'] ?? $val['id_studente_gc'] ?? '');
        if ($idStud === '') {
            continue;
        }

        // ricostruisci dati extra da note se schema legacy
        $extra = [];
        if (empty($val['dati_json']) && !empty($val['note'])) {
            $decoded = json_decode($val['note'], true);
            if (is_array($decoded)) {
                $extra = $decoded;
            }
        }
        $datiJsonRaw = $val['dati_json'] ?? ($extra['dati_json'] ?? '{}');
        $datiJson = json_decode($datiJsonRaw, true);
        if (!is_array($datiJson)) $datiJson = [];

        $votoOriginale = resolveVotoOriginale($val, $datiJson);
        $manualFinale = !empty($datiJson['voto_finale_manual']);
        $votoFinale = resolveVotoFinale($val, $datiJson, $votoOriginale);

        $valutazioniPerStudente[$idStud] = [
            // Il nome visualizzato è sempre quello risolto a runtime dal
            // servizio centralizzato; non ricadere su PII persistite o ID.
            'nome_studente' => $nomePerStudente[$idStud] ?? 'Nome non disponibile',
            'voto_originale' => $votoOriginale,
            'voto_finale' => $votoFinale,
            'voto_finale_manual' => $manualFinale,
            'voto' => $votoFinale !== null && $votoFinale !== '' ? $votoFinale : $votoOriginale,
            'valutazione_testuale' => $val['valutazione_testuale'] ?? $val['giudizio'] ?? '',
            'data_valutazione' => $val['data_valutazione'] ?? '',
            'registrato' => ($val['pubblicato_cv'] ?? $val['pubblicato'] ?? 0) == 1,
            'livello_ind_0' => $datiJson['livello_ind_0'] ?? null,
            'livello_ind_1' => $datiJson['livello_ind_1'] ?? null,
            'livello_ind_2' => $datiJson['livello_ind_2'] ?? null,
            'livello_dom_1' => $datiJson['livello_dom_1'] ?? null,
            'livello_dom_2' => $datiJson['livello_dom_2'] ?? null,
            'livello_dom_3' => $datiJson['livello_dom_3'] ?? null,
            'domanda_1' => $datiJson['domanda_1'] ?? '',
            'domanda_2' => $datiJson['domanda_2'] ?? '',
            'domanda_3' => $datiJson['domanda_3'] ?? ''
        ];
    }
}

} catch (Exception $e) {
    $error = PublicError::message($e, 'oral rubric page');
    error_log("Errore rubrica_orale_v2.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    $action = 'step0';
    $udas = [];
    $classi = [];
    $studenti = [];
}

// Garantisce che il conteggio/richiami successivi non vadano in errore
if (!isset($valutazioniSalvate) || !is_array($valutazioniSalvate)) {
    $valutazioniSalvate = [];
}

?><!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rubrica Valutazione Orale</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .voto-display {
            font-size: 2.5rem;
            font-weight: bold;
            color: #28a745;
        }
        .valutazione-box {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border-left: 4px solid #007bff;
            white-space: pre-line;
        }
        .indicatore-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 15px;
            margin-bottom: 15px;
        }
        tr.js-studente-row.is-selected {
            background-color: #fff3cd !important;
        }
        tr.js-studente-row.is-selected td:first-child {
            border-left: 4px solid #fd7e14;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-mic"></i> Rubrica Valutazione Orale';
    ob_start();
    ?>
        <?php if ($rubrica && $idUdaDaGet): ?>
            <?php
            // Verifica se ci sono valutazioni non salvate
            $votiNonPubblicati = array_filter($valutazioniSalvate ?? [], function($val) {
                return ($val['pubblicato_cv'] ?? 0) == 0;
            });
            $puoResettare = empty($votiNonPubblicati);
            ?>
            <?php if ($puoResettare): ?>
                <form method="POST" action="" style="display: inline;" onsubmit="return confirm('Sei sicuro di voler eliminare questa rubrica e tutte le valutazioni? Questa azione e' irreversibile!');">
                    <input type="hidden" name="action" value="reset_rubrica">
                    <input type="hidden" name="id_uda" value="<?= htmlspecialchars($idUdaDaGet) ?>">
                    <button type="submit" class="btn btn-outline-light btn-sm">
                        <i class="bi bi-trash"></i> Reset Rubrica
                    </button>
                </form>
            <?php else: ?>
                <button class="btn btn-outline-light btn-sm" disabled title="Ci sono valutazioni non salvate">
                    <i class="bi bi-lock"></i> Rubrica Bloccata
                </button>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($idUdaDaGet): ?>
            <a href="uda_view.php?id=<?= urlencode((string)$idUdaDaGet) ?>" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left"></i> Torna all'UDA
            </a>
        <?php endif; ?>
        <a href="index.php" class="btn btn-outline-light btn-sm">
            <i class="bi bi-house"></i> Dashboard
        </a>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container mt-4">
<!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible">
                <i class="bi bi-x-circle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>
        <?php
        $showGradesButton = !empty($_GET['voti_salvati']) && !empty($idUdaDaGet);
        if ($showGradesButton):
            $gradesLink = 'uda_grades.php?id=' . urlencode((string)$idUdaDaGet);
        ?>
            <div class="alert alert-primary d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-journal-check"></i> Voti salvati. Puoi pubblicarli dalla lista della classe.
                </div>
                <a class="btn btn-primary btn-sm" href="<?= htmlspecialchars($gradesLink) ?>">
                    <i class="bi bi-eye"></i> Vai ai voti della classe
                </a>
            </div>
        <?php endif; ?>

        <?php if (!$rubrica): ?>
            <!-- STEP 0: Importa rubrica da template -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-download"></i> Importa Rubrica da Template</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="import_template">

                        <div class="mb-3">
                            <label class="form-label">Seleziona UDA</label>
                            <select name="uda_id" class="form-select" required>
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                            <?= ($idUdaDaGet && $uda->id_uda === $idUdaDaGet) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($uda->titolo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Argomenti di Valutazione (max 3)</label>
                            <input type="text" name="argomento_1" class="form-control mb-2"
                                   placeholder="Es: Quale è il ciclo di vita di un processo?" >
                            <input type="text" name="argomento_2" class="form-control mb-2"
                                   placeholder="Es: Che cosa risolve la seconda forma normale?" >
                            <input type="text" name="argomento_3" class="form-control"
                                   placeholder="Es: A che rete appartiene l'indirizzo IP 10.2.0.3/21?">
                            <small class="text-muted">Questi argomenti saranno usati come indicatori di contenuto nella rubrica</small>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-download"></i> Importa Rubrica da Template
                        </button>
                    </form>
                </div>
            </div>

            <!-- STEP 0B: Oppure carica rubrica personalizzata -->
            <div class="card mb-4">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-upload"></i> Oppure Carica Rubrica Personalizzata</h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="import_file">

                        <div class="mb-3">
                            <label class="form-label">Seleziona UDA</label>
                            <select name="uda_id" class="form-select" required>
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                            <?= ($idUdaDaGet && $uda->id_uda === $idUdaDaGet) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($uda->titolo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Link Google Sheet (opzionale)</label>
                            <div class="input-group">
                                <input type="url" name="rubrica_sheet_url" id="rubricaSheetUrl" class="form-control"
                                       placeholder="https://docs.google.com/spreadsheets/d/...">
                                <button class="btn btn-outline-secondary" type="button" id="pasteSheetLink">
                                    <i class="bi bi-clipboard"></i> Incolla link
                                </button>
                            </div>
                            <small class="text-muted">
                                Se inserisci il link, il file Excel non e necessario. Il foglio verra esportato in .xlsx.
                            </small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">File Excel Rubrica (.xlsx)</label>
                            <input type="file" name="rubrica_file" class="form-control" accept=".xlsx">
                            <small class="text-muted">
                                Carica un file Excel con la stessa struttura del template.
                                Il file deve avere gli stessi indicatori e livelli.
                            </small>
                            <div class="mt-2">
                                <a class="btn btn-sm btn-outline-secondary" href="<?= htmlspecialchars($templateDownloadUrl) ?>" download>
                                    <i class="bi bi-file-earmark-arrow-down"></i> Scarica rubrica di esempio
                                </a>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-secondary">
                            <i class="bi bi-upload"></i> Carica Rubrica Personalizzata
                        </button>
                    </form>
                </div>
            </div>

        <?php elseif ($rubrica): ?>
            <!-- Valutazione Studenti -->

            <!-- Mostra info rubrica -->
            <div class="card mb-3">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-clipboard-data"></i> Rubrica: <?= htmlspecialchars($rubrica->nome_rubrica) ?></h5>
                </div>
                <div class="card-body">
                    <p><strong>UDA:</strong> <?= htmlspecialchars($udaSelezionata->titolo ?? 'N/A') ?></p>
                    <p><strong>Indicatori:</strong> <?= count($rubrica->indicatori) ?></p>
                </div>
            </div>

            <!-- Tabella Riepilogo Voti Salvati -->
            <?php if (!empty($valutazioniPerStudente)): ?>
            <div class="card mb-4">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h6 class="mb-0"><i class="bi bi-check-circle"></i> Riepilogo Voti Salvati (<?= count($valutazioniPerStudente) ?>)</h6>
                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-sm btn-outline-danger" onclick="apriPopupCancellaVoti()">
                            <i class="bi bi-x-circle"></i> Cancella voti registrati (solo DB)
                        </button>
                        <button type="button" class="btn btn-sm btn-light" onclick="apriPopupPubblicaVoti()">
                            <i class="bi bi-save"></i> Salva voti
                        </button>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover">
                            <thead>
                                <tr>
                                    <th>Studente</th>
                                    <th>Voto originale</th>
                                    <th>Voto finale</th>
                                    <th>Data</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($valutazioniPerStudente as $idStud => $val): ?>
                                    <tr class="js-studente-row" data-studente-id="<?= htmlspecialchars($idStud) ?>">
                                        <td><?= htmlspecialchars($val['nome_studente']) ?></td>
                                        <td><strong><?= htmlspecialchars(formatVotoLabel($val['voto_originale'], 2)) ?></strong></td>
                                        <td>
                                            <?php
                                            $votoFinaleSelezionatoRaw = $val['voto_finale'] ?? null;
                                            if (is_string($votoFinaleSelezionatoRaw)) {
                                                $votoFinaleSelezionatoRaw = str_replace(',', '.', $votoFinaleSelezionatoRaw);
                                            }
                                            $votoFinaleManuale = !empty($val['voto_finale_manual']);
                                            $votoFinaleSelezionato = $votoFinaleManuale
                                                ? $votoFinaleSelezionatoRaw
                                                : normalizeVotoFinaleDefault($val['voto_originale']);
                                        ?>
                                            <select class="form-select form-select-sm voto-finale-select"
                                                    style="width: 120px; font-weight: bold;"
                                                    onchange="aggiornaVotoFinale(this, '<?= htmlspecialchars($idStud) ?>')">
                                                <?php
                                                $selectedA = ($votoFinaleSelezionato === 'a') ? 'selected' : '';
                                                $selectedI = ($votoFinaleSelezionato === 'i') ? 'selected' : '';
                                                echo "<option value=\"a\" $selectedA>a (Assente)</option>";
                                                echo "<option value=\"i\" $selectedI>i (Impreparato)</option>";
                                                for ($v = 1.0; $v <= 10.0; $v += 0.5) {
                                                    $isSelected = '';
                                                    if ($votoFinaleSelezionato !== null && $votoFinaleSelezionato !== '' && is_numeric($votoFinaleSelezionato)) {
                                                        if (abs(floatval($votoFinaleSelezionato) - $v) < 0.01) {
                                                            $isSelected = 'selected';
                                                        }
                                                    }
                                                    $label = number_format($v, 1, '.', '');
                                                    echo "<option value=\"{$label}\" {$isSelected}>{$label}</option>";
                                                }
                                                ?>
                                            </select>
                                        </td>
                                        <td><small><?= htmlspecialchars($val['data_valutazione']) ?></small></td>
                                        <td>
                                            <?php if (!$val['registrato']): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary"
                                                        onclick="caricaSuRegistro('<?= htmlspecialchars($idStud) ?>')">
                                                    <i class="bi bi-save"></i> Salva voto
                                                </button>
                                            <?php else: ?>
                                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> Salvato</span>
                                            <?php endif; ?>
                                            <button type="button" class="btn btn-sm btn-outline-secondary"
                                                    onclick="modificaVoto('<?= htmlspecialchars($idStud) ?>')">
                                                <i class="bi bi-pencil"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-danger"
                                                    onclick="eliminaValutazione('<?= htmlspecialchars($idStud) ?>', '<?= htmlspecialchars($val['nome_studente']) ?>')">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <!-- Form Valutazione Studente -->
            <form method="POST" action="" id="formValutazione">
                <input type="hidden" name="action" value="salva_valutazione_studente">
                <input type="hidden" name="nome_studente" id="nomeStudenteHidden">
                <input type="hidden" name="id_uda" value="<?= htmlspecialchars($idUdaDaGet) ?>">
                <input type="hidden" name="id_rubrica" value="<?= htmlspecialchars($rubrica->id_rubrica ?? '') ?>">
                <input type="hidden" name="id_classe_cv" value="<?= htmlspecialchars($idClasseDaGet) ?>">
                <input type="hidden" name="id_gruppo" value="<?= htmlspecialchars($gruppoSelezionatoId) ?>">
                <input type="hidden" name="id_studente_provider" id="idStudenteProvider" value="<?= htmlspecialchars($studentiProvider) ?>">
                <input type="hidden" name="id_studente_internal" id="idStudenteInternal" value="">

                <div class="card mb-4">
                    <div class="card-header bg-warning">
                        <h5 class="mb-0">
                            <i class="bi bi-pencil-square"></i> Valuta Studente
                            <span id="selectedStudentNameBadge" class="badge bg-light text-dark ms-2" style="display:none;"></span>
                        </h5>
                    </div>
                    <div class="card-body">
                        <!-- Seleziona Classe (ricarica pagina via GET) -->
                        <div class="mb-4">
                            <div id="formClasse">
                                <input type="hidden" name="id_uda" value="<?= htmlspecialchars($idUdaDaGet) ?>">
                                <label class="form-label">Seleziona Classe *</label>
                                <select name="id_classe" id="selectClasse" class="form-select">
                                    <option value="">-- Seleziona classe --</option>
                                    <?php foreach ($classi as $classe):
                                        $idClasse = $classe['classId'] ?? '';
                                        $nomeClasse = $classe['className'] ?? 'N/A';
                                        $stats = $statisticheClassi[$idClasse] ?? null;

                                        // Costruisci testo statistiche
                                        $testoStats = '';
                                        if ($stats && $stats['voti_totali'] > 0) {
                                            if ($stats['voti_da_registrare'] > 0) {
                                                $testoStats = " ({$stats['voti_da_registrare']} voti da registrare di {$stats['voti_totali']})";
                                            } else {
                                                $testoStats = " ({$stats['voti_totali']} voti registrati)";
                                            }
                                        }
                                    ?>
                                        <option value="<?= htmlspecialchars($idClasse) ?>"
                                                <?= ($idClasseDaGet == $idClasse) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($nomeClasse . $testoStats) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <noscript>
                                    <button type="submit" class="btn btn-primary btn-sm mt-2">Carica Classe</button>
                                </noscript>
                            </div>
                        </div>

                        <?php if (!empty($studenti)): ?>
                        <!-- La materia è contestualizzata dal gruppo didattico e non
                             viene mostrata nella grafica. Manteniamo l'eventuale
                             associazione CV solo per compatibilità di pubblicazione. -->
                        <input type="hidden" name="id_materia_cv" value="<?= htmlspecialchars((string)($materiaSelezionataId ?? '')) ?>">

                        <div class="mb-4">
                            <label class="form-label">Seleziona Studente *</label>
                            <select name="id_studente" id="selectStudente" class="form-select" required onchange="caricaValutazioneStudente(this.value)">
                                <option value="">-- Seleziona uno studente --</option>
                                <?php $firstStudente = true; foreach ($studenti as $st):
                                    $idStud = $st['id'];
                                    $providerStud = $st['provider'] ?? 'classeviva';
                                    $nomeCompleto = $st['nome_completo'];

                                    // Controlla se ha un voto
                                    $testoVoto = '';
                                    if (isset($valutazioniPerStudente[$idStud])) {
                                        $valStud = $valutazioniPerStudente[$idStud];
                                        $voto = $valStud['voto'] ?? '';
                                        if ($voto !== '' && $voto !== null) {
                                            if ($valStud['registrato']) {
                                                // Registrato - solo voto
                                                $testoVoto = " ({$voto})";
                                            } else {
                                                // Da registrare - voto con asterisco
                                                $testoVoto = " ({$voto}*)";
                                            }
                                        }
                                    }
                                ?>
                                    <option value="<?= htmlspecialchars($idStud) ?>"
                                            data-nome="<?= htmlspecialchars($nomeCompleto) ?>"
                                            data-provider="<?= htmlspecialchars($providerStud) ?>"
                                            data-studente-internal="<?= htmlspecialchars((string)($st['id_studente_internal'] ?? '')) ?>"<?= $firstStudente ? ' selected' : '' ?>>
                                        <?= htmlspecialchars($nomeCompleto . $testoVoto) ?>
                                    </option>
                                    <?php $firstStudente = false; ?>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <?php endif; ?>

                        <div id="areaValutazione" style="display: none;">
                            <!-- Domande Personalizzabili -->
                            <?php
                            $domanda1Default = $rubrica->indicatori[3]['nome'] ?? 'Domanda 1';
                            $domanda2Default = $rubrica->indicatori[4]['nome'] ?? 'Domanda 2';
                            $domanda3Default = $rubrica->indicatori[5]['nome'] ?? 'Domanda 3';
                            ?>
                            <div class="row g-3 mb-4">
                                <div class="col-md-4">
                                    <label class="form-label">Domanda 1 (personalizzabile o seleziona da lista)</label>
                                    <input type="text" name="domanda_1" class="form-control"
                                           list="domande_list"
                                           value="<?= htmlspecialchars($domanda1Default) ?>"
                                           oninput="calcolaVotoRealTime()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Domanda 2 (personalizzabile o seleziona da lista)</label>
                                    <input type="text" name="domanda_2" class="form-control"
                                           list="domande_list"
                                           value="<?= htmlspecialchars($domanda2Default) ?>"
                                           oninput="calcolaVotoRealTime()">
                                </div>
                                <div class="col-md-4">
                                    <label class="form-label">Domanda 3 (personalizzabile o seleziona da lista)</label>
                                    <input type="text" name="domanda_3" class="form-control"
                                           list="domande_list"
                                           value="<?= htmlspecialchars($domanda3Default) ?>"
                                           oninput="calcolaVotoRealTime()">
                                </div>
                            </div>

                            <!-- Datalist con le domande esistenti -->
                            <datalist id="domande_list">
                                <?php foreach ($domandeUDA as $domanda): ?>
                                    <option value="<?= htmlspecialchars($domanda['domanda'] ?? '') ?>">
                                <?php endforeach; ?>
                            </datalist>

                            <hr>

                            <!-- Competenze Trasversali (Indicatori Fissi) -->
                            <h6>Competenze Trasversali</h6>

                            <?php
                            // Usa i primi 3 indicatori dalla rubrica caricata
                            $indicatoriFissi = array_slice($rubrica->indicatori, 0, 3);

                            foreach ($indicatoriFissi as $idx => $ind):
                                // Crea un ID univoco per l'indicatore basato sull'indice
                                $indId = 'ind_' . $idx;
                            ?>
                                <div class="indicatore-card">
                                    <strong><?= htmlspecialchars($ind['nome']) ?></strong>
                                    <span class="badge bg-secondary ms-2">Peso: <?= $ind['peso'] ?></span>
                                    <div class="mt-3">
                                        <?php
                                        // Itera sui livelli dell'indicatore
                                        foreach ($ind['livelli'] as $livNum => $livInfo):
                                            $punteggio = $livInfo['punteggio'];
                                            $percentuale = $punteggio * 100;
                                            $descrizione = $livInfo['descrizione'];
                                        ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input livello-radio"
                                                       type="radio"
                                                       name="livello_<?= $indId ?>"
                                                       value="<?= $livNum ?>"
                                                       id="<?= $indId ?>_<?= $livNum ?>"
                                                       data-punteggio="<?= $punteggio ?>"
                                                       data-peso="<?= $ind['peso'] ?>"
                                                       onchange="calcolaVotoRealTime()">
                                                <label class="form-check-label livello-label" for="<?= $indId ?>_<?= $livNum ?>">
                                                    <strong>Livello <?= $livNum ?></strong> (<?= $percentuale ?>%)
                                                    <?php if ($descrizione): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($descrizione) ?></small>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Contenuti (Indicatori Domande) -->
                            <h6 class="mt-4">Contenuti</h6>

                            <?php
                            // Usa gli indicatori 3, 4, 5 dalla rubrica (contenuti)
                            $indicatoriContenuto = array_slice($rubrica->indicatori, 3, 3);

                            foreach ($indicatoriContenuto as $idx => $ind):
                                $domandaNum = $idx + 1;
                                $indId = 'dom_' . $domandaNum;
                                // Usa il default della rubrica per ogni domanda
                                $testoDomanda = ${'domanda' . $domandaNum . 'Default'};
                            ?>
                                <div class="indicatore-card">
                                    <strong id="label_domanda_<?= $domandaNum ?>"><?= htmlspecialchars($testoDomanda) ?></strong>
                                    <span class="badge bg-secondary ms-2">Peso: <?= $ind['peso'] ?></span>
                                    <div class="mt-3">
                                        <?php
                                        // Itera sui livelli dell'indicatore
                                        foreach ($ind['livelli'] as $livNum => $livInfo):
                                            $punteggio = $livInfo['punteggio'];
                                            $percentuale = $punteggio * 100;
                                            $descrizione = $livInfo['descrizione'];
                                        ?>
                                            <div class="form-check mb-2">
                                                <input class="form-check-input livello-radio"
                                                       type="radio"
                                                       name="livello_<?= $indId ?>"
                                                       value="<?= $livNum ?>"
                                                       id="<?= $indId ?>_<?= $livNum ?>"
                                                       data-punteggio="<?= $punteggio ?>"
                                                       data-peso="<?= $ind['peso'] ?>"
                                                       onchange="calcolaVotoRealTime()">
                                                <label class="form-check-label livello-label" for="<?= $indId ?>_<?= $livNum ?>">
                                                    <strong>Livello <?= $livNum ?></strong> (<?= $percentuale ?>%)
                                                    <?php if ($descrizione): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($descrizione) ?></small>
                                                    <?php endif; ?>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>

                            <!-- Voto e Valutazione Testuale -->
                            <div class="row mt-4">
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body text-center">
                                            <h6>Voto Calcolato</h6>
                                            <div class="voto-display" id="votoDisplay">-</div>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-body">
                                            <h6>Valutazione Testuale</h6>
                                            <div class="valutazione-box" id="valutazioneBox">
                                                Seleziona i livelli per generare la valutazione...
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Campi Hidden per Voto e Valutazione -->
                            <input type="hidden" name="voto" id="votoHidden" value="">
                            <input type="hidden" name="valutazione_testuale" id="valutazioneTestualeHidden" value="">

                            <!-- Submit Button -->
                            <div class="mt-4">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-save"></i> Salva Valutazione
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
            </form>

        <?php endif; ?>
    </div>

    <!-- Modal salvataggio voti -->
    <div class="modal fade" id="publishVotiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-save"></i> Salva voti</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Seleziona i voti da salvare. I voti gia salvati restano selezionabili.</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="publishSelectAll" checked>
                        <label class="form-check-label" for="publishSelectAll">Seleziona tutti</label>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 36px;"></th>
                                    <th>Studente</th>
                                    <th>Voto finale</th>
                                    <th>Data</th>
                                    <th>Valutazione</th>
                                </tr>
                            </thead>
                            <tbody id="publishVotiBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" id="confirmPublishBtn">
                        <i class="bi bi-save"></i> Salva selezionati
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal cancellazione voti -->
    <div class="modal fade" id="deleteVotiModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-lg modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-trash"></i> Cancella voti registrati</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Seleziona i voti da cancellare. Sono preselezionati quelli gia registrati.</p>
                    <div class="form-check mb-2">
                        <input class="form-check-input" type="checkbox" id="deleteSelectAll">
                        <label class="form-check-label" for="deleteSelectAll">Seleziona tutti</label>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-sm table-hover align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 36px;"></th>
                                    <th>Studente</th>
                                    <th>Voto finale</th>
                                    <th>Data</th>
                                    <th>Valutazione</th>
                                </tr>
                            </thead>
                            <tbody id="deleteVotiBody"></tbody>
                        </table>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                        <i class="bi bi-trash"></i> Cancella selezionati
                    </button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dati valutazioni da DATABASE (non più sessioni)
        const valutazioniStudenti = <?= \App\Core\Security\OutputEncoder::json($valutazioniPerStudente ?? []) ?>;
        const domandeDefault = {
            domanda_1: <?= \App\Core\Security\OutputEncoder::json($domanda1Default ?? '') ?>,
            domanda_2: <?= \App\Core\Security\OutputEncoder::json($domanda2Default ?? '') ?>,
            domanda_3: <?= \App\Core\Security\OutputEncoder::json($domanda3Default ?? '') ?>
        };

        // Dati rubrica da PHP (indicatori con livelli e descrizioni)
        const rubricaData = <?= $rubrica ? \App\Core\Security\OutputEncoder::json([
            'indicatori_fissi' => array_values(array_map(function($ind, $idx) {
                return [
                    'id' => 'ind_' . $idx,
                    'nome' => $ind['nome'],
                    'peso' => $ind['peso'],
                    'livelli' => $ind['livelli']
                ];
            }, array_slice($rubrica->indicatori, 0, 3), array_keys(array_slice($rubrica->indicatori, 0, 3, true)))),
            'indicatori_contenuto' => array_values(array_map(function($ind, $idx) {
                return [
                    'id' => 'dom_' . ($idx + 1),
                    'nome' => $ind['nome'],
                    'peso' => $ind['peso'],
                    'livelli' => $ind['livelli']
                ];
            }, array_slice($rubrica->indicatori, 3, 3), range(0, 2)))
        ]) : \App\Core\Security\OutputEncoder::json(['indicatori_fissi' => [], 'indicatori_contenuto' => []]) ?>;

        function evidenziaRigaStudente(idStudente) {
            const id = idStudente ? String(idStudente) : '';
            document.querySelectorAll('tr.js-studente-row').forEach(tr => {
                const trId = tr.getAttribute('data-studente-id') || '';
                tr.classList.toggle('is-selected', id !== '' && trId === id);
            });
        }

        function aggiornaBadgeStudente(nomeCompleto) {
            const badge = document.getElementById('selectedStudentNameBadge');
            if (!badge) return;

            const name = (nomeCompleto || '').trim();
            if (name === '') {
                badge.style.display = 'none';
                badge.textContent = '';
                return;
            }

            badge.textContent = name;
            badge.style.display = 'inline-block';
        }

        // Carica valutazione studente esistente o reset form
        function caricaValutazioneStudente(idStudente) {
            evidenziaRigaStudente(idStudente);

            const selectEl = document.getElementById('selectStudente');
            const selectedOption = selectEl.options[selectEl.selectedIndex];
            const nomeCompleto = selectedOption ? (selectedOption.getAttribute('data-nome') || '') : '';
            const providerStud = selectedOption ? (selectedOption.getAttribute('data-provider') || 'classeviva') : 'classeviva';
            const internalStudentId = selectedOption ? (selectedOption.getAttribute('data-studente-internal') || '') : '';
            const providerEl = document.getElementById('idStudenteProvider');
            if (providerEl) providerEl.value = providerStud;
            const internalEl = document.getElementById('idStudenteInternal');
            if (internalEl) internalEl.value = internalStudentId;

            aggiornaBadgeStudente(nomeCompleto);

            if (!idStudente) {
                document.getElementById('nomeStudenteHidden').value = '';
                document.getElementById('areaValutazione').style.display = 'none';
                return;
            }

            document.getElementById('nomeStudenteHidden').value = nomeCompleto;
            document.getElementById('areaValutazione').style.display = 'block';

            // Carica valutazione salvata dal database se esiste
            if (valutazioniStudenti[idStudente]) {
                const val = valutazioniStudenti[idStudente];

                // Ripopola i livelli per indicatori fissi (competenze trasversali)
                rubricaData.indicatori_fissi.forEach(ind => {
                    const fieldName = `livello_${ind.id}`;
                    if (val[fieldName]) {
                        const radio = document.querySelector(`input[name="${fieldName}"][value="${val[fieldName]}"]`);
                        if (radio) radio.checked = true;
                    }
                });

                // Ripopola i livelli per indicatori contenuto (domande)
                rubricaData.indicatori_contenuto.forEach(ind => {
                    const fieldName = `livello_${ind.id}`;
                    if (val[fieldName]) {
                        const radio = document.querySelector(`input[name="${fieldName}"][value="${val[fieldName]}"]`);
                        if (radio) radio.checked = true;
                    }
                });

                // Ripopola domande personalizzate
                if (val.domanda_1) document.querySelector('input[name="domanda_1"]').value = val.domanda_1;
                if (val.domanda_2) document.querySelector('input[name="domanda_2"]').value = val.domanda_2;
                if (val.domanda_3) document.querySelector('input[name="domanda_3"]').value = val.domanda_3;

                calcolaVotoRealTime();
            } else {
                // Reset form
                document.querySelectorAll('.livello-radio').forEach(r => r.checked = false);

                // Ripristina domande di default dalla rubrica
                document.querySelector('input[name="domanda_1"]').value = domandeDefault.domanda_1;
                document.querySelector('input[name="domanda_2"]').value = domandeDefault.domanda_2;
                document.querySelector('input[name="domanda_3"]').value = domandeDefault.domanda_3;

                document.getElementById('votoDisplay').textContent = '-';
                document.getElementById('valutazioneBox').textContent = 'Seleziona i livelli per generare la valutazione...';
            }
        }

        // Calcola voto e valutazione testuale in tempo reale
        function calcolaVotoRealTime() {
            let somma = 0;
            let sommaPesi = 0;
            let valutazioneText = "VALUTAZIONE ORALE\n\n";
            valutazioneText += "COMPETENZE TRASVERSALI:\n\n";

            // Indicatori fissi (competenze trasversali) - usa dati dalla rubrica
            rubricaData.indicatori_fissi.forEach(ind => {
                const radio = document.querySelector(`input[name="livello_${ind.id}"]:checked`);
                if (radio) {
                    const livelloNum = parseInt(radio.value);
                    const punteggio = parseFloat(radio.getAttribute('data-punteggio'));
                    const peso = parseFloat(radio.getAttribute('data-peso'));

                    somma += punteggio * peso;
                    sommaPesi += peso;

                    // Trova la descrizione dal livello selezionato
                    const livelloInfo = ind.livelli[livelloNum];
                    if (livelloInfo) {
                        valutazioneText += `${ind.nome}: ${livelloInfo.descrizione}\n\n`;
                    }
                }
            });

            valutazioneText += "CONTENUTI:\n\n";

            // Domande (contenuti) - usa dati dalla rubrica
            rubricaData.indicatori_contenuto.forEach((ind, idx) => {
                const domandaNum = idx + 1;

                // Aggiorna sempre il label della domanda nella sezione Contenuti (indipendentemente dalla selezione)
                const domandaInput = document.querySelector(`input[name="domanda_${domandaNum}"]`);
                const labelDomanda = document.getElementById(`label_domanda_${domandaNum}`);
                if (labelDomanda && domandaInput) {
                    labelDomanda.textContent = domandaInput.value || ind.nome;
                }

                // Controlla se c'è un livello selezionato
                const radio = document.querySelector(`input[name="livello_${ind.id}"]:checked`);
                if (radio) {
                    const livelloNum = parseInt(radio.value);
                    const punteggio = parseFloat(radio.getAttribute('data-punteggio'));
                    const peso = parseFloat(radio.getAttribute('data-peso'));

                    somma += punteggio * peso;
                    sommaPesi += peso;

                    // Leggi la domanda corrente dal campo input
                    const domanda = domandaInput ? domandaInput.value : ind.nome;

                    // Trova la descrizione dal livello selezionato
                    const livelloInfo = ind.livelli[livelloNum];
                    if (livelloInfo) {
                        // Include sia la domanda che la descrizione del livello
                        valutazioneText += `${domanda}: ${livelloInfo.descrizione}\n\n`;
                    }
                }
            });

            // Calcola voto finale
            if (sommaPesi > 0) {
                const voto = (somma / sommaPesi) * 10;
                const votoOriginale = Math.round(voto * 100) / 100;
                document.getElementById('votoDisplay').textContent = votoOriginale.toFixed(2);
                document.getElementById('votoHidden').value = votoOriginale.toFixed(2);
            } else {
                document.getElementById('votoDisplay').textContent = '-';
                document.getElementById('votoHidden').value = '';
            }

            document.getElementById('valutazioneBox').textContent = valutazioneText;
            document.getElementById('valutazioneTestualeHidden').value = valutazioneText;
        }

        // Modifica un voto esistente: ricarica i dati nello studente selezionato
        function modificaVoto(idStudente) {
            // Seleziona lo studente
            const selectEl = document.getElementById('selectStudente');
            selectEl.value = idStudente;

            // Triggera il caricamento
            caricaValutazioneStudente(idStudente);

            // Scroll verso l'area di valutazione
            document.getElementById('areaValutazione').scrollIntoView({ behavior: 'smooth' });
        }

        // Elimina una valutazione
        function eliminaValutazione(idStudente, nomeStudente) {
            if (!confirm(`Sei sicuro di voler eliminare la valutazione di ${nomeStudente}?`)) {
                return;
            }

            // Recupera parametri dalla URL
            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');

            // Costruisci URL per eliminazione
            const form = document.createElement('form');
            form.method = 'POST';
            form.action = 'rubrica_orale_v2.php';

            const fields = {
                'action': 'elimina_valutazione',
                'id_uda': idUda,
                'id_classe': idClasse,
                'id_studente': idStudente
            };

            for (const key in fields) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = key;
                input.value = fields[key];
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        }

        function getRubricaId() {
            const input = document.querySelector('input[name="id_rubrica"]');
            return input ? input.value : '';
        }

        function getVotoFinaleEffettivo(val) {
            if (!val) return null;
            if (val.voto_finale !== undefined && val.voto_finale !== null && val.voto_finale !== '') {
                return val.voto_finale;
            }
            if (val.voto_originale !== undefined && val.voto_originale !== null && val.voto_originale !== '') {
                return val.voto_originale;
            }
            return val.voto ?? null;
        }

        function aggiornaVotoFinale(selectEl, idStudente) {
            const votoFinale = selectEl.value;
            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');
            const idRubrica = getRubricaId();

            if (!idStudente || !idUda || !idClasse) {
                return;
            }

            fetch('rubrica_orale_v2.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'aggiorna_voto_finale',
                    'id_uda': idUda,
                    'id_classe': idClasse,
                    'id_studente': idStudente,
                    'id_rubrica': idRubrica,
                    'voto_finale': votoFinale
                })
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success) {
                    alert('Errore: ' + (data.message || 'Aggiornamento fallito'));
                    if (selectEl.dataset.prevValue !== undefined) {
                        selectEl.value = selectEl.dataset.prevValue;
                    }
                    return;
                }
                if (valutazioniStudenti[idStudente]) {
                    valutazioniStudenti[idStudente].voto_finale = votoFinale;
                    valutazioniStudenti[idStudente].voto = votoFinale;
                }
                selectEl.dataset.prevValue = votoFinale;
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('Errore durante l\'aggiornamento del voto finale');
                if (selectEl.dataset.prevValue !== undefined) {
                    selectEl.value = selectEl.dataset.prevValue;
                }
            });
        }

        function redirectAfterSave() {
            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');
            const params = [];
            if (idUda) {
                params.push(`id_uda=${encodeURIComponent(idUda)}`);
            }
            if (idClasse) {
                params.push(`id_classe=${encodeURIComponent(idClasse)}`);
            }
            params.push('voti_salvati=1');
            const targetUrl = `rubrica_orale_v2.php?${params.join('&')}`;
            window.location.href = targetUrl;
        }

        // Salva un singolo voto nel sistema
        function caricaSuRegistro(idStudente) {
            if (!confirm('Vuoi salvare questo voto nel sistema?')) {
                return;
            }

            const val = valutazioniStudenti[idStudente];
            if (!val) {
                alert('Valutazione non trovata');
                return;
            }
            const votoFinale = getVotoFinaleEffettivo(val);

            // Recupera parametri dalla URL
            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');

            // TODO: Implementare chiamata API a ClasseViva
            // Per ora simula il caricamento
            fetch('rubrica_orale_v2.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'carica_registro',
                    'id_studente': idStudente,
                    'id_uda': idUda,
                    'id_classe': idClasse,
                    'id_gruppo': document.querySelector('input[name="id_gruppo"]')?.value || '',
                    'voto_finale': votoFinale,
                    'valutazione_testuale': val.valutazione_testuale
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('Voto salvato nel sistema');
                    redirectAfterSave();
                } else {
                    alert('Errore: ' + (data.message || 'Salvataggio fallito'));
                }
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('Errore durante il salvataggio');
            });
        }

        // Salva tutti i voti nel sistema
        function apriPopupPubblicaVoti() {
            const modalEl = document.getElementById('publishVotiModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                return;
            }

            const body = document.getElementById('publishVotiBody');
            const selectAll = document.getElementById('publishSelectAll');
            if (!body || !selectAll) {
                return;
            }

            const items = Object.entries(valutazioniStudenti)
                .map(([id, val]) => ({
                    id,
                    nome: val.nome_studente || 'Nome non disponibile',
                    votoFinale: getVotoFinaleEffettivo(val),
                    data: val.data_valutazione || '',
                    valutazione: val.valutazione_testuale || '',
                    registrato: !!val.registrato
                }))
                .sort((a, b) => a.nome.localeCompare(b.nome, 'it', { sensitivity: 'base' }));

            body.innerHTML = '';
            if (items.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted">Nessuna valutazione disponibile.</td></tr>';
                selectAll.checked = false;
            } else {
                items.forEach(item => {
                    const checked = item.registrato ? '' : 'checked';
                    const badge = item.registrato ? '<span class="badge bg-success ms-2">Gia salvato</span>' : '';
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><input type="checkbox" class="form-check-input publish-check" data-id="${item.id}" ${checked}></td>
                        <td>${escapeHtml(item.nome)} ${badge}</td>
                        <td><strong>${escapeHtml(String(item.votoFinale ?? '-'))}</strong></td>
                        <td>${escapeHtml(item.data || '-')}</td>
                        <td class="small">${escapeHtml(item.valutazione || '')}</td>
                    `;
                    body.appendChild(row);
                });
                selectAll.checked = true;
            }

            selectAll.onchange = () => {
                const checks = body.querySelectorAll('.publish-check');
                checks.forEach(chk => { chk.checked = selectAll.checked; });
            };

            const confirmBtn = document.getElementById('confirmPublishBtn');
            if (confirmBtn) {
                confirmBtn.onclick = () => pubblicaVotiSelezionati();
            }

            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }

        function pubblicaVotiSelezionati() {
            const body = document.getElementById('publishVotiBody');
            if (!body) return;
            const checks = Array.from(body.querySelectorAll('.publish-check'));
            const selezionati = checks
                .filter(chk => chk.checked)
                .map(chk => chk.getAttribute('data-id'));

            if (selezionati.length === 0) {
                alert('Seleziona almeno un voto.');
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');
            const idRubrica = getRubricaId();

            const payload = selezionati.map(id => ({
                id_studente: id,
                selected: true
            }));

            fetch('rubrica_orale_v2.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'carica_registro_blocco',
                    'id_uda': idUda,
                    'id_classe': idClasse,
                    'id_gruppo': document.querySelector('input[name="id_gruppo"]')?.value || '',
                    'id_rubrica': idRubrica,
                    'voti': JSON.stringify(payload)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(`${data.caricati || selezionati.length} voti salvati nel sistema`);
                    redirectAfterSave();
                } else {
                    alert('Errore: ' + (data.message || 'Salvataggio fallito'));
                }
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('Errore durante il salvataggio');
            });
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text ?? '';
            return div.innerHTML;
        }

        function apriPopupCancellaVoti() {
            const modalEl = document.getElementById('deleteVotiModal');
            if (!modalEl || typeof bootstrap === 'undefined') {
                return;
            }

            const body = document.getElementById('deleteVotiBody');
            const selectAll = document.getElementById('deleteSelectAll');
            if (!body || !selectAll) {
                return;
            }

            const items = Object.entries(valutazioniStudenti)
                .map(([id, val]) => ({
                    id,
                    nome: val.nome_studente || 'Nome non disponibile',
                    votoFinale: getVotoFinaleEffettivo(val),
                    data: val.data_valutazione || '',
                    valutazione: val.valutazione_testuale || '',
                    registrato: !!val.registrato
                }))
                .sort((a, b) => a.nome.localeCompare(b.nome, 'it', { sensitivity: 'base' }));

            body.innerHTML = '';
            if (items.length === 0) {
                body.innerHTML = '<tr><td colspan="5" class="text-muted">Nessuna valutazione disponibile.</td></tr>';
                selectAll.checked = false;
            } else {
                items.forEach(item => {
                    const checked = item.registrato ? 'checked' : '';
                    const badge = item.registrato ? '<span class="badge bg-success ms-2">Gia salvato</span>' : '';
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><input type="checkbox" class="form-check-input delete-check" data-id="${item.id}" ${checked}></td>
                        <td>${escapeHtml(item.nome)} ${badge}</td>
                        <td><strong>${escapeHtml(String(item.votoFinale ?? '-'))}</strong></td>
                        <td>${escapeHtml(item.data || '-')}</td>
                        <td class="small">${escapeHtml(item.valutazione || '')}</td>
                    `;
                    body.appendChild(row);
                });
                selectAll.checked = false;
            }

            selectAll.onchange = () => {
                const checks = body.querySelectorAll('.delete-check');
                checks.forEach(chk => { chk.checked = selectAll.checked; });
            };

            const confirmBtn = document.getElementById('confirmDeleteBtn');
            if (confirmBtn) {
                confirmBtn.onclick = () => cancellaVotiSelezionati();
            }

            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();
        }

        function cancellaVotiSelezionati() {
            const body = document.getElementById('deleteVotiBody');
            if (!body) return;
            const checks = Array.from(body.querySelectorAll('.delete-check'));
            const selezionati = checks
                .filter(chk => chk.checked)
                .map(chk => chk.getAttribute('data-id'));

            if (selezionati.length === 0) {
                alert('Seleziona almeno un voto.');
                return;
            }

            if (!confirm(`Vuoi cancellare ${selezionati.length} voto/i selezionati?`)) {
                return;
            }

            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');
            const idRubrica = getRubricaId();

            const payload = selezionati.map(id => ({
                id_studente: id,
                selected: true
            }));

            fetch('rubrica_orale_v2.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'cancella_registrati_blocco',
                    'id_uda': idUda,
                    'id_classe': idClasse,
                    'id_rubrica': idRubrica,
                    'voti': JSON.stringify(payload)
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Voti cancellati');
                    redirectAfterSave();
                } else {
                    alert('Errore: ' + (data.message || 'Cancellazione fallita'));
                }
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('Errore durante la cancellazione');
            });
        }

        // Cancella tutti i voti già salvati (pubblicato_cv=1) dal DB locale
        function cancellaTuttiRegistrati() {
            const urlParams = new URLSearchParams(window.location.search);
            const idUda = urlParams.get('id_uda');
            const idClasse = urlParams.get('id_classe');
            if (!idClasse) {
                alert('Seleziona prima una classe');
                return;
            }
            if (!confirm('Cancello tutti i voti già salvati (pubblicato_cv=1) dal DB (VALUTAZIONI_RUBRICA). Continuare?')) {
                return;
            }
            fetch('rubrica_orale_v2.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    'action': 'cancella_registrati_blocco',
                    'id_uda': idUda,
                    'id_classe': idClasse
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert(data.message || 'Cancellazione completata');
                    location.reload();
                } else {
                    alert('Errore: ' + (data.message || 'Operazione fallita'));
                }
            })
            .catch(error => {
                console.error('Errore:', error);
                alert('Errore durante la cancellazione');
            });
        }

        // ==== Gestore selezione classe ====
        document.addEventListener('DOMContentLoaded', function() {
            const pasteButton = document.getElementById('pasteSheetLink');
            if (pasteButton && navigator.clipboard && navigator.clipboard.readText) {
                pasteButton.addEventListener('click', async function() {
                    try {
                        const text = await navigator.clipboard.readText();
                        const input = document.getElementById('rubricaSheetUrl');
                        if (input && text) {
                            input.value = text.trim();
                        }
                    } catch (err) {
                        alert('Impossibile leggere dagli appunti.');
                    }
                });
            }

            document.querySelectorAll('.voto-finale-select').forEach(select => {
                select.addEventListener('focus', () => {
                    select.dataset.prevValue = select.value;
                });
            });

            const selectClasse = document.getElementById('selectClasse');
            if (selectClasse) {
                selectClasse.addEventListener('change', function(e) {
                    const idClasse = this.value;
                    console.log('Classe selezionata:', idClasse);

                    if (idClasse && idClasse !== '') {
                        // Recupera id_uda dall'input hidden
                        const idUdaInput = document.querySelector('#formValutazione input[name="id_uda"]');
                        const idUda = idUdaInput ? idUdaInput.value : '';

                        // Costruisci URL manualmente
                        const newUrl = `rubrica_orale_v2.php?id_uda=${encodeURIComponent(idUda)}&id_classe=${encodeURIComponent(idClasse)}`;
                        console.log('Redirecting to:', newUrl);

                        // Redirect diretto invece di form.submit()
                        window.location.href = newUrl;
                    } else {
                        console.log('Valore vuoto, non submitto');
                    }
                });
            }

            // Preseleziona il primo studente (la classe e' gia' preselezionata lato server).
            const selectStudente = document.getElementById('selectStudente');
            if (selectStudente && selectStudente.value) {
                caricaValutazioneStudente(selectStudente.value);
            }
        });
    </script>
</body>
</html>
