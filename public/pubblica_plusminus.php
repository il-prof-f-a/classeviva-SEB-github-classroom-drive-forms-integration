<?php

/**
 * Pubblica le evidenze +/- come annotazioni su ClasseViva.
 * Puo' essere chiamata:
 *  - via AJAX dalla griglia laboratorio (con filtri id_uda, id_classe_cv, id_materia_cv)
 *  - via cron HTTP senza filtri per processare tutte le evidenze in coda.
 */

error_reporting(E_ALL);

header('Content-Type: application/json');

// Output JSON anche in caso di fatal error
$__pubblica_done = false;
register_shutdown_function(function () use (&$__pubblica_done) {
    if ($__pubblica_done) {
        return;
    }
    $err = error_get_last();
    if ($err) {
        echo json_encode(['ok' => false, 'error' => 'Fatal: ' . $err['message']]);
    }
});

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderCapabilityResolver;
use App\Integration\ClasseVivaAPI;

try {
    $config = require_once __DIR__ . '/../bootstrap.php';

    $cvConfig = $config['classeviva'] ?? [];
    $cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
    $cvTokenValid = $cvConfig['token_valid'] ?? false;
    $cvTokenError = $cvConfig['token_error'] ?? null;
    $cvEnabled = !empty($cvConfig['enabled']);
    $cvHasToken = !empty($cvTokenPayload['token']);
    $cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;

    if (!$cvReady) {
        if (!$cvEnabled) {
            $cvTokenNotice = 'Integrazione ClasseViva disabilitata.';
        } elseif (!$cvHasToken) {
            $cvTokenNotice = 'Token ClasseViva mancante.';
        } else {
            $cvTokenNotice = $cvTokenError
                ? "Token ClasseViva non valido: {$cvTokenError}"
                : 'Token ClasseViva non valido o scaduto.';
        }

        echo json_encode([
            'ok' => false,
            'error' => $cvTokenNotice . ' Autorizza l\'accesso dalle Integrazioni.'
        ]);
        exit;
    }

    $db = DatabaseFactory::createWithInitialization($config, true);
    $cv = new ClasseVivaAPI($config);
    $userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
    $valutazioniColumns = [];
    $valutazioniColumnSet = [];
    try {
        $valutazioniColumns = $db->getColumns('VALUTAZIONI_LABORATORIO');
        $valutazioniColumnSet = array_fill_keys($valutazioniColumns, true);
    } catch (Exception $e) {
        $valutazioniColumnSet = [];
    }
    $filterValutazioniData = function(array $row) use ($valutazioniColumnSet): array {
        if (empty($valutazioniColumnSet)) {
            return $row;
        }
        return array_intersect_key($row, $valutazioniColumnSet);
    };

    // Filtri opzionali
    $fUda = $_POST['id_uda'] ?? $_GET['id_uda'] ?? null;
    $fClasse = $_POST['id_classe_cv'] ?? $_GET['id_classe_cv'] ?? null;
    $fMateria = $_POST['id_materia_cv'] ?? $_GET['id_materia_cv'] ?? null;
    if ($fClasse && $fMateria && !ProviderCapabilityResolver::supportsCvForPair($db, $userId, (string)$fClasse, (string)$fMateria, 'publish_annotation')) {
        echo json_encode(['ok' => false, 'error' => 'La classe non è collegata a un gruppo con ClasseViva.']);
        exit;
    }

    // Estrai evidenze non registrate
    if ($fUda || $fClasse || $fMateria) {
        $filter = ['registrato' => 0];
        if ($fUda) $filter['id_uda'] = $fUda;
        if ($fClasse) $filter['id_classe_cv'] = $fClasse;
        if ($fMateria) $filter['id_materia_cv'] = $fMateria;
        $queue = $db->findWhere('PLUSMINUS_QUEUE', $filter);
    } else {
    $queue = $db->findWhere('PLUSMINUS_QUEUE', ['registrato' => 0]);
}

if (empty($queue)) {
    echo json_encode(['ok' => true, 'processed' => 0, 'published' => 0, 'errors' => [], 'mail' => 'skipped']);
    exit;
}

// Recupera nome materia (cache)
$subjectNames = [];
try {
    $subjects = $cv->getSubjects();
    foreach ($subjects as $subj) {
        $subjectNames[$subj['id']] = $subj['nome'] ?? ($subj['description'] ?? 'Materia');
    }
} catch (Exception $e) {
    // fallback: lasceremo "Materia"
}

// Recupera indicatori e categorie per arricchire descrizione
$indicatoriRaw = $db->findAll('INDICATORI_LABORATORIO');
$categorieRaw = $db->findAll('CATEGORIE_COMPETENZE');
$catMap = [];
foreach ($categorieRaw as $c) {
    $catMap[$c['id_categoria']] = $c['nome'] ?? '';
}
$indMap = [];
foreach ($indicatoriRaw as $ind) {
    $indMap[$ind['id_indicatore']] = [
        'nome' => $ind['nome'] ?? '',
        'descrizione' => $ind['descrizione'] ?? '',
        'categoria' => $catMap[$ind['id_categoria'] ?? ''] ?? ''
    ];
}

$processed = 0;
$published = 0;
$errors = [];
$mailLines = [];
$now = date('Y-m-d H:i:s');

// Cache titoli UDA
$udaTitleCache = [];
$getUdaTitle = function ($id) use ($db, &$udaTitleCache) {
    if (!$id) return null;
    if (isset($udaTitleCache[$id])) return $udaTitleCache[$id];
    try {
        $row = $db->findOne('UDA_ANAGRAFICA', 'id_uda', $id);
        $title = $row['titolo'] ?? $row['titolo_uda'] ?? null;
        $udaTitleCache[$id] = $title;
        return $title;
    } catch (Exception $e) {
        return null;
    }
};

foreach ($queue as $ev) {
        $processed++;
        $idEvid = $ev['id_evidenza'] ?? null;
        $idStud = $ev['id_studente_cv'] ?? '';
        $idCls = $ev['id_classe_cv'] ?? '';
    $idMat = $ev['id_materia_cv'] ?? '';
    $idUda = $ev['id_uda'] ?? '';
    $idGruppoInternal = trim((string)($ev['id_gruppo'] ?? ''));
    $idStudenteInternal = trim((string)($ev['id_studente'] ?? ''));
    $idUtenteEvidenza = trim((string)($ev['id_utente'] ?? '')) ?: 'system';
    $val = $ev['valore'] ?? '';
    $indInfo = $indMap[$ev['id_indicatore'] ?? ''] ?? null;
    $indNome = $indInfo['nome'] ?? ($ev['nome_indicatore'] ?? ($ev['id_indicatore'] ?? 'Indicatore'));
    $indDesc = $indInfo['descrizione'] ?? '';
    $indCat = $indInfo['categoria'] ?? '';
    $commento = trim($ev['commento'] ?? '');
    // Nome docente: prima config profilo utente, poi valore presente sull'evidenza
    $profProfile = $config['user_profile']['prof_name'] ?? null;
    $prof = $profProfile ?: ($ev['prof'] ?? '');
    $dataIns = $ev['data_inserimento'] ?? $now;

    $subjectName = $subjectNames[$idMat] ?? 'Materia';

    // Genera ID valutazione/annotazione subito
    $valId = 'VL_' . uniqid();
    $valTag = "<VAL_{$valId}>";

    $udaLabel = $ev['uda_title'] ?? ($ev['titolo_uda'] ?? $getUdaTitle($idUda) ?? $idUda);
    $testo = "Laboratorio UDA {$udaLabel} <{$valId}>\n(" . $indCat . ") " . $indNome . ": " . $indDesc . "\nVoto: " . ($val === '+' ? '+' : '-') . ($commento ? "\nCommento: {$commento}" : '') . "\nProf: {$prof}\nData: {$dataIns}";

    $type = 'neutral';
    if ($val === '+') $type = 'positive';
        if ($val === '-') $type = 'negative';

        $annotationData = [
            'student_id' => $idStud,
            'class_id' => $idCls,
            'subject_id' => $idMat,
            'subject_name' => $subjectName,
            'text' => $testo,
            'date' => date('Y-m-d'),
            'type' => $type,
            'valore_pm' => $val,
            'visible_to_student' => true
        ];

    // Inserisci subito la valutazione in bozza (pubblicato=0)
    $commentoVal = trim($commento . ' ' . $valTag);
    $insertOk = $db->insertRow('VALUTAZIONI_LABORATORIO', $filterValutazioniData([
        'id_valutazione' => $valId,
        'id_uda' => $idUda,
        'id_gruppo' => $idGruppoInternal,
        'id_studente' => $idStudenteInternal,
        'id_indicatore' => $ev['id_indicatore'] ?? '',
        'nome_indicatore' => $indNome,
        'valore' => $val,
        'data_inserimento' => $dataIns,
        'data_registrazione' => null,
        'id_annotazione_cv' => null,
        'commento' => $commentoVal,
        'prof' => $prof,
        'id_utente' => $idUtenteEvidenza
    ]));
    if (!$insertOk) {
        $errors[] = "Studente {$idStud}: errore inserimento VALUTAZIONI_LABORATORIO";
        continue;
    }

    $idAnnot = null;
    $publishSuccess = false;
    $softReason = null;
    try {
        // prova web, poi REST
        $resWeb = $cv->publishAnnotationWeb($annotationData);
        $idAnnot = $resWeb['id'] ?? ($resWeb['annotation_id'] ?? null);
        $publishSuccess = true;
    } catch (Exception $exWeb) {
        $msg = strtolower($exWeb->getMessage() ?? '');
        // Alcuni endpoint rispondono 405 ma l'annotazione risulta comunque salvata lato RE
        if (str_contains($msg, '405') || str_contains($msg, 'method not allowed') || str_contains($msg, 'nessun endpoint disponibile')) {
            $publishSuccess = true;
            $softReason = $exWeb->getMessage();
        }
        try {
            $res = $cv->publishAnnotation($annotationData);
            $idAnnot = $res['id'] ?? ($res['annotation_id'] ?? null);
            $publishSuccess = true;
        } catch (Exception $exRest) {
            $msg2 = strtolower($exRest->getMessage() ?? '');
            if (str_contains($msg2, '405') || str_contains($msg2, 'method not allowed') || str_contains($msg2, 'nessun endpoint disponibile')) {
                $publishSuccess = true;
                $softReason = $softReason ?: $exRest->getMessage();
            } else {
                $errors[] = "Studente {$idStud}: " . $exRest->getMessage();
            }
        }
    }

    if ($publishSuccess) {
        // Aggiorna la bozza come pubblicata, aggiungendo ID annotazione e data
        $db->updateRow('VALUTAZIONI_LABORATORIO', 'id_valutazione', $valId, $filterValutazioniData([
            'id_annotazione_cv' => $idAnnot,
            'id_utente' => $idUtenteEvidenza
        ]));

        // Rimuovi dalla coda solo se pubblicato
        if ($idEvid) {
            $db->deleteRow('PLUSMINUS_QUEUE', $idEvid, 'id_evidenza');
        }

        $mailLines[] = "Studente {$idStud} | {$val} | (" . $indCat . ") " . $indNome . " - " . $indDesc . " | Data: {$dataIns}" . ($commento ? " | Note: {$commento}" : "") . ($idAnnot ? " | Annotazione ID: {$idAnnot}" : "") . ($softReason ? " | Soft: {$softReason}" : "");
        $published++;
    } else {
        // Rollback: elimina la bozza inserita
        $db->deleteRow('VALUTAZIONI_LABORATORIO', $valId, 'id_valutazione');
    }
}

echo json_encode([
    'ok' => true,
    'processed' => $processed,
    'published' => $published,
    'errors' => $errors
]);
$__pubblica_done = true;
exit;

} catch (Exception $e) {
    echo json_encode(['ok' => false, 'error' => \App\Core\Security\PublicError::message($e, 'pubblica_plusminus')]);
    $__pubblica_done = true;
    exit;
}









