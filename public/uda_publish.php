<?php

error_reporting(E_ALL);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/../storage/logs/uda_publish_error.log');

// Catch fatal errors
register_shutdown_function(function() {
    $error = error_get_last();
    if ($error !== null && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        $logFile = __DIR__ . '/../storage/logs/uda_publish_error.log';
        $message = date('Y-m-d H:i:s') . " FATAL: " . $error['message'] . " in " . $error['file'] . ":" . $error['line'] . "\n";
        @file_put_contents($logFile, $message, FILE_APPEND);

        http_response_code(500);
        echo "<!DOCTYPE html><html><body>";
        echo "<h1>Errore Server</h1>";
        echo "<p>Controlla: storage/logs/uda_publish_error.log</p>";
        echo "<pre>" . htmlspecialchars($message) . "</pre>";
        echo "</body></html>";
        exit;
    }
});

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\GoogleTokenProvider;
use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;
use App\Core\UdaClassroomPublishService;
use App\Integration\GoogleClassroomAPI;
use App\Integration\GoogleDriveAPI;
$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$groupRepo = new UdaGroupRepository($dbAdapter, $userId);
$integrationRepo = new TeachingGroupIntegrationRepository($dbAdapter, $userId);
$teachingGroupRepo = new TeachingGroupRepository($dbAdapter, $userId);

$error_message = null;
$success_message = null;
$publishLog = [];

// Verifica ID UDA
$udaId = $_GET['id'] ?? null;
if (!$udaId) {
    header('Location: index.php');
    exit;
}

// Carica UDA esistente
$udaComplete = null;
$uda = null;
$materiali = [];
$obiettivi = [];
$publishTargets = [];
$tests = [];

try {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception("UDA non trovata");
    }
    $uda = $udaComplete['uda'];
    $materiali = $udaComplete['materiali'];
    $obiettivi = $udaComplete['obiettivi'];
    // Gruppi didattici dell'UDA con integrazione Google Classroom attiva.
    foreach ($groupRepo->listForUda($udaId) as $assignment) {
        $gid = (string)($assignment['id_gruppo'] ?? '');
        if ($gid === '') {
            continue;
        }
        $integration = $integrationRepo->findForGroupProvider($gid, 'google_classroom');
        if ($integration === null) {
            continue;
        }
        $group = $teachingGroupRepo->findById($gid);
        $publishTargets[] = [
            'id' => $gid,
            'nome' => (string)($group['nome_gruppo'] ?? ('Gruppo ' . $gid)),
            'course_id' => (string)($integration['external_context_id'] ?? ''),
            'course_name' => (string)($integration['external_name'] ?? ''),
        ];
    }

    // Carica test associati all'UDA
    $allTests = $dbAdapter->findAll('TEST');
    $tests = array_filter($allTests, function($test) use ($udaId) {
        return ($test['id_uda'] ?? '') === $udaId;
    });
} catch (Exception $e) {
    $error_message = "Errore: " . $e->getMessage();
}

// Verifica Google APIs disponibili
$googleEnabled = false;
$googleDriveAPI = null;
$googleClassroomAPI = null;

// Verifica che Google Classroom e Drive siano abilitati
$googleClassroomEnabled = ($config['google']['classroom']['enabled'] ?? false);
$googleDriveEnabled = ($config['google']['drive']['enabled'] ?? false);

