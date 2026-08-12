<?php
/**
 * Gestione Materiali Didattici
 *
 * Permette di:
 * - Visualizzare materiali esistenti
 * - Caricare nuovi file su Google Drive
 * - Aggiungere link esterni
 * - Eliminare materiali
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Integration\GoogleDriveAPI;

// Inizializza adapter e manager
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$pickerApiKey = $_ENV['GOOGLE_API_KEY'] ?? '';
$pickerClientId = $config['google']['oauth_client_id'] ?? '';
$driveRootId = trim($config['google']['drive']['root_folder_id'] ?? '');
$driveRootConfigured = $driveRootId !== '';

function normalizeImportedMaterialType(string $value): string
{
    $allowed = [
        'documento',
        'foglio_calcolo',
        'presentazione',
        'immagine',
        'video',
        'video_youtube',
        'link',
        'sito_web',
        'risorsa_online',
        'altro'
    ];
    $value = trim(strtolower($value));
    return in_array($value, $allowed, true) ? $value : 'link';
}

function detectImportedTestPlatform(string $url, bool $isAssignment = false): string
{
    $normalized = strtolower(trim($url));
    if ($normalized === '') {
        return $isAssignment ? 'google-classroom' : 'altro';
    }
    if (strpos($normalized, 'docs.google.com/forms') !== false || strpos($normalized, 'forms.gle') !== false) {
        return 'google-forms';
    }
    if (strpos($normalized, 'kahoot.it') !== false || strpos($normalized, 'create.kahoot.it') !== false) {
        return 'kahoot';
    }
    if (strpos($normalized, 'socrative.com') !== false || strpos($normalized, 'b.socrative.com') !== false) {
        return 'socrative';
    }
    if (strpos($normalized, 'classroom.google.com') !== false && $isAssignment) {
        return 'google-classroom';
    }
    return $isAssignment ? 'google-classroom' : 'altro';
}

function extractGoogleFormIdFromValue(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', $value)) {
        return $value;
    }

    $patterns = [
        '/forms\/d\/e\/([a-zA-Z0-9_-]+)\/viewform/i',
        '/forms\/d\/([a-zA-Z0-9_-]+)\/edit/i',
        '/forms\/d\/([a-zA-Z0-9_-]+)/i'
    ];
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $value, $matches)) {
            return $matches[1] ?? null;
        }
    }

    return null;
}

function detectImportedMaterialTypeFromUrl(string $url): string
{
    $normalized = strtolower(trim($url));
    if ($normalized === '') {
        return 'link';
    }
    if (strpos($normalized, 'youtube.com') !== false || strpos($normalized, 'youtu.be') !== false) {
        return 'video_youtube';
    }
    if (strpos($normalized, 'docs.google.com/spreadsheets') !== false) {
        return 'foglio_calcolo';
    }
    if (strpos($normalized, 'docs.google.com/presentation') !== false) {
        return 'presentazione';
    }
    if (strpos($normalized, 'docs.google.com/document') !== false || preg_match('/\.(pdf|doc|docx|txt)$/i', $normalized)) {
        return 'documento';
    }
    if (preg_match('/\.(jpg|jpeg|png|gif|webp|svg)$/i', $normalized)) {
        return 'immagine';
    }
    if (preg_match('/\.(mp4|avi|mov|webm)$/i', $normalized)) {
        return 'video';
    }
    return 'link';
}

// Gestisci azioni
$message = '';
$messageType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload_file':
                // Upload file su Google Drive
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Errore durante l'upload del file");
                }

                $idUda = $_POST['id_uda'] ?? '';
                $tipoMateriale = $_POST['tipo_materiale'] ?? 'documento';
                $descrizione = $_POST['descrizione'] ?? '';

                // Upload file su Google Drive
                $driveApi = new GoogleDriveAPI($config);
                $folderId = $config['google']['drive']['root_folder_id'] ?? '';

                // Upload file (parametri corretti: localPath, fileName, folderId, mimeType)
                $uploadResult = $driveApi->uploadFile(
                    $_FILES['file']['tmp_name'],
                    $_FILES['file']['name'],
                    $folderId,
                    $_FILES['file']['type']
                );

                $fileId = $uploadResult['id'];
                $fileUrl = $uploadResult['viewLink'];

                // Salva nel database
                $materialId = 'MAT_' . uniqid();
                $dbAdapter->insertRow('MATERIALI', [
                    'id_materiale' => $materialId,
                    'id_uda' => $idUda,
                    'tipo_materiale' => $tipoMateriale,
                    'nome' => $_FILES['file']['name'],
                    'descrizione' => $descrizione,
                    'url_drive' => $fileUrl,
                    'file_id_drive' => $fileId,
                    'data_creazione' => date('Y-m-d H:i:s')
                ]);

                $message = "File caricato con successo!";
                $messageType = "success";
                break;

            case 'add_link':
                // Aggiungi link esterno
                $idUda = $_POST['id_uda'] ?? '';
                $tipoMateriale = $_POST['tipo_materiale'] ?? 'link';
                $titolo = $_POST['titolo'] ?? '';
                $url = $_POST['url'] ?? '';
                $descrizione = $_POST['descrizione'] ?? '';

                if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new Exception("URL non valido");
                }

                $materialId = 'MAT_' . uniqid();
                $dbAdapter->insertRow('MATERIALI', [
                    'id_materiale' => $materialId,
                    'id_uda' => $idUda,
                    'tipo_materiale' => $tipoMateriale,
                    'nome' => $titolo,
                    'descrizione' => $descrizione,
                    'url_drive' => $url,
                    'data_creazione' => date('Y-m-d H:i:s')
                ]);

                $message = "Link aggiunto con successo!";
                $messageType = "success";
                break;

            case 'import_classroom_topic':
                $idUda = trim((string)($_POST['id_uda'] ?? ''));
                $payloadRaw = trim((string)($_POST['import_payload'] ?? ''));
                if ($idUda === '') {
                    throw new Exception("Seleziona un'UDA per importare le risorse.");
                }
                if ($payloadRaw === '') {
                    throw new Exception("Nessuna risorsa selezionata per l'import.");
                }

                $payload = json_decode($payloadRaw, true);
                if (!is_array($payload)) {
                    throw new Exception("Payload import non valido.");
                }

                $importedMaterials = 0;
                $importedTests = 0;
                foreach ($payload as $row) {
                    if (!is_array($row)) {
                        continue;
                    }

                    $url = trim((string)($row['url'] ?? ''));
                    if ($url === '' || !filter_var($url, FILTER_VALIDATE_URL)) {
                        continue;
                    }

                    $title = trim((string)($row['title'] ?? ''));
                    if ($title === '') {
                        $title = 'Risorsa Classroom';
                    }

                    $description = trim((string)($row['description'] ?? ''));
                    $sourceLabel = trim((string)($row['source_label'] ?? 'Classroom'));
                    $destination = trim((string)($row['destination'] ?? 'materiale'));
                    $isAssignment = !empty($row['is_assignment']);
                    $sourceId = trim((string)($row['source_id'] ?? ''));
                    $courseId = trim((string)($row['classroom_course_id'] ?? ''));
                    $topicId = trim((string)($row['topic_id'] ?? ''));

                    if ($destination === 'test') {
                        $platform = detectImportedTestPlatform($url, $isAssignment);
                        $noteParts = [];
                        if ($sourceLabel !== '') {
                            $noteParts[] = 'Import da ' . $sourceLabel;
                        }
                        if (!empty($row['work_type'])) {
                            $noteParts[] = 'Tipo Classroom: ' . trim((string)$row['work_type']);
                        }

                        $formId = '';
                        if ($platform === 'google-forms') {
                            $formId = extractGoogleFormIdFromValue($url) ?? '';
                        }

                        $testData = [
                            'id_test' => 'TEST_' . uniqid(),
                            'id_uda' => $idUda,
                            'tipo_test' => 'altro',
                            'nome' => $title,
                            'descrizione' => $description,
                            'piattaforma' => $platform,
                            'url' => $url,
                            'url_studenti' => $url,
                            'url_docente' => $url,
                            'id_esterno' => $platform === 'google-forms' ? $formId : $sourceId,
                            'data_creazione' => date('Y-m-d H:i:s'),
                            'pubblicato' => 'NO',
                            'risultati_importati' => 'NO',
                            'note' => implode(' | ', $noteParts)
                        ];

                        if ($platform === 'google-classroom') {
                            if ($courseId !== '') {
                                $testData['classroom_course_id'] = $courseId;
                            }
                            if ($sourceId !== '') {
                                $testData['classroom_assignment_id'] = $sourceId;
                            }
                            if ($topicId !== '') {
                                $testData['classroom_topic_id'] = $topicId;
                            }
                        }

                        $dbAdapter->insertRow('TEST', $testData);
                        $importedTests++;
                        continue;
                    }

                    $rawMaterialType = trim((string)($row['material_type'] ?? ''));
                    $materialType = $rawMaterialType !== ''
                        ? normalizeImportedMaterialType($rawMaterialType)
                        : detectImportedMaterialTypeFromUrl($url);

                    $descrParts = [];
                    if ($description !== '') {
                        $descrParts[] = $description;
                    }
                    if ($sourceLabel !== '') {
                        $descrParts[] = 'Import da ' . $sourceLabel;
                    }

                    $dbAdapter->insertRow('MATERIALI', [
                        'id_materiale' => 'MAT_' . uniqid(),
                        'id_uda' => $idUda,
                        'tipo_materiale' => $materialType,
                        'nome' => $title,
                        'descrizione' => implode(' | ', $descrParts),
                        'url_drive' => $url,
                        'data_creazione' => date('Y-m-d H:i:s')
                    ]);
                    $importedMaterials++;
                }

                if ($importedMaterials === 0 && $importedTests === 0) {
                    throw new Exception("Nessuna risorsa valida da importare.");
                }

                $message = "Import completato: {$importedMaterials} materiali e {$importedTests} test.";
                $messageType = "success";
                break;

            case 'delete':
                // Elimina materiale
                $materialId = trim((string)($_POST['material_id'] ?? ''));
                if ($materialId === '') {
                    throw new Exception("ID materiale mancante.");
                }

                // Recupera info materiale per eliminare anche da Drive se necessario
                $material = $dbAdapter->findOne('MATERIALI', 'id_materiale', $materialId);

                if ($material && !empty($material['file_id_drive'])) {
                    // Elimina da Google Drive
                    try {
                        $driveApi = new GoogleDriveAPI($config);
                        $driveApi->deleteFile($material['file_id_drive']);
                    } catch (\Throwable $e) {
                        error_log('Errore eliminazione file Drive materiale ' . $materialId . ': ' . $e->getMessage());
                    }
                }

                // Elimina dal database
                $dbAdapter->deleteRow('MATERIALI', $materialId, 'id_materiale');

                $message = "Materiale eliminato con successo!";
                $messageType = "success";
                break;
        }
    } catch (\Throwable $e) {
        $message = "Errore: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Recupera tutte le UDA
$udas = $udaManager->getAllUDAs();

// Recupera tutti i materiali
$allMaterials = $dbAdapter->findAll('MATERIALI');

// Filtra per UDA se specificato
$selectedUdaId = $_GET['uda_id'] ?? $_GET['id'] ?? '';
$selectedUdaId = is_string($selectedUdaId) ? trim($selectedUdaId) : '';
if (!empty($selectedUdaId)) {
    $materials = array_filter($allMaterials, function($m) use ($selectedUdaId) {
        return ($m['id_uda'] ?? '') === $selectedUdaId;
    });
} else {
    $materials = $allMaterials;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Materiali Didattici - UDA System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .material-card {
            transition: all 0.3s;
            border-left: 4px solid #0d6efd;
        }
        .material-card:hover {
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            transform: translateY(-2px);
        }
        .material-type-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
        .upload-zone {
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s;
            cursor: pointer;
        }
        .upload-zone:hover {
            border-color: #0d6efd;
            background-color: #f8f9fa;
        }
        .upload-zone.drag-over {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-folder"></i> Gestione Materiali Didattici';
    $pageSubtitle = 'Carica file su Google Drive o aggiungi link esterni';
    $headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container mt-4">


        <?php if (!empty($message)): ?>
        <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
            <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
            <?= htmlspecialchars($message) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <!-- Filtro UDA -->
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-end">
                    <div class="col-md-6">
                        <label for="filter-uda" class="form-label">
                            <i class="bi bi-filter"></i> Filtra per UDA
                        </label>
                        <select id="filter-uda" class="form-select" onchange="filterByUda(this.value)">
                            <option value="">Tutte le UDA</option>
                            <?php foreach ($udas as $uda): ?>
                            <option value="<?= htmlspecialchars($uda->id_uda ?? '') ?>"
                                    <?= ($uda->id_uda ?? '') === $selectedUdaId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($uda->titolo ?? 'Senza titolo') ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6 text-end">
                        <button type="button" class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                            <i class="bi bi-cloud-upload"></i> Carica File
                        </button>
                        <button type="button" class="btn btn-outline-secondary ms-2" onclick="openDrivePickerForMaterials()">
                            <i class="bi bi-cloud-arrow-down"></i> Seleziona da Drive
                        </button>
                        <button type="button" class="btn btn-outline-primary ms-2" data-bs-toggle="modal" data-bs-target="#linkModal">
                            <i class="bi bi-link-45deg"></i> Aggiungi Link
                        </button>
                        <button type="button" class="btn btn-outline-success ms-2" onclick="openMaterialiClassroomImportModal()">
                            <i class="bi bi-google"></i> Importa da Argomento Classroom
                        </button>
                        <div class="small text-muted mt-2">
                            <i class="bi bi-gear"></i>
                            Imposta la cartella Drive in
                            <a href="/public/user_integrations.php#google-section">Integrazioni Google</a>.
                        </div>
                        <?php if (!$driveRootConfigured): ?>
                            <div class="small text-warning mt-1">
                                <i class="bi bi-exclamation-triangle"></i>
                                ID cartella Drive non configurato: i file caricati verranno salvati nella root di Drive.
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Lista Materiali -->
        <div class="row">
            <?php if (empty($materials)): ?>
            <div class="col-12">
                <div class="alert alert-info">
                    <i class="bi bi-info-circle"></i>
                    Nessun materiale trovato. Inizia caricando un file o aggiungendo un link!
                </div>
            </div>
            <?php else: ?>
                <?php foreach ($materials as $material): ?>
                <div class="col-md-6 col-lg-4 mb-3">
                    <div class="card material-card h-100">
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start mb-2">
                                <h5 class="card-title mb-0">
                                    <i class="bi bi-<?= getMaterialIcon($material['tipo_materiale'] ?? 'documento') ?>"></i>
                                    <?= htmlspecialchars($material['nome'] ?? 'Senza titolo') ?>
                                </h5>
                                <span class="badge bg-secondary material-type-badge">
                                    <?= htmlspecialchars($material['tipo_materiale'] ?? 'N/A') ?>
                                </span>
                            </div>

                            <?php if (!empty($material['descrizione'])): ?>
                            <p class="card-text text-muted small">
                                <?= htmlspecialchars($material['descrizione']) ?>
                            </p>
                            <?php endif; ?>

                            <div class="mb-2">
                                <small class="text-muted">
                                    <i class="bi bi-calendar"></i>
                                    <?= date('d/m/Y', strtotime($material['data_creazione'] ?? 'now')) ?>
                                </small>
                            </div>

                            <div class="d-flex gap-2">
                                <a href="<?= htmlspecialchars($material['url_drive'] ?? '#') ?>"
                                   target="_blank"
                                   class="btn btn-sm btn-primary flex-grow-1">
                                    <i class="bi bi-box-arrow-up-right"></i> Apri
                                </a>
                                <button type="button"
                                        class="btn btn-sm btn-outline-danger"
                                        onclick="confirmDelete('<?= htmlspecialchars($material['id_materiale'] ?? '') ?>', '<?= htmlspecialchars($material['nome'] ?? '') ?>')">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

    <!-- Modal Upload File -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-cloud-upload"></i> Carica File su Google Drive
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload_file">

                        <div class="mb-3">
                            <label for="upload-uda" class="form-label">UDA *</label>
                            <select id="upload-uda" name="id_uda" class="form-select" required>
                                <option value="">Seleziona UDA...</option>
                                <?php foreach ($udas as $uda): ?>
                                <option value="<?= htmlspecialchars($uda->id_uda ?? '') ?>"
                                        <?= ($uda->id_uda ?? '') === $selectedUdaId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uda->titolo ?? 'Senza titolo') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="upload-tipo" class="form-label">Tipo Materiale *</label>
                            <select id="upload-tipo" name="tipo_materiale" class="form-select" required>
                                <option value="documento">Documento</option>
                                <option value="foglio_calcolo">Foglio di Calcolo</option>
                                <option value="presentazione">Presentazione</option>
                                <option value="immagine">Immagine</option>
                                <option value="video">Video</option>
                                <option value="video_youtube">Video YouTube</option>
                                <option value="link">Link</option>
                                <option value="sito_web">Sito Web</option>
                                <option value="risorsa_online">Risorsa Online</option>
                                <option value="altro">Altro</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="upload-file" class="form-label">File *</label>
                            <div class="upload-zone" id="uploadZone">
                                <i class="bi bi-cloud-upload fs-1 text-muted"></i>
                                <p class="mb-2">Trascina qui il file o clicca per selezionare</p>
                                <input type="file"
                                       id="upload-file"
                                       name="file"
                                       class="form-control"
                                       required
                                       style="display: none;">
                                <button type="button" class="btn btn-sm btn-outline-primary" onclick="event.stopPropagation(); document.getElementById('upload-file').click()">
                                    Seleziona File
                                </button>
                                <div id="file-name" class="mt-2 text-muted small"></div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="upload-descrizione" class="form-label">Descrizione</label>
                            <textarea id="upload-descrizione"
                                      name="descrizione"
                                      class="form-control"
                                      rows="3"
                                      placeholder="Descrizione del materiale..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-upload"></i> Carica
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Aggiungi Link -->
    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-link-45deg"></i> Aggiungi Link Esterno
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_link">

                        <div class="mb-3">
                            <label for="link-uda" class="form-label">UDA *</label>
                            <select id="link-uda" name="id_uda" class="form-select" required>
                                <option value="">Seleziona UDA...</option>
                                <?php foreach ($udas as $uda): ?>
                                <option value="<?= htmlspecialchars($uda->id_uda ?? '') ?>"
                                        <?= ($uda->id_uda ?? '') === $selectedUdaId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uda->titolo ?? 'Senza titolo') ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="link-tipo" class="form-label">Tipo Materiale *</label>
                            <select id="link-tipo" name="tipo_materiale" class="form-select" required>
                                <option value="documento">Documento</option>
                                <option value="foglio_calcolo">Foglio di Calcolo</option>
                                <option value="presentazione">Presentazione</option>
                                <option value="immagine">Immagine</option>
                                <option value="video">Video</option>
                                <option value="video_youtube">Video YouTube</option>
                                <option value="link">Link</option>
                                <option value="sito_web">Sito Web</option>
                                <option value="risorsa_online">Risorsa Online</option>
                                <option value="altro">Altro</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="link-titolo" class="form-label">Titolo *</label>
                            <input type="text"
                                   id="link-titolo"
                                   name="titolo"
                                   class="form-control"
                                   required
                                   placeholder="Es: Tutorial Python Basics">
                        </div>

                        <div class="mb-3">
                            <label for="link-url" class="form-label">URL *</label>
                            <input type="url"
                                   id="link-url"
                                   name="url"
                                   class="form-control"
                                   required
                                   placeholder="https://...">
                        </div>

                        <div class="mb-3">
                            <label for="link-descrizione" class="form-label">Descrizione</label>
                            <textarea id="link-descrizione"
                                      name="descrizione"
                                      class="form-control"
                                      rows="3"
                                      placeholder="Descrizione della risorsa..."></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-circle"></i> Aggiungi
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Import da Argomento Classroom -->
    <div class="modal fade" id="classroomImportModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">
                        <i class="bi bi-google"></i> Importa da Argomento Classroom
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" id="classroomImportForm">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="import_classroom_topic">
                        <input type="hidden" name="import_payload" id="classroomImportPayload">

                        <div id="materialiClassroomImportMsg" class="alert d-none" role="alert"></div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-4">
                                <label for="classroomImportUda" class="form-label">UDA di destinazione</label>
                                <select id="classroomImportUda" name="id_uda" class="form-select" required>
                                    <option value="">Seleziona UDA...</option>
                                    <?php foreach ($udas as $uda): ?>
                                        <option value="<?= htmlspecialchars($uda->id_uda ?? '') ?>"
                                                <?= ($uda->id_uda ?? '') === $selectedUdaId ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($uda->titolo ?? 'Senza titolo') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="classroomImportCourse" class="form-label">Classroom</label>
                                <select id="classroomImportCourse" class="form-select" onchange="loadMaterialiClassroomResources()">
                                    <option value="">Seleziona classroom...</option>
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label for="classroomImportTopic" class="form-label">Argomento</label>
                                <select id="classroomImportTopic" class="form-select" onchange="renderMaterialiClassroomResources()" disabled>
                                    <option value="">Seleziona argomento...</option>
                                </select>
                            </div>
                        </div>

                        <div id="materialiClassroomImportLoading" class="text-center text-muted py-3 d-none">
                            <div class="spinner-border spinner-border-sm me-2" role="status"></div>
                            Caricamento risorse Classroom...
                        </div>

                        <div class="border rounded">
                            <div class="d-flex justify-content-between align-items-center p-2 border-bottom bg-light">
                                <div class="form-check mb-0">
                                    <input class="form-check-input" type="checkbox" id="classroomImportSelectAll" checked onchange="toggleMaterialiClassroomSelectAll(this)">
                                    <label class="form-check-label" for="classroomImportSelectAll">Seleziona tutti</label>
                                </div>
                                <small class="text-muted">Scegli per ogni link se importarlo come materiale o test</small>
                            </div>
                            <div class="table-responsive" style="max-height: 360px;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light position-sticky top-0">
                                        <tr>
                                            <th style="width: 55px;">Importa</th>
                                            <th>Risorsa</th>
                                            <th style="width: 180px;">Importa come</th>
                                            <th style="width: 190px;">Tipo materiale</th>
                                        </tr>
                                    </thead>
                                    <tbody id="classroomImportResourcesBody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div id="classroomImportResourcesEmpty" class="alert alert-light border mt-3 mb-0 d-none">
                            Nessuna risorsa trovata per l'argomento selezionato.
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="button" class="btn btn-primary" onclick="submitMaterialiClassroomImport()">
                            <i class="bi bi-download"></i> Importa Selezionati
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form nascosto per eliminazione -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="material_id" id="delete-material-id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://apis.google.com/js/api.js"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script>
        // Filtra materiali per UDA
        function filterByUda(udaId) {
            window.location.href = '?uda_id=' + encodeURIComponent(udaId);
        }

        function resolveMaterialTypeFromMime(mimeType) {
            if (!mimeType) return '';
            const normalized = mimeType.toLowerCase();
            if (normalized.startsWith('image/')) return 'immagine';
            if (normalized.startsWith('video/')) return 'video';
            if (normalized.startsWith('audio/')) return 'altro';
            const map = {
                'application/pdf': 'documento',
                'application/msword': 'documento',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'documento',
                'application/vnd.google-apps.document': 'documento',
                'text/plain': 'documento',
                'text/html': 'documento',
                'application/vnd.ms-powerpoint': 'presentazione',
                'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'presentazione',
                'application/vnd.google-apps.presentation': 'presentazione',
                'application/vnd.ms-excel': 'foglio_calcolo',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'foglio_calcolo',
                'application/vnd.google-apps.spreadsheet': 'foglio_calcolo',
                'application/vnd.google-apps.drawing': 'immagine',
                'application/vnd.google-apps.video': 'video',
                'application/vnd.google-apps.form': 'link',
                'application/vnd.google-apps.site': 'sito_web',
                'application/vnd.google-apps.folder': 'link'
            };
            return map[normalized] || '';
        }

        function applyMaterialTypeFromMime(selectEl, mimeType, fallbackValue = '') {
            if (!selectEl) return;
            const resolved = resolveMaterialTypeFromMime(mimeType);
            if (resolved) {
                selectEl.value = resolved;
            } else if (fallbackValue) {
                selectEl.value = fallbackValue;
            }
        }

        // Drag & Drop file upload
        const uploadZone = document.getElementById('uploadZone');
        const fileInput = document.getElementById('upload-file');
        const fileName = document.getElementById('file-name');

        uploadZone.addEventListener('click', () => fileInput.click());

        uploadZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            uploadZone.classList.add('drag-over');
        });

        uploadZone.addEventListener('dragleave', () => {
            uploadZone.classList.remove('drag-over');
        });

        uploadZone.addEventListener('drop', (e) => {
            e.preventDefault();
            uploadZone.classList.remove('drag-over');

            const files = e.dataTransfer.files;
            if (files.length > 0) {
                fileInput.files = files;
                fileName.textContent = 'File selezionato: ' + files[0].name;
                applyMaterialTypeFromMime(document.getElementById('upload-tipo'), files[0].type || '');
            }
        });

        fileInput.addEventListener('change', (e) => {
            if (e.target.files.length > 0) {
                fileName.textContent = 'File selezionato: ' + e.target.files[0].name;
                applyMaterialTypeFromMime(document.getElementById('upload-tipo'), e.target.files[0].type || '');
            }
        });

        // Conferma eliminazione
        function confirmDelete(materialId, materialTitle) {
            if (confirm('Sei sicuro di voler eliminare "' + materialTitle + '"?\n\nQuesta azione non può essere annullata.')) {
                document.getElementById('delete-material-id').value = materialId;
                document.getElementById('deleteForm').submit();
            }
        }
    </script>
    <script>
        const pickerApiKey = "<?= htmlspecialchars($pickerApiKey) ?>";
        const pickerClientId = "<?= htmlspecialchars($pickerClientId) ?>";
        const selectedUdaId = <?= json_encode($selectedUdaId) ?>;
        let pickerInited = false;
        let tokenClient = null;
        let driveAccessToken = null;
        const driveTokenCacheKey = 'uda_drive_token';

        function loadCachedDriveToken() {
            try {
                const raw = localStorage.getItem(driveTokenCacheKey);
                if (!raw) return null;
                const data = JSON.parse(raw);
                if (!data.access_token || !data.expiry) return null;
                if (Date.now() > (data.expiry - 60000)) {
                    localStorage.removeItem(driveTokenCacheKey);
                    return null;
                }
                driveAccessToken = data.access_token;
                return driveAccessToken;
            } catch (e) {
                return null;
            }
        }

        function cacheDriveToken(token, expiresInSec = 3600) {
            if (!token) return;
            const expiry = Date.now() + (expiresInSec * 1000);
            const data = { access_token: token, expiry };
            try {
                localStorage.setItem(driveTokenCacheKey, JSON.stringify(data));
            } catch (e) { }
            driveAccessToken = token;
        }

        function clearCachedDriveToken() {
            driveAccessToken = null;
            localStorage.removeItem(driveTokenCacheKey);
        }

        function ensurePickerLoaded() {
            return new Promise((resolve, reject) => {
                if (pickerInited) return resolve();
                gapi.load('picker', {
                    callback: () => { pickerInited = true; resolve(); },
                    onerror: () => reject('Errore caricamento Google Picker')
                });
            });
        }

        function ensureTokenClient() {
            if (typeof google === 'undefined' || !google.accounts || !google.accounts.oauth2) {
                alert('Google Identity non e stato caricato. Ricarica la pagina (verifica che accounts.google.com/gsi/client non sia bloccato).');
                return null;
            }
            if (tokenClient) return tokenClient;
            if (!pickerClientId) {
                alert('Config Picker mancante: imposta web.client_id in google_credentials.json');
                return null;
            }
            tokenClient = google.accounts.oauth2.initTokenClient({
                client_id: pickerClientId,
                scope: 'https://www.googleapis.com/auth/drive.file',
                callback: (res) => {
                    if (res && res.access_token) {
                        driveAccessToken = res.access_token;
                    } else {
                        alert('Autorizzazione negata.');
                    }
                }
            });
            return tokenClient;
        }

        async function openDrivePickerForMaterials() {
            if (!pickerApiKey || !pickerClientId) {
                alert('Config Picker mancante: definire GOOGLE_API_KEY e web.client_id in google_credentials.json');
                return;
            }
            loadCachedDriveToken();
            const tc = ensureTokenClient();
            if (!tc) return;
            const openPickerNow = async () => {
                try {
                    await ensurePickerLoaded();
                    const view = new google.picker.DocsView(google.picker.ViewId.DOCS);
                    view.setIncludeFolders(true);
                    view.setSelectFolderEnabled(true);
                    const picker = new google.picker.PickerBuilder()
                        .setDeveloperKey(pickerApiKey)
                        .setOAuthToken(driveAccessToken)
                        .addView(view)
                        .enableFeature(google.picker.Feature.MULTISELECT_ENABLED)
                        .enableFeature(google.picker.Feature.NAV_HIDDEN)
                        .setCallback(pickerCallbackMaterials)
                        .build();
                    picker.setVisible(true);
                } catch (err) {
                    alert('Errore apertura picker: ' + err);
                }
            };

            if (driveAccessToken) {
                openPickerNow();
                return;
            }

            tc.callback = (res) => {
                if (res && res.access_token) {
                    cacheDriveToken(res.access_token, res.expires_in || 3600);
                    openPickerNow();
                } else {
                    clearCachedDriveToken();
                    alert('Autorizzazione negata.');
                }
            };
            const hasCached = !!loadCachedDriveToken();
            tc.requestAccessToken({
                prompt: hasCached ? 'none' : 'consent'
            });
        }

        function pickerCallbackMaterials(data) {
            if (data.action !== google.picker.Action.PICKED) return;
            const doc = data.docs[0];
            if (!doc) return;
            const url = doc.url || doc.alternateLink || '';
            const name = doc.name || doc.title || '';
            const linkUda = document.getElementById('link-uda');
            if (linkUda && selectedUdaId) linkUda.value = selectedUdaId;
            const titleInput = document.getElementById('link-titolo');
            const urlInput = document.getElementById('link-url');
            const typeSelect = document.getElementById('link-tipo');
            const descrInput = document.getElementById('link-descrizione');

            if (urlInput) urlInput.value = url;
            if (titleInput && !titleInput.value) titleInput.value = name;
            const resolvedType = resolveMaterialTypeFromMime(doc.mimeType || '');
            if (typeSelect) typeSelect.value = resolvedType || 'link';
            if (descrInput && !descrInput.value) descrInput.value = doc.mimeType || '';

            const linkModalEl = document.getElementById('linkModal');
            if (linkModalEl && typeof bootstrap !== 'undefined') {
                const modal = bootstrap.Modal.getInstance(linkModalEl) || new bootstrap.Modal(linkModalEl);
                modal.show();
            }
        }
    </script>
    <script>
        const materialiClassroomIntegrationUrl = 'user_integrations.php#google-section';
        let materialiClassroomResources = [];

        function escapeMaterialiClassroomHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function detectMaterialiClassroomMaterialType(url, suggested = '') {
            if (suggested) return suggested;
            const normalized = String(url || '').toLowerCase().trim();
            if (!normalized) return 'link';
            if (normalized.includes('youtube.com') || normalized.includes('youtu.be')) return 'video_youtube';
            if (normalized.includes('docs.google.com/spreadsheets')) return 'foglio_calcolo';
            if (normalized.includes('docs.google.com/presentation')) return 'presentazione';
            if (normalized.includes('docs.google.com/document') || /\.(pdf|doc|docx|txt)$/i.test(normalized)) return 'documento';
            if (/\.(jpg|jpeg|png|gif|webp|svg)$/i.test(normalized)) return 'immagine';
            if (/\.(mp4|avi|mov|webm)$/i.test(normalized)) return 'video';
            return 'link';
        }

        function getMaterialiClassroomTypeOptions(selectedValue = 'link') {
            const options = [
                ['documento', 'Documento'],
                ['foglio_calcolo', 'Foglio di Calcolo'],
                ['presentazione', 'Presentazione'],
                ['immagine', 'Immagine'],
                ['video', 'Video'],
                ['video_youtube', 'Video YouTube'],
                ['link', 'Link'],
                ['sito_web', 'Sito Web'],
                ['risorsa_online', 'Risorsa Online'],
                ['altro', 'Altro']
            ];
            return options.map(([value, label]) => {
                const selected = value === selectedValue ? ' selected' : '';
                return `<option value="${value}"${selected}>${label}</option>`;
            }).join('');
        }

        function setMaterialiClassroomMessage(type, message, withIntegrationLink = false) {
            const box = document.getElementById('materialiClassroomImportMsg');
            if (!box) return;
            if (!message) {
                box.className = 'alert d-none';
                box.innerHTML = '';
                return;
            }
            box.className = `alert alert-${type || 'info'}`;
            if (withIntegrationLink) {
                box.innerHTML = `${escapeMaterialiClassroomHtml(message)} <a href="${materialiClassroomIntegrationUrl}" class="alert-link">Apri integrazioni</a>`;
            } else {
                box.textContent = message;
            }
        }

        function setMaterialiClassroomLoading(isLoading) {
            const loading = document.getElementById('materialiClassroomImportLoading');
            if (!loading) return;
            loading.classList.toggle('d-none', !isLoading);
        }

        function toggleMaterialiClassroomSelectAll(masterCheckbox) {
            const checked = masterCheckbox ? !!masterCheckbox.checked : false;
            document.querySelectorAll('.materiali-classroom-check').forEach(input => {
                input.checked = checked;
            });
        }

        function updateMaterialiClassroomDestination(selectEl) {
            const row = selectEl.closest('tr');
            if (!row) return;
            const materialTypeSelect = row.querySelector('.materiali-classroom-material-type');
            if (!materialTypeSelect) return;
            materialTypeSelect.disabled = selectEl.value === 'test';
        }

        function fillMaterialiClassroomTopicSelect(topics, resources) {
            const topicSelect = document.getElementById('classroomImportTopic');
            if (!topicSelect) return;

            topicSelect.innerHTML = '';
            const noTopicResources = resources.filter(r => !(r.topic_id || '').trim());
            if (topics.length > 0) {
                topics.forEach((topic, idx) => {
                    const option = document.createElement('option');
                    option.value = topic.id;
                    option.textContent = topic.name;
                    if (idx === 0) option.selected = true;
                    topicSelect.appendChild(option);
                });
                if (noTopicResources.length > 0) {
                    const option = document.createElement('option');
                    option.value = '__NO_TOPIC__';
                    option.textContent = 'Senza argomento';
                    topicSelect.appendChild(option);
                }
                topicSelect.disabled = false;
                return;
            }

            if (noTopicResources.length > 0) {
                const option = document.createElement('option');
                option.value = '__NO_TOPIC__';
                option.textContent = 'Senza argomento';
                option.selected = true;
                topicSelect.appendChild(option);
                topicSelect.disabled = false;
                return;
            }

            const placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Nessun argomento disponibile';
            topicSelect.appendChild(placeholder);
            topicSelect.disabled = true;
        }

        function renderMaterialiClassroomResources() {
            const body = document.getElementById('classroomImportResourcesBody');
            const emptyBox = document.getElementById('classroomImportResourcesEmpty');
            const topicSelect = document.getElementById('classroomImportTopic');
            const master = document.getElementById('classroomImportSelectAll');
            if (!body || !emptyBox || !topicSelect || !master) return;

            const selectedTopicId = String(topicSelect.value || '');
            const filtered = materialiClassroomResources.filter(resource => {
                const topic = String(resource.topic_id || '').trim();
                if (selectedTopicId === '__NO_TOPIC__') return topic === '';
                if (!selectedTopicId) return false;
                return topic === selectedTopicId;
            });

            body.innerHTML = '';
            if (filtered.length === 0) {
                emptyBox.classList.remove('d-none');
                master.checked = false;
                return;
            }
            emptyBox.classList.add('d-none');
            master.checked = true;

            filtered.forEach((resource) => {
                const destination = resource.default_destination === 'test' ? 'test' : 'materiale';
                const suggestedMaterialType = detectMaterialiClassroomMaterialType(resource.url || '', resource.suggested_material_type || '');

                const row = document.createElement('tr');
                row.dataset.resourceId = String(resource.resource_id || '');
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input materiali-classroom-check" checked>
                    </td>
                    <td>
                        <div class="fw-semibold">${escapeMaterialiClassroomHtml(resource.title || 'Risorsa Classroom')}</div>
                        <div class="small text-muted">
                            <span class="badge bg-light text-dark border me-1">${escapeMaterialiClassroomHtml(resource.source_label || 'Classroom')}</span>
                            ${(resource.work_type ? `<span class="badge bg-light text-dark border me-1">${escapeMaterialiClassroomHtml(resource.work_type)}</span>` : '')}
                            ${destination === 'test' ? '<span class="badge bg-warning text-dark">Test suggerito</span>' : ''}
                        </div>
                        ${(resource.description ? `<div class="small text-muted mt-1">${escapeMaterialiClassroomHtml(resource.description)}</div>` : '')}
                        <div class="small mt-1">
                            <a href="${escapeMaterialiClassroomHtml(resource.url || '')}" target="_blank" rel="noopener">Apri link</a>
                        </div>
                    </td>
                    <td>
                        <select class="form-select form-select-sm materiali-classroom-destination" onchange="updateMaterialiClassroomDestination(this)">
                            <option value="materiale"${destination === 'materiale' ? ' selected' : ''}>Materiale</option>
                            <option value="test"${destination === 'test' ? ' selected' : ''}>Test</option>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm materiali-classroom-material-type" ${destination === 'test' ? 'disabled' : ''}>
                            ${getMaterialiClassroomTypeOptions(suggestedMaterialType)}
                        </select>
                    </td>
                `;
                body.appendChild(row);
            });
        }

        async function loadMaterialiClassroomCourses() {
            const select = document.getElementById('classroomImportCourse');
            if (!select) return false;

            setMaterialiClassroomLoading(true);
            setMaterialiClassroomMessage('info', 'Caricamento classroom...');
            try {
                const response = await fetch('ajax_get_classroom_courses_import.php', { credentials: 'same-origin' });
                const raw = await response.text();
                let result = null;
                try {
                    result = JSON.parse(raw);
                } catch (e) {
                    result = null;
                }

                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.error) ? result.error : 'Impossibile caricare le classroom.');
                }

                const courses = Array.isArray(result.courses) ? result.courses : [];
                select.innerHTML = '';
                if (courses.length === 0) {
                    const emptyOption = document.createElement('option');
                    emptyOption.value = '';
                    emptyOption.textContent = 'Nessuna classroom disponibile';
                    select.appendChild(emptyOption);
                    setMaterialiClassroomMessage('warning', 'Classroom non collegato o senza corsi disponibili.', true);
                    return false;
                }

                courses.forEach((course, idx) => {
                    const option = document.createElement('option');
                    option.value = course.id || '';
                    option.textContent = course.name || `Classroom ${idx + 1}`;
                    if (idx === 0) option.selected = true;
                    select.appendChild(option);
                });
                setMaterialiClassroomMessage('', '');
                return true;
            } catch (error) {
                setMaterialiClassroomMessage('warning', error.message || 'Impossibile caricare le classroom.', true);
                return false;
            } finally {
                setMaterialiClassroomLoading(false);
            }
        }

        async function loadMaterialiClassroomResources() {
            const courseSelect = document.getElementById('classroomImportCourse');
            if (!courseSelect) return;
            const courseId = String(courseSelect.value || '').trim();
            if (!courseId) {
                materialiClassroomResources = [];
                renderMaterialiClassroomResources();
                return;
            }

            setMaterialiClassroomLoading(true);
            setMaterialiClassroomMessage('info', 'Caricamento argomenti e risorse...');
            try {
                const response = await fetch(`ajax_get_classroom_topic_resources.php?course_id=${encodeURIComponent(courseId)}`, {
                    credentials: 'same-origin'
                });
                const raw = await response.text();
                let result = null;
                try {
                    result = JSON.parse(raw);
                } catch (e) {
                    result = null;
                }

                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.error) ? result.error : 'Errore durante il caricamento delle risorse.');
                }

                const topics = Array.isArray(result.topics) ? result.topics : [];
                const resources = Array.isArray(result.resources) ? result.resources : [];
                materialiClassroomResources = resources.filter(item => String(item.url || '').trim() !== '');
                fillMaterialiClassroomTopicSelect(topics, materialiClassroomResources);
                renderMaterialiClassroomResources();
                setMaterialiClassroomMessage('', '');
            } catch (error) {
                materialiClassroomResources = [];
                renderMaterialiClassroomResources();
                setMaterialiClassroomMessage('danger', error.message || 'Impossibile caricare le risorse Classroom.');
            } finally {
                setMaterialiClassroomLoading(false);
            }
        }

        async function openMaterialiClassroomImportModal() {
            const modalEl = document.getElementById('classroomImportModal');
            if (!modalEl || typeof bootstrap === 'undefined') return;

            const udaSelect = document.getElementById('classroomImportUda');
            if (udaSelect && selectedUdaId) {
                udaSelect.value = selectedUdaId;
            }

            const modal = bootstrap.Modal.getInstance(modalEl) || new bootstrap.Modal(modalEl);
            modal.show();

            const hasCourses = await loadMaterialiClassroomCourses();
            if (hasCourses) {
                await loadMaterialiClassroomResources();
            } else {
                const body = document.getElementById('classroomImportResourcesBody');
                const emptyBox = document.getElementById('classroomImportResourcesEmpty');
                if (body) body.innerHTML = '';
                if (emptyBox) {
                    emptyBox.classList.remove('d-none');
                    emptyBox.textContent = 'Collega Google Classroom dalle integrazioni per usare questo import.';
                }
            }
        }

        function submitMaterialiClassroomImport() {
            const form = document.getElementById('classroomImportForm');
            const payloadInput = document.getElementById('classroomImportPayload');
            const udaSelect = document.getElementById('classroomImportUda');
            const courseSelect = document.getElementById('classroomImportCourse');
            if (!form || !payloadInput || !udaSelect || !courseSelect) return;

            if (!udaSelect.value) {
                alert('Seleziona l\'UDA di destinazione.');
                return;
            }

            const rows = document.querySelectorAll('#classroomImportResourcesBody tr[data-resource-id]');
            if (!rows.length) {
                alert('Nessuna risorsa disponibile da importare.');
                return;
            }

            const payload = [];
            rows.forEach((row) => {
                const check = row.querySelector('.materiali-classroom-check');
                if (!check || !check.checked) return;

                const resourceId = String(row.dataset.resourceId || '');
                const resource = materialiClassroomResources.find(item => String(item.resource_id || '') === resourceId);
                if (!resource) return;

                const destinationSelect = row.querySelector('.materiali-classroom-destination');
                const materialTypeSelect = row.querySelector('.materiali-classroom-material-type');
                const destination = destinationSelect ? destinationSelect.value : 'materiale';
                const materialType = materialTypeSelect ? materialTypeSelect.value : 'link';

                payload.push({
                    title: resource.title || '',
                    description: resource.description || '',
                    url: resource.url || '',
                    destination: destination,
                    material_type: materialType,
                    is_assignment: !!resource.is_assignment,
                    source_id: resource.source_id || '',
                    source_label: resource.source_label || 'Classroom',
                    work_type: resource.work_type || '',
                    classroom_course_id: courseSelect.value || '',
                    topic_id: resource.topic_id || ''
                });
            });

            if (payload.length === 0) {
                alert('Seleziona almeno una risorsa da importare.');
                return;
            }

            payloadInput.value = JSON.stringify(payload);
            form.submit();
        }
    </script>
</body>
</html>

<?php
// Helper function per icone
function getMaterialIcon($type) {
    $icons = [
        'documento' => 'file-text',
        'presentazione' => 'file-earmark-slides',
        'video' => 'camera-video',
        'video_youtube' => 'youtube',
        'immagine' => 'image',
        'foglio_calcolo' => 'file-earmark-spreadsheet',
        'link' => 'link-45deg',
        'sito_web' => 'globe',
        'risorsa_online' => 'cloud',
    ];
    return $icons[$type] ?? 'file-earmark';
}
?>
