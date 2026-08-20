<?php

/**
 * Laboratorio PiùOMeno - Vista Griglia Classe
 *
 * Vista ottimizzata:
 * - Studenti in verticale (righe)
 * - Indicatori in orizzontale (colonne)
 * - Voti registrati in grigio
 * - Voti in coda (<2h) evidenziati in rosso
 * - Click su +/- per inserire voto in coda
 */

error_reporting(E_ALL);

define('REQUIRES_CLASSEVIVA', true);
$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\GroupStudentRepository;
use App\Core\ProviderCapabilityResolver;
use App\Core\StudentIdentityRepository;
use App\Core\UDAManager;
use App\Core\StudentiManager;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\UdaGroupRepository;
use App\Integration\ClasseVivaAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

// Inizializza ClasseViva API
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classeVivaState['ready'];
$cvAuthError = $cvReady ? null : ($classeVivaState['notice'] ?? 'Token ClasseViva non valido o assente.');
$cvAPI = new ClasseVivaAPI($config);
$studentiManager = new StudentiManager($dbAdapter, $cvAPI, $config);

$message = null;
$error = null;

// Parametri
$udaId = $_GET['id_uda'] ?? $_POST['uda_id'] ?? null;
$idClasseCV = $_GET['id_classe_cv'] ?? $_POST['id_classe_cv'] ?? null;
$idMateriaCV = $_GET['id_materia_cv'] ?? $_POST['id_materia_cv'] ?? null;

// Risolvi il gruppo didattico dalla coppia classe/materia ClasseViva.
$integrationRepo = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
$udaGroupRepo = new UdaGroupRepository($dbAdapter, $userId);
$groupStudentRepo = new GroupStudentRepository($dbAdapter, $userId);
$studentIdentityRepo = new StudentIdentityRepository($dbAdapter, $userId);
$groupId = null;
if ($idClasseCV && $idMateriaCV) {
    $cvIntegration = $integrationRepo->findByExternal('classeviva', (string)$idClasseCV, (string)$idMateriaCV);
    $groupId = $cvIntegration['id_gruppo'] ?? null;
}
if (($groupId === null || $groupId === '') && $udaId) {
    $assignments = $udaGroupRepo->listForUda((string)$udaId);
    if (count($assignments) === 1) {
        $groupId = trim((string)($assignments[0]['id_gruppo'] ?? '')) ?: null;
    }
}

// Username docente
$username = $_SESSION['username'] ?? 'docente';

// Timestamp 2 ore fa
$duehOraFa = date('Y-m-d H:i:s', strtotime('-2 hours'));

// ============================================================
// ACTION: salva voto laboratorio (AJAX POST)
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pubblica_voto') {
    header('Content-Type: application/json');

    try {
        // Salvataggio locale: non richiede token né mappatura ClasseViva.
        $idStud = $_POST['id_studente'] ?? ($_POST['id_studente_cv'] ?? null);
        $idStudInternal = trim((string)($_POST['id_studente_internal'] ?? ''));
        if ($idStudInternal === '' && $groupId && $idStud) {
            foreach ($groupStudentRepo->listForGroup((string)$groupId) as $membership) {
                if ((string)($membership['id_studente'] ?? '') === (string)$idStud) {
                    $idStudInternal = (string)$idStud;
                    break;
                }
            }
        }
        $votoProposto = isset($_POST['voto']) ? floatval($_POST['voto']) : null;
        $descrizioneEvidenze = $_POST['descrizione_evidenze'] ?? '';

        if (!$udaId || !$idStud || !$groupId) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti (udaId=' . ($udaId ?? 'null') . ', idClasseCV=' . ($idClasseCV ?? 'null') . ', idMateriaCV=' . ($idMateriaCV ?? 'null') . ', idStud=' . ($idStud ?? 'null') . ')']);
            exit;
        }
        if ($votoProposto === null) {
            echo json_encode(['ok' => false, 'error' => 'Voto non valido']);
            exit;
        }

        // Recupera evidenze in coda per lo studente
        $studentLookup = [
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
        ];
        if ($idStudInternal !== '') {
            $studentLookup['id_studente'] = $idStudInternal;
        } else {
            $studentLookup['id_studente_cv'] = $idStud;
        }
        $queueRows = $dbAdapter->findWhere('PLUSMINUS_QUEUE', $studentLookup);

        // Copia su VALUTAZIONI_LABORATORIO e cancella dalla coda
        $now = date('Y-m-d H:i:s');
        $migrateCount = 0;
        foreach ($queueRows as $row) {
            $insert = $row;
            $insert['data_registrazione'] = $now;
            // Rimuovi campi specifici della coda che non esistono in VALUTAZIONI_LABORATORIO
            unset($insert['id_evidenza']); // nuovo id auto
            unset($insert['registrato']); // campo non presente in VALUTAZIONI_LABORATORIO
            $dbAdapter->insertRow('VALUTAZIONI_LABORATORIO', $insert);
            if (isset($row['id_evidenza'])) {
                $dbAdapter->deleteRow('PLUSMINUS_QUEUE', $row['id_evidenza'], 'id_evidenza');
            }
            $migrateCount++;
        }

        // Recupera evidenze già pubblicate (non registrate) e somma a quelle migrate
        $pubblicateRaw = $dbAdapter->findWhere('VALUTAZIONI_LABORATORIO', [
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
            ...(($idStudInternal !== '') ? ['id_studente' => $idStudInternal] : ['id_studente_cv' => $idStud])
        ]);
        $pubblicate = array_filter($pubblicateRaw, function($row) {
            return empty($row['data_registrazione']);
        });

        // Costruisci lista completa evidenze per calcolo voto e descrizione
        $evidenzeCalcolo = [];
        foreach ($queueRows as $row) {
            $evidenzeCalcolo[] = [
                'valore' => $row['valore'],
                'nome_indicatore' => $row['nome_indicatore'] ?? '',
                'fonte' => 'queue'
            ];
        }
        foreach ($pubblicate as $row) {
            $evidenzeCalcolo[] = [
                'valore' => $row['valore'],
                'nome_indicatore' => $row['nome_indicatore'] ?? '',
                'fonte' => 'pubblicata'
            ];
        }

        // Calcolo voto con tutte le evidenze non registrate (queue + pubblicate) e arrotonda al mezzo più vicino
        $positivi = count(array_filter($evidenzeCalcolo, fn($e) => ($e['valore'] ?? '') === '+'));
        $totEvidenze = count($evidenzeCalcolo);
        $percentualePositivi = $totEvidenze > 0 ? ($positivi / $totEvidenze) : 0.5;
        $votoProposto = (($percentualePositivi - 0.5) * 4) + 6;
        $votoProposto = max(0, min(10, $votoProposto));
        $votoProposto = round($votoProposto * 2) / 2; // arrotonda al mezzo voto più vicino

        // Descrizione completa per Registro/Email con TUTTE le evidenze considerate
        $righeDescrizione = [];
        foreach ($evidenzeCalcolo as $evCalc) {
            $righeDescrizione[] = ($evCalc['valore'] ?? '') . ' ' . ($evCalc['nome_indicatore'] ?? '');
        }
        $descrizione = "Voto derivato dai più e meno assegnati in laboratorio e registrati come annotazioni sul registro. Le evidenze sono:\n" . implode("\n", $righeDescrizione);

        // Marca come registrate le evidenze pubblicate (non registrate)
        foreach ($pubblicate as $pubRow) {
            if (!empty($pubRow['id_valutazione'])) {
                $dbAdapter->updateRow('VALUTAZIONI_LABORATORIO', 'id_valutazione', $pubRow['id_valutazione'], [
                    'data_registrazione' => $now
                ]);
            }
        }

        // Salva il voto laboratorio nel sistema (pubblicazione da Gestione Voti)
        // Usa la descrizione dettagliata (tutte le evidenze considerate)
        if (empty($descrizioneEvidenze)) {
            $descrizioneEvidenze = $descrizione;
        }

        // Genera ID tracciabilità  per identificare voto come inserito dalla piattaforma
        $votoId = 'VOTO_' . uniqid();
        // Note: marker tracciabilità  + descrizione evidenze completa
        // Inserisci nel campo note anche la descrizione completa cosÃ¬ viene riportata sul RE
        $notes = $descrizione . "\n<" . $votoId . ">";

        $linkOrigine = app_url(
            'public/laboratorio_griglia.php?id_uda=' . urlencode((string)$udaId)
            . '&id_classe_cv=' . urlencode((string)$idClasseCV)
            . '&id_materia_cv=' . urlencode((string)$idMateriaCV)
        );
        $resultCV = null;

        // Registra il voto anche nella tabella VOTI per visualizzazione in uda_grades.php
        $votoPayload = [
            'id_voto' => $votoId,
            'id_uda' => $udaId,
            'id_studente' => $idStudInternal !== '' ? $idStudInternal : null,
            'id_studente_cv' => $idStudInternal !== '' ? null : $idStud,
            'id_gruppo' => $groupId,
            'voto' => $votoProposto,
            'tipo_voto' => 'pratico',
            'descrizione' => $descrizione,
            'data_valutazione' => date('Y-m-d'),
            'pubblicato' => 0,
            'link_origine' => $linkOrigine
        ];
        $dbAdapter->insertRow('VOTI', $votoPayload);

        echo json_encode([
            'ok' => true,
            'migrate' => $migrateCount,
            'cv' => $resultCV ?? [],
            'voto_id' => $votoId
        ]);
        exit;
    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => 'Errore: ' . $e->getMessage()]);
        exit;
    }
}

