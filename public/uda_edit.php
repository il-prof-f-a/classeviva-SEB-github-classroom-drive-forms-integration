<?php

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$cvSubjectsList = [];
try {
    $cvApi = new ClasseVivaAPI($config);
    $classes = $cvApi->getClassesWithTeacherSubjects();
    foreach ($classes as $class) {
        foreach ($class['subjects'] ?? [] as $sub) {
            $name = trim($sub['name'] ?? $sub['subjectName'] ?? $sub['subjectDesc'] ?? '');
            if ($name !== '' && !in_array($name, $cvSubjectsList, true)) {
                $cvSubjectsList[] = $name;
            }
        }
    }
    sort($cvSubjectsList, SORT_NATURAL | SORT_FLAG_CASE);
} catch (\Throwable $e) {
    // se ClasseViva non è accessibile, lascio i soli valori di default
}

$error_message = null;
$success_message = null;

// Verifica ID UDA
$udaId = $_GET['id'] ?? null;
if (!$udaId) {
    header('Location: index.php');
    exit;
}

// Carica UDA esistente
$udaComplete = null;
$uda = null;
try {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception("UDA non trovata");
    }
    $uda = $udaComplete['uda'];
} catch (Exception $e) {
    $error_message = "Errore: " . $e->getMessage();
}

