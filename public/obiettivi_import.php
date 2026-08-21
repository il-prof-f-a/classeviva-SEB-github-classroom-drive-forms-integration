<?php
/**
 * Import Obiettivi Didattici
 *
 * Permette di:
 * - Scaricare template Excel
 * - Importare obiettivi da file Excel/CSV
 * - Validare dati prima dell'import
 * - Salvare in OBIETTIVI_MASTER o associare a UDA
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Core\ObiettiviManager;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$obiettiviManager = new ObiettiviManager($dbAdapter, $config);

$message = null;
$error = null;
$risultatoImport = null;

// Gestione azioni
$action = $_POST['action'] ?? null;
$downloadAction = $_GET['action'] ?? null;
// Supporta id_uda da uda_view.php per preselezionare l'UDA
$preselectedUdaId = $_GET['id_uda'] ?? null;

try {
    // Recupera UDA per selezione
    $udas = $udaManager->getAllUDAs();

    if ($downloadAction === 'download_template') {
        // Genera e scarica template
        $templatePath = ROOT_PATH . '/storage/template_obiettivi.xlsx';

        // Crea directory se non esiste
        if (!is_dir(ROOT_PATH . '/storage')) {
            mkdir(ROOT_PATH . '/storage', 0755, true);
        }

        $obiettiviManager->generaTemplateExcel($templatePath);

        // Forza download
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="template_obiettivi.xlsx"');
        header('Content-Length: ' . filesize($templatePath));
        readfile($templatePath);
        exit;
    }

    if ($action === 'import' && isset($_FILES['file'])) {
        // Import da file
        $file = $_FILES['file'];

        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Errore durante l'upload del file");
        }

        $udaId = $_POST['uda_id'] ?? null;
        if ($udaId === 'master') {
            $udaId = null;
        }

        $validazioneStretta = isset($_POST['validazione_stretta']);

        // Esegui import passando anche il nome originale del file
        $risultatoImport = $obiettiviManager->importDaFile(
            $file['tmp_name'],
            $udaId,
            $validazioneStretta,
            $file['name'] // Nome originale del file
        );

        if ($risultatoImport['imported'] > 0) {
            $message = "Import completato! {$risultatoImport['imported']} obiettivi importati.";
            if ($risultatoImport['skipped'] > 0) {
                $message .= " {$risultatoImport['skipped']} righe saltate per errori di validazione.";
            }
        } else {
            $error = "Nessun obiettivo importato. Controlla gli errori di validazione.";
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
    <title>Import Obiettivi Didattici</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .upload-area {
            border: 2px dashed #ccc;
            border-radius: 8px;
            padding: 40px;
            text-align: center;
            background: #f8f9fa;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover {
            border-color: #007bff;
            background: #e7f3ff;
        }
        .upload-area.dragover {
            border-color: #28a745;
            background: #d4edda;
        }
        .error-row {
            background-color: #fff3cd;
        }
        .success-row {
            background-color: #d1e7dd;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-download"></i> Import Obiettivi Didattici';
    $selectUrl = 'obiettivi_seleziona.php' . ($preselectedUdaId ? '?id_uda=' . urlencode($preselectedUdaId) : '');
    ob_start();
    if ($preselectedUdaId):
    ?>
    <a href="uda_view.php?id=<?php echo urlencode($preselectedUdaId); ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left"></i> Torna all'UDA
    </a>
    <?php endif; ?>
    <a href="<?php echo $selectUrl; ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-list-check"></i> Seleziona da Master
    </a>
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
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$risultatoImport): ?>
            <!-- Step 1: Istruzioni e Download Template -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-1-circle"></i> Scarica Template</h5>
                        </div>
                        <div class="card-body">
                            <p>Scarica il template Excel pre-formattato per importare gli obiettivi:</p>
                            <ul>
                                <li>Intestazioni già configurate</li>
                                <li>Riga di esempio inclusa</li>
                                <li>Formati celle corretti</li>
                            </ul>
                            <a href="?action=download_template" class="btn btn-primary w-100">
                                <i class="bi bi-download"></i> Scarica Template Excel
                            </a>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-info text-white">
                            <h5 class="mb-0"><i class="bi bi-info-circle"></i> Formato File</h5>
                        </div>
                        <div class="card-body">
                            <p><strong>Colonne obbligatorie:</strong></p>
                            <ul class="mb-3">
                                <li><code>tipo_obiettivo</code> - conoscenze, abilità, competenze</li>
                                <li><code>descrizione</code> - Descrizione completa</li>
                            </ul>
                            <p><strong>Colonne opzionali:</strong></p>
                            <ul>
                                <li><code>codice</code>, <code>competenza</code>, <code>livello_tassonomia</code> (1-6)</li>
                                <li><code>peso</code>, <code>area_disciplinare</code>, <code>parole_chiave</code></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Step 2: Upload File -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="bi bi-2-circle"></i> Carica File</h5>
                </div>
                <div class="card-body">
                    <form method="post" action="obiettivi_import.php" enctype="multipart/form-data" id="importForm">
                        <input type="hidden" name="action" value="import">

                        <!-- Upload Area -->
                        <div class="upload-area mb-4" id="uploadArea">
                            <i class="bi bi-cloud-upload" style="font-size: 3rem; color: #007bff;"></i>
                            <h5 class="mt-3">Trascina il file qui o clicca per selezionare</h5>
                            <p class="text-muted">Formati supportati: Excel (.xlsx, .xls) o CSV (.csv)</p>
                            <input type="file" name="file" id="fileInput" accept=".xlsx,.xls,.csv" class="d-none" required>
                            <div id="fileName" class="mt-3 fw-bold"></div>
                        </div>

                        <!-- Opzioni Import -->
                        <div class="row mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Destinazione</label>
                                <select name="uda_id" class="form-select" required>
                                    <option value="master" <?php echo !$preselectedUdaId ? 'selected' : ''; ?>>Repository Master (riutilizzabili)</option>
                                    <option value="" disabled>── Associa direttamente a UDA ──</option>
                                    <?php foreach ($udas as $uda): ?>
                                        <option value="<?php echo htmlspecialchars($uda->id_uda); ?>"
                                                <?php echo ($preselectedUdaId && $uda->id_uda === $preselectedUdaId) ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($uda->titolo); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">
                                    Scegli "Repository Master" per obiettivi riutilizzabili, oppure associa direttamente a una UDA
                                </small>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">Opzioni Validazione</label>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="validazione_stretta" id="validazioneStretta">
                                    <label class="form-check-label" for="validazioneStretta">
                                        Validazione stretta
                                    </label>
                                    <small class="d-block text-muted">
                                        Richiede tutti i campi obbligatori (competenza, livello_tassonomia)
                                    </small>
                                </div>
                            </div>
                        </div>

                        <button type="submit" class="btn btn-success btn-lg w-100">
                            <i class="bi bi-upload"></i> Importa Obiettivi
                        </button>
                    </form>
                </div>
            </div>

        <?php else: ?>
            <!-- Step 3: Risultati Import -->
            <div class="card mb-4">
                <div class="card-header <?php echo $risultatoImport['imported'] > 0 ? 'bg-success' : 'bg-warning'; ?> text-white">
                    <h5 class="mb-0"><i class="bi bi-check-circle"></i> Risultati Import</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-4">
                            <h2 class="text-success"><?php echo $risultatoImport['imported']; ?></h2>
                            <p>Importati</p>
                        </div>
                        <div class="col-md-4">
                            <h2 class="text-warning"><?php echo $risultatoImport['skipped']; ?></h2>
                            <p>Saltati</p>
                        </div>
                        <div class="col-md-4">
                            <h2 class="text-primary"><?php echo count($risultatoImport['obiettivi']); ?></h2>
                            <p>Totali</p>
                        </div>
                    </div>

                    <?php if (!empty($risultatoImport['obiettivi'])): ?>
                        <h6>Obiettivi Importati:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Tipo</th>
                                        <th>Codice</th>
                                        <th>Descrizione</th>
                                        <th>Livello Bloom</th>
                                        <th>Peso</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($risultatoImport['obiettivi'] as $obiettivo): ?>
                                        <tr class="success-row">
                                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($obiettivo->tipo_obiettivo); ?></span></td>
                                            <td><?php echo htmlspecialchars($obiettivo->codice ?? '-'); ?></td>
                                            <td><?php echo htmlspecialchars(substr($obiettivo->descrizione, 0, 80)) . '...'; ?></td>
                                            <td><?php echo $obiettivo->livello_tassonomia ? $obiettivo->getLivelloBloomDescrizione() : '-'; ?></td>
                                            <td><?php echo $obiettivo->peso ?? '-'; ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($risultatoImport['errors'])): ?>
                        <h6 class="mt-4 text-danger">Errori di Validazione:</h6>
                        <div class="table-responsive">
                            <table class="table table-sm">
                                <thead>
                                    <tr>
                                        <th>Riga</th>
                                        <th>Errori</th>
                                        <th>Dati</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($risultatoImport['errors'] as $errore): ?>
                                        <tr class="error-row">
                                            <td><?php echo $errore['riga']; ?></td>
                                            <td>
                                                <ul class="mb-0">
                                                    <?php foreach ($errore['errori'] as $msg): ?>
                                                        <li><?php echo htmlspecialchars($msg); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </td>
                                            <td><small><?php echo htmlspecialchars(substr(json_encode($errore['dati']), 0, 100)); ?></small></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <div class="mt-4">
                        <a href="obiettivi_import.php" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> Nuovo Import
                        </a>
                        <a href="obiettivi_seleziona.php" class="btn btn-outline-primary">
                            <i class="bi bi-list-check"></i> Vai a Selezione Obiettivi
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Drag & Drop e selezione file
        const uploadArea = document.getElementById('uploadArea');
        const fileInput = document.getElementById('fileInput');
        const fileName = document.getElementById('fileName');

        uploadArea.addEventListener('click', () => fileInput.click());

        uploadArea.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadArea.classList.add('dragover');
        });

        uploadArea.addEventListener('dragleave', () => {
            uploadArea.classList.remove('dragover');
        });

        uploadArea.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadArea.classList.remove('dragover');

            if (e.dataTransfer.files.length > 0) {
                fileInput.files = e.dataTransfer.files;
                updateFileName();
            }
        });

        fileInput.addEventListener('change', updateFileName);

        function updateFileName() {
            if (fileInput.files.length > 0) {
                const file = fileInput.files[0];
                fileName.innerHTML = `<i class="bi bi-file-earmark-check text-success"></i> ${file.name} (${(file.size / 1024).toFixed(2)} KB)`;
            }
        }
    </script>
</body>
</html>
