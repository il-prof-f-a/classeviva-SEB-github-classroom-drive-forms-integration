<?php
/**
 * Esportazione UDA Completa
 *
 * Genera documento Word con tutti i dati dell'UDA
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ExportAccessService;
use App\Core\ExportManager;
use App\Core\Security\PublicError;

$db = DatabaseFactory::createWithInitialization($config, true);
$exportManager = new ExportManager($db, $config);

$message = null;
$error = null;
$anteprima = null;
$fileGenerato = null;
$fileToken = null;
$userId = (string)($_SESSION['user_id'] ?? '');

// Recupera ID UDA
$udaId = $_GET['id'] ?? $_GET['id_uda'] ?? $_POST['id_uda'] ?? null;
$action = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? ($_POST['action'] ?? null)
    : ($_GET['action'] ?? null);

if (!$udaId) {
    header('Location: index.php');
    exit;
}

try {
    // Carica anteprima
    $anteprima = $exportManager->anteprimaExport($udaId);
    $uda = $anteprima['uda'];

    if ($action === 'esporta') {
        // Sezioni selezionate
        $sezioni = $_POST['sezioni'] ?? ['info', 'obiettivi', 'materiali', 'rubriche', 'laboratorio', 'domande', 'test', 'statistiche'];
        $password = trim((string)($_POST['password'] ?? ''));

        // Genera pacchetto ZIP protetto (Word + Excel)
        $filePath = $exportManager->esportaUDAPacchetto($udaId, $sezioni, $password);

        if (file_exists($filePath)) {
            $fileGenerato = basename($filePath);
            $fileToken = (new ExportAccessService(ROOT_PATH . '/storage/exports'))
                ->register($_SESSION, $userId, (string)$udaId, $filePath);
            $message = "Pacchetto ZIP protetto generato con successo!";
        } else {
            throw new Exception("Errore durante la generazione del pacchetto");
        }
    }

    if ($action === 'download' && isset($_GET['token'])) {
        $filePath = (new ExportAccessService(ROOT_PATH . '/storage/exports'))->resolve(
            $_SESSION,
            (string)$_GET['token'],
            $userId,
            (string)$udaId
        );

        if ($filePath !== null && file_exists($filePath)) {
            $fileName = basename($filePath);
            $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
            $contentType = $ext === 'zip'
                ? 'application/zip'
                : 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . $fileName . '"');
            header('Content-Length: ' . filesize($filePath));
            readfile($filePath);
            exit;
        } else {
            throw new Exception("File non trovato");
        }
    }

} catch (Exception $e) {
    $error = PublicError::message($e, 'uda export');
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esportazione UDA - <?= htmlspecialchars($uda->titolo ?? 'UDA') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .sezione-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.2s;
        }
        .sezione-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            border-radius: 10px;
            text-align: center;
        }
        .stat-box h3 {
            font-size: 2.5em;
            margin-bottom: 5px;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-box-arrow-up"></i> Esportazione UDA';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a href="uda_view.php?id=' . urlencode($udaId) . '" class="btn btn-outline-light btn-sm">'
        . '<i class="bi bi-arrow-left"></i> Torna all\'UDA</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>
    <div class="container mt-4">

        <!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
                <?php if ($fileGenerato && $fileToken): ?>
                    <a href="?id=<?= urlencode($udaId) ?>&action=download&token=<?= urlencode($fileToken) ?>" class="btn btn-sm btn-success ms-3">
                        <i class="bi bi-download"></i> Scarica Pacchetto ZIP
                    </a>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($anteprima): ?>
            <div class="row">
                <!-- Statistiche Anteprima -->
                <div class="col-md-4">
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-graph-up"></i> Contenuto UDA</h5>
                        </div>
                        <div class="card-body">
                            <div class="list-group list-group-flush">
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-bullseye text-success"></i> Obiettivi</span>
                                    <span class="badge bg-success rounded-pill"><?= $anteprima['num_obiettivi'] ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-folder text-info"></i> Materiali</span>
                                    <span class="badge bg-info rounded-pill"><?= $anteprima['num_materiali'] ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-clipboard-check text-primary"></i> Rubriche</span>
                                    <span class="badge bg-primary rounded-pill"><?= $anteprima['num_rubriche'] ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-plus-slash-minus text-warning"></i> Laboratorio</span>
                                    <span class="badge bg-warning rounded-pill"><?= $anteprima['num_laboratorio'] ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-question-circle text-secondary"></i> Domande</span>
                                    <span class="badge bg-secondary rounded-pill"><?= $anteprima['num_domande'] ?></span>
                                </div>
                                <div class="list-group-item d-flex justify-content-between align-items-center">
                                    <span><i class="bi bi-people text-dark"></i> Classi</span>
                                    <span class="badge bg-dark rounded-pill"><?= $anteprima['num_classi'] ?></span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Info Documento -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Formato Documento</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Formato:</strong> Archivio ZIP protetto da password</p>
                            <p><strong>Contiene:</strong></p>
                            <ul class="small">
                                <li>Documento Word (.docx) con tutti i link a materiali e test</li>
                                <li>File Excel (.xlsx) con voti, valutazioni e risposte ai test</li>
                            </ul>
                            <div class="alert alert-warning mb-0">
                                <small><i class="bi bi-exclamation-triangle"></i> Il documento includerà solo le sezioni con contenuto disponibile. I dati sensibili sono protetti dalla password del file ZIP.</small>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Form Esportazione -->
                <div class="col-md-8">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-gear"></i> Configurazione Esportazione</h5>
                        </div>
                        <div class="card-body">
                            <form method="post">
                                <input type="hidden" name="action" value="esporta">
                                <input type="hidden" name="id_uda" value="<?= htmlspecialchars($udaId) ?>">

                                <h6 class="mb-3">Seleziona le sezioni da includere:</h6>

                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="info" id="sez_info" checked>
                                                    <label class="form-check-label" for="sez_info">
                                                        <strong><i class="bi bi-info-circle"></i> Informazioni Generali</strong>
                                                        <div class="text-muted small">Dati UDA, date, descrizione</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="obiettivi" id="sez_obiettivi" checked>
                                                    <label class="form-check-label" for="sez_obiettivi">
                                                        <strong><i class="bi bi-bullseye"></i> Obiettivi Didattici</strong>
                                                        <div class="text-muted small">Lista completa con livelli Bloom (<?= $anteprima['num_obiettivi'] ?>)</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="materiali" id="sez_materiali" checked>
                                                    <label class="form-check-label" for="sez_materiali">
                                                        <strong><i class="bi bi-folder"></i> Materiali Didattici</strong>
                                                        <div class="text-muted small">File e link con descrizioni (<?= $anteprima['num_materiali'] ?>)</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="rubriche" id="sez_rubriche" checked>
                                                    <label class="form-check-label" for="sez_rubriche">
                                                        <strong><i class="bi bi-clipboard-check"></i> Rubriche Valutazione</strong>
                                                        <div class="text-muted small">Valutazioni orali compilate (<?= $anteprima['num_rubriche'] ?>)</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="col-md-6">
                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="laboratorio" id="sez_lab" checked>
                                                    <label class="form-check-label" for="sez_lab">
                                                        <strong><i class="bi bi-plus-slash-minus"></i> Valutazioni Laboratorio</strong>
                                                        <div class="text-muted small">Evidenze +/- per competenze (<?= $anteprima['num_laboratorio'] ?>)</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="domande" id="sez_domande" checked>
                                                    <label class="form-check-label" for="sez_domande">
                                                        <strong><i class="bi bi-question-circle"></i> Domande e Test</strong>
                                                        <div class="text-muted small">Domande per interrogazioni (<?= $anteprima['num_domande'] ?>)</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="test" id="sez_test" checked>
                                                    <label class="form-check-label" for="sez_test">
                                                        <strong><i class="bi bi-link-45deg"></i> Test e Link Esterni</strong>
                                                        <div class="text-muted small">Link ai test e ai materiali collegati</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>

                                        <div class="card sezione-card mb-3">
                                            <div class="card-body">
                                                <div class="form-check">
                                                    <input class="form-check-input" type="checkbox" name="sezioni[]"
                                                           value="statistiche" id="sez_stats" checked>
                                                    <label class="form-check-label" for="sez_stats">
                                                        <strong><i class="bi bi-graph-up"></i> Statistiche</strong>
                                                        <div class="text-muted small">Medie, sufficienze, andamento</div>
                                                    </label>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <div class="mb-3 mt-4">
                                    <label for="zip_password" class="form-label">
                                        <i class="bi bi-lock"></i> Password del file ZIP *
                                    </label>
                                    <input type="password" name="password" id="zip_password" class="form-control"
                                           placeholder="Definisci la password per proteggere il pacchetto" required minlength="4" autocomplete="new-password">
                                    <small class="form-text text-muted">
                                        Il file ZIP conterrà il documento Word e il file Excel con voti, valutazioni e risposte ai test, protetti con questa password.
                                    </small>
                                </div>

                                <div class="d-grid gap-2 mt-4">
                                    <button type="submit" class="btn btn-success btn-lg">
                                        <i class="bi bi-file-earmark-zip"></i> Genera Pacchetto ZIP protetto
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary" onclick="selezionaTutte()">
                                        <i class="bi bi-check-all"></i> Seleziona/Deseleziona Tutto
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Suggerimenti -->
                    <div class="card mt-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="bi bi-lightbulb"></i> Suggerimenti</h6>
                        </div>
                        <div class="card-body">
                            <ul class="mb-0">
                                <li>Il documento Word include i collegamenti ipertestuali a materiali e test</li>
                                <li>Il file Excel contiene voti, valutazioni e risposte ai test (protetti nel ZIP)</li>
                                <li>La password del ZIP è richiesta per aprire l'archivio</li>
                                <li>Puoi personalizzare stili e formattazione del Word dopo l'estrazione</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let tuttoSelezionato = true;

        function selezionaTutte() {
            tuttoSelezionato = !tuttoSelezionato;
            document.querySelectorAll('input[name="sezioni[]"]').forEach(cb => {
                cb.checked = tuttoSelezionato;
            });
        }
    </script>
</body>
</html>
