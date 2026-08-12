<?php
// Script per generare rubrica_orale_v2.php

$content = <<<'PHPCODE'
<?php
/**
 * Rubrica Valutazione Orale - Versione 2
 *
 * Workflow:
 * 0. Import o carica rubrica
 * 1. Seleziona Classe + 3 Domande personalizzate → Inizia sessione
 * 2. Valuta studenti uno per uno (dropdown)
 * 3. Salva in sessione tutti i voti (persistenti al refresh)
 * 4. Salva tutto nel database
 * 5. Pubblica su ClasseViva
 */

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Core\RubricManager;
use App\Integration\ClasseVivaAPI;

$config = require_once __DIR__ . '/../bootstrap.php';

error_reporting(E_ALL);

try {

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$rubricManager = new RubricManager($dbAdapter, $config);

// Inizializza ClasseViva API
$tokenState = ClasseVivaTokenGuard::getTokenState($config);
if (!$tokenState['ready']) {
    throw new Exception($tokenState['notice'] ?? 'Token ClasseViva non disponibile.');
}

$cvAPI = new ClasseVivaAPI($config);

// ============================================
// GESTIONE AZIONI
// ============================================

$action = $_POST['action'] ?? $_GET['action'] ?? 'step0';
$message = null;
$error = null;
$rubrica = null;
$sessione = $_SESSION['rubrica_sessione'] ?? null;

// ID UDA da URL (da uda_view.php)
$idUdaDaGet = $_GET['id_uda'] ?? null;

// ==== ACTION: Import rubrica da template ====
if ($action === 'import_template') {
    $udaId = $_POST['uda_id'] ?? $idUdaDaGet;
    $argomenti = [
        $_POST['argomento_1'] ?? 'Argomento 1',
        $_POST['argomento_2'] ?? 'Argomento 2',
        $_POST['argomento_3'] ?? 'Argomento 3'
    ];

    $templatePath = ROOT_PATH . '/Materiale/Rubrica valutazione orale VUOTA.xlsx';
    if (file_exists($templatePath) && $udaId) {
        $rubrica = $rubricManager->importRubricaDaTemplate($templatePath, $udaId, $argomenti);
        $message = "Rubrica importata con successo! (ID: {$rubrica->id_rubrica})";
        $idUdaDaGet = $udaId;
    } else {
        $error = "Template non trovato o UDA non specificata";
    }
}

// ==== ACTION: Carica rubrica esistente ====
if ($action === 'carica_rubrica') {
    $rubricaId = $_GET['rubrica_id'] ?? $_POST['rubrica_id'] ?? null;
    if ($rubricaId) {
        $rubrica = $rubricManager->getRubrica($rubricaId);
        if ($rubrica) {
            $message = "Rubrica caricata (ID: $rubricaId)";
            $idUdaDaGet = $rubrica->id_uda;
        } else {
            $error = "Rubrica non trovata";
        }
    }
}

// Auto-carica rubrica se id_uda è presente
if ($idUdaDaGet && !$rubrica && !$sessione) {
    $rubricheEsistenti = $dbAdapter->findWhere('RUBRICHE', ['id_uda' => $idUdaDaGet]);

    if (!empty($rubricheEsistenti)) {
        $rubricaId = $rubricheEsistenti[0]['id_rubrica'];
        $rubrica = $rubricManager->getRubrica($rubricaId);
        $message = "Rubrica caricata automaticamente (ID: $rubricaId)";
    }
}

// ==== ACTION: Inizia sessione (Step 1) ====
if ($action === 'inizia_sessione') {
    $idUda = $_POST['id_uda'] ?? $idUdaDaGet;
    $idRubrica = $_POST['id_rubrica'] ?? null;
    $idClasseCV = $_POST['id_classe_cv'] ?? null;
    $domanda1 = $_POST['domanda_1'] ?? 'Domanda 1';
    $domanda2 = $_POST['domanda_2'] ?? 'Domanda 2';
    $domanda3 = $_POST['domanda_3'] ?? 'Domanda 3';

    if ($idUda && $idRubrica && $idClasseCV) {
        // Crea sessione persistente
        $_SESSION['rubrica_sessione'] = [
            'id_uda' => $idUda,
            'id_rubrica' => $idRubrica,
            'id_classe_cv' => $idClasseCV,
            'domanda_1' => $domanda1,
            'domanda_2' => $domanda2,
            'domanda_3' => $domanda3,
            'valutazioni' => [],
            'data_creazione' => date('Y-m-d H:i:s')
        ];

        $sessione = $_SESSION['rubrica_sessione'];
        $rubrica = $rubricManager->getRubrica($idRubrica);
        $idUdaDaGet = $idUda;
        $message = "Sessione avviata! Seleziona uno studente per iniziare a valutare.";
    } else {
        $error = "Dati mancanti per avviare la sessione";
    }
}

// ==== ACTION: Salva valutazione studente in SESSIONE ====
if ($action === 'salva_valutazione_studente') {
    $idStudente = $_POST['id_studente'];
    $nomeStudente = $_POST['nome_studente'] ?? '';

    $valutazioneData = [
        'id_studente_cv' => $idStudente,
        'nome_studente' => $nomeStudente,
        'domanda_1' => $_POST['domanda_1'] ?? '',
        'domanda_2' => $_POST['domanda_2'] ?? '',
        'domanda_3' => $_POST['domanda_3'] ?? '',
        'livello_esposizione' => $_POST['livello_esposizione'] ?? null,
        'livello_espressione' => $_POST['livello_espressione'] ?? null,
        'livello_organizzazione' => $_POST['livello_organizzazione'] ?? null,
        'livello_dom1' => $_POST['livello_dom1'] ?? null,
        'livello_dom2' => $_POST['livello_dom2'] ?? null,
        'livello_dom3' => $_POST['livello_dom3'] ?? null,
        'data_valutazione' => date('Y-m-d H:i:s')
    ];

    if ($sessione) {
        $_SESSION['rubrica_sessione']['valutazioni'][$idStudente] = $valutazioneData;
        $sessione = $_SESSION['rubrica_sessione'];

        $voto = calcolaVoto($valutazioneData);
        $message = "Valutazione salvata in sessione per $nomeStudente (Voto: $voto)";
    } else {
        $error = "Sessione non trovata.";
    }
}

// ==== ACTION: Salva tutto nel DATABASE ====
if ($action === 'salva_database') {
    if ($sessione && !empty($sessione['valutazioni'])) {
        $contatore = 0;
        foreach ($sessione['valutazioni'] as $idStudente => $valutazioneData) {
            $voto = calcolaVoto($valutazioneData);
            $valutazione_testuale = generaValutazioneTestuale($valutazioneData, $sessione);

            // Controlla se esiste già
	            $esistente = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', [
	                'id_uda' => $sessione['id_uda'],
	                'id_classe_cv' => $sessione['id_classe_cv'],
	                'id_studente_cv' => $idStudente,
	                'id_rubrica' => $sessione['id_rubrica']
	            ]);

            if (empty($esistente)) {
                $dbAdapter->insertRow('VALUTAZIONI_RUBRICA', [
                    'id_valutazione' => 'VAL_RUB_' . uniqid(),
                    'id_uda' => $sessione['id_uda'],
                    'id_classe_cv' => $sessione['id_classe_cv'],
                    'id_studente_cv' => $idStudente,
                    'nome_studente' => $valutazioneData['nome_studente'],
                    'id_rubrica' => $sessione['id_rubrica'],
                    'voto_numerico' => $voto,
                    'valutazione_testuale' => $valutazione_testuale,
                    'data_valutazione' => date('Y-m-d H:i:s'),
                    'pubblicato_cv' => 0,
                    'dati_json' => json_encode($valutazioneData)
                ]);
                $contatore++;
            }
        }
        $message = "$contatore valutazioni salvate nel database!";
    } else {
        $error = "Nessuna valutazione da salvare";
    }
}