if ($googleClassroomEnabled && $googleDriveEnabled) {
    try {
        // Verifica che i file di configurazione esistano
        $credentialsPath = ROOT_PATH . '/' . ($config['google']['credentials_file'] ?? 'config/google_credentials.json');
        if (!file_exists($credentialsPath)) {
            throw new Exception("File google_credentials.json non trovato in config/");
        }

        $tokenData = GoogleTokenProvider::getToken($config);
        if (empty($tokenData['access_token'])) {
            throw new Exception("Token Google non trovato. Autorizza l'app in: google_auth.php");
        }
        $hasRefreshToken = !empty($tokenData['refresh_token']);

        if (!$hasRefreshToken) {
            // Verifica scadenza solo se non c'è refresh token
            $tokenCreated = $tokenData['created'] ?? 0;
            $tokenExpires = $tokenData['expires_in'] ?? 3600;
            $tokenExpiryTime = $tokenCreated + $tokenExpires;

            if (time() > $tokenExpiryTime) {
                throw new Exception("Token Google scaduto. Rinnova in: <a href='google_auth.php'>google_auth.php</a>");
            }
        }
        // Se c'è refresh_token, Google Client lo rinnoverà automaticamente

        $googleDriveAPI = new GoogleDriveAPI($config);
        $googleClassroomAPI = new GoogleClassroomAPI($config);
        $googleEnabled = true;
    } catch (Exception $e) {
        $error_message = "Nota: Google APIs non disponibili. " . $e->getMessage();
    }
} else {
    if (!$googleClassroomEnabled) {
        $error_message = "Google Classroom non abilitato in config.yaml";
    } elseif (!$googleDriveEnabled) {
        $error_message = "Google Drive non abilitato in config.yaml";
    }
}

