<?php
/**
 * Pagina unica: gestione + editing rubriche GitHub (tabella RUBRICA).
 *
 * - Mostra elenco rubriche esistenti (distinct id_rubrica)
 * - Permette di selezionare: Test GitHub / id_rubrica / UDA dai valori presenti nel DB
 * - Permette di creare/modificare la rubrica (una riga per indicatore)
 *
 * Convenzione GitHub: per rubrica "per assegnazione" usa id_rubrica = TEST.id_test.
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

function defaultGitHubRubricRows(string $rubricId, string $idUda): array
{
    $defs = [
        [
            'nome' => 'Progressione e scomposizione del lavoro',
            'descr' => 'Valuta se lo sviluppo è organizzato in passaggi significativi e riconoscibili. Il numero di commit non è un obiettivo in sé: contano distribuzione, granularità e significatività.',
            'l1' => 'La cronologia è dominata da uno o pochi caricamenti finali o da commit molto ampi; non emergono passaggi intermedi riconoscibili.',
            'l2' => 'Sono presenti alcuni passaggi intermedi, ma lo sviluppo è irregolare o la scomposizione del lavoro è solo parziale.',
            'l3' => 'I commit corrispondono generalmente a step logici e permettono di riconoscere una progressione del lavoro.',
            'l4' => 'Lo sviluppo è articolato in micro-obiettivi coerenti; la cronologia mostra una progressione chiara, intenzionale e proporzionata alla complessità del progetto.',
            'peso' => '25',
        ],
        [
            'nome' => 'Verifica tecnica degli avanzamenti',
            'descr' => 'Valuta la capacità di controllare che uno step sia verificabile rispetto all’obiettivo dichiarato.',
            'l1' => 'Numerosi checkpoint risultano non compilabili/non eseguibili o incoerenti con l’obiettivo dichiarato, senza spiegazione.',
            'l2' => 'La verifica è discontinua; alcuni avanzamenti sono instabili o incompleti e solo in parte documentati.',
            'l3' => 'Gli avanzamenti sono generalmente compilabili/eseguibili e vengono effettuati controlli essenziali; eventuali problemi sono riconoscibili.',
            'l4' => 'Ogni checkpoint significativo è verificabile rispetto all’obiettivo dichiarato; test o modalità di verifica sono documentati e gli eventuali stati WIP sono isolati e motivati.',
            'peso' => '20',
        ],
        [
            'nome' => 'Organizzazione dello sviluppo',
            'descr' => 'Valuta la capacità di separare funzionalità, fix o fasi di lavoro quando la complessità del progetto lo richiede. Indicatore da escludere se i branch non sono richiesti dalla consegna.',
            'l1' => 'Quando richiesto, tutto lo sviluppo avviene su main/master con modifiche eterogenee e difficili da isolare.',
            'l2' => 'I branch sono usati in modo occasionale, poco coerente o con nomi poco significativi.',
            'l3' => 'I branch separano le principali funzionalità/fasi e sono generalmente ben gestiti.',
            'l4' => 'Branch e relativa integrazione rispecchiano chiaramente la scomposizione del progetto; nomi, finalità e ciclo di vita risultano coerenti e leggibili.',
            'peso' => '15',
        ],
        [
            'nome' => 'Documentazione e motivazione delle scelte',
            'descr' => 'Valuta se i messaggi di commit rendono comprensibile problema, scelta effettuata, soluzione e verifica.',
            'l1' => 'Messaggi vaghi o puramente descrittivi (fix, modifica, aggiornamento), che non permettono di comprendere il lavoro svolto.',
            'l2' => 'Il messaggio descrive cosa è stato fatto, ma chiarisce poco il problema, la motivazione o la verifica.',
            'l3' => 'Titolo e descrizione rendono comprensibili problema e soluzione, con un livello tecnico adeguato.',
            'l4' => 'Il commit funziona come breve diario tecnico: problema/domanda, soluzione, verifica, eventuali rischi/limiti e fonti utilizzate, compreso l’eventuale supporto di strumenti di IA quando rilevante.',
            'peso' => '20',
        ],
        [
            'nome' => 'Integrazione e collaborazione',
            'descr' => 'Valuta l’affidabilità nel contribuire a un flusso di lavoro condiviso. Indicatore da escludere nelle attività individuali se push/merge non hanno funzione collaborativa.',
            'l1' => 'Push o merge compromettono frequentemente branch condivisi; integrazioni premature o conflitti sono gestiti senza attenzione al lavoro altrui.',
            'l2' => 'Il flusso condiviso è rispettato solo in parte; push e merge sono talvolta disordinati o poco coordinati.',
            'l3' => 'Push e merge avvengono generalmente su modifiche verificate e con una separazione sufficientemente chiara delle attività.',
            'l4' => 'Lo studente integra in modo consapevole e riproducibile: protegge i branch condivisi, gestisce correttamente conflitti e merge e, quando previsto, usa PR/review/issue in modo funzionale alla collaborazione.',
            'peso' => '0',
        ],
        [
            'nome' => 'Revisione e tracciabilità del processo',
            'descr' => 'Valuta se la cronologia permette di ricostruire non solo cosa è stato prodotto, ma come la soluzione è stata corretta, raffinata e migliorata.',
            'l1' => 'La cronologia è confusa o non consente di ricostruire il percorso; correzioni e cambiamenti appaiono opachi.',
            'l2' => 'Il percorso è ricostruibile solo in parte; le correzioni sono visibili ma raramente motivate o collegate ai problemi incontrati.',
            'l3' => 'La cronologia è chiara e permette di comprendere le principali revisioni, correzioni e miglioramenti.',
            'l4' => 'La storia del repository racconta il processo passo-passo: problema/errore → analisi → revisione → verifica. Le modifiche successive mostrano capacità di apprendere dagli errori e migliorare consapevolmente la soluzione.',
            'peso' => '20',
        ],
    ];

    $rows = [];
    foreach ($defs as $idx => $d) {
        $rows[] = [
            'id_rubrica' => $rubricId,
            'id_uda' => $idUda,
            'nome_indicatore' => $d['nome'],
            'descrizione' => $d['descr'],
            'livello_1_desc' => $d['l1'],
            'livello_2_desc' => $d['l2'],
            'livello_3_desc' => $d['l3'],
            'livello_4_desc' => $d['l4'],
            'livello_5_desc' => '',
            'peso' => $d['peso'],
            'ordine' => (string)($idx + 1),
            'note' => 'github_rubric',
            'pubblicato' => '0',
            'data_pubblicazione' => '',
            'id_annotazione_cv' => ''
        ];
    }
    return $rows;
}

function distinctRubriche(array $allRows): array
{
    $rubriche = [];
    foreach ($allRows as $r) {
        $id = (string)($r['id_rubrica'] ?? '');
        if ($id === '') continue;
        if (!isset($rubriche[$id])) {
            $rubriche[$id] = [
                'id_rubrica' => $id,
                'id_uda' => (string)($r['id_uda'] ?? ''),
                'count' => 0,
                'note' => (string)($r['note'] ?? '')
            ];
        }
        $rubriche[$id]['count']++;
    }
    ksort($rubriche);
    return array_values($rubriche);
}

$message = '';
$error = '';

$selectedTestId = trim((string)($_GET['test_id'] ?? $_POST['test_id'] ?? ''));
$selectedRubricId = trim((string)($_GET['id_rubrica'] ?? $_POST['id_rubrica'] ?? ''));
$selectedUdaId = '';

$test = null;
if ($selectedTestId !== '') {
    $test = $dbAdapter->findOne('TEST', 'id_test', $selectedTestId);
    if ($test) {
        $selectedUdaId = (string)($test['id_uda'] ?? '');
        $selectedRubricId = $selectedTestId; // convenzione GitHub
    }
}
if ($selectedTestId === '' && $selectedRubricId !== '') {
    $candidateTest = $dbAdapter->findOne('TEST', 'id_test', $selectedRubricId);
    if ($candidateTest && strtolower((string)($candidateTest['piattaforma'] ?? '')) === 'github') {
        $selectedTestId = $selectedRubricId;
        $test = $candidateTest;
        $selectedUdaId = (string)($candidateTest['id_uda'] ?? '');
    }
}

$action = (string)($_POST['action'] ?? '');

try {
    if ($action === 'delete' && !empty($_POST['id_rubrica'])) {
        $idRubrica = (string)$_POST['id_rubrica'];
        $existing = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $idRubrica]);
        if (empty($existing)) {
            $error = 'Rubrica non trovata.';
        } else {
            $dbAdapter->deleteRow('RUBRICA', $idRubrica, 'id_rubrica');
            $message = 'Rubrica eliminata.';
            if ($selectedRubricId === $idRubrica) {
                $selectedRubricId = '';
                $selectedTestId = '';
            }
        }
    }

    if ($action === 'save') {
        if ($selectedRubricId === '') {
            throw new Exception('Seleziona prima un Test GitHub o una rubrica.');
        }

        $rows = $_POST['rows'] ?? [];
        if (!is_array($rows)) {
            throw new Exception('Dati righe non validi');
        }

        $toInsert = [];
        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            $nome = trim((string)($row['nome_indicatore'] ?? ''));
            if ($nome === '') continue;

            $toInsert[] = [
                'id_rubrica' => $selectedRubricId,
                'id_uda' => $selectedUdaId,
                'nome_indicatore' => $nome,
                'descrizione' => trim((string)($row['descrizione'] ?? '')),
                'livello_1_desc' => trim((string)($row['livello_1_desc'] ?? '')),
                'livello_2_desc' => trim((string)($row['livello_2_desc'] ?? '')),
                'livello_3_desc' => trim((string)($row['livello_3_desc'] ?? '')),
                'livello_4_desc' => trim((string)($row['livello_4_desc'] ?? '')),
                'livello_5_desc' => trim((string)($row['livello_5_desc'] ?? '')),
                'peso' => (string)($row['peso'] ?? '1'),
                'ordine' => (string)($row['ordine'] ?? ''),
                'note' => (string)($row['note'] ?? 'github_rubric'),
                'pubblicato' => (string)($row['pubblicato'] ?? '0'),
                'data_pubblicazione' => (string)($row['data_pubblicazione'] ?? ''),
                'id_annotazione_cv' => (string)($row['id_annotazione_cv'] ?? '')
            ];
        }

        if (empty($toInsert)) {
            throw new Exception('Nessun indicatore valido da salvare');
        }

        // Sostituisci tutte le righe della rubrica
        $dbAdapter->deleteRow('RUBRICA', $selectedRubricId, 'id_rubrica');
        foreach ($toInsert as $r) {
            $dbAdapter->insertRow('RUBRICA', $r);
        }

        header('Location: github_rubriche.php?test_id=' . urlencode($selectedTestId) . '&id_rubrica=' . urlencode($selectedRubricId) . '&saved=1');
        exit;
    }
} catch (Exception $e) {
    $error = $e->getMessage();
}

if (!empty($_GET['saved'])) {
    $message = 'Rubrica salvata.';
}

// Liste da DB (selezionabili)
$allTests = $dbAdapter->findAll('TEST');
$githubTests = array_values(array_filter($allTests, function ($t) {
    return strtolower((string)($t['piattaforma'] ?? '')) === 'github';
}));
usort($githubTests, function ($a, $b) {
    return strcmp((string)($a['nome'] ?? ''), (string)($b['nome'] ?? ''));
});

$rubricaAllRows = $dbAdapter->findAll('RUBRICA');
$rubricheListAll = distinctRubriche($rubricaAllRows);
$githubTestIdSet = [];
foreach ($githubTests as $t) {
    $tid = (string)($t['id_test'] ?? '');
    if ($tid !== '') $githubTestIdSet[$tid] = true;
}

// Questa pagina è dedicata a rubriche GitHub: filtra la lista per evitare rubriche "orali" o di altri contesti.
$rubricheList = array_values(array_filter($rubricheListAll, function ($r) use ($githubTestIdSet) {
    $id = (string)($r['id_rubrica'] ?? '');
    $note = (string)($r['note'] ?? '');
    return ($id !== '' && isset($githubTestIdSet[$id])) || $note === 'github_rubric';
}));

// Se è selezionato un test GitHub, mostra solo la rubrica relativa a quel test.
if ($selectedTestId !== '') {
    $rubricheList = array_values(array_filter($rubricheList, function ($r) use ($selectedTestId) {
        return (string)($r['id_rubrica'] ?? '') === $selectedTestId;
    }));
}

// Carica righe rubrica selezionata (o default)
$editingRows = [];
if ($selectedRubricId !== '') {
    $editingRows = $dbAdapter->findWhere('RUBRICA', ['id_rubrica' => $selectedRubricId]);
    if ($selectedUdaId === '' && !empty($editingRows)) {
        $selectedUdaId = (string)($editingRows[0]['id_uda'] ?? '');
    }
    if (empty($editingRows)) {
        $editingRows = defaultGitHubRubricRows($selectedRubricId, $selectedUdaId);
    }
    usort($editingRows, function ($a, $b) {
        return (int)($a['ordine'] ?? 0) <=> (int)($b['ordine'] ?? 0);
    });
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Rubriche GitHub</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .rubric-editor-table { min-width: 2000px; }
	        .rubric-editor-table td, .rubric-editor-table th { min-height: 140px; vertical-align: top; padding: .25rem .25rem; }
	        .rubric-editor-table textarea.form-control { min-height: 140px; resize: vertical; }
	        .rubric-editor-table textarea.form-control-sm { min-height: 140px; resize: vertical; }
	        .rubric-editor-table textarea.indicatore-text { min-height: 140px; }
	        .rubric-editor-table th.level-col,
	        .rubric-editor-table td.level-col { width: 200px; }
	        .rubric-editor-table th.level5-col,
	        .rubric-editor-table td.level5-col { width: 160px; }
	        .rubric-editor-table input.form-control-sm,
	        .rubric-editor-table select.form-select-sm { padding: .2rem .35rem; }
	        .rubric-list-table td { white-space: nowrap; }
	        .rubric-list-table td.note-cell { white-space: normal; }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-github"></i> Rubriche GitHub';
    $pageSubtitle = 'Seleziona da DB un Test GitHub o una rubrica, poi crea o modifica indicatori.';
    ob_start();
    ?>
    <?php if ($selectedTestId !== ''): ?>
        <a class="btn btn-outline-light btn-sm" href="github_assignment_review.php?test_id=<?= urlencode($selectedTestId) ?>">
            <i class="bi bi-arrow-left"></i> Torna al test
        </a>
    <?php endif; ?>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>


<div class="container mt-4">
<?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>
	    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

	    <div class="card mb-4">
	        <div class="card-header bg-light">
	            <strong>Rubriche esistenti</strong>
	        </div>
	        <div class="card-body">
	            <?php if (empty($rubricheList)): ?>
	                <div class="text-muted">Nessuna rubrica trovata<?= ($selectedTestId !== '') ? ' per questo test.' : '.' ?></div>
	            <?php else: ?>
	                <div class="table-responsive">
	                    <table class="table table-striped align-middle rubric-list-table">
	                        <thead>
	                        <tr>
	                            <th>id_rubrica</th>
	                            <th># indicatori</th>
	                            <th>note</th>
	                            <th class="text-end">azioni</th>
	                        </tr>
	                        </thead>
	                        <tbody>
	                        <?php foreach ($rubricheList as $r): ?>
	                            <tr>
	                                <td class="font-monospace"><?= htmlspecialchars($r['id_rubrica']) ?></td>
	                                <td><?= (int)$r['count'] ?></td>
	                                <td class="note-cell text-muted small"><?= htmlspecialchars($r['note']) ?></td>
	                                <td class="text-end">
	                                    <a class="btn btn-sm btn-outline-primary"
	                                       href="github_rubriche.php?id_rubrica=<?= urlencode($r['id_rubrica']) ?>">
	                                        <i class="bi bi-pencil-square"></i>
	                                    </a>
	                                    <form method="POST" class="d-inline" onsubmit="return confirm('Eliminare la rubrica?')">
	                                        <input type="hidden" name="action" value="delete">
	                                        <input type="hidden" name="id_rubrica" value="<?= htmlspecialchars($r['id_rubrica']) ?>">
	                                        <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
	                                    </form>
	                                </td>
	                            </tr>
	                        <?php endforeach; ?>
	                        </tbody>
	                    </table>
	                </div>
	            <?php endif; ?>
	        </div>
	    </div>
	
	    <div class="card mb-4">
	        <div class="card-header bg-light">
	            <strong>Selezione</strong>
        </div>
        <div class="card-body">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-lg-6">
                    <label class="form-label">Test GitHub</label>
                    <select class="form-select" name="test_id" id="selectTest">
                        <option value="">-- Seleziona test GitHub --</option>
                        <?php foreach ($githubTests as $t): ?>
                            <?php
                            $tid = (string)($t['id_test'] ?? '');
                            $tname = (string)($t['nome'] ?? $tid);
                            $tuda = (string)($t['id_uda'] ?? '');
                            ?>
                            <option value="<?= htmlspecialchars($tid) ?>" <?= ($tid === $selectedTestId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($tname) ?> — <?= htmlspecialchars($tid) ?> — UDA <?= htmlspecialchars($tuda) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text">Se selezioni un test, la rubrica usa automaticamente <code>id_rubrica = test_id</code>.</div>
                </div>
                <div class="col-lg-6">
                    <label class="form-label">Rubrica esistente</label>
                    <select class="form-select" name="id_rubrica" id="selectRubrica">
                        <option value="">-- Seleziona id_rubrica --</option>
                        <?php foreach ($rubricheList as $r): ?>
                            <option value="<?= htmlspecialchars($r['id_rubrica']) ?>" <?= ($r['id_rubrica'] === $selectedRubricId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($r['id_rubrica']) ?> (<?= (int)$r['count'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <button class="btn btn-primary"><i class="bi bi-arrow-repeat"></i> Carica</button>
                    <a class="btn btn-outline-secondary" href="github_rubriche.php"><i class="bi bi-x-circle"></i> Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center">
            <strong>Editor rubrica</strong>
            <?php if ($selectedRubricId !== ''): ?>
                <span class="text-muted small">id_rubrica: <span class="font-monospace"><?= htmlspecialchars($selectedRubricId) ?></span></span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <?php if ($selectedRubricId === ''): ?>
                <div class="text-muted">Seleziona un Test GitHub o una Rubrica per iniziare.</div>
            <?php else: ?>
                <form method="POST">
                    <input type="hidden" name="action" value="save">
                    <input type="hidden" name="test_id" value="<?= htmlspecialchars($selectedTestId) ?>">
                    <input type="hidden" name="id_rubrica" value="<?= htmlspecialchars($selectedRubricId) ?>">

                    <div class="table-responsive">
                        <table class="table table-striped align-top rubric-editor-table">
                            <thead>
                            <tr>
                                <th style="width: 180px;">Indicatore</th>
                                <th style="width: 220px;">Descrizione</th>
	                                <th class="level-col">Livello 1</th>
	                                <th class="level-col">Livello 2</th>
	                                <th class="level-col">Livello 3</th>
	                                <th class="level-col">Livello 4</th>
	                                <th class="level5-col">Livello 5</th>
                                <th style="width: 80px;">Peso</th>
                                <th style="width: 80px;">Ordine</th>
                                <th style="width: 140px;">Pubblicato</th>
                                <th style="width: 220px;">Note</th>
                                <th style="width: 180px;">Annotazione CV</th>
                                <th style="width: 180px;">Data pubbl.</th>
                                <th style="width: 60px;"></th>
                            </tr>
                            </thead>
                            <tbody id="rowsBody">
                            <?php foreach (array_values($editingRows) as $idx => $r): ?>
                                <tr>
                                    <td><textarea class="form-control indicatore-text" name="rows[<?= $idx ?>][nome_indicatore]"><?= htmlspecialchars($r['nome_indicatore'] ?? '') ?></textarea></td>
                                    <td><textarea class="form-control" name="rows[<?= $idx ?>][descrizione]"><?= htmlspecialchars($r['descrizione'] ?? '') ?></textarea></td>
	                                    <td class="level-col"><textarea class="form-control" name="rows[<?= $idx ?>][livello_1_desc]"><?= htmlspecialchars($r['livello_1_desc'] ?? '') ?></textarea></td>
	                                    <td class="level-col"><textarea class="form-control" name="rows[<?= $idx ?>][livello_2_desc]"><?= htmlspecialchars($r['livello_2_desc'] ?? '') ?></textarea></td>
	                                    <td class="level-col"><textarea class="form-control" name="rows[<?= $idx ?>][livello_3_desc]"><?= htmlspecialchars($r['livello_3_desc'] ?? '') ?></textarea></td>
	                                    <td class="level-col"><textarea class="form-control" name="rows[<?= $idx ?>][livello_4_desc]"><?= htmlspecialchars($r['livello_4_desc'] ?? '') ?></textarea></td>
	                                    <td class="level5-col"><textarea class="form-control" name="rows[<?= $idx ?>][livello_5_desc]"><?= htmlspecialchars($r['livello_5_desc'] ?? '') ?></textarea></td>
                                    <td><input class="form-control form-control-sm" name="rows[<?= $idx ?>][peso]" value="<?= htmlspecialchars($r['peso'] ?? '1') ?>"></td>
                                    <td><input class="form-control form-control-sm" name="rows[<?= $idx ?>][ordine]" value="<?= htmlspecialchars($r['ordine'] ?? (string)($idx + 1)) ?>"></td>
                                    <td>
                                        <select class="form-select form-select-sm" name="rows[<?= $idx ?>][pubblicato]">
                                            <option value="0" <?= ((string)($r['pubblicato'] ?? '0') === '0') ? 'selected' : '' ?>>0</option>
                                            <option value="1" <?= ((string)($r['pubblicato'] ?? '0') === '1') ? 'selected' : '' ?>>1</option>
                                        </select>
                                    </td>
                                    <td><input class="form-control form-control-sm" name="rows[<?= $idx ?>][note]" value="<?= htmlspecialchars($r['note'] ?? '') ?>"></td>
                                    <td><input class="form-control form-control-sm" name="rows[<?= $idx ?>][id_annotazione_cv]" value="<?= htmlspecialchars($r['id_annotazione_cv'] ?? '') ?>"></td>
                                    <td><input class="form-control form-control-sm" name="rows[<?= $idx ?>][data_pubblicazione]" value="<?= htmlspecialchars($r['data_pubblicazione'] ?? '') ?>"></td>
                                    <td class="text-end">
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x-lg"></i></button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="button" class="btn btn-outline-primary" id="addRowBtn"><i class="bi bi-plus-lg"></i> Aggiungi indicatore</button>
                        <button type="submit" class="btn btn-success"><i class="bi bi-save"></i> Salva rubrica</button>
                    </div>
                </form>
            <?php endif; ?>
	        </div>
	    </div>
	</div>

<template id="rowTemplate">
	    <tr>
	        <td><textarea class="form-control indicatore-text" name=""></textarea></td>
	        <td><textarea class="form-control" name=""></textarea></td>
	        <td class="level-col"><textarea class="form-control" name=""></textarea></td>
	        <td class="level-col"><textarea class="form-control" name=""></textarea></td>
	        <td class="level-col"><textarea class="form-control" name=""></textarea></td>
	        <td class="level-col"><textarea class="form-control" name=""></textarea></td>
	        <td class="level5-col"><textarea class="form-control" name=""></textarea></td>
	        <td><input class="form-control form-control-sm" name="" value="1"></td>
	        <td><input class="form-control form-control-sm" name="" value=""></td>
	        <td>
	            <select class="form-select form-select-sm" name="">
                <option value="0" selected>0</option>
                <option value="1">1</option>
            </select>
        </td>
        <td><input class="form-control form-control-sm" name="" value="github_rubric"></td>
        <td><input class="form-control form-control-sm" name="" value=""></td>
        <td><input class="form-control form-control-sm" name="" value=""></td>
        <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger remove-row"><i class="bi bi-x-lg"></i></button></td>
    </tr>
</template>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    function reindexRows() {
        const body = document.getElementById('rowsBody');
        if (!body) return;
        const rows = Array.from(body.querySelectorAll('tr'));
        rows.forEach((tr, idx) => {
            const fields = [
                'nome_indicatore','descrizione','livello_1_desc','livello_2_desc','livello_3_desc','livello_4_desc','livello_5_desc',
                'peso','ordine','pubblicato','note','id_annotazione_cv','data_pubblicazione'
            ];
            const inputs = tr.querySelectorAll('input,textarea,select');
            inputs.forEach((el, i) => {
                const field = fields[i];
                if (!field) return;
                el.name = `rows[${idx}][${field}]`;
                if (field === 'ordine' && (!el.value || el.value === '')) {
                    el.value = String(idx + 1);
                }
            });
        });
    }

    const addBtn = document.getElementById('addRowBtn');
    if (addBtn) {
        addBtn.addEventListener('click', function () {
            const tpl = document.getElementById('rowTemplate');
            const body = document.getElementById('rowsBody');
            if (!tpl || !body) return;
            body.appendChild(tpl.content.cloneNode(true));
            reindexRows();
        });
    }

    const body = document.getElementById('rowsBody');
    if (body) {
        body.addEventListener('click', function (e) {
            const btn = e.target.closest('.remove-row');
            if (!btn) return;
            const tr = btn.closest('tr');
            if (tr) tr.remove();
            reindexRows();
        });
        reindexRows();
    }
</script>
</body>
</html>
