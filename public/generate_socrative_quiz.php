<?php
/**
 * Selezione e generazione file Excel per Socrative
 * a partire dalle domande associate a una UDA.
 *
 * Usa il template: templates/socrativeQuizTemplate.xlsx
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\SocrativeQuizBuilder;
use PhpOffice\PhpSpreadsheet\IOFactory;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$udaId = $_GET['id'] ?? null;
$action = $_POST['action'] ?? null;

if (!$udaId) {
    die('ID UDA mancante');
}

$udaComplete = $udaManager->getUDAComplete($udaId);
if (!$udaComplete) {
    die('UDA non trovata');
}

$uda = $udaComplete['uda'];

// Carica domande per l'UDA
$allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
$domande = array_filter($allDomande, function ($d) use ($udaId) {
    return ($d['id_uda'] ?? '') === $udaId;
});

// Ordina per ordine_consigliato se presente
usort($domande, function ($a, $b) {
    $orderA = intval($a['ordine_consigliato'] ?? 999);
    $orderB = intval($b['ordine_consigliato'] ?? 999);
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

$errorMessage = null;
$downloadLink = null;
$createTestUrl = null;

// Gestione esportazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'export_quiz') {
    try {
        $selectedQuestions = $_POST['questions'] ?? [];
        if (empty($selectedQuestions)) {
            throw new Exception('Seleziona almeno una domanda!');
        }

        $domandeSelezionate = [];
        foreach ($domande as $d) {
            if (in_array($d['id_domanda'], $selectedQuestions, true)) {
                $domandeSelezionate[] = $d;
            }
        }

        if (empty($domandeSelezionate)) {
            throw new Exception('Nessuna domanda valida selezionata.');
        }

        $builder = new SocrativeQuizBuilder($config);
        $spreadsheet = $builder->buildSpreadsheet($domandeSelezionate, 'Quiz UDA - ' . $uda->titolo);

        // Salva in public/storage/temp per download via browser
        $tempDir = ROOT_PATH . '/public/storage/temp';
        if (!is_dir($tempDir)) {
            mkdir($tempDir, 0755, true);
        }
        $filenameBase = $udaId . '_socrative_quiz';
        $safeName = preg_replace('/[^A-Za-z0-9_\-]+/', '_', $filenameBase) . '_' . date('Ymd_His') . '.xlsx';
        $tempPath = $tempDir . '/' . $safeName;

        $writer = IOFactory::createWriter($spreadsheet, 'Xlsx');
        $writer->save($tempPath);

        $downloadLink = 'storage/temp/' . $safeName;

        // URL per aprire il modal creazione test con campi precompilati
        $qs = http_build_query([
            'id' => $udaId,
            'new_test_modal' => 1,
            'platform' => 'socrative',
            'prefill_name' => 'Socrative - ' . ($uda->titolo ?? ''),
            'prefill_descr' => 'Quiz Socrative generato per UDA: ' . ($uda->titolo ?? ''),
            'prefill_num_domande' => count($domandeSelezionate),
            'prefill_punteggio_max' => count($domandeSelezionate) * 10,
        ]);
        $createTestUrl = 'uda_tests.php?' . $qs . '#addTestModal';
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Genera Quiz Socrative - <?= htmlspecialchars($uda->titolo) ?></title>
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
            background-color: #fff3cd;
            border-left: 4px solid #ffc107;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-clipboard-check"></i> Genera Quiz Socrative';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a class="nav-link" href="uda_questions.php?id=' . urlencode($udaId) . '"><i class="bi bi-arrow-left"></i> Torna alle Domande</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-4">
        <div class="d-flex justify-content-end align-items-center mb-4">
<?php if (!empty($domande)): ?>
                <div class="btn-group">
                    <button type="submit" form="generateSocrativeForm" class="btn btn-warning">
                        <i class="bi bi-download"></i> Esporta Socrative
                    </button>
                </div>
            <?php endif; ?>
        </div>

<?php if ($errorMessage): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
<?php endif; ?>

<?php if ($downloadLink): ?>
    <div class="alert alert-success">
        <h5><i class="bi bi-check-circle"></i> File generato</h5>
        <p class="mb-2">Scarica il file e importa il quiz su Socrative. Poi, se vuoi tracciare il test nell'UDA, clicca su "Crea test in UDA".</p>
        <div class="d-flex gap-2 flex-wrap">
            <a href="<?= htmlspecialchars($downloadLink) ?>" class="btn btn-success" download>
                <i class="bi bi-download"></i> Scarica file Socrative
            </a>
            <?php if ($createTestUrl): ?>
                <a href="<?= htmlspecialchars($createTestUrl) ?>" class="btn btn-primary">
                    <i class="bi bi-plus-circle"></i> Crea test in UDA
                </a>
            <?php endif; ?>
        </div>
        <ul class="small mt-2 mb-0">
            <li>Carica il file in Socrative per creare il quiz. <a href="https://youtu.be/QIFbkl729PQ?si=SLs-wgWLX6Y0LFJh&t=331" target="_blank">Clicca qui per la video-guida di Daniele Berardino</a></li>
            <li>Ottieni i link Studenti/Docente da Socrative.</li>
            <li>Usa "Crea test in UDA" per inserire i link e finalizzare.</li>
        </ul>
    </div>
<?php endif; ?>

        <?php if (empty($domande)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Non ci sono domande per questa UDA.
                <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="alert-link">Aggiungi prima alcune domande</a>.
            </div>
        <?php else: ?>
            <form method="POST" id="generateSocrativeForm">
                <input type="hidden" name="action" value="export_quiz">
                <input type="hidden" name="create_test" value="1">

                <!-- Selezione Domande -->
                <div class="card mb-4">
                    <div class="card-header bg-warning text-dark">
                        <div class="d-flex justify-content-between align-items-center">
                            <h5 class="mb-0">
                                <i class="bi bi-list-check"></i> Seleziona Domande
                            </h5>
                            <div class="btn-group btn-group-sm" role="group">
                                <button type="button" class="btn btn-light" onclick="selectAllQuestions()">
                                    <i class="bi bi-check-all"></i> Seleziona/Deseleziona Tutte
                                </button>
                                <button type="button" class="btn btn-outline-dark" onclick="selectQuestionsByType('aperta')">
                                    Aperte
                                </button>
                                <button type="button" class="btn btn-outline-dark" onclick="selectQuestionsByType('chiusa')">
                                    Chiuse
                                </button>
                                <button type="button" class="btn btn-outline-dark" onclick="selectQuestionsByType('vero_falso')">
                                    V/F
                                </button>
                            </div>
                        </div>
                    </div>
                    <div class="card-body">
                        <p class="text-muted">
                            Seleziona le domande da includere nel file Socrative. Verrà generato un file Excel compatibile con l'import "Quick Quiz".
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
                                                   id="sq_<?= htmlspecialchars($q['id_domanda']) ?>">
                                            <label class="form-check-label w-100" for="sq_<?= htmlspecialchars($q['id_domanda']) ?>">
                                                <strong><?= htmlspecialchars($q['domanda']) ?></strong>
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
                        <p class="mb-0">
                            Il file includerà solo le domande selezionate.
                        </p>
                    </div>
                </div>

                <!-- Azioni -->
                <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-5">
                    <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                        <i class="bi bi-x-circle"></i> Annulla
                    </a>
                    <button type="submit" class="btn btn-warning btn-lg">
                        <i class="bi bi-download"></i> Esporta Socrative
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
            const el = document.getElementById('selectedCount');
            if (el) {
                el.textContent = count;
            }
        }

        document.addEventListener('DOMContentLoaded', updatePreview);
    </script>
</body>
</html>