// ============================================================
// ACTION: pubblica tutte le evidenze +/- come annotazioni per questa UDA/classe/materia
// ============================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'pubblica_plusminus') {
    header('Content-Type: application/json');
    try {
        if ($cvAuthError !== null) {
            echo json_encode(['ok' => false, 'error' => 'Autenticazione ClasseViva fallita: ' . $cvAuthError]);
            exit;
        }
        if (!$udaId || !$idClasseCV || !$idMateriaCV) {
            echo json_encode(['ok' => false, 'error' => 'Parametri mancanti (UDA/classe/materia)']);
            exit;
        }
        if (!ProviderCapabilityResolver::supportsCvForPair($dbAdapter, $userId, (string)$idClasseCV, (string)$idMateriaCV, 'publish_grade')) {
            echo json_encode(['ok' => false, 'error' => 'La classe non è collegata a un gruppo con ClasseViva.']);
            exit;
        }

        $queue = $dbAdapter->findWhere('PLUSMINUS_QUEUE', [
            'id_uda' => $udaId,
            'id_gruppo' => $groupId,
        ]);
        $queue = array_filter($queue, function($ev) {
            return ($ev['registrato'] ?? 0) == 0;
        });

        if (empty($queue)) {
            echo json_encode(['ok' => true, 'registrate' => 0, 'msg' => 'Nessuna evidenza da pubblicare']);
            exit;
        }

        // Recupera nome materia (tollerante a errori API)
        $subjectName = 'Materia';
        try {
            $subjects = $cvAPI->getSubjects();
            foreach ($subjects as $subj) {
                if (($subj['id'] ?? '') == $idMateriaCV) {
                    $subjectName = $subj['nome'] ?? $subj['description'] ?? 'Materia';
                    break;
                }
            }
        } catch (Exception $e) {
            $subjectName = 'Materia';
        }

        // Raggruppa per studente
        $gruppi = [];
        foreach ($queue as $ev) {
            $stud = $ev['id_studente_cv'] ?? '';
            if (!isset($gruppi[$stud])) {
                $gruppi[$stud] = [];
            }
            $gruppi[$stud][] = $ev;
        }

        $registrate = 0;
        $errors = [];
        $summary = [];
        $now = date('Y-m-d H:i:s');

        foreach ($gruppi as $studenteId => $evidenze) {
            try {
                $positivi = 0; $negativi = 0; $commenti = [];
                foreach ($evidenze as $ev) {
                    if (($ev['valore'] ?? '') === '+') $positivi++; else $negativi++;
                    if (!empty($ev['commento'])) $commenti[] = trim($ev['commento']);
                }
                $tot = count($evidenze);
                $testo = "Laboratorio UDA {$udaId}: {$positivi}+ / {$negativi}- (tot: {$tot})";
                if (!empty($commenti)) {
                    $testo .= "\\nNote: " . implode('; ', array_slice($commenti, 0, 3));
                }

                // Tipo e colore coerente con prevalenza di +/-, usato da publishAnnotationWeb
                $tipoAnnotazione = 'neutral';
                if ($positivi > $negativi) $tipoAnnotazione = 'positive';
                if ($negativi > $positivi) $tipoAnnotazione = 'negative';
                $valorePmGlobale = ($positivi >= $negativi) ? '+' : '-';

                $annotationData = [
                    'student_id' => $studenteId,
                    'class_id' => $idClasseCV,
                    'subject_id' => $idMateriaCV,
                    'subject_name' => $subjectName,
                    'text' => $testo,
                    'date' => date('Y-m-d'),
                    'type' => $tipoAnnotazione,
                    'valore_pm' => $valorePmGlobale,
                    'visible_to_student' => true
                ];

                $idAnnot = null;
                try {
                    // Preferisci la via web se disponibile (più permissiva)
                    $result = $cvAPI->publishAnnotationWeb($annotationData);
                    $idAnnot = $result['id'] ?? ($result['annotation_id'] ?? null);
                } catch (Exception $eWeb) {
                    // Fallback REST
                    try {
                        $result = $cvAPI->publishAnnotation($annotationData);
                        $idAnnot = $result['id'] ?? ($result['annotation_id'] ?? null);
                    } catch (Exception $eRest) {
                        // Se falliscono entrambe, logga ma prosegui
                        error_log("Annotazione non pubblicata su ClasseViva per studente {$studenteId}: WEB=" . $eWeb->getMessage() . " REST=" . $eRest->getMessage());
                        $idAnnot = null;
                    }
                }

                foreach ($evidenze as $ev) {
                    if (!empty($ev['id_evidenza'])) {
                        $valId = 'VL_' . uniqid();
                        $valTag = "<VAL_{$valId}>";
                        $commentoVal = trim(($ev['commento'] ?? '') . ' ' . $valTag);
                        $valNum = (($ev['valore'] ?? '') === '+') ? 1 : -1;
                        // Traccia su VALUTAZIONI_LABORATORIO (storico)
                        $dbAdapter->insertRow('VALUTAZIONI_LABORATORIO', [
                            'id_valutazione' => $valId,
                            'id_uda' => $udaId,
                            'id_gruppo' => $groupId,
                            'id_studente_cv' => $studenteId,
                            'id_indicatore' => $ev['id_indicatore'] ?? '',
                            'nome_indicatore' => $ev['nome_indicatore'] ?? '',
                            'valore' => $valNum,
                            'data_inserimento' => $ev['data_inserimento'] ?? $now,
                            'data_registrazione' => null,
                            'id_annotazione_cv' => $idAnnot,
                            'commento' => $commentoVal,
                            'prof' => ($config['user_profile']['prof_name'] ?? ($ev['prof'] ?? ($username ?? ''))),
                            'data_pubblicazione' => $now,
                            'pubblicato' => 1
                        ]);
                        // Rimuovi dalla coda
                        $dbAdapter->deleteRow('PLUSMINUS_QUEUE', $ev['id_evidenza'], 'id_evidenza');
                        $registrate++;
                    }
                }
                $summary[] = "Studente {$studenteId}: {$positivi}+ / {$negativi}- (tot {$tot})" . ($idAnnot ? " - annotazione ID {$idAnnot}" : " - annotazione non inviata");
            } catch (Exception $ex) {
                // Logga ma non bloccare l'esecuzione
                error_log("Errore pubblicazione evidenze studente {$studenteId}: " . $ex->getMessage());
            }
        }

        echo json_encode(['ok' => true, 'registrate' => $registrate, 'errors' => []]);
        exit;

    } catch (Exception $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// Inizializzazione difensiva (evita warning se una query fallisce)
$categorie = [];
$indicatori = [];
$studenti = [];
$votiCoda = [];
$votiPubblicati = [];
$votiRegistrati = [];
$pesiIndicatori = [];
$udas = [];
$classi = [];
$statisticheClassi = [];

try {
    // ============================================
    // STEP 1: CARICA CATEGORIE (7 righe)
    // ============================================
    $categorieRaw = $dbAdapter->findAll('CATEGORIE_COMPETENZE');
    $categorie = [];
    foreach ($categorieRaw as $cat) {
        $categorie[$cat['id_categoria']] = $cat; // Array associativo per lookup O(1)
    }

    // ============================================
    // STEP 2: CARICA INDICATORI (8 righe) + JOIN categorie + FILTRA ATTIVI + ORDINA
    // ============================================
    $indicatoriRaw = $dbAdapter->findAll('INDICATORI_LABORATORIO');
    $indicatori = [];
    foreach ($indicatoriRaw as $ind) {
        // FILTRO: Solo indicatori attivi
        if (isset($ind['attivo']) && $ind['attivo'] != 1) {
            continue; // Salta indicatori non attivi
        }

        // Join con categoria in memoria
        if (isset($categorie[$ind['id_categoria']])) {
            $ind['categoria_nome'] = $categorie[$ind['id_categoria']]['nome'];
            $ind['categoria_colore'] = $categorie[$ind['id_categoria']]['colore_hex'];
        } else {
            $ind['categoria_nome'] = 'Generale';
            $ind['categoria_colore'] = '6c757d';
        }
        $indicatori[] = $ind;
    }

    // ORDINA per colonna 'ordine'
    usort($indicatori, function($a, $b) {
        $ordineA = isset($a['ordine']) ? (int)$a['ordine'] : 999;
        $ordineB = isset($b['ordine']) ? (int)$b['ordine'] : 999;
        return $ordineA <=> $ordineB;
    });

    // ============================================
    // STEP 3: CARICA STUDENTI CLASSE (da API)
    // ============================================
    $studenti = [];
    if ($idClasseCV) {
        if (!$cvReady) {
            $error = $error ?? null;
        } else {
            try {
                $studenti = $studentiManager->getStudentiClasse($idClasseCV);
            } catch (Exception $e) {
                $error = "Impossibile caricare studenti: " . $e->getMessage();
            }
        }
    }
    if (empty($studenti) && $groupId) {
        foreach ($groupStudentRepo->listForGroup((string)$groupId) as $membership) {
            $internalId = trim((string)($membership['id_studente'] ?? ''));
            if ($internalId === '') {
                continue;
            }
            $displayName = '';
            foreach ($studentIdentityRepo->listForStudent($internalId) as $identity) {
                $metadata = json_decode((string)($identity['metadata_json'] ?? '{}'), true);
                if (is_array($metadata)) {
                    $displayName = trim((string)($metadata['display_name'] ?? ($metadata['name'] ?? '')));
                }
                if ($displayName !== '') {
                    break;
                }
            }
            $studenti[] = [
                'id_studente' => $internalId,
                'id' => $internalId,
                'nome_completo' => $displayName !== '' ? $displayName : $internalId,
                'cognome' => '',
                'nome' => $displayName !== '' ? $displayName : $internalId,
            ];
        }
    }

    // ============================================
    // STEP 4: CARICA VOTI IN CODA (max 50 righe)
    // ============================================
    $votiCodaRaw = [];
    if ($idClasseCV && $idMateriaCV && $udaId) {
        $votiCodaRaw = $dbAdapter->findWhere('PLUSMINUS_QUEUE', [
            'id_gruppo' => $groupId,
            'id_uda' => $udaId
        ]);
    }

    // Organizza voti in coda per studente+indicatore
    // Struttura: $votiCoda[id_studente][id_indicatore] = ['valore' => '+', 'data' => '...', 'in_coda' => true]
    $votiCoda = [];
    foreach ($votiCodaRaw as $voto) {
        $idStud = $voto['id_studente'] ?? $voto['id_studente_cv'] ?? '';
        $idInd = $voto['id_indicatore'];
        $dataInserimento = $voto['data_inserimento'] ?? '';

        // Controlla se è ancora modificabile (< 2h e non registrato)
        // NOTA: Mostriamo TUTTI i voti non registrati, anche se > 2h (in attesa del cron)
        $isModificabile = ($dataInserimento >= $duehOraFa) && (($voto['registrato'] ?? 0) == 0);
        $isNonRegistrato = (($voto['registrato'] ?? 0) == 0); // Mostra se non registrato, indipendentemente da 2h

        if (!isset($votiCoda[$idStud])) {
            $votiCoda[$idStud] = [];
        }

        if (!isset($votiCoda[$idStud][$idInd])) {
            $votiCoda[$idStud][$idInd] = [];
        }

        $votiCoda[$idStud][$idInd][] = [
            'valore' => $voto['valore'],
            'data' => $dataInserimento,
            'in_coda' => $isNonRegistrato,  // Mostra tutti i non registrati
            'modificabile' => $isModificabile,  // Modificabile solo se < 2h
            'id_evidenza' => $voto['id_evidenza']
        ];
    }

    // ============================================
    // STEP 5: CARICA VOTI REGISTRATI (tutte le storiche)
    // ============================================
    $votiRegistratiRaw = [];
    if ($idClasseCV && $idMateriaCV && $udaId) {
        // Controlla se foglio esiste
        try {
            $votiRegistratiRaw = $dbAdapter->findWhere('VALUTAZIONI_LABORATORIO', [
                'id_gruppo' => $groupId,
                'id_uda' => $udaId
            ]);
        } catch (Exception $e) {
            // Foglio non esiste ancora, ignora
            $votiRegistratiRaw = [];
        }
    }

    // Organizza voti da VALUTAZIONI_LABORATORIO per studente+indicatore
    // Separa in due categorie:
    // - Pubblicati: hanno data_inserimento ma NON data_registrazione (pubblicati come annotazioni)
    // - Registrati: hanno data_registrazione (trasformati in voti)
    // Struttura: $votiPubblicati[id_studente][id_indicatore] = [oggetto, ...]
    //            $votiRegistrati[id_studente][id_indicatore] = [oggetto, ...]
    $votiPubblicati = [];
    $votiRegistrati = [];

    foreach ($votiRegistratiRaw as $voto) {
        $idStud = $voto['id_studente'] ?? $voto['id_studente_cv'] ?? '';
        $idInd = $voto['id_indicatore'];

        $votoObj = [
            'valore' => $voto['valore'],
            'data_inserimento' => $voto['data_inserimento'] ?? null,
            'data_pubblicazione' => $voto['data_pubblicazione'] ?? null,
            'data_registrazione' => $voto['data_registrazione'] ?? null,
            'id_evidenza' => $voto['id_evidenza'] ?? null
        ];

        // Se ha data_registrazione → REGISTRATO (già trasformato in voto)
        // Altrimenti → PUBBLICATO (pubblicato come annotazione dal cron)
        if (!empty($voto['data_registrazione'])) {
            if (!isset($votiRegistrati[$idStud])) {
                $votiRegistrati[$idStud] = [];
            }
            if (!isset($votiRegistrati[$idStud][$idInd])) {
                $votiRegistrati[$idStud][$idInd] = [];
            }
            $votiRegistrati[$idStud][$idInd][] = $votoObj;
        } else {
            if (!isset($votiPubblicati[$idStud])) {
                $votiPubblicati[$idStud] = [];
            }
            if (!isset($votiPubblicati[$idStud][$idInd])) {
                $votiPubblicati[$idStud][$idInd] = [];
            }
            $votiPubblicati[$idStud][$idInd][] = $votoObj;
        }
    }

    // ============================================
    // STEP 7: CALCOLO MEDIA / VOTO PROPOSTO PER STUDENTE
    // ============================================
    $pesiIndicatori = [];
    foreach ($indicatori as $ind) {
        $pesiIndicatori[$ind['id_indicatore']] = isset($ind['peso']) ? (float)$ind['peso'] : 1.0;
    }

    $statStudenti = []; // idStud => ['media'=>..., 'voto'=>..., 'peso_tot'=>..., 'peso_plus'=>..., 'peso_minus'=>..., 'count'=>...]
    foreach ($studenti as $stud) {
        $idS = $stud['id_studente'] ?? $stud['id'];
        $pesoPlus = 0.0;
        $pesoMinus = 0.0;
        $totEvidenze = 0;

        // CALCOLO:
        // - Include: voti in coda (PLUSMINUS_QUEUE) + voti pubblicati (VALUTAZIONI_LABORATORIO senza data_registrazione)
        // - Esclude: voti registrati (VALUTAZIONI_LABORATORIO con data_registrazione) perché già usati

        // 1. Voti in coda (PLUSMINUS_QUEUE)
        if (isset($votiCoda[$idS])) {
            foreach ($votiCoda[$idS] as $idInd => $vals) {
                $peso = $pesiIndicatori[$idInd] ?? 1.0;
                foreach ($vals as $vc) {
                    $val = $vc['valore'] ?? '';
                    if ($val === '+') {
                        $pesoPlus += $peso;
                    } elseif ($val === '-') {
                        $pesoMinus += $peso;
                    }
                    $totEvidenze++;
                }
            }
        }

        // 2. Voti pubblicati (VALUTAZIONI_LABORATORIO senza data_registrazione)
        if (isset($votiPubblicati[$idS])) {
            foreach ($votiPubblicati[$idS] as $idInd => $vals) {
                $peso = $pesiIndicatori[$idInd] ?? 1.0;
                foreach ($vals as $vp) {
                    $val = $vp['valore'] ?? '';
                    if ($val === '+') {
                        $pesoPlus += $peso;
                    } elseif ($val === '-') {
                        $pesoMinus += $peso;
                    }
                    $totEvidenze++;
                }
            }
        }

        $pesoTot = $pesoPlus + $pesoMinus;
        $media = $pesoTot > 0 ? ($pesoPlus / $pesoTot) : 0.5; // baseline 0.5
        $voto = (($media - 0.5) * 4) + 6;
        if ($voto < 0) $voto = 0;
        if ($voto > 10) $voto = 10;
        $statStudenti[$idS] = [
            'media' => round($media, 2),
            'voto' => round($voto, 2),
            'peso_tot' => $pesoTot,
            'peso_plus' => $pesoPlus,
            'peso_minus' => $pesoMinus,
            'count' => $totEvidenze
        ];
    }

    // ============================================
    // STEP 6: CARICA UDA E CLASSI (solo per dropdown)
    // ============================================
    $udas = [];
    $classi = [];

    if (!$udaId) {
        $udas = $udaManager->getAllUDAs();
    } else {
        // UDA già selezionata, carica solo quella
        $allUdas = $udaManager->getAllUDAs();
        foreach ($allUdas as $uda) {
            if ($uda->id_uda === $udaId) {
                $udas = [$uda];
                break;
            }
        }
    }

    if ($udaId) {
        $classiAssegnate = $dbAdapter->findWhere('CLASSI_ASSEGNATE', ['id_uda' => $udaId]);
        foreach ($classiAssegnate as $ca) {
            $key = ($ca['id_classe'] ?? '') . '|' . ($ca['id_materia_cv'] ?? '');
            if (!isset($classi[$key])) {
                $classi[$key] = [
                    'id_classe_cv' => $ca['id_classe'] ?? '',
                    'id_materia_cv' => $ca['id_materia_cv'] ?? '',
                    'nome_classe' => $ca['nome_classe'] ?? '',
                    'nome_materia' => $ca['nome_materia'] ?? ''
                ];
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratorio - Griglia Classe</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* Container griglia con overflow */
        .griglia-container {
            overflow-x: auto;
            overflow-y: auto;
            max-height: calc(100vh - 250px); /* Altezza dinamica basata su viewport */
            position: relative;
        }

        /* Griglia ottimizzata */
        .griglia-laboratorio {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }

        .griglia-laboratorio th {
            position: sticky;
            top: 0;
            background: white;
            z-index: 20;
            border: 1px solid #dee2e6;
            padding: 8px;
            vertical-align: top;
        }

        .griglia-laboratorio td {
            border: 1px solid #dee2e6;
            padding: 6px;
            text-align: center;
            vertical-align: middle;
            min-width: 120px;
        }

        .griglia-laboratorio .studente-col {
            position: sticky;
            left: 0;
            background: white;
            z-index: 15;
            font-weight: bold;
            text-align: left;
            min-width: 150px;
        }

        /* Cella angolo (studente nell'header): sticky sia orizzontale che verticale */
        .griglia-laboratorio th.studente-col {
            z-index: 30;
        }

        /* Header indicatore */
        .ind-header {
            writing-mode: horizontal-tb;
            font-size: 0.75rem;
            line-height: 1.2;
        }

        .ind-nome {
            font-weight: bold;
            display: block;
            margin-bottom: 4px;
        }

        .ind-categoria {
            font-size: 0.7rem;
            opacity: 0.8;
            display: block;
        }

        .ind-descrizione {
            font-size: 0.65rem;
            font-style: italic;
            opacity: 0.7;
            display: block;
            margin-top: 8px;
            padding-top: 6px;
            border-top: 1px solid rgba(0,0,0,0.1);
            line-height: 1.3;
            max-width: 200px;
            text-align: left;
        }

        /* Cella voto */
        .voti-cella {
            display: flex;
            flex-direction: column;
            gap: 4px;
            align-items: center;
        }

        .voti-registrati {
            display: flex;
            gap: 2px;
            flex-wrap: wrap;
            justify-content: center;
            min-height: 20px;
        }

        /* Voti registrati (già usati per voti precedenti) - senza bordo */
        .voto-registrato {
            color: #999;
            font-size: 0.9rem;
            opacity: 0.6;
            cursor: pointer;
            padding: 2px 4px;
        }

        .voto-registrato:hover {
            opacity: 1;
            background: #f8f9fa;
            border-radius: 3px;
        }

        /* Voti in coda (modificabili < 2h) - bordo verde/rosso */
        .voto-coda {
            border: 2px solid;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
            background: #fff;
            cursor: pointer;
        }

        .voto-coda.positivo {
            color: #198754;
            border-color: #198754;
            background: #d1e7dd;
        }

        .voto-coda.negativo {
            color: #dc3545;
            border-color: #dc3545;
            background: #f8d7da;
        }

        /* Voti pubblicati (> 2h, in attesa registrazione) - bordo grigio */
        .voto-pubblicato {
            border: 2px solid #6c757d;
            padding: 2px 4px;
            border-radius: 3px;
            font-weight: bold;
            background: #e9ecef;
            cursor: pointer;
        }

        .voto-pubblicato.positivo {
            color: #0d5a2e;
        }

        .voto-pubblicato.negativo {
            color: #8b1c1c;
        }

        /* Pulsanti +/- */
        .btn-voto {
            width: 35px;
            height: 35px;
            padding: 0;
            font-size: 18px;
            font-weight: bold;
            border: 2px solid;
            border-radius: 4px;
            cursor: pointer;
            background: white;
        }

        .btn-voto.btn-plus {
            color: #198754;
            border-color: #198754;
        }

        .btn-voto.btn-minus {
            color: #dc3545;
            border-color: #dc3545;
        }

        .btn-voto:hover {
            opacity: 0.7;
        }

        .btn-voto.active {
            color: white;
        }

        .btn-voto.btn-plus.active {
            background: #198754;
        }

        .btn-voto.btn-minus.active {
            background: #dc3545;
        }

        .pulsanti-voto {
            display: flex;
            gap: 4px;
            justify-content: center;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-plus-slash-minus"></i> Laboratorio PiuOMeno - Griglia Classe';
    $headerActions = '<a href="index.php" class="btn btn-outline-light btn-sm"><i class="bi bi-house"></i> Dashboard</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
    <div class="container-fluid mt-4">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
            </div>
        <?php endif; ?>

        <!-- Info Sistema -->
        <div class="alert alert-info">
            <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Come Funziona</h6>
            <ul class="mb-0 small">
                <li><strong>Grigi:</strong> Voti già registrati (oltre 2 ore)</li>
                <li><strong>Riquadrati:</strong> Voti in coda (modificabili per 2 ore)</li>
                <li><strong>Click +/-:</strong> Inserisci nuovo voto in coda</li>
                <li><strong>Dopo 2 ore:</strong> I voti in coda vengono automaticamente registrati</li>
            </ul>
        </div>

        <!-- Selezione -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> Selezione UDA e Classe</h5>
            </div>
            <div class="card-body">
                <form method="GET" action="">
                    <div class="row g-3">
                        <!-- UDA -->
                        <div class="col-md-4">
                            <label class="form-label">UDA</label>
                            <select name="id_uda" class="form-select" required onchange="this.form.submit()">
                                <option value="">-- Seleziona UDA --</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                            <?= $uda->id_uda === $udaId ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($uda->titolo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Classe + Materia -->
                        <?php if (!empty($classi)): ?>
                            <div class="col-md-4">
                                <label class="form-label">Classe + Materia</label>
                                <select name="classe_materia" class="form-select" required onchange="aggiornaClasseMateria(this)">
                                    <option value="">-- Seleziona --</option>
                                    <?php foreach ($classi as $key => $cm): ?>
                                        <?php
                                        $selected = ($cm['id_classe_cv'] === $idClasseCV && $cm['id_materia_cv'] === $idMateriaCV) ? 'selected' : '';
                                        ?>
                                        <option value="<?= htmlspecialchars($key) ?>" <?= $selected ?>
                                                data-classe="<?= htmlspecialchars($cm['id_classe_cv']) ?>"
                                                data-materia="<?= htmlspecialchars($cm['id_materia_cv']) ?>">
                                            <?= htmlspecialchars($cm['nome_classe']) ?> - <?= htmlspecialchars($cm['nome_materia']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <input type="hidden" name="id_classe_cv" id="id_classe_cv" value="<?= htmlspecialchars($idClasseCV) ?>">
                                <input type="hidden" name="id_materia_cv" id="id_materia_cv" value="<?= htmlspecialchars($idMateriaCV) ?>">
                            </div>
                        <?php endif; ?>

                        <?php if ($idClasseCV && $idMateriaCV): ?>
                            <div class="col-md-4 d-flex align-items-end gap-2">
                                <button type="button" class="btn btn-success" onclick="location.reload()">
                                    <i class="bi bi-arrow-clockwise"></i> Aggiorna
                                </button>
                                <button type="button" class="btn btn-info" id="toggle-descrizioni-btn" onclick="toggleDescrizioni()">
                                    <i class="bi bi-info-circle"></i> <span id="toggle-text">Mostra Descrizioni</span>
                                </button>
                                <a class="btn btn-outline-primary" href="uda_grades.php?id=<?= htmlspecialchars((string)$udaId) ?>">
                                    <i class="bi bi-eye"></i> Visualizza voti assegnati
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>

        <!-- Griglia -->
        <?php if ($idClasseCV && $idMateriaCV && $udaId && !empty($studenti) && !empty($indicatori)): ?>
            <div class="card">
                <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0">
                        <i class="bi bi-table"></i>
                        Griglia Valutazioni - <?= count($studenti) ?> Studenti × <?= count($indicatori) ?> Indicatori
                    </h5>
                    <button class="btn btn-light btn-sm" id="btnForzaPubblicazione" onclick="forzaPubblicazioneAnnotazioni()">
                        <i class="bi bi-lightning-charge"></i> Forza Pubblicazione Annotazioni
                    </button>
                </div>
                <div class="card-body p-0 griglia-container">
                    <table class="griglia-laboratorio">
                            <thead>
                                <tr>
                                    <th class="studente-col">Studente</th>
                                    <?php foreach ($indicatori as $ind): ?>
                                        <th class="ind-header" style="background-color: #<?= htmlspecialchars($ind['categoria_colore']) ?>22;">
                                            <span class="ind-nome"><?= htmlspecialchars($ind['nome']) ?></span>
                                            <span class="ind-categoria"><?= htmlspecialchars($ind['categoria_nome']) ?></span>
                                            <?php if (!empty($ind['descrizione'])): ?>
                                                <span class="ind-descrizione" style="display: none;">
                                                    <?= htmlspecialchars($ind['descrizione']) ?>
                                                </span>
                                            <?php endif; ?>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Media</th>
                                    <th class="text-center">Voto proposto</th>
                                    <th class="text-center">
                                        <div class="d-flex flex-column align-items-center gap-1">
                                            <div>Azioni</div>
                                            <button type="button" class="btn btn-outline-primary btn-sm" id="btnSaveAllVotes">
                                                <i class="bi bi-save"></i> Salva tutti i voti
                                            </button>
                                            <div class="d-flex align-items-center gap-1 small">
                                                <span>con almeno</span>
                                                <select id="minEvidenceSelect" class="form-select form-select-sm w-auto">
                                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                                        <option value="<?= $i ?>" <?= $i === 2 ? 'selected' : '' ?>><?= $i ?></option>
                                                    <?php endfor; ?>
                                                </select>
                                                <span>voti</span>
                                            </div>
                                        </div>
                                    </th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studenti as $studente): ?>
                                    <?php $idStud = $studente['id']; ?>
                                    <tr>
                                        <td class="studente-col">
                                            <?= htmlspecialchars($studente['nome_completo'] ?? ($studente['cognome'] . ' ' . $studente['nome'])) ?>
                                        </td>
                                        <?php foreach ($indicatori as $ind): ?>
                                            <?php
                                            $idInd = $ind['id_indicatore'];

                                            // Tre stati:
                                            // 1. In coda (PLUSMINUS_QUEUE) → bordo verde/rosso
                                            $inCoda = $votiCoda[$idStud][$idInd] ?? [];

                                            // 2. Pubblicati (VALUTAZIONI_LABORATORIO senza data_registrazione) → bordo grigio
                                            $pubblicati = $votiPubblicati[$idStud][$idInd] ?? [];

                                            // 3. Registrati (VALUTAZIONI_LABORATORIO con data_registrazione) → senza bordo, grigio
                                            $registrati = $votiRegistrati[$idStud][$idInd] ?? [];

                                            // Trova ultimo voto in coda MODIFICABILE (< 2h)
                                            $ultimoCodaModificabile = null;
                                            foreach ($inCoda as $vc) {
                                                if ($vc['in_coda'] && $vc['modificabile']) {
                                                    $ultimoCodaModificabile = $vc;
                                                    break; // Prendi il più recente modificabile
                                                }
                                            }
                                            ?>
                                            <td style="background-color: #<?= htmlspecialchars($ind['categoria_colore']) ?>11;">
                                                <div class="voti-cella">
                                                    <div class="voti-registrati">
                                                        <!-- 1. VOTI IN CODA (PLUSMINUS_QUEUE) - Bordo verde/rosso -->
                                                        <?php foreach ($inCoda as $vc): ?>
                                                            <?php if ($vc['in_coda']): ?>
                                                                <?php
                                                                    $dataVoto = $vc['data'] ?? '';
                                                                    $dataFormattata = $dataVoto ? date('d/m/Y H:i', strtotime($dataVoto)) : '';
                                                                    $colorClass = $vc['valore'] === '+' ? 'positivo' : 'negativo';
                                                                    $tooltipText = "IN CODA (non ancora pubblicato)\nInserito il: $dataFormattata";
                                                                ?>
                                                                <span class="voto-coda <?= $colorClass ?>"
                                                                      title="<?= htmlspecialchars($tooltipText) ?>"
                                                                      data-valore="<?= htmlspecialchars($vc['valore']) ?>"
                                                                      data-data="<?= htmlspecialchars($dataFormattata) ?>"
                                                                      onclick="alert('Voto: <?= $vc['valore'] ?>\n\nStato: IN CODA (non ancora pubblicato)\n\nInserito il: <?= $dataFormattata ?>')">
                                                                    <?= htmlspecialchars($vc['valore']) ?>
                                                                </span>
                                                            <?php endif; ?>
                                                        <?php endforeach; ?>

                                                        <!-- 2. VOTI PUBBLICATI (VALUTAZIONI_LABORATORIO senza data_registrazione) - Bordo grigio -->
                                                        <?php foreach ($pubblicati as $votoPub): ?>
                                                            <?php
                                                                $dataIns = $votoPub['data_inserimento'] ? date('d/m/Y H:i', strtotime($votoPub['data_inserimento'])) : 'N/D';
                                                                $dataPub = $votoPub['data_pubblicazione'] ? date('d/m/Y H:i', strtotime($votoPub['data_pubblicazione'])) : 'N/D';
                                                                $colorClass = $votoPub['valore'] === '+' ? 'positivo' : 'negativo';
                                                                $tooltipPub = "PUBBLICATO (annotazione su RE)\nInserito: $dataIns\nPubblicato: $dataPub";
                                                            ?>
                                                            <span class="voto-pubblicato <?= $colorClass ?>"
                                                                  title="<?= htmlspecialchars($tooltipPub) ?>"
                                                                  onclick="alert('Voto: <?= $votoPub['valore'] ?>\n\nStato: PUBBLICATO come annotazione sul Registro Elettronico\n\nInserito il: <?= $dataIns ?>\nPubblicato il: <?= $dataPub ?>')">
                                                                <?= htmlspecialchars($votoPub['valore']) ?>
                                                            </span>
                                                        <?php endforeach; ?>

                                                        <!-- 3. VOTI REGISTRATI (VALUTAZIONI_LABORATORIO con data_registrazione) - Senza bordo, grigio -->
                                                        <?php foreach ($registrati as $votoReg): ?>
                                                            <?php
                                                                $dataIns = $votoReg['data_inserimento'] ? date('d/m/Y H:i', strtotime($votoReg['data_inserimento'])) : 'N/D';
                                                                $dataPub = $votoReg['data_pubblicazione'] ? date('d/m/Y H:i', strtotime($votoReg['data_pubblicazione'])) : 'N/D';
                                                                $dataReg = $votoReg['data_registrazione'] ? date('d/m/Y H:i', strtotime($votoReg['data_registrazione'])) : 'N/D';
                                                                $tooltipReg = "REGISTRATO (già usato per voto)\nInserito: $dataIns\nPubblicato: $dataPub\nRegistrato: $dataReg";
                                                            ?>
                                                            <span class="voto-registrato"
                                                                  title="<?= htmlspecialchars($tooltipReg) ?>"
                                                                  onclick="alert('Voto: <?= $votoReg['valore'] ?>\n\nStato: REGISTRATO (già trasformato in voto)\n\nInserito il: <?= $dataIns ?>\nPubblicato il: <?= $dataPub ?>\nRegistrato il: <?= $dataReg ?>')">
                                                                <?= htmlspecialchars($votoReg['valore']) ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                    </div>

                                                    <!-- Pulsanti +/- -->
                                                    <div class="pulsanti-voto">
                                                        <button class="btn-voto btn-plus <?= ($ultimoCodaModificabile && $ultimoCodaModificabile['valore'] === '+') ? 'active' : '' ?>"
                                                                data-studente="<?= htmlspecialchars($idStud) ?>"
                                                                data-indicatore="<?= htmlspecialchars($idInd) ?>"
                                                                data-nome="<?= htmlspecialchars($ind['nome']) ?>"
                                                                data-valore="+"
                                                                title="Inserisci +">
                                                            +
                                                        </button>
                                                        <button class="btn-voto btn-minus <?= ($ultimoCodaModificabile && $ultimoCodaModificabile['valore'] === '-') ? 'active' : '' ?>"
                                                                data-studente="<?= htmlspecialchars($idStud) ?>"
                                                                data-indicatore="<?= htmlspecialchars($idInd) ?>"
                                                                data-nome="<?= htmlspecialchars($ind['nome']) ?>"
                                                                data-valore="-"
                                                                title="Inserisci -">
                                                            −
                                                        </button>
                                                    </div>
                                                </div>
                                            </td>
                                        <?php endforeach; ?>
                                        <td class="text-center">
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars($statStudenti[$idStud]['media'] ?? '0.00') ?>
                                            </span>
                                            <div class="small text-muted">
                                                <?= ($statStudenti[$idStud]['peso_plus'] ?? 0) ?> / <?= ($statStudenti[$idStud]['peso_tot'] ?? 0) ?>
                                            </div>
                                        </td>
                                        <td class="text-center">
                                            <span class="badge bg-info text-dark">
                                                <?= htmlspecialchars($statStudenti[$idStud]['voto'] ?? '0.00') ?>
                                            </span>
                                        </td>
                                        <td class="text-center">
                                            <div class="d-grid gap-2">
                                                <?php $evidenzeCount = (int)($statStudenti[$idStud]['count'] ?? 0); ?>
                                                <button class="btn btn-success btn-sm btn-pubblica"
                                                        data-studente="<?= htmlspecialchars($idStud) ?>"
                                                        data-voto="<?= htmlspecialchars($statStudenti[$idStud]['voto'] ?? 0) ?>"
                                                        data-evidenze="<?= $evidenzeCount ?>"
                                                        <?= $evidenzeCount > 0 ? '' : 'disabled' ?>>
                                                    <i class="bi bi-save"></i> Salva voto
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                </div>
            </div>

            <!-- Statistiche -->
            <div class="card mt-3">
                <div class="card-body">
                    <h6><i class="bi bi-bar-chart"></i> Statistiche Rapide</h6>
                    <div class="row">
                        <div class="col-md-4">
                            <strong>Voti in coda (modificabili):</strong>
                            <?php
                            $countCoda = 0;
                            foreach ($votiCoda as $stud => $inds) {
                                foreach ($inds as $ind => $voti) {
                                    foreach ($voti as $v) {
                                        if ($v['in_coda']) $countCoda++;
                                    }
                                }
                            }
                            echo $countCoda;
                            ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Voti registrati:</strong>
                            <?php
                            $countRegistrati = 0;
                            foreach ($votiRegistrati as $stud => $inds) {
                                foreach ($inds as $ind => $voti) {
                                    $countRegistrati += count($voti);
                                }
                            }
                            echo $countRegistrati;
                            ?>
                        </div>
                        <div class="col-md-4">
                            <strong>Totale voti:</strong>
                            <?= $countCoda + $countRegistrati ?>
                        </div>
                    </div>
                </div>
            </div>

        <?php elseif ($idClasseCV && $idMateriaCV && $udaId): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Nessun dato disponibile. Verifica che ci siano studenti nella classe e indicatori configurati.
            </div>
        <?php else: ?>
            <div class="alert alert-info">
                <i class="bi bi-arrow-up"></i>
                Seleziona UDA, Classe e Materia per visualizzare la griglia di valutazione.
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Commento (opzionale) -->
    <div id="comment-modal" style="display: none;">
        <div id="comment-modal-content">
            <h5>Aggiungi Commento (Opzionale)</h5>
            <textarea id="comment-input" class="form-control" rows="3" placeholder="Es: L'alunno gioca con il telefono"></textarea>
            <div class="mt-3">
                <button class="btn btn-primary" onclick="salvaConCommento()">Salva</button>
                <button class="btn btn-secondary" onclick="chiudiModal()">Annulla</button>
            </div>
        </div>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Dati correnti
        const udaId = <?= json_encode($udaId) ?>;
        const idClasseCV = <?= json_encode($idClasseCV) ?>;
        const idMateriaCV = <?= json_encode($idMateriaCV) ?>;
        const username = <?= json_encode($username) ?>;

        // Pesi degli indicatori (per calcolo voto)
        const pesiIndicatori = <?= json_encode($pesiIndicatori) ?>;

        let pendingVoto = null;

        /**
         * Ricalcola il voto di uno studente basandosi sui voti visibili nella sua riga
         * IMPORTANTE: Esclude i voti registrati (già usati per voti precedenti)
         * Calcola solo con voti in coda (.voto-coda) e pubblicati (.voto-pubblicato)
         * @param {string} idStudente - ID dello studente
         */
        function ricalcolaVotoStudente(idStudente) {
            // Trova la riga cercando il pulsante con data-studente corrispondente
            const $riga = $(`.btn-pubblica[data-studente="${idStudente}"]`).closest('tr');
            if ($riga.length === 0) {
                console.warn('Riga studente non trovata:', idStudente);
                return;
            }

            let pesoPlus = 0.0;
            let pesoMinus = 0.0;
            let totEvidenze = 0;

            // Scansiona tutte le celle indicatore della riga
            $riga.find('td').not('.studente-col').not(':last-child').not(':nth-last-child(2)').not(':nth-last-child(3)').each(function() {
                const $cella = $(this);
                const $pulsantePlus = $cella.find('.btn-plus');

                if ($pulsantePlus.length === 0) return; // Non è una cella indicatore

                const idIndicatore = $pulsantePlus.data('indicatore');
                const peso = pesiIndicatori[idIndicatore] || 1.0;

                // NOTA: NON contare voti registrati (.voto-registrato) perché già usati

                // Conta voti in coda (modificabili < 2h)
                $cella.find('.voto-coda').each(function() {
                    const valore = $(this).data('valore') || $(this).text().trim();
                    if (valore === '+') {
                        pesoPlus += peso;
                    } else if (valore === '-') {
                        pesoMinus += peso;
                    }
                    totEvidenze++;
                });

                // Conta voti pubblicati (non modificabili > 2h, in attesa registrazione)
                $cella.find('.voto-pubblicato').each(function() {
                    const valore = $(this).data('valore') || $(this).text().trim();
                    if (valore === '+') {
                        pesoPlus += peso;
                    } else if (valore === '-') {
                        pesoMinus += peso;
                    }
                    totEvidenze++;
                });
            });

            const pesoTot = pesoPlus + pesoMinus;
            const media = pesoTot > 0 ? (pesoPlus / pesoTot) : 0.5;
            let voto = ((media - 0.5) * 4) + 6;
            if (voto < 0) voto = 0;
            if (voto > 10) voto = 10;

            // Aggiorna UI
            const $colMedia = $riga.find('td').eq(-3); // Terzultima colonna
            const $colVoto = $riga.find('td').eq(-2);  // Penultima colonna

            $colMedia.find('.badge').text(media.toFixed(2));
            $colMedia.find('.small.text-muted').text(`${pesoPlus.toFixed(1)} / ${pesoTot.toFixed(1)}`);

            $colVoto.find('.badge').text(voto.toFixed(2));

            // Aggiorna anche il data-voto del pulsante pubblica
            const $btnPubblica = $riga.find('.btn-pubblica');
            $btnPubblica.data('voto', voto.toFixed(2));
            $btnPubblica.data('evidenze', totEvidenze);
            $btnPubblica.prop('disabled', totEvidenze === 0);

            console.log('✓ Voto ricalcolato per studente', idStudente, ':', {
                media: media.toFixed(2),
                voto: voto.toFixed(2),
                pesoPlus: pesoPlus.toFixed(1),
                pesoTot: pesoTot.toFixed(1),
                totEvidenze
            });
        }

        async function salvaVotoDaPulsante($btn, options = {}) {
            const settings = Object.assign({
                skipConfirm: false,
                reloadAfter: true,
                showAlerts: true
            }, options);

            const stud = $btn.data('studente');
            const voto = $btn.data('voto');
            const evidenzeCount = parseInt($btn.data('evidenze') || 0, 10);
            if (!stud || voto === undefined || evidenzeCount <= 0) {
                return { ok: false, skipped: true };
            }

            // Trova la riga dello studente e il nome
            const $riga = $btn.closest('tr');
            const nomeStudente = $riga.find('td.studente-col').text().trim();

            // Raccoglie tutte le evidenze dello studente (voti registrati + in coda)
            let evidenzeList = [];

            $riga.find('td').not('.studente-col').not(':last-child').not(':nth-last-child(2)').not(':nth-last-child(3)').each(function() {
                const $cella = $(this);
                const $pulsantePlus = $cella.find('.btn-plus');

                if ($pulsantePlus.length === 0) return; // Non è una cella indicatore

                const nomeIndicatore = $pulsantePlus.data('nome');

                // Raccogli voti registrati (grigi)
                $cella.find('.voto-grigio').each(function() {
                    const valore = $(this).text().trim();
                    evidenzeList.push({
                        valore: valore,
                        indicatore: nomeIndicatore,
                        data: null,
                        tipo: 'registrato'
                    });
                });

                // Raccogli voti in coda (evidenziati)
                $cella.find('.voto-coda').each(function() {
                    const valore = $(this).data('valore') || $(this).text().trim();
                    const dataStr = $(this).data('data') || '';
                    evidenzeList.push({
                        valore: valore,
                        indicatore: nomeIndicatore,
                        data: dataStr,
                        tipo: 'coda'
                    });
                });

                // Raccogli voti pubblicati (attesa registrazione)
                $cella.find('.voto-pubblicato').each(function() {
                    const valore = $(this).data('valore') || $(this).text().trim();
                    evidenzeList.push({
                        valore: valore,
                        indicatore: nomeIndicatore,
                        data: null,
                        tipo: 'pubblicato'
                    });
                });
            });

            // Costruisci messaggio dettagliato per popup
            let messaggioEvidenze = '';
            // Costruisci descrizione per ClasseViva (più compatta)
            let descrizioneCV = '';

            if (evidenzeList.length > 0) {
                messaggioEvidenze = '\n\nDettaglio evidenze:\n';
                descrizioneCV = 'Evidenze:\n';

                evidenzeList.forEach(ev => {
                    const dataFormatted = ev.data ? ` (${ev.data})` : '';
                    messaggioEvidenze += `${ev.valore} ${ev.indicatore}${dataFormatted}\n`;
                    descrizioneCV += `${ev.valore} ${ev.indicatore}${dataFormatted}\n`;
                });
            }

            if (!settings.skipConfirm) {
                const messaggioConferma = `Salvare il voto per:\n\nStudente: ${nomeStudente}\nVoto: ${voto}${messaggioEvidenze}\n\nConfermi il salvataggio del voto nel sistema?`;
                if (!confirm(messaggioConferma)) {
                    return { ok: false, skipped: true };
                }
            }
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Salvo...');
            const fd = new FormData();
            fd.append('action', 'pubblica_voto');
            fd.append('uda_id', udaId);
            fd.append('id_uda', udaId);
            fd.append('id_classe_cv', idClasseCV);
            fd.append('id_materia_cv', idMateriaCV);
            fd.append('id_studente_cv', stud);
            fd.append('voto', voto);
            fd.append('descrizione_evidenze', descrizioneCV);
            let result = { ok: false, skipped: false };
            try {
                const response = await fetch(window.location.href, {
                    method: 'POST',
                    body: fd
                });
                const text = await response.text();
                if (!text) {
                    throw new Error('Risposta vuota dal server');
                }
                let json = null;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    console.error('Risposta non JSON ricevuta:', text);
                    throw new Error('Il server ha restituito una risposta non valida. Controlla i log PHP.');
                }
                if (!json.ok) {
                    if (settings.showAlerts) {
                        alert('Errore salvataggio: ' + (json.error || 'sconosciuto'));
                    }
                } else {
                    result.ok = true;
                    if (settings.showAlerts) {
                        alert('Voto salvato. Evidenze migrate: ' + (json.migrate ?? 0));
                    }
                }
            } catch (err) {
                console.error('Errore completo:', err);
                if (settings.showAlerts) {
                    alert('Errore: ' + err.message);
                }
            } finally {
                $btn.prop('disabled', evidenzeCount <= 0).html('<i class="bi bi-save"></i> Salva voto');
                if (settings.reloadAfter && result.ok) {
                    location.reload();
                }
            }
            return result;
        }

        // Salva voto laboratorio nel sistema
        $('.btn-pubblica').click(function () {
            salvaVotoDaPulsante($(this), { skipConfirm: false, reloadAfter: true, showAlerts: true });
        });

        $('#btnSaveAllVotes').click(async function () {
            const minVotes = parseInt($('#minEvidenceSelect').val() || '1', 10);
            const $buttons = $('.btn-pubblica').filter(function() {
                const evidenze = parseInt($(this).data('evidenze') || 0, 10);
                return evidenze >= minVotes;
            });

            if ($buttons.length === 0) {
                alert(`Nessun voto con almeno ${minVotes} evidenze da salvare.`);
                return;
            }

            if (!confirm(`Salvare ${$buttons.length} voti con almeno ${minVotes} evidenze?`)) {
                return;
            }

            const $bulkBtn = $(this);
            $bulkBtn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Salvo...');
            let okCount = 0;
            let errorCount = 0;

            for (const btn of $buttons.toArray()) {
                const result = await salvaVotoDaPulsante($(btn), {
                    skipConfirm: true,
                    reloadAfter: false,
                    showAlerts: false
                });
                if (result.ok) {
                    okCount++;
                } else if (!result.skipped) {
                    errorCount++;
                }
            }

            let message = `Salvati ${okCount} voti.`;
            if (errorCount > 0) {
                message += ` Errori: ${errorCount}.`;
            }
            alert(message);
            location.reload();
        });

        // Click su pulsanti +/-
        $('.btn-voto').click(function(e) {
            e.preventDefault(); // Previeni submit del form
            e.stopPropagation(); // Previeni propagazione evento

            const $btn = $(this);
            const idStudente = $btn.data('studente');
            const idIndicatore = $btn.data('indicatore');
            const nomeIndicatore = $btn.data('nome');
            const valore = $btn.data('valore');

            // VERIFICA: Se il pulsante è già attivo (voto in coda), CANCELLA invece di inserire
            if ($btn.hasClass('active')) {
                cancellaVoto($btn, idStudente, idIndicatore, valore);
                return;
            }

            // VERIFICA: Se c'è già un voto opposto in coda, cancellalo prima
            const $cella = $btn.closest('.voti-cella');
            const valoreOpposto = valore === '+' ? '-' : '+';
            const $btnOpposto = $cella.find('.btn-voto').filter(function() {
                return $(this).data('valore') === valoreOpposto && $(this).hasClass('active');
            });

            if ($btnOpposto.length > 0) {
                // Cancella il voto opposto in background
                cancellaVotoInBackground($btnOpposto, idStudente, idIndicatore, valoreOpposto);
            }

            pendingVoto = {
                id_studente_cv: idStudente,
                id_indicatore: idIndicatore,
                nome_indicatore: nomeIndicatore,
                valore: valore,
                $button: $btn  // Salva riferimento al pulsante
            };

            // CTRL+Click o Click destro = mostra modal commento
            if (e.ctrlKey || e.button === 2) {
                $('#comment-modal').show();
                $('#comment-input').val('').focus();
            } else {
                // Click normale = inserisci direttamente
                inserisciVoto('');
            }
        });

        function inserisciVoto(commento) {
            if (!pendingVoto) return;

            const $btn = pendingVoto.$button;
            const $cella = $btn.closest('.voti-cella');
            const valore = pendingVoto.valore;

            console.log('Inserimento voto:', pendingVoto);

            // FEEDBACK IMMEDIATO: Colora subito il pulsante e aggiungi badge provvisorio
            $btn.addClass('active');
            $btn.prop('disabled', true); // Disabilita durante invio

            // Rimuovi altri pulsanti active nella stessa cella (stesso studente/indicatore)
            $cella.find('.btn-voto').not($btn).removeClass('active');

            // Aggiungi badge provvisorio
            const badgeClass = valore === '+' ? 'positivo' : 'negativo';
            const $tempBadge = $('<span class="voto-coda ' + badgeClass + '" data-temp="true">' + valore + '</span>');
            $cella.find('.voti-registrati').append($tempBadge);

            $.ajax({
                url: 'ajax_inserisci_evidenza.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    id_uda: udaId,
                    id_classe_cv: idClasseCV,
                    id_materia_cv: idMateriaCV,
                    id_studente_cv: pendingVoto.id_studente_cv,
                    id_indicatore: pendingVoto.id_indicatore,
                    nome_indicatore: pendingVoto.nome_indicatore,
                    valore: valore,
                    prof: "<?php echo htmlspecialchars($config['user_profile']['prof_name'] ?? ($_SESSION['username'] ?? 'unknown'), ENT_QUOTES); ?>",
                    commento: commento
                },
                success: function(response) {
                    console.log('Risposta server:', response);
                    if (response.success) {
                        // Successo: Aggiorna badge con dati reali dal server
                        const dataInserimento = response.data.data_inserimento || '';
                        const dataFormattata = new Date(dataInserimento).toLocaleString('it-IT', {
                            day: '2-digit',
                            month: '2-digit',
                            year: 'numeric',
                            hour: '2-digit',
                            minute: '2-digit'
                        });

                        // Rimuovi badge temporaneo e aggiungi quello definitivo
                        $cella.find('.voto-coda[data-temp="true"]').remove();
                        const badgeClass = valore === '+' ? 'positivo' : 'negativo';
                        const tooltipText = 'In coda - Inserito il ' + dataFormattata + ' (modificabile)';
                        const onclickText = "alert('Voto: " + valore + "\\nInserito il: " + dataFormattata + "\\nStato: Modificabile')";

                        const $newBadge = $('<span class="voto-coda ' + badgeClass + '" ' +
                            'title="' + tooltipText + '" ' +
                            'data-valore="' + valore + '" ' +
                            'data-data="' + dataFormattata + '" ' +
                            'onclick="' + onclickText + '">' +
                            valore + '</span>');

                        $cella.find('.voti-registrati').append($newBadge);

                        // RICALCOLA IL VOTO IMMEDIATAMENTE
                        const idStudente = $btn.data('studente');
                        ricalcolaVotoStudente(idStudente);
                    } else {
                        // Errore dal server: rimuovi feedback provvisorio
                        $btn.removeClass('active');
                        $cella.find('.voto-coda[data-temp="true"]').remove();
                        alert('Errore: ' + (response.error || response.message || 'Sconosciuto'));
                    }
                },
                error: function(xhr, status, error) {
                    // Errore AJAX: rimuovi feedback provvisorio
                    $btn.removeClass('active');
                    $cella.find('.voto-coda[data-temp="true"]').remove();
                    console.error('Errore AJAX:', xhr.responseText);
                    alert('Errore di connessione. Il voto non è stato salvato. Riprova.');
                },
                complete: function() {
                    // Riabilita pulsante in ogni caso
                    $btn.prop('disabled', false);
                }
            });

            pendingVoto = null;
        }

        function cancellaVoto($btn, idStudente, idIndicatore, valore) {
            const $cella = $btn.closest('.voti-cella');

            console.log('Cancellazione voto:', { idStudente, idIndicatore, valore });

            // FEEDBACK IMMEDIATO: Rimuovi colore
            $btn.removeClass('active');
            $btn.prop('disabled', true); // Disabilita durante invio

            // Trova l'ultimo badge dello stesso valore per rimuoverlo
            const $badgeDaRimuovere = $cella.find('.voto-coda').filter(function() {
                return $(this).data('valore') === valore && !$(this).data('temp');
            }).last();

            // Salva riferimento per eventuale ripristino
            const badgeHtml = $badgeDaRimuovere.length ? $badgeDaRimuovere[0].outerHTML : null;

            $.ajax({
                url: 'ajax_cancella_evidenza.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    id_studente_cv: idStudente,
                    id_indicatore: idIndicatore,
                    valore: valore
                },
                success: function(response) {
                    console.log('Risposta server (cancella):', response);
                    if (response.success) {
                        // Successo: Rimuovi badge dal DOM DOPO conferma server
                        $badgeDaRimuovere.remove();
                        console.log('✓ Voto cancellato con successo');

                        // RICALCOLA IL VOTO IMMEDIATAMENTE (dopo aver rimosso il badge)
                        ricalcolaVotoStudente(idStudente);
                    } else {
                        // Errore dal server: ripristina pulsante
                        $btn.addClass('active');
                        alert('Errore durante cancellazione: ' + (response.error || 'Sconosciuto'));
                    }
                },
                error: function(xhr, status, error) {
                    // Errore AJAX: ripristina badge e pulsante
                    $btn.addClass('active');
                    if (badgeHtml) {
                        $cella.find('.voti-registrati').append(badgeHtml);
                    }
                    console.error('Errore AJAX (cancella):', xhr.responseText);
                    alert('Errore di connessione. Il voto non è stato cancellato.');
                },
                complete: function() {
                    // Riabilita pulsante
                    $btn.prop('disabled', false);
                }
            });
        }

        // Cancella voto in background (senza feedback visivo pesante, solo rimozione UI)
        function cancellaVotoInBackground($btn, idStudente, idIndicatore, valore) {
            const $cella = $btn.closest('.voti-cella');

            console.log('Cancellazione automatica voto opposto:', { idStudente, idIndicatore, valore });

            // Rimuovi immediatamente pulsante attivo
            $btn.removeClass('active');

            // Trova badge del valore opposto da rimuovere
            const $badgeDaRimuovere = $cella.find('.voto-coda').filter(function() {
                return $(this).data('valore') === valore && !$(this).data('temp');
            }).last();

            // AJAX silenzioso (non mostra errori all'utente, solo log console)
            $.ajax({
                url: 'ajax_cancella_evidenza.php',
                type: 'POST',
                dataType: 'json',
                data: {
                    id_studente_cv: idStudente,
                    id_indicatore: idIndicatore,
                    valore: valore
                },
                success: function(response) {
                    if (response.success) {
                        // Rimuovi badge dal DOM DOPO conferma server
                        $badgeDaRimuovere.remove();
                        console.log('✓ Voto opposto cancellato automaticamente');

                        // RICALCOLA IL VOTO IMMEDIATAMENTE (dopo aver rimosso il badge)
                        ricalcolaVotoStudente(idStudente);
                    } else {
                        console.warn('⚠ Errore cancellazione automatica:', response.error);
                    }
                },
                error: function(xhr, status, error) {
                    console.error('✗ Errore AJAX cancellazione automatica:', error);
                }
            });
        }

        function salvaConCommento() {
            const commento = $('#comment-input').val();
            chiudiModal();
            inserisciVoto(commento);
        }

        function chiudiModal() {
            $('#comment-modal').hide();
        }

        // Gestione select classe+materia
        function aggiornaClasseMateria(sel) {
            const selected = sel.options[sel.selectedIndex];
            if (!selected) return;
            const classe = selected.getAttribute('data-classe') || '';
            const materia = selected.getAttribute('data-materia') || '';
            document.getElementById('id_classe_cv').value = classe;
            document.getElementById('id_materia_cv').value = materia;
            // Subito submit del form per ricaricare tabella con parametri corretti
            sel.form.submit();
        }

        // Previeni menu contestuale sui pulsanti
        $('.btn-voto').on('contextmenu', function(e) {
            e.preventDefault();
            $(this).click();
        });

        // Toggle visibilità descrizioni indicatori
        function toggleDescrizioni() {
            const descrizioni = document.querySelectorAll('.ind-descrizione');
            const toggleText = document.getElementById('toggle-text');

            if (descrizioni.length === 0) return;

            const isHidden = descrizioni[0].style.display === 'none';

            descrizioni.forEach(desc => {
                desc.style.display = isHidden ? 'block' : 'none';
            });

            toggleText.textContent = isHidden ? 'Nascondi Descrizioni' : 'Mostra Descrizioni';
        }

        // Forza pubblicazione annotazioni (chiamata AJAX a questa pagina)
        function forzaPubblicazioneAnnotazioni() {
            if (!confirm('Vuoi forzare la pubblicazione immediata di tutti i +/- come annotazioni sul Registro Elettronico?\n\nNormalmente questo processo avviene automaticamente ogni 30 minuti.')) {
                return;
            }

            const $btn = $('#btnForzaPubblicazione');
            $btn.prop('disabled', true).html('<span class="spinner-border spinner-border-sm"></span> Pubblicazione in corso...');

        const fd = new FormData();
        fd.append('id_uda', udaId);
        fd.append('id_classe_cv', idClasseCV);
        fd.append('id_materia_cv', idMateriaCV);

        fetch('pubblica_plusminus.php', {
            method: 'POST',
            body: fd
        })
            .then(r => r.text())
            .then(text => {
                if (!text) {
                    throw new Error('Risposta vuota dal server');
                }
                let json;
                try {
                    json = JSON.parse(text);
                } catch (e) {
                    console.error('Risposta non JSON ricevuta:', text);
                    throw new Error('Il server ha restituito una risposta non valida. Controlla i log PHP.');
                }
                if (!json.ok) {
                    alert('Errore pubblicazione: ' + (json.error || 'sconosciuto'));
                } else {
                    const errs = (json.errors || []).filter(e => e);
                    alert(`Pubblicazione completata!\n\nEvidenze pubblicate come annotazioni: ${json.published ?? json.registrate ?? 0}${errs.length ? '\\nErrori: ' + errs.join('; ') : ''}`);
                    location.reload();
                }
            })
            .catch(error => {
                console.error('Errore pubblicazione:', error);
                alert('Errore durante la pubblicazione delle annotazioni.\n\nDettagli: ' + error.message);
            })
            .finally(() => {
                $btn.prop('disabled', false).html('<i class="bi bi-lightning-charge"></i> Forza Pubblicazione Annotazioni');
            });
        }
    </script>
</body>
</html>