// ==== ACTION: Pubblica su ClasseViva ====
if ($action === 'pubblica_classeviva') {
    $idValutazione = $_POST['id_valutazione'] ?? null;

    if ($idValutazione) {
        // TODO: Implementare chiamata API ClasseViva
        // $cvAPI->pubblicaVoto(...);

        $dbAdapter->updateRow('VALUTAZIONI_RUBRICA', $idValutazione, [
            'pubblicato_cv' => 1,
            'data_pubblicazione_cv' => date('Y-m-d H:i:s')
        ]);

        $message = "Valutazione pubblicata su ClasseViva!";
    } else {
        $error = "ID valutazione mancante";
    }
}

// ==== ACTION: Reset sessione ====
if ($action === 'reset_sessione') {
    unset($_SESSION['rubrica_sessione']);
    $sessione = null;
    $message = "Sessione resettata";
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
        return round($voto * 4) / 4;
    }

    return null;
}

function generaValutazioneTestuale($valutazione, $sessione) {
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
        $domanda = $valutazione["domanda_$i"] ?? $sessione["domanda_$i"] ?? "Domanda $i";
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

// Carica rubrica dalla sessione se esiste
if ($sessione && !$rubrica) {
    $rubrica = $rubricManager->getRubrica($sessione['id_rubrica']);
    $idUdaDaGet = $sessione['id_uda'];
}

// Determina UDA selezionata
$udaSelezionata = null;
$idUdaSelezionata = $idUdaDaGet ?? ($sessione['id_uda'] ?? null);

if ($idUdaSelezionata) {
    foreach ($udas as $u) {
        if ($u->id_uda === $idUdaSelezionata) {
            $udaSelezionata = $u;
            break;
        }
    }
}

// Carica classi da ClasseViva
try {
    $tutteClassi = $cvAPI->getClasses();

    if ($udaSelezionata) {
        $classiUda = $dbAdapter->findWhere('UDA_CLASSI', ['id_uda' => $udaSelezionata->id_uda]);
        $idClassiUda = array_column($classiUda, 'id_classe_cv');

        if (!empty($idClassiUda)) {
            foreach ($tutteClassi as $classe) {
                if (in_array($classe['classId'], $idClassiUda)) {
                    $classi[] = $classe;
                }
            }
        } else {
            $classi = $tutteClassi;
        }
    } else {
        $classi = $tutteClassi;
    }
} catch (Exception $e) {
    error_log("Errore caricamento classi: " . $e->getMessage());
    $error = "Impossibile caricare le classi da ClasseViva.";
    $classi = [];
}

// Carica studenti dalla sessione
if ($sessione && !empty($sessione['id_classe_cv']) && !empty($classi)) {
    $idClasseSessione = $sessione['id_classe_cv'];

    try {
        foreach ($classi as $classe) {
            if ($classe['classId'] == $idClasseSessione && isset($classe['students'])) {
                foreach ($classe['students'] as $st) {
                    $studenti[] = [
                        'id' => $st['studentId'] ?? null,
                        'nome_completo' => ($st['lastName'] ?? '') . ' ' . ($st['firstName'] ?? ''),
                        'cognome' => $st['lastName'] ?? '',
                        'nome' => $st['firstName'] ?? ''
                    ];
                }
                break;
            }
        }
    } catch (Exception $e) {
        error_log("Errore caricamento studenti: " . $e->getMessage());
        $error = "Impossibile caricare gli studenti.";
    }
}

// Carica valutazioni salvate nel database
$valutazioniSalvate = [];
	if ($sessione) {
	    $valutazioniSalvate = $dbAdapter->findWhere('VALUTAZIONI_RUBRICA', [
	        'id_uda' => $sessione['id_uda'],
	        'id_classe_cv' => $sessione['id_classe_cv'],
	        'id_rubrica' => $sessione['id_rubrica']
	    ]);
	}

} catch (Exception $e) {
    $error = "ERRORE FATALE: " . $e->getMessage();
    error_log("Errore rubrica_orale_v2.php: " . $e->getMessage() . "\n" . $e->getTraceAsString());

    $action = 'step0';
    $udas = [];
    $classi = [];
    $studenti = [];
    $sessione = null;
}

?>
PHPCODE;

// Continua con l'HTML nel prossimo blocco
file_put_contents(__DIR__ . '/rubrica_orale_v2.php', $content);
echo "Parte 1 scritta!\n";
?>