// Gestione POST per pubblicazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'publish_classroom') {
    try {
        if (!$googleEnabled) {
            throw new Exception("Google APIs non configurate o non disponibili");
        }

        $selectedClasses = $_POST['classes'] ?? [];

        if (empty($selectedClasses)) {
            throw new Exception("Seleziona almeno una classe per la pubblicazione");
        }

        // Raccogli selezioni su cosa pubblicare
        $publishUdaMaterial = isset($_POST['publish_uda_material']);
        $selectedTests = $_POST['tests'] ?? [];

        $publishedCount = 0;
        $publishLog = [];

        foreach ($selectedClasses as $groupId) {
            // Trova il gruppo didattico target
            $target = null;
            foreach ($publishTargets as $t) {
                if ($t['id'] === $groupId) {
                    $target = $t;
                    break;
                }
            }

            if (!$target) {
                $publishLog[] = "⚠ Gruppo non trovato (ID: $groupId)";
                continue;
            }

            $publishLog[] = "📋 Pubblicazione per: " . $target['nome'];

            $courseId = $target['course_id'];
            $courseName = $target['course_name'];
            $mapping = ($courseId !== '');
            if ($mapping) {
                $publishLog[] = "✓ Corso Google Classroom trovato: {$courseName} (ID: {$courseId})";
            } else {
                $publishLog[] = "⚠ Nessun corso Google Classroom per {$target['nome']}";
            }

            try {
                // Variabili per tracking
                $topicId = null;
                $classroomUrl = null;

                // Se c'è mapping, pubblica realmente
                if ($mapping) {
                    // 1. Trova o crea argomento su Classroom (usa l'argomento dell'UDA)
                    $topicName = !empty($uda->argomento) ? $uda->argomento : $uda->titolo;
                    $publishLog[] = "  📚 Verifica argomento su Classroom: " . $topicName;
                    try {
                        $topic = $googleClassroomAPI->findOrCreateTopic($courseId, $topicName);
                        $topicId = $topic['id'];
                        $publishLog[] = "    ✅ Argomento pronto (ID: {$topicId})";
                    } catch (Exception $e) {
                        $publishLog[] = "    ⚠️  Errore gestione argomento: " . $e->getMessage();
                        // Continua comunque
                    }

                    // 2. Pubblica materiale UDA principale (se selezionato)
                    if ($publishUdaMaterial) {
                        $publishLog[] = "  📚 Pubblicazione materiale UDA principale...";

                        // Crea descrizione dettagliata (SENZA i link, ora vanno come allegati)
                        $udaDescription = UdaClassroomPublishService::materialDescription(
                            (string)($uda->descrizione ?? 'N/A'),
                            (string)($uda->note ?? ''),
                            $obiettivi
                        );

                        // Prepara array di materiali da allegare (solo "Materiale classroom")
                        $materialsToAttach = UdaClassroomPublishService::attachableMaterials($materiali);

                        // Se non ci sono materiali, aggiungi un link placeholder
                        if (empty($materialsToAttach)) {
                            $materialsToAttach[] = [
                                'url' => "https://classroom.google.com/c/{$courseId}"
                            ];
                        }

                        try {
                            $udaMaterialData = [
                                'title' => $uda->titolo,
                                'description' => $udaDescription,
                                'materials' => $materialsToAttach,
                                'topicId' => $topicId, // topic corretto per Classroom
                                'state' => 'DRAFT'
                            ];

                            $result = $googleClassroomAPI->createMaterial($courseId, $udaMaterialData);
                            $materialLink = trim((string)($result['link'] ?? ''));
                            $materialLog = "    ✅ Materiale UDA pubblicato come BOZZA con " . count($materialsToAttach) . " allegati";
                            if ($materialLink !== '') {
                                $publishLog[] = ['html' => $materialLog . ': <a href="' . htmlspecialchars($materialLink, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars((string)$uda->titolo, ENT_QUOTES) . ' <i class="bi bi-box-arrow-up-right"></i></a>'];
                            } else {
                                $publishLog[] = $materialLog . ': ' . $uda->titolo;
                            }
                        } catch (Exception $e) {
                            $publishLog[] = "    ⚠️  Errore pubblicazione materiale UDA: " . $e->getMessage();
                        }
                    }

                    // 3. Pubblica test selezionati
                    if (!empty($selectedTests)) {
                        $publishLog[] = "  📝 Pubblicazione test...";

                        foreach ($selectedTests as $testId) {
                            // Trova il test
                            $test = null;
                            foreach ($tests as $t) {
                                if ($t['id_test'] === $testId) {
                                    $test = $t;
                                    break;
                                }
                            }

                            if (!$test) continue;

                            // Titolo del test: nome reale ("Test classroom")
                            $testTitle = UdaClassroomPublishService::testTitle($test);
                            $piattaforma = strtolower(trim((string)($test['piattaforma'] ?? '')));

                            $testDescription = UdaClassroomPublishService::testDescription($test);

                            try {
                                // Usa l'URL studenti o fallback a url generico
                                $testUrl = $test['url_studenti'] ?? $test['url'] ?? '';

                                $testAssignmentData = [
                                    'title' => $testTitle,
                                    'description' => $testDescription,
                                    'topicId' => $topicId,  // Corretto: topicId invece di topic_id
                                    'workType' => 'ASSIGNMENT',
                                    'state' => 'DRAFT',  // Pubblica sempre come bozza
                                    'maxPoints' => floatval($test['punteggio_max'] ?? 100)
                                ];

                                // Se ha un URL, aggiungilo come materiale
                                if (!empty($testUrl)) {
                                    $testAssignmentData['materials'] = [$testUrl];
                                }

                                $result = $googleClassroomAPI->createAssignment($courseId, $testAssignmentData);

                                // Aggiorna il test nel database con i dati della pubblicazione
                                $updateFields = [
                                    'classroom_course_id' => $courseId,
                                    'classroom_assignment_id' => $result['id'] ?? '',
                                    'classroom_topic_id' => $topicId,
                                    'classroom_url' => trim((string)($result['link'] ?? '')),
                                    'pubblicato' => 'NO',  // Bozza
                                ];

                                // Preimposta "Importa voti" per i Google Form.
                                if ($piattaforma === 'google-forms') {
                                    $urlDocente = UdaClassroomPublishService::googleFormDocenteUrl($test);
                                    if ($urlDocente !== '') {
                                        $updateFields['url_docente'] = $urlDocente;
                                    }
                                }

                                $dbAdapter->updateRow('TEST', 'id_test', $test['id_test'], $updateFields);

                                $testLink = trim((string)($result['link'] ?? ''));
                                $testLog = "    ✅ Test pubblicato come BOZZA";
                                if ($testLink !== '') {
                                    $publishLog[] = ['html' => $testLog . ': <a href="' . htmlspecialchars($testLink, ENT_QUOTES) . '" target="_blank" rel="noopener">' . htmlspecialchars($testTitle, ENT_QUOTES) . ' <i class="bi bi-box-arrow-up-right"></i></a>'];
                                } else {
                                    $publishLog[] = $testLog . ': ' . $testTitle;
                                }

                                // Link diretto "Importa voti" per i Google Form
                                if ($piattaforma === 'google-forms') {
                                    $importUrl = 'import_form_results.php?test_id=' . urlencode((string)$test['id_test']) . '&step=read_responses';
                                    $publishLog[] = ['html' => '        <a href="' . htmlspecialchars($importUrl, ENT_QUOTES) . '" target="_blank" rel="noopener"><i class="bi bi-cloud-upload"></i> Importa voti</a>'];
                                }
                            } catch (Exception $e) {
                                $publishLog[] = "    ⚠️  Errore pubblicazione test '$testTitle': " . $e->getMessage();
                                error_log("Errore pubblicazione test: " . $e->getMessage());
                            }
                        }
                    }

                    $publishLog[] = "  ✓ Pubblicazione completata";
                    $classroomUrl = "https://classroom.google.com/c/{$courseId}";

                } else {
                    // Senza mapping, solo simulazione
                    $publishLog[] = "  ⚠️  SIMULAZIONE (nessun mapping):";
                    $publishLog[] = "  📁 Preparazione cartella Drive...";

                    $uploadedMaterials = 0;
                    foreach ($materiali as $mat) {
                        if (!empty($mat['nome'])) {
                            $publishLog[] = "  📄 Materiale: " . $mat['nome'];
                            $uploadedMaterials++;
                        }
                    }
                    $publishLog[] = "  ✓ {$uploadedMaterials} materiali preparati (non pubblicati)";
                    $publishLog[] = "  📚 Argomento: " . $uda->titolo . " (non creato)";
                    $classroomUrl = "https://classroom.google.com/";
                }

                // 3. Registra la pubblicazione nel DB provider-neutral
                $existing = $dbAdapter->findWhere('UDA_PUBBLICAZIONI', [
                    'id_utente' => $userId,
                    'id_uda' => $udaId,
                    'id_gruppo' => $target['id'],
                    'provider' => 'google_classroom',
                ]);
                $pubRow = [
                    'id_uda' => $udaId,
                    'id_gruppo' => $target['id'],
                    'provider' => 'google_classroom',
                    'external_resource_id' => $mapping ? $courseId : '',
                    'external_url' => $classroomUrl ?? 'https://classroom.google.com/',
                    'stato' => $mapping ? 'pubblicata' : 'preparata',
                    'data_pubblicazione' => $mapping ? date('Y-m-d H:i:s') : null,
                    'id_utente' => $userId,
                ];
                if ($existing !== []) {
                    $dbAdapter->updateRow('UDA_PUBBLICAZIONI', 'id_pubblicazione', $existing[0]['id_pubblicazione'], $pubRow);
                } else {
                    $pubRow['id_pubblicazione'] = 'PUB_' . uniqid();
                    $dbAdapter->insertRow('UDA_PUBBLICAZIONI', $pubRow);
                }

                if ($mapping) {
                    $publishLog[] = "  ✅ Pubblicazione REALE completata per " . $target['nome'];
                    $publishedCount++;
                } else {
                    $publishLog[] = "  ⚠️  Simulazione completata - nessun corso Google Classroom collegato";
                }

            } catch (Exception $e) {
                $publishLog[] = "  ❌ Errore: " . $e->getMessage();
            }
        }

        if ($publishedCount > 0) {
            $success_message = "UDA pubblicata con successo su {$publishedCount} " . ($publishedCount === 1 ? 'classe' : 'classi');
        } else {
            $error_message = "Nessuna classe pubblicata. Vedi log per dettagli.";
        }

    } catch (Exception $e) {
        $error_message = "Errore durante la pubblicazione: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pubblica UDA - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .publish-card {
            border: 2px solid #dee2e6;
            border-radius: 0.5rem;
            padding: 1.5rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
            transition: all 0.3s;
        }
        .publish-card:hover {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        .publish-card.disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        .publish-log {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            max-height: 400px;
            overflow-y: auto;
            font-family: monospace;
            font-size: 0.875rem;
        }
        .status-badge {
            font-size: 0.75rem;
            padding: 0.25rem 0.5rem;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-send"></i> Pubblica UDA su Google Classroom';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a class="nav-link" href="index.php">Dashboard</a>';
    if ($udaId) {
        $headerActions .= '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">Visualizza UDA</a>';
        $headerActions .= '<a href="uda_view.php?id=' . urlencode($udaId) . '" class="btn btn-outline-light btn-sm">'
            . '<i class="bi bi-arrow-left"></i> Torna all\'UDA</a>';
    }
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4 mb-5">

        <?php if ($error_message): ?>
            <div class="alert alert-warning alert-dismissible fade show">
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

        <?php if (!empty($publishLog)): ?>
            <div class="card mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="bi bi-terminal"></i> Log Pubblicazione</h6>
                </div>
                <div class="card-body">
                    <div class="publish-log">
                        <?php foreach ($publishLog as $log): ?>
                            <?php if (is_array($log) && isset($log['html'])): ?>
                                <?php echo $log['html']; ?><br>
                            <?php else: ?>
                                <?php echo htmlspecialchars((string)$log); ?><br>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Riepilogo UDA -->
        <div class="row mb-4">
            <div class="col-md-12">
                <div class="card">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Riepilogo UDA</h5>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-8">
                                <p class="mb-2"><strong>Titolo:</strong> <?= htmlspecialchars($uda->titolo) ?></p>
                                <p class="mb-2"><strong>Argomento:</strong> <?= htmlspecialchars($uda->argomento) ?></p>
                                <?php if (!empty($uda->descrizione)): ?>
                                <p class="mb-2"><strong>Descrizione:</strong> <?= htmlspecialchars($uda->descrizione) ?></p>
                                <?php endif; ?>
                            </div>
                            <div class="col-md-4">
                                <div class="text-end">
                                    <div class="mb-2">
                                        <span class="badge bg-info"><?= count($materiali) ?> Materiali</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="badge bg-success"><?= count($obiettivi) ?> Obiettivi</span>
                                    </div>
                                    <div class="mb-2">
                                        <span class="badge bg-warning text-dark"><?= count($tests) ?> Test</span>
                                    </div>
                                    <div>
                                        <span class="badge bg-primary"><?= count($publishTargets) ?> Gruppi</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <?php if (empty($publishTargets)): ?>
            <!-- Nessun gruppo con corso Google Classroom -->
            <div class="alert alert-warning">
                <h5 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Nessun Gruppo con Corso Google Classroom</h5>
                <p>Prima di pubblicare l'UDA, assegnala a un gruppo didattico e collega un corso Google Classroom al gruppo.</p>
            </div>

        <?php else: ?>
            <!-- Form pubblicazione -->
            <form method="POST" id="publishForm">
                <input type="hidden" name="action" value="publish_classroom">

                <div class="card shadow mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-google"></i> Pubblica su Google Classroom</h5>
                    </div>
                    <div class="card-body">
                        <?php if (!$googleEnabled): ?>
                            <div class="alert alert-danger">
                                <h6 class="alert-heading">Google APIs non configurate</h6>
                                <p class="mb-0">Per pubblicare su Google Classroom, configura le credenziali Google in <code>config/google_credentials.json</code> e abilita le integrazioni in <code>config.yaml</code>.</p>
                            </div>
                        <?php else: ?>
                            <p class="mb-3">Seleziona i gruppi su cui pubblicare questa UDA:</p>

                            <div class="row">
                                <?php foreach ($publishTargets as $target): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="publish-card">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" value="<?= htmlspecialchars($target['id']) ?>"
                                                       id="group_<?= htmlspecialchars($target['id']) ?>"
                                                       name="classes[]">
                                                <label class="form-check-label w-100" for="group_<?= htmlspecialchars($target['id']) ?>">
                                                    <div class="d-flex justify-content-between align-items-start">
                                                        <div class="flex-grow-1">
                                                            <h6 class="mb-1"><?= htmlspecialchars($target['nome']) ?></h6>
                                                            <small class="text-muted">
                                                                <?php if ($target['course_id'] !== ''): ?>
                                                                    <i class="bi bi-google"></i> <?= htmlspecialchars($target['course_name'] ?: $target['course_id']) ?>
                                                                <?php else: ?>
                                                                    Nessun corso Google Classroom collegato
                                                                <?php endif; ?>
                                                            </small>
                                                        </div>
                                                    </div>
                                                </label>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <hr>

                            <div class="card mb-3">
                                <div class="card-header bg-info text-white">
                                    <h6 class="mb-0"><i class="bi bi-check-square"></i> Seleziona Contenuti da Pubblicare</h6>
                                </div>
                                <div class="card-body">
                                    <p class="text-muted mb-3">
                                        <i class="bi bi-info-circle"></i> Seleziona cosa pubblicare su Google Classroom. <strong>Tutto verrà salvato come BOZZA</strong>.
                                    </p>

                                    <!-- Materiale UDA -->
                                    <div class="form-check mb-3">
                                        <input class="form-check-input" type="checkbox" name="publish_uda_material" id="publish_uda_material" value="1" checked>
                                        <label class="form-check-label" for="publish_uda_material">
                                            <strong><i class="bi bi-file-earmark-text"></i> Materiale Classroom</strong>
                                            <small class="d-block text-muted">
                                                Pubblica insieme i materiali collegabili (Drive/link) con descrizione, note e obiettivi didattici
                                            </small>
                                        </label>
                                    </div>

                                    <!-- Test -->
                                    <?php if (!empty($tests)): ?>
                                        <div class="mb-2">
                                            <strong><i class="bi bi-clipboard-check"></i> Test Disponibili (<?= count($tests) ?>):</strong>
                                        </div>
                                        <?php foreach ($tests as $test): ?>
                                            <?php
                                            $testName = UdaClassroomPublishService::testTitle($test);
                                            ?>
                                            <div class="form-check mb-2 ms-4">
                                                <input class="form-check-input" type="checkbox" name="tests[]" value="<?= htmlspecialchars($test['id_test']) ?>" id="test_<?= htmlspecialchars($test['id_test']) ?>">
                                                <label class="form-check-label" for="test_<?= htmlspecialchars($test['id_test']) ?>">
                                                    <?= htmlspecialchars($testName) ?>
                                                    <small class="text-muted">
                                                        <?php if (!empty($test['num_domande'])): ?>
                                                            (<?= $test['num_domande'] ?> domande,
                                                        <?php endif; ?>
                                                        <?php if (!empty($test['durata_minuti'])): ?>
                                                            <?= $test['durata_minuti'] ?> min,
                                                        <?php endif; ?>
                                                        <?php if (!empty($test['punteggio_max'])): ?>
                                                            max <?= $test['punteggio_max'] ?> punti)
                                                        <?php endif; ?>
                                                    </small>
                                                </label>
                                            </div>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <div class="alert alert-warning mb-0">
                                            <small><i class="bi bi-exclamation-triangle"></i> Nessun test disponibile per questa UDA</small>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>

                            <div class="alert alert-success">
                                <strong><i class="bi bi-info-circle"></i> Importante:</strong>
                                <ul class="mb-0 mt-2">
                                    <li>Verrà usato l'argomento "<strong><?= htmlspecialchars(!empty($uda->argomento) ? $uda->argomento : $uda->titolo) ?></strong>" in Classroom</li>
                                    <li>Tutti i contenuti verranno salvati come <strong>BOZZA</strong></li>
                                    <li>I materiali verranno pubblicati insieme come <strong>Materiale classroom</strong>, con descrizione e obiettivi didattici</li>
                                    <li>I test verranno creati singolarmente come <strong>Test classroom</strong> (Compiti) con i link ai Google Forms</li>
                                    <li>Per i Google Form verrà preimpostato <strong>Importa voti</strong></li>
                                    <li>Potrai rivedere e pubblicare manualmente da Google Classroom</li>
                                </ul>
                            </div>

                            <div class="d-flex justify-content-end">
                                <button type="submit" class="btn btn-success btn-lg">
                                    <i class="bi bi-cloud-upload"></i> Pubblica Classi Selezionate
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </form>

            <!-- Materiali da pubblicare -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h6 class="mb-0"><i class="bi bi-folder-fill"></i> Materiali da Pubblicare (<?= count($materiali) ?>)</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($materiali)): ?>
                        <p class="text-muted mb-0">Nessun materiale disponibile</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($materiali as $mat): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <div>
                                            <h6 class="mb-1"><?= htmlspecialchars($mat['nome']) ?></h6>
                                            <?php if (!empty($mat['descrizione'])): ?>
                                                <small class="text-muted"><?= htmlspecialchars($mat['descrizione']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                        <span class="badge bg-info"><?= htmlspecialchars($mat['tipo_materiale']) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Obiettivi didattici -->
            <div class="card mb-4">
                <div class="card-header bg-success text-white">
                    <h6 class="mb-0"><i class="bi bi-bullseye"></i> Obiettivi Didattici (<?= count($obiettivi) ?>)</h6>
                </div>
                <div class="card-body">
                    <?php if (empty($obiettivi)): ?>
                        <p class="text-muted mb-0">Nessun obiettivo definito</p>
                    <?php else: ?>
                        <div class="list-group">
                            <?php foreach ($obiettivi as $ob): ?>
                                <div class="list-group-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div class="flex-grow-1">
                                            <?= htmlspecialchars($ob['descrizione']) ?>
                                        </div>
                                        <div>
                                            <span class="badge bg-success"><?= htmlspecialchars($ob['tipo_obiettivo']) ?></span>
                                            <span class="badge bg-warning text-dark">Bloom: <?= $ob['livello_tassonomia'] ?></span>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>

        <!-- Note importanti -->
        <div class="card">
            <div class="card-header bg-warning">
                <h6 class="mb-0"><i class="bi bi-exclamation-triangle"></i> Note Importanti</h6>
            </div>
            <div class="card-body">
                <ul class="mb-0">
                    <li>La pubblicazione userà l'<strong>argomento</strong> dell'UDA in ciascuna classe selezionata</li>
                    <li>I materiali verranno caricati su <strong>Google Drive</strong> e condivisi con gli studenti</li>
                    <li>Gli studenti riceveranno una <strong>notifica</strong> sulla piattaforma Classroom</li>
                    <li>Puoi ripubblicare per aggiornare i contenuti già pubblicati</li>
                    <li>Le classi già pubblicate verranno <strong>aggiornate</strong> con i nuovi contenuti</li>
                </ul>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Conferma prima della pubblicazione
        document.getElementById('publishForm')?.addEventListener('submit', function(e) {
            const checked = document.querySelectorAll('input[name="classes[]"]:checked').length;

            if (checked === 0) {
                e.preventDefault();
                alert('Seleziona almeno una classe per la pubblicazione');
                return false;
            }

            const confirmed = confirm(`Pubblicare l'UDA su ${checked} ${checked === 1 ? 'classe' : 'classi'}?\n\nQuesta operazione creerà argomenti e materiali su Google Classroom.`);

            if (!confirmed) {
                e.preventDefault();
                return false;
            }
        });
    </script>
</body>
</html>
