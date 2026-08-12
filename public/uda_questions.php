<?php
/**
 * Gestione Domande per Interrogazioni/Compiti
 * Permette di creare domande e generare automaticamente Google Forms
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

// GET parametri
$udaId = $_GET['id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$udaId) {
    die("ID UDA mancante");
}

// Carica UDA
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

// Gestione azioni POST
$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($action) {
            case 'add_question':
                $domandaData = [
                    'id_domanda' => 'DOM_' . uniqid(),
                    'id_uda' => $udaId,
                    'argomento' => $_POST['argomento'] ?? '',
                    'domanda' => $_POST['domanda'] ?? '',
                    'risposta_attesa' => $_POST['risposta_attesa'] ?? '',
                    'parole_chiave' => $_POST['parole_chiave'] ?? '',
                    'difficolta' => $_POST['difficolta'] ?? 3,
                    'tempo_risposta_min' => $_POST['tempo_risposta_min'] ?? 3,
                    'collegata_a' => $_POST['collegata_a'] ?? '',
                    'ordine_consigliato' => $_POST['ordine_consigliato'] ?? 0,
                    'note' => $_POST['note'] ?? ''
                ];

                $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', $domandaData);
                $successMessage = "Domanda aggiunta con successo!";

                // Ricarica domande
                $allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
                $domande = array_filter($allDomande, function($d) use ($udaId) {
                    return ($d['id_uda'] ?? '') === $udaId;
                });
                break;

            case 'update_question':
                $domandaId = $_POST['domanda_id'] ?? null;
                if ($domandaId) {
                    $all = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
                    $existing = null;
                    foreach ($all as $d) {
                        if (($d['id_domanda'] ?? '') === $domandaId) {
                            $existing = $d;
                            break;
                        }
                    }
                    if ($existing) {
                        $existing['argomento'] = $_POST['argomento'] ?? $existing['argomento'];
                        $existing['domanda'] = $_POST['domanda'] ?? $existing['domanda'];
                        $existing['risposta_attesa'] = $_POST['risposta_attesa'] ?? $existing['risposta_attesa'];
                        $existing['parole_chiave'] = $_POST['parole_chiave'] ?? $existing['parole_chiave'];
                        $existing['difficolta'] = $_POST['difficolta'] ?? $existing['difficolta'];
                        $existing['tempo_risposta_min'] = $_POST['tempo_risposta_min'] ?? $existing['tempo_risposta_min'];
                        $existing['collegata_a'] = $_POST['collegata_a'] ?? $existing['collegata_a'];
                        $existing['ordine_consigliato'] = $_POST['ordine_consigliato'] ?? $existing['ordine_consigliato'];
                        $existing['note'] = $_POST['note'] ?? $existing['note'];
                        $dbAdapter->updateRow('DOMANDE_INTERROGAZIONE', 'id_domanda', $domandaId, $existing);
                        $successMessage = "Domanda aggiornata con successo!";
                    }

                    $allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
                    $domande = array_filter($allDomande, function($d) use ($udaId) {
                        return ($d['id_uda'] ?? '') === $udaId;
                    });
                }
                break;

            case 'delete_question':
                $domandaId = $_POST['domanda_id'] ?? null;
                if ($domandaId) {
                    $dbAdapter->deleteRow('DOMANDE_INTERROGAZIONE', $domandaId, 'id_domanda');
                    $successMessage = "Domanda eliminata con successo!";

                    // Ricarica domande
                    $allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
                    $domande = array_filter($allDomande, function($d) use ($udaId) {
                        return ($d['id_uda'] ?? '') === $udaId;
                    });
                }
                break;

            case 'delete_bulk':
                $argomentoFiltro = $_POST['argomento_filtro'] ?? 'tutti';
                $deleted = 0;
                foreach ($domande as $d) {
                    $argOk = ($argomentoFiltro === 'tutti') || (($d['argomento'] ?? 'Generale') === $argomentoFiltro);
                    if ($argOk) {
                        $dbAdapter->deleteRow('DOMANDE_INTERROGAZIONE', $d['id_domanda'], 'id_domanda');
                        $deleted++;
                    }
                }
                $successMessage = $deleted > 0
                    ? "Eliminate {$deleted} domande."
                    : "Nessuna domanda da eliminare.";

                $allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
                $domande = array_filter($allDomande, function($d) use ($udaId) {
                    return ($d['id_uda'] ?? '') === $udaId;
                });
                break;
        }
    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

// Raggruppa domande per argomento
$domandePerArgomento = [];
foreach ($domande as $domanda) {
    $arg = $domanda['argomento'] ?? 'Generale';
    if (!isset($domandePerArgomento[$arg])) {
        $domandePerArgomento[$arg] = [];
    }
    $domandePerArgomento[$arg][] = $domanda;
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
    $text = preg_replace('/^scelta\\s+multipla:\\s*/i', '', $text);
    $parts = preg_split('/\\s*;\\s*/', $text);
    if (count($parts) <= 1 && strpos($text, ',') !== false) {
        $parts = preg_split('/\\s*,\\s*/', $text);
    }
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