// Gestione POST per l'aggiornamento della UDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_uda' && !$error_message) {

    try {
        // Dati UDA aggiornati
        $udaData = [
            'titolo' => $_POST['titolo'] ?? '',
            'argomento' => $_POST['argomento'] ?? '',
            'disciplina' => $_POST['disciplina'] ?? '',
            'metodologia' => $_POST['metodologia'] ?? '',
            'anno_scolastico' => $_POST['anno_scolastico'] ?? '',
            'data_inizio' => $_POST['data_inizio'] ?? null,
            'data_fine' => $_POST['data_fine'] ?? null,
            'durata_ore' => intval($_POST['durata_ore'] ?? 0),
            'note' => $_POST['note'] ?? '',
            'classi_target' => $_POST['classi_target'] ?? '',
            'progetto' => $_POST['progetto'] ?? '',
            'descrizione' => $_POST['descrizione'] ?? '',
            'stato' => $_POST['stato'] ?? 'bozza'
        ];

        // Validazione campi obbligatori
        if (empty($udaData['titolo']) || empty($udaData['argomento'])) {
            throw new Exception("I campi Titolo e Argomento sono obbligatori.");
        }

        // Aggiorna la UDA
        $updated = $udaManager->updateUDA($udaId, $udaData);

        if ($updated) {
            // Redirect alla pagina di visualizzazione
            header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=update_success");
            exit;
        } else {
            throw new Exception("Impossibile aggiornare l'UDA");
        }

    } catch (Exception $e) {
        $error_message = "Errore durante l'aggiornamento della UDA: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Modifica UDA - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-pencil"></i> Modifica UDA';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '';
    if ($udaId) {
        $headerActions .= '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">Visualizza UDA</a>';
    }
    $headerActions .= '<a class="nav-link" href="uda_tests.php?id=' . urlencode($udaId) . '">Test</a>';
    $headerActions .= '<a class="nav-link" href="uda_questions.php?id=' . urlencode($udaId) . '">Domande</a>';
    if ($udaId) {
        $headerActions .= '<a href="uda_view.php?id=' . urlencode($udaId) . '" class="btn btn-outline-light btn-sm">'
            . '<i class="bi bi-arrow-left"></i> Torna alla Visualizzazione</a>';
    }
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4 mb-5">
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($success_message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($uda): ?>
        <div class="card shadow">
            <div class="card-body p-4">
                <form method="POST" id="udaEditForm">
                    <input type="hidden" name="action" value="update_uda">

                    <!-- Informazioni Base -->
                    <div class="mb-4">
                        <h3 class="mb-4">Informazioni Base</h3>

                        <div class="row">
                            <div class="col-md-8 mb-3">
                                <label for="titolo" class="form-label">Titolo UDA <span class="text-danger">*</span></label>
                                <input type="text" class="form-control form-control-lg" id="titolo" name="titolo" required
                                       value="<?= htmlspecialchars($uda->titolo ?? '') ?>"
                                       placeholder="Es: La Rivoluzione Industriale">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="anno_scolastico" class="form-label">Anno Scolastico</label>
                                <input type="text" class="form-control" id="anno_scolastico" name="anno_scolastico"
                                       value="<?= htmlspecialchars($uda->anno_scolastico ?? '') ?>">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="argomento" class="form-label">Argomento <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="argomento" name="argomento" required
                                       value="<?= htmlspecialchars($uda->argomento ?? '') ?>"
                                       placeholder="Es: Storia Moderna">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="disciplina" class="form-label">Disciplina</label>
                                <input list="disciplineList" class="form-control" id="disciplina" name="disciplina"
                                       value="<?= htmlspecialchars($uda->disciplina ?? '') ?>"
                                       placeholder="Seleziona o digita...">
                                <datalist id="disciplineList">
                                    <option value="Sistemi e reti">
                                    <option value="Telecomunicazioni">
                                    <option value="GPOI">
                                    <option value="Informatica">
                                    <option value="TPSIT">
                                    <option value="Matematica">
                                    <option value="Inglese">
                                    <option value="Italiano">
                                    <option value="Storia">
                                    <option value="Educazione Civica">
                                    <?php foreach ($cvSubjectsList as $sub): ?>
                                        <option value="<?= htmlspecialchars($sub) ?>"></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control" id="descrizione" name="descrizione" rows="3"
                                      placeholder="Breve descrizione dell'UDA e dei suoi obiettivi principali"><?= htmlspecialchars($uda->descrizione ?? '') ?></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="metodologia" class="form-label">Metodologia Didattica</label>
                                <input list="metodologiaList" class="form-control" id="metodologia" name="metodologia"
                                       value="<?= htmlspecialchars($uda->metodologia ?? '') ?>"
                                       placeholder="Seleziona o digita...">
                                <datalist id="metodologiaList">
                                    <option value="Metodologie di insegnamento innovative">
                                    <option value="Flipped classroom">
                                    <option value="Jigsaw">
                                    <option value="Debate">
                                    <option value="Didattica laboratoriale">
                                    <option value="PBL">
                                    <option value="pbl">
                                    <option value="Inquiry guidato">
                                </datalist>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="progetto" class="form-label">Progetto</label>
                                <input type="text" class="form-control" id="progetto" name="progetto"
                                       value="<?= htmlspecialchars($uda->progetto ?? '') ?>"
                                       placeholder="Es: Analisi delle fonti storiche">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="data_inizio" class="form-label">Data Inizio</label>
                                <input type="date" class="form-control" id="data_inizio" name="data_inizio"
                                       value="<?= htmlspecialchars($uda->data_inizio ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="data_fine" class="form-label">Data Fine</label>
                                <input type="date" class="form-control" id="data_fine" name="data_fine"
                                       value="<?= htmlspecialchars($uda->data_fine ?? '') ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="durata_ore" class="form-label">Durata (ore)</label>
                                <input type="number" class="form-control" id="durata_ore" name="durata_ore"
                                       value="<?= htmlspecialchars($uda->durata_ore ?? '') ?>"
                                       min="0" placeholder="Es: 10">
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="note" class="form-label">Note / Prerequisiti</label>
                            <textarea class="form-control" id="note" name="note" rows="2"
                                      placeholder="Note aggiuntive o prerequisiti richiesti"><?= htmlspecialchars($uda->note ?? '') ?></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="classi_target" class="form-label">Classi Target / Destinatari</label>
                            <input type="text" class="form-control" id="classi_target" name="classi_target"
                                   value="<?= htmlspecialchars($uda->classi_target ?? '') ?>"
                                   placeholder="Es: Classe 3A, 3B">
                        </div>

                        <div class="mb-3">
                            <label for="stato" class="form-label">Stato</label>
                            <select class="form-select" id="stato" name="stato">
                                <?php
                                $stati = [
                                    'bozza' => 'Bozza',
                                    'attiva' => 'Attiva',
                                    'completata' => 'Completata',
                                    'archiviata' => 'Archiviata'
                                ];
                                foreach ($stati as $value => $label) {
                                    $selected = (($uda->stato ?? 'bozza') === $value) ? 'selected' : '';
                                    echo "<option value=\"{$value}\" {$selected}>{$label}</option>";
                                }
                                ?>
                            </select>
                        </div>
                    </div>

                    <!-- Info Aggiuntive -->
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i> <strong>Nota:</strong> I materiali e gli obiettivi possono essere modificati dalla
                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="alert-link">pagina di visualizzazione UDA</a>.
                    </div>

                    <!-- Metadati -->
                    <?php if (isset($uda->data_creazione) || isset($uda->ultima_modifica)): ?>
                    <div class="card bg-light mb-4">
                        <div class="card-body">
                            <h6 class="card-title">Metadati</h6>
                            <div class="row">
                                <?php if (isset($uda->data_creazione)): ?>
                                <div class="col-md-6">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-plus"></i> Creata il:
                                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($uda->data_creazione))) ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                                <?php if (isset($uda->ultima_modifica)): ?>
                                <div class="col-md-6">
                                    <small class="text-muted">
                                        <i class="bi bi-calendar-check"></i> Modificata il:
                                        <?= htmlspecialchars(date('d/m/Y H:i', strtotime($uda->ultima_modifica))) ?>
                                    </small>
                                </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>

                    <!-- Pulsanti -->
                    <div class="d-flex justify-content-between mt-4">
                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary btn-lg">
                            <i class="bi bi-x-circle"></i> Annulla
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <i class="bi bi-save"></i> Salva Modifiche
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Statistiche UDA -->
        <?php if ($udaComplete): ?>
        <div class="row mt-4">
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-info"><?= count($udaComplete['materiali'] ?? []) ?></h3>
                        <p class="mb-0 text-muted">Materiali</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-success"><?= count($udaComplete['obiettivi'] ?? []) ?></h3>
                        <p class="mb-0 text-muted">Obiettivi</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-warning"><?= count($udaComplete['test'] ?? []) ?></h3>
                        <p class="mb-0 text-muted">Test</p>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card text-center">
                    <div class="card-body">
                        <h3 class="text-primary"><?= count($udaComplete['classi_assegnate'] ?? []) ?></h3>
                        <p class="mb-0 text-muted">Classi</p>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Conferma prima di abbandonare il form con modifiche non salvate
        let formModified = false;
        const form = document.getElementById('udaEditForm');

        if (form) {
            const inputs = form.querySelectorAll('input, textarea, select');
            inputs.forEach(input => {
                input.addEventListener('change', () => {
                    formModified = true;
                });
            });

            window.addEventListener('beforeunload', (e) => {
                if (formModified) {
                    e.preventDefault();
                    e.returnValue = '';
                }
            });

            form.addEventListener('submit', () => {
                formModified = false;
            });
        }
    </script>
</body>
</html>
