<?php
/**
 * Gestione Obiettivi Didattici
 *
 * Permette di:
 * - Visualizzare obiettivi esistenti
 * - Aggiungere nuovi obiettivi disciplinari e trasversali
 * - Modificare obiettivi esistenti
 * - Eliminare obiettivi
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;

// Inizializza adapter e manager
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);

// Gestisci azioni
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'add_obiettivo':
                $idUda = $_POST['id_uda'] ?? '';
                $tipoObiettivo = $_POST['tipo_obiettivo'] ?? 'disciplinare';
                $codice = $_POST['codice'] ?? '';
                $descrizione = $_POST['descrizione'] ?? '';
                $competenza = $_POST['competenza'] ?? '';
                $livelloTassonomia = $_POST['livello_tassonomia'] ?? '';
                $peso = $_POST['peso'] ?? '';

                if (empty($descrizione)) {
                    throw new Exception("La descrizione è obbligatoria");
                }

                $obiettivoId = 'OBJ_' . uniqid();
                $dbAdapter->insertRow('OBIETTIVI', [
                    'id_obiettivo' => $obiettivoId,
                    'id_uda' => $idUda,
                    'tipo_obiettivo' => $tipoObiettivo,
                    'codice' => $codice,
                    'descrizione' => $descrizione,
                    'competenza' => $competenza,
                    'livello_tassonomia' => $livelloTassonomia,
                    'peso' => $peso,
                    'raggiunto' => 'NO'
                ]);

                $message = "Obiettivo aggiunto con successo!";
                $messageType = "success";
                break;

            case 'delete':
                $obiettivoId = $_POST['obiettivo_id'] ?? '';
                $dbAdapter->deleteRow('OBIETTIVI', $obiettivoId, 'id_obiettivo');

                $message = "Obiettivo eliminato con successo!";
                $messageType = "success";
                break;
        }
    } catch (Exception $e) {
        $message = "Errore: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Recupera tutte le UDA
$udas = $udaManager->getAllUDAs();

// Recupera tutti gli obiettivi
$allObiettivi = $dbAdapter->findAll('OBIETTIVI');

// Filtri
$selectedUdaId = $_GET['uda_id'] ?? '';
$filterTipo = $_GET['tipo'] ?? '';
$filterBloom = $_GET['bloom'] ?? '';
$filterSearch = trim((string)($_GET['q'] ?? ''));
$filterCompetenza = trim((string)($_GET['competenza'] ?? ''));

// Valori distinti per filtri
$tipiDistinct = [];
$livelliDistinct = [];
$competenzeDistinct = [];
foreach ($allObiettivi as $o) {
    if (!empty($o['tipo_obiettivo'])) {
        $tipiDistinct[$o['tipo_obiettivo']] = true;
    }
    if (($o['livello_tassonomia'] ?? '') !== '') {
        $livelliDistinct[(string)$o['livello_tassonomia']] = true;
    }
    if (!empty($o['competenza'])) {
        $competenzeDistinct[$o['competenza']] = true;
    }
}
ksort($tipiDistinct);
ksort($livelliDistinct);
ksort($competenzeDistinct);

// Applica filtri
$obiettivi = array_filter($allObiettivi, function($o) use ($selectedUdaId, $filterTipo, $filterBloom, $filterSearch, $filterCompetenza) {
    if ($selectedUdaId && ($o['id_uda'] ?? '') !== $selectedUdaId) {
        return false;
    }
    if ($filterTipo && ($o['tipo_obiettivo'] ?? '') !== $filterTipo) {
        return false;
    }
    if ($filterBloom !== '' && (string)($o['livello_tassonomia'] ?? '') !== (string)$filterBloom) {
        return false;
    }
    if ($filterCompetenza !== '' && stripos((string)($o['competenza'] ?? ''), $filterCompetenza) === false) {
        return false;
    }
    if ($filterSearch !== '') {
        $haystack = (string)($o['descrizione'] ?? '') . ' ' . (string)($o['codice'] ?? '') . ' ' . (string)($o['competenza'] ?? '');
        if (stripos($haystack, $filterSearch) === false) {
            return false;
        }
    }
    return true;
});

// Raggruppa per tipo
$obiettiviPerTipo = [];
foreach ($obiettivi as $obj) {
    $tipo = $obj['tipo_obiettivo'] ?? 'altro';
    $obiettiviPerTipo[$tipo][] = $obj;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Obiettivi Didattici - UDA System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .obiettivo-card {
            transition: transform 0.2s, box-shadow 0.2s;
            border-left: 4px solid #0d6efd;
        }
        .obiettivo-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .obiettivo-card.disciplinare {
            border-left-color: #0d6efd;
        }
        .obiettivo-card.trasversale {
            border-left-color: #198754;
        }
        .tassonomia-badge {
            font-size: 0.75rem;
        }
        .peso-indicator {
            font-weight: bold;
            color: #6c757d;
        }
    </style>
    <style>
        .competenza-suggestions {
            max-height: 180px;
            overflow-y: auto;
            border: 1px dashed #dee2e6;
            border-radius: 0.375rem;
            padding: 0.5rem;
            background: #f8f9fa;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-bullseye"></i> Gestione Obiettivi Didattici';
    $headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>' . '<a class="nav-link" href="materiali.php"><i class="bi bi-folder-fill"></i> Materiali</a>' . '<a class="nav-link active" href="obiettivi.php"><i class="bi bi-bullseye"></i> Obiettivi</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-4">
        <div class="d-flex justify-content-end align-items-center mb-4">
    <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#addObiettivoModal">
                <i class="bi bi-plus-circle"></i> Aggiungi Obiettivo
            </button>
</div>

        <?php if ($message): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Filtro UDA -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-6">
                        <label for="uda-filter" class="form-label">Filtra per UDA</label>
                        <select name="uda_id" id="uda-filter" class="form-select">
                            <option value="">Tutte le UDA</option>
                            <?php foreach ($udas as $uda): ?>
                                <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                    <?= $selectedUdaId === $uda->id_uda ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uda->titolo) ?> (<?= htmlspecialchars($uda->argomento) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="tipo-filter" class="form-label">Tipo Obiettivo</label>
                        <select name="tipo" id="tipo-filter" class="form-select">
                            <option value="">Tutti</option>
                            <?php foreach ($tipiDistinct as $tipo => $_): ?>
                                <option value="<?= htmlspecialchars($tipo) ?>" <?= $filterTipo === $tipo ? 'selected' : '' ?>>
                                    <?= htmlspecialchars(ucwords(str_replace('_', ' ', $tipo))) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="bloom-filter" class="form-label">Livello Bloom</label>
                        <select name="bloom" id="bloom-filter" class="form-select">
                            <option value="">Tutti</option>
                            <?php foreach ($livelliDistinct as $livello => $_): ?>
                                <option value="<?= htmlspecialchars($livello) ?>" <?= (string)$filterBloom === (string)$livello ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($livello) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label for="search-filter" class="form-label">Ricerca</label>
                        <input type="text" id="search-filter" name="q" class="form-control"
                               placeholder="Descrizione, codice, competenza..."
                               value="<?= htmlspecialchars($filterSearch) ?>">
                    </div>
                    <div class="col-md-4">
                        <label for="competenza-filter" class="form-label">Competenza</label>
                        <input type="text" id="competenza-filter" name="competenza" class="form-control"
                               list="competenza-list"
                               placeholder="Es. competenze digitali"
                               value="<?= htmlspecialchars($filterCompetenza) ?>">
                        <datalist id="competenza-list">
                            <?php foreach ($competenzeDistinct as $competenza => $_): ?>
                                <option value="<?= htmlspecialchars($competenza) ?>"></option>
                            <?php endforeach; ?>
                        </datalist>
                        <?php if (!empty($competenzeDistinct)): ?>
                            <div id="competenza-suggestions" class="competenza-suggestions mt-2 <?= $filterCompetenza === '' ? '' : 'd-none' ?>">
                                <div class="d-flex flex-wrap gap-2">
                                    <?php foreach ($competenzeDistinct as $competenza => $_): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-secondary competenza-suggestion"
                                                data-value="<?= htmlspecialchars($competenza) ?>">
                                            <?= htmlspecialchars($competenza) ?>
                                        </button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-2 d-grid">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-funnel"></i> Filtra
                        </button>
                    </div>
                    <div class="col-md-12 d-grid">
                        <a href="obiettivi.php" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Reset filtri
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card bg-primary text-white">
                    <div class="card-body text-center">
                        <h3><?= count($obiettivi) ?></h3>
                        <p class="mb-0">Obiettivi Totali</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-info text-white">
                    <div class="card-body text-center">
                        <h3><?= count($obiettiviPerTipo['disciplinare'] ?? []) ?></h3>
                        <p class="mb-0">Obiettivi Disciplinari</p>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card bg-success text-white">
                    <div class="card-body text-center">
                        <h3><?= count($obiettiviPerTipo['competenza trasversale'] ?? []) ?></h3>
                        <p class="mb-0">Competenze Trasversali</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista Obiettivi -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-list-ul"></i> Elenco Obiettivi
                    <?php if ($selectedUdaId): ?>
                        <span class="badge bg-light text-dark ms-2">
                            Filtrati per UDA selezionata
                        </span>
                    <?php endif; ?>
                </h5>
            </div>
            <div class="card-body">
                <?php if (empty($obiettivi)): ?>
                    <div class="text-center py-5">
                        <i class="bi bi-inbox fs-1 text-muted"></i>
                        <p class="text-muted mt-3">
                            Nessun obiettivo trovato. Inizia aggiungendo il primo obiettivo!
                        </p>
                    </div>
                <?php else: ?>
                    <?php foreach ($obiettiviPerTipo as $tipo => $objs): ?>
                        <h5 class="mt-4 mb-3 text-capitalize border-bottom pb-2">
                            <i class="bi bi-<?= $tipo === 'disciplinare' ? 'book' : 'star' ?>"></i>
                            <?= htmlspecialchars($tipo) ?>
                            <span class="badge bg-secondary"><?= count($objs) ?></span>
                        </h5>
                        <div class="row">
                            <?php foreach ($objs as $obj): ?>
                                <div class="col-12 mb-3">
                                    <div class="card obiettivo-card <?= $obj['tipo_obiettivo'] === 'disciplinare' ? 'disciplinare' : 'trasversale' ?>">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <div class="flex-grow-1">
                                                    <div class="d-flex align-items-center mb-2">
                                                        <?php if (!empty($obj['codice'])): ?>
                                                            <span class="badge bg-dark me-2">
                                                                <?= htmlspecialchars($obj['codice']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($obj['livello_tassonomia'])): ?>
                                                            <span class="badge bg-info tassonomia-badge me-2">
                                                                Bloom <?= htmlspecialchars($obj['livello_tassonomia']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                        <?php if (!empty($obj['peso'])): ?>
                                                            <span class="badge bg-warning text-dark">
                                                                Peso: <?= htmlspecialchars($obj['peso']) ?>%
                                                            </span>
                                                        <?php endif; ?>
                                                    </div>
                                                    <p class="mb-2">
                                                        <strong><?= htmlspecialchars($obj['descrizione'] ?? 'N/A') ?></strong>
                                                    </p>
                                                    <?php if (!empty($obj['competenza'])): ?>
                                                        <p class="text-muted small mb-0">
                                                            <i class="bi bi-award"></i>
                                                            Competenza: <?= htmlspecialchars($obj['competenza']) ?>
                                                        </p>
                                                    <?php endif; ?>
                                                </div>
                                                <div class="ms-3">
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="confirmDelete('<?= htmlspecialchars($obj['id_obiettivo'] ?? '') ?>', '<?= htmlspecialchars(substr($obj['descrizione'] ?? '', 0, 50)) ?>...')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Modal Aggiungi Obiettivo -->
    <div class="modal fade" id="addObiettivoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Aggiungi Obiettivo Didattico</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_obiettivo">

                        <div class="mb-3">
                            <label for="add-uda" class="form-label">UDA *</label>
                            <select name="id_uda" id="add-uda" class="form-select" required>
                                <option value="">Seleziona UDA...</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                        <?= $selectedUdaId === $uda->id_uda ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($uda->titolo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="add-tipo" class="form-label">Tipo Obiettivo *</label>
                            <select name="tipo_obiettivo" id="add-tipo" class="form-select" required>
                                <option value="disciplinare">Obiettivo Disciplinare</option>
                                <option value="competenza trasversale">Competenza Trasversale</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="add-codice" class="form-label">Codice</label>
                                <input type="text" class="form-control" id="add-codice" name="codice"
                                       placeholder="es. DISC01, COMP01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="add-livello" class="form-label">Livello Tassonomia (Bloom)</label>
                                <select name="livello_tassonomia" id="add-livello" class="form-select">
                                    <option value="">Seleziona...</option>
                                    <option value="1">1 - Ricordare</option>
                                    <option value="2">2 - Comprendere</option>
                                    <option value="3">3 - Applicare</option>
                                    <option value="4">4 - Analizzare</option>
                                    <option value="5">5 - Valutare</option>
                                    <option value="6">6 - Creare</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="add-descrizione" class="form-label">Descrizione Obiettivo *</label>
                            <textarea class="form-control" id="add-descrizione" name="descrizione"
                                      rows="3" required
                                      placeholder="Descrivi l'obiettivo didattico..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="add-competenza" class="form-label">Competenza</label>
                            <input type="text" class="form-control" id="add-competenza" name="competenza"
                                   placeholder="es. Analizzare sistemi informatici">
                        </div>

                        <div class="mb-3">
                            <label for="add-peso" class="form-label">Peso (%)</label>
                            <input type="number" class="form-control" id="add-peso" name="peso"
                                   min="0" max="100" step="5"
                                   placeholder="es. 40">
                            <small class="text-muted">Peso dell'obiettivo nella valutazione complessiva</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Aggiungi Obiettivo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form nascosto per eliminazione -->
    <form id="delete-form" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="obiettivo_id" id="delete-obiettivo-id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function confirmDelete(obiettivoId, descrizione) {
            if (confirm('Sei sicuro di voler eliminare questo obiettivo?\n\n' + descrizione)) {
                document.getElementById('delete-obiettivo-id').value = obiettivoId;
                document.getElementById('delete-form').submit();
            }
        }
    </script>
    <script>
        const competenzaInput = document.getElementById('competenza-filter');
        const competenzaSuggestions = document.getElementById('competenza-suggestions');

        function toggleCompetenzaSuggestions() {
            if (!competenzaInput || !competenzaSuggestions) return;
            if (competenzaInput.value.trim() === '') {
                competenzaSuggestions.classList.remove('d-none');
            } else {
                competenzaSuggestions.classList.add('d-none');
            }
        }

        if (competenzaInput && competenzaSuggestions) {
            competenzaInput.addEventListener('input', toggleCompetenzaSuggestions);
            competenzaInput.addEventListener('focus', toggleCompetenzaSuggestions);
        }

        document.querySelectorAll('.competenza-suggestion').forEach(button => {
            button.addEventListener('click', () => {
                if (!competenzaInput) return;
                competenzaInput.value = button.getAttribute('data-value') || '';
                toggleCompetenzaSuggestions();
                competenzaInput.focus();
            });
        });
    </script>
</body>
</html>