function buildMultipleChoicePreview(array $domanda): array
{
    $extra = parseExtraDataForPreview($domanda);
    $items = [];
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
    return $items;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Domande - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .question-card {
            transition: all 0.3s;
            border-left: 4px solid #0dcaf0;
        }
        .question-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .difficulty-1 { border-left-color: #198754; }
        .difficulty-2 { border-left-color: #20c997; }
        .difficulty-3 { border-left-color: #0dcaf0; }
        .difficulty-4 { border-left-color: #ffc107; }
        .difficulty-5 { border-left-color: #dc3545; }

        .keyword-tag {
            font-size: 0.75rem;
            margin: 0.1rem;
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
        .page-actions-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 0.5rem;
            min-width: 600px;
        }
        @media (max-width: 991px) {
            .page-actions-grid {
                grid-template-columns: 1fr;
                min-width: 0;
            }
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-question-circle"></i> Domande per Interrogazioni';
    $pageSubtitle = 'UDA: ' . ($uda->titolo ?? '');

    // Link di navigazione (restano nella barra)
    $headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($udaId) . '"><i class="bi bi-clipboard-check"></i> Test</a>'
        . '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '"><i class="bi bi-arrow-left"></i> Torna alla UDA</a>';

    // Pulsanti azione (vanno in linea con il titolo) - organizzati su 2 righe in griglia
    $pageActions = '<div class="page-actions-grid">'
        // Riga 1: Domande
        . '<a href="import_questions.php?id=' . urlencode($udaId) . '" class="btn btn-info btn-sm text-nowrap"><i class="bi bi-cloud-upload"></i> Importa Domande</a>'
        . '<button class="btn btn-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addQuestionModal"><i class="bi bi-plus-circle"></i> Aggiungi Domanda</button>'
        . '<a href="uda_questions_auto.php?id_uda=' . urlencode($udaId) . '" class="btn btn-outline-secondary btn-sm text-nowrap"><i class="bi bi-magic"></i> Genera Domande (AI)</a>'
        // Riga 2: Genera test
        . '<a href="generate_google_form.php?id=' . urlencode($udaId) . '" class="btn btn-success btn-sm text-nowrap"><i class="bi bi-file-earmark-text"></i> Genera Google Form</a>'
        . '<a href="generate_socrative_quiz.php?id=' . urlencode($udaId) . '" class="btn btn-warning btn-sm text-nowrap"><i class="bi bi-file-earmark-spreadsheet"></i> Genera Socrative</a>'
        . '<a href="generate_kahoot_quiz.php?id=' . urlencode($udaId) . '" class="btn btn-outline-primary btn-sm text-nowrap" style="border-color: #46178f; color: #46178f;"><i class="bi bi-stars"></i> Genera Kahoot</a>'
        . '</div>';

    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">

        <!-- Azioni bulk eliminazione -->
        <div class="d-flex justify-content-end mb-3">
            <form method="POST" class="d-inline" onsubmit="return confirm('Confermi l\\'eliminazione?');">
                <input type="hidden" name="action" value="delete_bulk">
                <div class="input-group" style="max-width: 420px;">
                    <select name="argomento_filtro" class="form-select form-select-sm" title="Seleziona argomento">
                        <option value="tutti">Tutti gli argomenti</option>
                        <?php foreach (array_keys($domandePerArgomento) as $arg): ?>
                            <option value="<?= htmlspecialchars($arg) ?>"><?= htmlspecialchars($arg) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button class="btn btn-outline-danger btn-sm" type="submit">
                        <i class="bi bi-trash"></i> Elimina
                    </button>
                </div>
            </form>
        </div>

        <!-- Messaggi -->
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

        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-list-ol"></i> Totale Domande
                        </h5>
                        <h2><?= count($domande) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-info text-white">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-tags"></i> Argomenti
                        </h5>
                        <h2><?= count($domandePerArgomento) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-star"></i> Facili (1-2)
                        </h5>
                        <h2><?= count(array_filter($domande, fn($d) => ($d['difficolta'] ?? 0) <= 2)) ?></h2>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h5 class="card-title">
                            <i class="bi bi-fire"></i> Difficili (4-5)
                        </h5>
                        <h2><?= count(array_filter($domande, fn($d) => ($d['difficolta'] ?? 0) >= 4)) ?></h2>
                    </div>
                </div>
            </div>
        </div>

        <!-- Domande raggruppate per argomento -->
        <?php if (empty($domandePerArgomento)): ?>
            <div class="alert alert-info">
                <i class="bi bi-info-circle"></i>
                Nessuna domanda trovata. Clicca su "Aggiungi Domanda" per iniziare.
            </div>
        <?php else: ?>
            <?php foreach ($domandePerArgomento as $argomento => $questList): ?>
                <div class="card mb-4">
                    <div class="card-header bg-light">
                        <h4 class="mb-0">
                            <i class="bi bi-folder"></i> <?= htmlspecialchars($argomento) ?>
                            <span class="badge bg-secondary float-end"><?= count($questList) ?> domande</span>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php foreach ($questList as $q):
                            $difficolta = $q['difficolta'] ?? 3;
                            $diffClass = "difficulty-{$difficolta}";
                            $paroleChiave = explode(',', $q['parole_chiave'] ?? '');
                            $tipoDomanda = strtolower($q['tipo_domanda'] ?? ($q['tipo'] ?? 'aperta'));
                            $previewItems = [];
                            if (in_array($tipoDomanda, ['multipla', 'multipla_multi'], true)) {
                                $previewItems = buildMultipleChoicePreview($q);
                            }
                        ?>
                            <div class="card question-card <?= $diffClass ?> mb-3">
                                <div class="card-body">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <h5 class="card-title">
                                                <?= htmlspecialchars($q['domanda']) ?>
                                            </h5>

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
                                            <?php elseif (!empty($q['risposta_attesa'])): ?>
                                                <div class="alert alert-light mt-2 mb-2">
                                                    <strong>Risposta attesa:</strong><br>
                                                    <?= nl2br(htmlspecialchars($q['risposta_attesa'])) ?>
                                                </div>
                                            <?php endif; ?>

                                            <div class="mb-2">
                                                <span class="badge bg-info">
                                                    Difficoltà: <?= $difficolta ?>/5
                                                </span>
                                                <span class="badge bg-warning text-dark">
                                                    <i class="bi bi-clock"></i> <?= $q['tempo_risposta_min'] ?? 3 ?> min
                                                </span>
                                                <?php if (!empty($q['ordine_consigliato'])): ?>
                                                    <span class="badge bg-secondary">
                                                        Ordine: #<?= $q['ordine_consigliato'] ?>
                                                    </span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if (!empty($q['parole_chiave'])): ?>
                                                <div class="mb-2">
                                                    <small class="text-muted">Parole chiave:</small>
                                                    <?php foreach ($paroleChiave as $keyword): ?>
                                                        <span class="badge bg-light text-dark keyword-tag">
                                                            <?= htmlspecialchars(trim($keyword)) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php endif; ?>

                                            <?php if (!empty($q['note'])): ?>
                                                <small class="text-muted">
                                                    <i class="bi bi-info-circle"></i> <?= htmlspecialchars($q['note']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </div>

                                        <div class="ms-3">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button"
                                                        class="btn btn-outline-primary"
                                                        data-bs-toggle="modal"
                                                        data-bs-target="#addQuestionModal"
                                                        data-edit="true"
                                                        data-id="<?= htmlspecialchars($q['id_domanda']) ?>"
                                                        data-argomento="<?= htmlspecialchars($q['argomento'] ?? '') ?>"
                                                        data-domanda="<?= htmlspecialchars($q['domanda'] ?? '') ?>"
                                                        data-risposta="<?= htmlspecialchars($q['risposta_attesa'] ?? '') ?>"
                                                        data-parole="<?= htmlspecialchars($q['parole_chiave'] ?? '') ?>"
                                                        data-difficolta="<?= htmlspecialchars($q['difficolta'] ?? '') ?>"
                                                        data-tempo="<?= htmlspecialchars($q['tempo_risposta_min'] ?? '') ?>"
                                                        data-collegata="<?= htmlspecialchars($q['collegata_a'] ?? '') ?>"
                                                        data-ordine="<?= htmlspecialchars($q['ordine_consigliato'] ?? '') ?>"
                                                        data-note="<?= htmlspecialchars($q['note'] ?? '') ?>">
                                                    <i class="bi bi-pencil"></i>
                                                </button>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Sicuro di voler eliminare questa domanda?');">
                                                    <input type="hidden" name="action" value="delete_question">
                                                    <input type="hidden" name="domanda_id" value="<?= htmlspecialchars($q['id_domanda']) ?>">
                                                    <button type="submit" class="btn btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>

        <!-- Info Card -->
        <div class="card border-info mt-4">
            <div class="card-body">
                <h5 class="card-title">
                    <i class="bi bi-lightbulb"></i> Come Usare le Domande
                </h5>
                <ul class="mb-0">
                    <li><strong>Interrogazioni Orali</strong>: Usa le domande come traccia durante le interrogazioni</li>
                    <li><strong>Compiti in Classe</strong>: Seleziona domande di difficoltà variabile per i compiti scritti</li>
                    <li><strong>Google Forms</strong>: Genera automaticamente un modulo Google con le domande selezionate</li>
                    <li><strong>Ordine Consigliato</strong>: Segui l'ordine suggerito per una progressione didattica logica</li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Modal Aggiungi/Modifica Domanda -->
    <div class="modal fade" id="addQuestionModal" tabindex="-1">
        <div class="modal-dialog modal-xl">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="action" value="add_question" id="question-action">
                    <input type="hidden" name="domanda_id" id="domanda-id">

                    <div class="modal-header">
                        <h5 class="modal-title" id="questionModalTitle">
                            <i class="bi bi-plus-circle"></i> Aggiungi Nuova Domanda
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Argomento -->
                            <div class="col-md-6">
                                <label class="form-label">Argomento *</label>
                                <input type="text" name="argomento" class="form-control" required
                                       placeholder="Es: Scheduling, Processi, Thread..."
                                       list="argomentiList">
                                <datalist id="argomentiList">
                                    <?php foreach (array_keys($domandePerArgomento) as $arg): ?>
                                        <option value="<?= htmlspecialchars($arg) ?>">
                                    <?php endforeach; ?>
                                </datalist>
                            </div>

                            <!-- Difficoltà -->
                            <div class="col-md-3">
                                <label class="form-label">Difficoltà *</label>
                                <select name="difficolta" class="form-select" required>
                                    <option value="1">1 - Molto Facile</option>
                                    <option value="2">2 - Facile</option>
                                    <option value="3" selected>3 - Medio</option>
                                    <option value="4">4 - Difficile</option>
                                    <option value="5">5 - Molto Difficile</option>
                                </select>
                            </div>

                            <!-- Tempo Risposta -->
                            <div class="col-md-3">
                                <label class="form-label">Tempo Risposta (min)</label>
                                <input type="number" name="tempo_risposta_min" class="form-control" value="3" min="1">
                            </div>

                            <!-- Domanda -->
                            <div class="col-12">
                                <label class="form-label">Testo Domanda *</label>
                                <textarea name="domanda" class="form-control" rows="3" required
                                          placeholder="Scrivi qui la domanda..."></textarea>
                            </div>

                            <!-- Risposta Attesa -->
                            <div class="col-12">
                                <label class="form-label">Risposta Attesa</label>
                                <textarea name="risposta_attesa" class="form-control" rows="4"
                                          placeholder="Descrivi la risposta che ti aspetti dallo studente (opzionale ma consigliato)"></textarea>
                                <small class="form-text text-muted">
                                    Aiuta a valutare oggettivamente e condividere criteri con gli studenti
                                </small>
                            </div>

                            <!-- Parole Chiave -->
                            <div class="col-md-8">
                                <label class="form-label">Parole Chiave</label>
                                <input type="text" name="parole_chiave" class="form-control"
                                       placeholder="processo,stati,scheduling (separate da virgola)">
                                <small class="form-text text-muted">
                                    Concetti chiave che devono emergere nella risposta
                                </small>
                            </div>

                            <!-- Ordine Consigliato -->
                            <div class="col-md-4">
                                <label class="form-label">Ordine Consigliato</label>
                                <input type="number" name="ordine_consigliato" class="form-control" value="0" min="0">
                                <small class="form-text text-muted">
                                    0 = nessun ordine specifico
                                </small>
                            </div>

                            <!-- Collegata A -->
                            <div class="col-md-6">
                                <label class="form-label">Collegata a (ID Domanda)</label>
                                <input type="text" name="collegata_a" class="form-control"
                                       placeholder="ID di una domanda correlata">
                            </div>

                            <!-- Note -->
                            <div class="col-md-6">
                                <label class="form-label">Note</label>
                                <input type="text" name="note" class="form-control"
                                       placeholder="Note aggiuntive (opzionali)">
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-save"></i> Salva Domanda
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        const addQuestionModal = document.getElementById('addQuestionModal');
        addQuestionModal.addEventListener('show.bs.modal', function (event) {
            const button = event.relatedTarget;
            const isEdit = button?.getAttribute('data-edit') === 'true';
            const form = addQuestionModal.querySelector('form');
            const actionInput = document.getElementById('question-action');
            const idInput = document.getElementById('domanda-id');

            // campi
            const argomento = form.querySelector('[name=\"argomento\"]');
            const difficolta = form.querySelector('[name=\"difficolta\"]');
            const tempo = form.querySelector('[name=\"tempo_risposta_min\"]');
            const domanda = form.querySelector('[name=\"domanda\"]');
            const risposta = form.querySelector('[name=\"risposta_attesa\"]');
            const parole = form.querySelector('[name=\"parole_chiave\"]');
            const ordine = form.querySelector('[name=\"ordine_consigliato\"]');
            const collegata = form.querySelector('[name=\"collegata_a\"]');
            const note = form.querySelector('[name=\"note\"]');
            const title = document.getElementById('questionModalTitle');

            if (isEdit) {
                actionInput.value = 'update_question';
                idInput.value = button.getAttribute('data-id') || '';
                argomento.value = button.getAttribute('data-argomento') || '';
                difficolta.value = button.getAttribute('data-difficolta') || '3';
                tempo.value = button.getAttribute('data-tempo') || '3';
                domanda.value = button.getAttribute('data-domanda') || '';
                risposta.value = button.getAttribute('data-risposta') || '';
                parole.value = button.getAttribute('data-parole') || '';
                ordine.value = button.getAttribute('data-ordine') || '0';
                collegata.value = button.getAttribute('data-collegata') || '';
                note.value = button.getAttribute('data-note') || '';
                title.innerHTML = '<i class=\"bi bi-pencil\"></i> Modifica Domanda';
            } else {
                actionInput.value = 'add_question';
                idInput.value = '';
                form.reset();
                difficolta.value = '3';
                tempo.value = '3';
                ordine.value = '0';
                title.innerHTML = '<i class=\"bi bi-plus-circle\"></i> Aggiungi Nuova Domanda';
            }
        });
    </script>
</body>
</html>
