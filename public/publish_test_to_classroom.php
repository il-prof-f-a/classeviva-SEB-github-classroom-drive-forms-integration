<?php
/**
 * Pubblicazione Test su Google Classroom - Versione Semplificata
 * Gestisce la pubblicazione di test (Google Forms, Kahoot, ecc.) su Google Classroom
 * Modalità: link diretto o file .seb con placeholder
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\GoogleClassroomAPI;
use App\Integration\GoogleDriveAPI;

// Inizializzazione con gestione errori completa
$initError = null;
$test = null;
$uda = null;
$udaId = null;
$mode = $_GET['mode'] ?? 'draft';
$testId = $_GET['test_id'] ?? null;
$successMessage = null;
$errorMessage = null;

function formatClassroomPublishError(Throwable $e): string
{
    if ($e instanceof \Google\Service\Exception) {
        $code = (int)$e->getCode();
        $errors = $e->getErrors();
        $firstError = (is_array($errors) && !empty($errors[0]) && is_array($errors[0])) ? $errors[0] : [];
        $reason = strtolower((string)($firstError['reason'] ?? ''));

        if ($code === 400 && $reason === 'failedprecondition') {
            return "Precondizione Classroom non soddisfatta: il corso potrebbe non essere ATTIVO/modificabile oppure il materiale allegato non è valido per Classroom.";
        }

        if ($code === 403 && in_array($reason, ['forbidden', 'insufficientpermissions', 'permissiondenied'], true)) {
            return "Permessi Google insufficienti: riesegui l'autenticazione da google_auth.php con gli scope Classroom richiesti.";
        }
    }

    return $e->getMessage();
}

try {
    if (!$testId) {
        throw new Exception("ID Test mancante");
    }

    $udaManager = new UDAManager($config);
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

    // Carica test
    $allTests = $dbAdapter->findAll('TEST');
    foreach ($allTests as $t) {
        if (($t['id_test'] ?? '') === $testId) {
            $test = $t;
            break;
        }
    }

    if (!$test) {
        throw new Exception("Test non trovato con ID: " . $testId);
    }

    // Carica UDA
    $udaId = $test['id_uda'] ?? '';
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception("UDA non trovata con ID: " . $udaId);
    }
    $uda = $udaComplete['uda'];

} catch (Throwable $e) {
    $initError = $e->getMessage();
    error_log("ERRORE publish_test_to_classroom: " . $e->getMessage());
    error_log("Stack trace: " . $e->getTraceAsString());
}

// Gestione POST - Pubblicazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish') {
    try {
        $publishMode = $_POST['publish_mode'] ?? 'link';
        $draftOrPublish = $_POST['draft_or_publish'] ?? 'draft';
        $courseId = $_POST['course_id'] ?? '';
        $topicName = $_POST['topic_name'] ?? ($uda ? $uda->titolo : 'Test');
        $testTitle = $_POST['test_title'] ?? ($test['nome'] ?? 'Test');
        $testDescription = $_POST['test_description'] ?? ($test['descrizione'] ?? '');
        $dueDate = $_POST['due_date'] ?? '';
        $dueTime = $_POST['due_time'] ?? '';

        if (empty($courseId)) {
            throw new Exception("Seleziona una classe/corso Classroom");
        }

        $testUrl = $test['url_studenti'] ?? $test['url'] ?? '';
        if (empty($testUrl)) {
            throw new Exception("URL del test non trovato");
        }

        // Istanzia API solo qui
        $classroomAPI = new GoogleClassroomAPI($config);
        $courseInfo = $classroomAPI->getCourse($courseId);
        $courseState = strtoupper((string)($courseInfo['course_state'] ?? ''));
        if ($courseState !== '' && $courseState !== 'ACTIVE') {
            throw new Exception("Il corso selezionato è in stato {$courseState}. Per pubblicare compiti il corso deve essere ACTIVE.");
        }

        // Trova o crea topic
        $topic = $classroomAPI->findOrCreateTopic($courseId, $topicName);
        $topicId = $topic['id'] ?? null;

        error_log("Topic creato/trovato: " . json_encode($topic));
        error_log("TopicId estratto: " . ($topicId ?? 'NULL'));

        // Prepara assignment data
        $assignmentData = [
            'title' => $testTitle,
            'description' => $testDescription,
            'topicId' => $topicId,
            'workType' => 'ASSIGNMENT',
            'state' => $draftOrPublish === 'publish' ? 'PUBLISHED' : 'DRAFT',
            'maxPoints' => floatval($test['punteggio_max'] ?? 100)
        ];

        error_log("Assignment data topicId: " . ($assignmentData['topicId'] ?? 'NULL'));

        // Gestisci data e ora di consegna
        if (!empty($dueDate)) {
            $dateParts = explode('-', $dueDate);
            $assignmentData['dueDate'] = [
                'year' => intval($dateParts[0]),
                'month' => intval($dateParts[1]),
                'day' => intval($dateParts[2])
            ];

            if (!empty($dueTime)) {
                $timeParts = explode(':', $dueTime);
                $assignmentData['dueTime'] = [
                    'hours' => intval($timeParts[0]),
                    'minutes' => intval($timeParts[1])
                ];
            }
        }

        // Modalità di pubblicazione
        if ($publishMode === 'link') {
            // L'API si aspetta un array di URL (stringhe), non oggetti
            $assignmentData['materials'] = [$testUrl];
        } else {
            // Modalità .seb
            $sebTemplateFile = $_FILES['seb_template'] ?? null;

            if (!$sebTemplateFile || $sebTemplateFile['error'] !== UPLOAD_ERR_OK) {
                throw new Exception("File .seb template non caricato correttamente");
            }

            $sebContent = file_get_contents($sebTemplateFile['tmp_name']);
            $sebContent = str_replace('{{link_test}}', $testUrl, $sebContent);

            $tempSebPath = sys_get_temp_dir() . '/' . uniqid('seb_') . '.seb';
            file_put_contents($tempSebPath, $sebContent);

            $driveAPI = new GoogleDriveAPI($config);
            $sebFileName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $test['nome']) . '.seb';
            $driveFolderId = $config['google']['drive']['root_folder_id'] ?? null;
            $driveUploadResult = $driveAPI->uploadFile(
                $tempSebPath,
                $sebFileName,
                !empty($driveFolderId) ? $driveFolderId : null,
                'application/octet-stream'
            );

            @unlink($tempSebPath);

            $assignmentData['materials'] = [
                [
                    'driveFile' => [
                        'driveFile' => [
                            'id' => $driveUploadResult['id'],
                            'title' => $sebFileName
                        ],
                        'shareMode' => 'VIEW'
                    ]
                ]
            ];
        }

        // Crea assignment
        $createdAssignment = $classroomAPI->createAssignment($courseId, $assignmentData);
        // Fallback: se alternateLink non valorizzato, recupera i dettagli appena creati
        if (empty($createdAssignment['link'] ?? '')) {
            try {
                $fresh = $classroomAPI->getAssignment($courseId, $createdAssignment['id']);
                if (!empty($fresh['link'])) {
                    $createdAssignment['link'] = $fresh['link'];
                }
            } catch (Throwable $e) {
                error_log("Impossibile recuperare link assignment: " . $e->getMessage());
            }
        }
        // Ulteriore fallback: link del corso
        if (empty($createdAssignment['link'])) {
            $b64 = function($id) {
                return rtrim(base64_encode((string)$id), '=');
            };
            //if (!empty($createdAssignment['id']) && !empty($courseId)) {
            //    $createdAssignment['link'] = "https://classroom.google.com/w/" . $b64($courseId) . "/tc/" . $b64($createdAssignment['id']);
            //} else
            if (!empty($courseId)) {
                $createdAssignment['link'] = "https://classroom.google.com/w/" . $b64($courseId) . "/t/all";
            }
        }

        // Aggiorna test nel database
        $test['classroom_course_id'] = $courseId;
        $test['classroom_assignment_id'] = $createdAssignment['id'];
        $test['classroom_topic_id'] = $topicId;
        // Sul portale il test non deve più risultare "Bozza" dopo la pubblicazione (anche se in Classroom è bozza)
        $test['pubblicato'] = 'SI';
        $test['url_docente'] = $createdAssignment['link'] ?? '';

        $dbAdapter->updateRow('TEST', 'id_test', $testId, $test);

        $successMessage = "Test pubblicato su Google Classroom con successo!";
        if ($draftOrPublish === 'draft') {
            $successMessage .= " Lo trovi in bozza nel corso selezionato.";
        }


    } catch (Throwable $e) {
        $errorMessage = "Errore durante la pubblicazione: " . formatClassroomPublishError($e);
        error_log("Errore pubblicazione test Classroom: " . $e->getMessage());
    }
}

// Carica corsi Classroom solo per il form (non durante inizializzazione)
$classroomCourses = [];
if (!$initError && $_SERVER['REQUEST_METHOD'] === 'GET') {
    try {
        $classroomAPI = new GoogleClassroomAPI($config);
        $classroomCourses = $classroomAPI->getCourses(['ACTIVE']);
    } catch (Throwable $e) {
        // Non è fatale, mostra solo un warning
        if (!$errorMessage) {
            $errorMessage = "Avviso: Impossibile caricare i corsi Classroom. " . $e->getMessage();
        }
        error_log("Errore caricamento corsi: " . $e->getMessage());
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pubblica Test su Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .test-info-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 20px;
            margin-bottom: 20px;
        }
        .mode-card {
            cursor: pointer;
            transition: all 0.3s;
            border: 2px solid transparent;
        }
        .mode-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 20px rgba(0,0,0,0.1);
        }
        .mode-card.selected {
            border-color: #0d6efd;
            background-color: #e7f1ff;
        }
    </style>
</head>
<body class="bg-light">
    <?php
    $pageTitle = '<i class="bi bi-upload"></i> Pubblicazione Test su Classroom';
    $pageSubtitle = isset($uda) ? ($uda->titolo ?? '') : '';
    include __DIR__ . '/partials/app_header.php';
    ?>


    <div class="container my-4">
        <?php if ($initError): ?>
            <!-- Errore di Inizializzazione -->
            <div class="alert alert-danger">
                <h4><i class="bi bi-exclamation-triangle"></i> Errore di Caricamento</h4>
                <p><?= htmlspecialchars($initError) ?></p>
                <hr>
                <p class="mb-0">
                    <a href="index.php" class="btn btn-primary">
                        <i class="bi bi-arrow-left"></i> Torna alla Dashboard
                    </a>
                </p>
            </div>
        <?php else: ?>
            <!-- Breadcrumb -->
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="uda_view.php?id=<?= urlencode($udaId) ?>"><?= htmlspecialchars($uda->titolo) ?></a></li>
                    <li class="breadcrumb-item"><a href="uda_tests.php?id=<?= urlencode($udaId) ?>">Test</a></li>
                    <li class="breadcrumb-item active">Pubblica su Classroom</li>
                </ol>
            </nav>

            <!-- Info Test -->
            <div class="test-info-card">
                <h2 class="mb-3"><?= htmlspecialchars($test['nome']) ?></h2>
                <div class="row">
                    <div class="col-md-6">
                        <p class="mb-1"><strong>Piattaforma:</strong> <?= strtoupper($test['piattaforma'] ?? 'N/A') ?></p>
                        <p class="mb-1"><strong>Tipo:</strong> <?= ucfirst($test['tipo_test'] ?? 'altro') ?></p>
                    </div>
                    <div class="col-md-6">
                        <p class="mb-1"><strong>UDA:</strong> <?= htmlspecialchars($uda->titolo) ?></p>
                        <p class="mb-1"><strong>Modalità:</strong> <?= $mode === 'draft' ? 'Bozza' : 'Pubblicazione immediata' ?></p>
                    </div>
                </div>
            </div>

            <?php if ($errorMessage): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($successMessage): ?>
                <div class="alert alert-success">
                    <h4 class="alert-heading">
                        <i class="bi bi-check-circle-fill"></i> Pubblicazione Completata!
                    </h4>
                    <p class="mb-3"><?= htmlspecialchars($successMessage) ?></p>
                    <hr>
                    <div class="d-flex gap-2 flex-wrap">
                        <?php
                        // Il link del docente è stato salvato dopo la creazione
                        $classroomLink = $test['url_docente'] ?? '';
                        if (!empty($classroomLink)):
                        ?>
                            <a href="<?= htmlspecialchars($classroomLink) ?>"
                               target="_blank"
                               class="btn btn-success btn-lg">
                                <i class="bi bi-google"></i>
                                <?php if ($mode === 'draft'): ?>
                                    Apri Bozza e Pubblica su Classroom
                                <?php else: ?>
                                    Visualizza Compito su Classroom
                                <?php endif; ?>
                            </a>
                        <?php else: ?>
                            <div class="alert alert-warning mb-0">
                                <i class="bi bi-exclamation-triangle"></i> Link Classroom non disponibile
                            </div>
                        <?php endif; ?>
                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-primary">
                            <i class="bi bi-arrow-left"></i> Torna all'UDA
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <!-- Form Pubblicazione -->
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="action" value="publish">
                    <input type="hidden" name="draft_or_publish" value="<?= htmlspecialchars($mode) ?>">
                    <input type="hidden" name="publish_mode" id="publish_mode_input" value="link">

                    <!-- Selezione Modalità -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-1-circle"></i> Modalità di Pubblicazione</h5>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <div class="card mode-card h-100" onclick="selectMode('link');" id="mode-link">
                                        <div class="card-body text-center">
                                            <i class="bi bi-link-45deg" style="font-size: 3rem; color: #0d6efd;"></i>
                                            <h5 class="mt-3">Link Diretto</h5>
                                            <p class="text-muted">Pubblica allegando il link diretto al test.</p>
                                            <span class="badge bg-success">Semplice</span>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-6 mb-3">
                                    <div class="card mode-card h-100" onclick="selectMode('seb');" id="mode-seb">
                                        <div class="card-body text-center">
                                            <i class="bi bi-file-earmark-lock" style="font-size: 3rem; color: #dc3545;"></i>
                                            <h5 class="mt-3">File .seb</h5>
                                            <p class="text-muted">Carica template con placeholder {{link_test}}.</p>
                                            <span class="badge bg-warning text-dark">Avanzato</span>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <div id="seb-upload-section" style="display: none;" class="mt-3">
                                <div class="alert alert-info">
                                    <i class="bi bi-info-circle"></i>
                                    Il file .seb deve contenere <code>{{link_test}}</code> che verrà sostituito con l'URL del test.
                                </div>
                                <input type="file" class="form-control" name="seb_template" accept=".seb">
                            </div>
                        </div>
                    </div>

                    <!-- Configurazione -->
                    <div class="card mb-4">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="bi bi-2-circle"></i> Configurazione Compito</h5>
                        </div>
                        <div class="card-body">
                            <div class="mb-3">
                                <label class="form-label">Corso Classroom *</label>
                                <select class="form-select" name="course_id" required>
                                    <option value="">Seleziona...</option>
                                    <?php foreach ($classroomCourses as $course): ?>
                                        <option value="<?= htmlspecialchars($course['id']) ?>">
                                            <?= htmlspecialchars($course['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Argomento (Topic)</label>
                                <input type="text" class="form-control" name="topic_name" value="<?= htmlspecialchars($uda->titolo) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Titolo Compito *</label>
                                <input type="text" class="form-control" name="test_title" value="<?= htmlspecialchars($test['nome']) ?>" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Descrizione</label>
                                <textarea class="form-control" name="test_description" rows="3"><?= htmlspecialchars($test['descrizione'] ?? '') ?></textarea>
                            </div>

                            <div class="row">
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Data Consegna</label>
                                    <input type="date" class="form-control" name="due_date">
                                </div>
                                <div class="col-md-6 mb-3">
                                    <label class="form-label">Ora Consegna</label>
                                    <input type="time" class="form-control" name="due_time">
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between">
                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Annulla
                        </a>
                        <button type="submit" class="btn btn-primary btn-lg">
                            <?php if ($mode === 'draft'): ?>
                                <i class="bi bi-file-earmark-plus"></i> Pubblica in Bozza
                            <?php else: ?>
                                <i class="bi bi-send"></i> Pubblica Subito
                            <?php endif; ?>
                        </button>
                    </div>
                </form>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function selectMode(mode) {
            document.getElementById('mode-link').classList.remove('selected');
            document.getElementById('mode-seb').classList.remove('selected');
            document.getElementById('mode-' + mode).classList.add('selected');
            document.getElementById('publish_mode_input').value = mode;
            document.getElementById('seb-upload-section').style.display = mode === 'seb' ? 'block' : 'none';
        }

        document.addEventListener('DOMContentLoaded', function() {
            selectMode('link');
        });
    </script>
</body>
</html>
