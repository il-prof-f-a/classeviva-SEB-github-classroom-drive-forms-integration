<?php
/**
 * Gestione Test UDA
 * Permette di creare/modificare test (Google Forms, Kahoot) per una UDA
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClassroomPublishState;
use App\Core\Database\DatabaseFactory;
use App\Core\GoogleTokenProvider;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;
use App\Core\UDAManager;
use App\Integration\GoogleClassroomAPI;

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
$tests = $udaComplete['test'];

// Catalogo GitHub dal DB interno: gli assignment sono righe TEST (piattaforma='github')
// collegate ai gruppi dell'UDA. Nessuna risoluzione delle GitHub Classroom via API.
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

// Gruppi didattici (nome + corso Classroom) per i badge per-gruppo: usa lo
// stesso repository di uda_view.php/materiali.php, NON classi_assegnate (che
// può non avere id_gruppo valorizzato).
$udaGroupIds = [];
$udaGroups = [];
foreach ((new UdaGroupRepository($dbAdapter, $userId))->listForUda($udaId) as $assignment) {
    $gid = trim((string)($assignment['id_gruppo'] ?? ''));
    if ($gid === '') {
        continue;
    }
    $udaGroupIds[] = $gid;
    $grp = (new TeachingGroupRepository($dbAdapter, $userId))->findById($gid);
    if ($grp === null) continue;
    $google = (new TeachingGroupIntegrationRepository($dbAdapter, $userId))->findForGroupProvider($gid, 'google_classroom');
    $udaGroups[] = [
        'id_gruppo' => $gid,
        'nome_gruppo' => (string)($grp['nome_gruppo'] ?? ('Gruppo ' . $gid)),
        'google_course_id' => $google !== null ? (string)($google['external_context_id'] ?? '') : '',
    ];
}
$udaGroupIds = array_values(array_unique($udaGroupIds));

// Google Classroom API per i badge.
$googleClassroomAPI = null;
try {
    $tokenData = GoogleTokenProvider::getToken($config);
    if (!empty($tokenData['access_token'])) {
        $googleClassroomAPI = new GoogleClassroomAPI($config);
    }
} catch (Throwable $ignored) {}

// Badge test per gruppo.
$testBadges = []; // testId => [groupId => badge]
if ($googleClassroomAPI !== null) {
    foreach ($tests as $t) {
        $tid = (string)($t['id_test'] ?? '');
        if ($tid === '') continue;
        foreach ($udaGroups as $grp) {
            $testBadges[$tid][$grp['id_gruppo']] = ClassroomPublishState::testBadgeForGroup(
                $googleClassroomAPI, $dbAdapter, $userId, $t, $grp['id_gruppo'], $grp['google_course_id']
            );
        }
    }
}

$udaGithubContext = [
    'id_uda' => $udaId,
    'group_ids' => $udaGroupIds,
];
$udaTestsReturnTo = urlencode('uda_tests.php?id=' . $udaId);

// Gestione azioni POST
$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        switch ($action) {
            case 'add_test':
                // Estrai ID esterno automaticamente dall'URL se non fornito
                $url = $_POST['url'] ?? '';
                $idEsterno = $_POST['id_esterno'] ?? '';

                if (empty($idEsterno) && !empty($url)) {
                    // Estrai da Google Forms: https://docs.google.com/forms/d/{ID}/...
                    if (preg_match('/forms\/d\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
                        $idEsterno = $matches[1];
                    }
                    // Estrai da Kahoot: https://kahoot.it/challenge/{ID} o https://create.kahoot.it/details/{ID}
                    elseif (preg_match('/kahoot\.it\/(challenge|details)\/([a-zA-Z0-9_-]+)/', $url, $matches)) {
                        $idEsterno = $matches[2];
                    }
                }

                // DEBUG: Log POST data per diagnostica
                $debugLog = "=== DEBUG ADD TEST ===\n";
                $debugLog .= "Timestamp: " . date('Y-m-d H:i:s') . "\n";
                $debugLog .= "Piattaforma: " . ($_POST['piattaforma'] ?? 'N/A') . "\n";
                $debugLog .= "Nome: '" . ($_POST['nome'] ?? 'VUOTO') . "' (length: " . strlen($_POST['nome'] ?? '') . ")\n";
                $debugLog .= "Descrizione: '" . substr($_POST['descrizione'] ?? 'VUOTO', 0, 50) . "...'\n";
                $debugLog .= "Punteggio Max: '" . ($_POST['punteggio_max'] ?? 'VUOTO') . "'\n";
                $debugLog .= "Classroom Course ID: '" . ($_POST['classroom_course_id'] ?? 'VUOTO') . "'\n";
                $debugLog .= "Classroom Assignment ID: '" . ($_POST['classroom_assignment_id'] ?? 'VUOTO') . "'\n";
                $debugLog .= "All POST keys: " . implode(', ', array_keys($_POST)) . "\n";
                $debugLog .= "==================\n\n";
                @file_put_contents(__DIR__ . '/../storage/logs/test_debug.log', $debugLog, FILE_APPEND);

                // Gestisci URL in base alla piattaforma
                $piattaforma = $_POST['piattaforma'] ?? '';
                $urlDocente = '';
                $urlStudenti = '';
                $urlAssignmentStudent = '';
                $urlAssignmentTeacher = '';

                if ($piattaforma === 'google-forms') {
                    // Google Forms usa url_gestione (docente) e url_pubblico (studenti)
                    $urlDocente = $_POST['url_gestione'] ?? '';
                    $urlStudenti = $_POST['url'] ?? '';
                    $urlAssignmentStudent = $urlStudenti;
                    $urlAssignmentTeacher = $urlDocente;
                } else {
                    // Kahoot e Altro usano url_docente e url_studenti
                    $urlDocente = $_POST['url_docente'] ?? '';
                    $urlStudenti = $_POST['url_studenti'] ?? '';
                    $urlAssignmentStudent = $_POST['url_studenti'] ?? '';
                    $urlAssignmentTeacher = $_POST['url_docente'] ?? '';
                }

                // CBM config base (default pesi)
                $cbmEnabled = isset($_POST['cbm_enabled']) && $_POST['cbm_enabled'] === '1';
                $cbmLevels = [
                    'c1' => ['label' => 'Poco sicuro', 'correct' => 1, 'wrong' => 0],
                    'c2' => ['label' => 'Abbastanza sicuro', 'correct' => 2, 'wrong' => -2],
                    'c3' => ['label' => 'Molto sicuro', 'correct' => 3, 'wrong' => -6],
                ];
                $cbmScoringModel = 'default_c1_1_0_c2_2_-2_c3_3_-6';

                $pubblicato = $_POST['pubblicato'] ?? 'NO';
                $hasLinkedUrl = !empty($urlStudenti) || !empty($urlDocente) || !empty($_POST['url'] ?? '');
                $isImportedClassroom = ($piattaforma === 'google-classroom' && !empty($_POST['classroom_assignment_id']));
                if (($pubblicato === 'NO' || $pubblicato === '') && ($isImportedClassroom || ($piattaforma !== 'google-classroom' && $hasLinkedUrl))) {
                    $pubblicato = 'SI';
                }

                $testData = [
                    'id_test' => 'TEST_' . uniqid(),
                    'id_uda' => $udaId,
                    'tipo_test' => $_POST['tipo_test'] ?? '',
                    'nome' => $_POST['nome'] ?? '',
                    'descrizione' => $_POST['descrizione'] ?? '',
                    'piattaforma' => $piattaforma,
                    'url' => $urlStudenti, // Mantiene compatibilità con vecchio schema
                    'url_docente' => $urlDocente,
                    'url_studenti' => $urlStudenti,
                    'id_esterno' => $idEsterno,
                    'num_domande' => $_POST['num_domande'] ?? 0,
                    'durata_minuti' => $_POST['durata_minuti'] ?? 0,
                    'punteggio_max' => $_POST['punteggio_max'] ?? 100,
                    'soglia_sufficienza' => $_POST['soglia_sufficienza'] ?? 60,
                    'data_creazione' => date('d/m/Y'),
                    'data_somministrazione' => $_POST['data_somministrazione'] ?? '',
                    'ora_consegna' => $_POST['ora_consegna'] ?? '',
                    'pubblicato' => $pubblicato,
                    'risultati_importati' => 'NO',
                    'note' => $_POST['note'] ?? '',
                    // Campi Google Classroom
                    'classroom_course_id' => $_POST['classroom_course_id'] ?? '',
                    'classroom_assignment_id' => $_POST['classroom_assignment_id'] ?? '',
                    'classroom_topic_id' => $_POST['classroom_topic_id'] ?? '',
                    'allegati_json' => $_POST['allegati_json'] ?? '',
                    // CBM
                    'cbm_enabled' => $cbmEnabled ? 'YES' : 'NO',
                    'cbm_levels_json' => json_encode($cbmLevels),
                    'cbm_scoring_model' => $cbmScoringModel,
                    'cbm_form_config_json' => null,
                    // GitHub Classroom (link studente/docente)
                    'github_classroom_id' => $_POST['github_classroom_id'] ?? '',
                    'github_assignment_id' => $_POST['github_assignment_id'] ?? '',
                    'url_assignment_student' => $urlAssignmentStudent,
                    'url_assignment_teacher' => $urlAssignmentTeacher,
                    'repo_default_branch' => $_POST['repo_default_branch'] ?? ''
                ];

                // Se è Google Classroom, crea l'assignment su Classroom o importa esistente
                if ($piattaforma === 'google-classroom' && !empty($_POST['classroom_course_id'])) {
                    try {
                        $classroomAPI = new GoogleClassroomAPI($config);

                        // CASO 1: Importa assignment esistente
                        if (!empty($_POST['classroom_assignment_id'])) {
                            // L'assignment esiste già su Classroom, salviamo solo i riferimenti
                            $testData['classroom_assignment_id'] = $_POST['classroom_assignment_id'];
                            $testData['classroom_course_id'] = $_POST['classroom_course_id_import'] ?? $_POST['classroom_course_id'];

                            // Usa topic_id se fornito
                            if (!empty($_POST['classroom_topic_id'])) {
                                $testData['classroom_topic_id'] = $_POST['classroom_topic_id'];
                            }

                            // URL dell'assignment
                            if (!empty($_POST['classroom_assignment_url'])) {
                                $testData['classroom_url'] = $_POST['classroom_assignment_url'];
                                $testData['url'] = $_POST['classroom_assignment_url'];
                                $testData['url_docente'] = $_POST['classroom_assignment_url'];
                                $testData['url_studenti'] = $_POST['classroom_assignment_url'];
                            }

                            $successMessage = "Compito '{$testData['nome']}' importato da Google Classroom!";
                        }
                        // CASO 2: Crea nuovo assignment
                        else {
                            // Trova o crea il topic
                            $topicName = $_POST['argomento_classroom'] ?? $uda->titolo;
                            $topic = $classroomAPI->findOrCreateTopic($_POST['classroom_course_id'], $topicName);
                            $testData['classroom_topic_id'] = $topic['id'];

                            // Prepara materiali allegati
                            $materials = [];
                            if (!empty($_POST['allegati_json'])) {
                                $allegati = json_decode($_POST['allegati_json'], true);
                                foreach ($allegati as $allegato) {
                                    if ($allegato['tipo'] === 'link') {
                                        $materials[] = $allegato['valore'];
                                    }
                                    // TODO: Gestire file Drive
                                }
                            }

                            // Prepara data di consegna
                            $dueDate = !empty($_POST['data_somministrazione']) ?
                                       $_POST['data_somministrazione'] : date('Y-m-d', strtotime('+7 days'));

                            if (!empty($_POST['ora_consegna'])) {
                                $dueDate .= ' ' . $_POST['ora_consegna'];
                            }

                            // Crea assignment
                            $assignmentData = [
                                'title' => $testData['nome'],
                                'description' => $testData['descrizione'],
                                'due_date' => $dueDate,
                                'due_time' => $_POST['ora_consegna'] ?? '23:59',
                                'max_points' => $testData['punteggio_max'],
                                'topic_id' => $topic['id'],
                                'materials' => $materials
                            ];

                            $assignment = $classroomAPI->createAssignment($_POST['classroom_course_id'], $assignmentData);

                            // Salva dati Classroom nel test
                            $testData['classroom_assignment_id'] = $assignment['id'];
                            $testData['classroom_url'] = $assignment['link'] ?? '';
                            $testData['url_docente'] = $assignment['link']; // Link per docente
                            $testData['url_studenti'] = $assignment['link']; // Stesso link, ma studenti vedono versione diversa
                            $testData['url'] = $assignment['link'];

                            $successMessage = "Compito '{$testData['nome']}' creato su Google Classroom!";
                        }
                    } catch (Exception $e) {
                        $errorMessage = "Errore gestione compito Classroom: " . $e->getMessage();
                    }
                }

                $dbAdapter->insertRow('TEST', $testData);

                if (!$errorMessage) {
                    $successMessage = $successMessage ?? "Test '{$testData['nome']}' aggiunto con successo!";
                }

                // Ricarica test
                $tests = $dbAdapter->findWhere('TEST', ['id_uda' => $udaId]);
                break;

            case 'delete_test':
                $testId = $_POST['test_id'] ?? null;
                if ($testId) {
                    $dbAdapter->deleteRow('TEST', $testId, 'id_test');
                    $successMessage = "Test eliminato con successo!";

                    // Ricarica test
                    $tests = $dbAdapter->findWhere('TEST', ['id_uda' => $udaId]);
                }
                break;

            case 'update_test':
                $testId = $_POST['test_id'] ?? null;
                if ($testId) {
                    // Carica test esistente
                    $allTests = $dbAdapter->findAll('TEST');
                    $existingTest = null;
                    foreach ($allTests as $t) {
                        if (($t['id_test'] ?? '') === $testId) {
                            $existingTest = $t;
                            break;
                        }
                    }

                    if ($existingTest) {
                        // Lista campi consentiti
                        $allowed = [
                            'id_test', 'id_uda', 'tipo_test', 'nome', 'descrizione', 'piattaforma',
                            'url', 'url_docente', 'url_studenti', 'id_esterno',
                            'num_domande', 'durata_minuti', 'punteggio_max', 'soglia_sufficienza',
                            'data_creazione', 'data_somministrazione', 'ora_consegna',
                            'pubblicato', 'risultati_importati', 'note',
                            'classroom_course_id', 'classroom_assignment_id', 'classroom_topic_id',
                            'allegati_json',
                            'github_classroom_id', 'github_assignment_id',
                            'url_assignment_student', 'url_assignment_teacher', 'repo_default_branch',
                            // CBM
                            'cbm_enabled', 'cbm_levels_json', 'cbm_scoring_model', 'cbm_form_config_json'
                        ];

                        // Ricostruisci i dati esistenti limitandoli ai campi previsti
                        $sanitized = array_intersect_key($existingTest, array_flip($allowed));

                        // Aggiorna solo i campi modificabili da form
                        $sanitized['nome'] = $_POST['nome'] ?? ($sanitized['nome'] ?? '');
                        $sanitized['descrizione'] = $_POST['descrizione'] ?? ($sanitized['descrizione'] ?? '');
                        $sanitized['num_domande'] = $_POST['num_domande'] ?? ($sanitized['num_domande'] ?? 0);
                        $sanitized['durata_minuti'] = $_POST['durata_minuti'] ?? ($sanitized['durata_minuti'] ?? 0);
                        $sanitized['punteggio_max'] = $_POST['punteggio_max'] ?? ($sanitized['punteggio_max'] ?? 100);
                        $sanitized['soglia_sufficienza'] = $_POST['soglia_sufficienza'] ?? ($sanitized['soglia_sufficienza'] ?? 60);
                        $sanitized['data_somministrazione'] = $_POST['data_somministrazione'] ?? ($sanitized['data_somministrazione'] ?? '');
                        $sanitized['ora_consegna'] = $_POST['ora_consegna'] ?? ($sanitized['ora_consegna'] ?? '');
                        $sanitized['note'] = $_POST['note'] ?? ($sanitized['note'] ?? '');
                        // CBM
                        $sanitized['cbm_enabled'] = isset($_POST['cbm_enabled']) ? $_POST['cbm_enabled'] : ($sanitized['cbm_enabled'] ?? 'NO');
                        if (isset($_POST['cbm_levels_json'])) {
                            $sanitized['cbm_levels_json'] = $_POST['cbm_levels_json'];
                        }
                        if (isset($_POST['cbm_scoring_model'])) {
                            $sanitized['cbm_scoring_model'] = $_POST['cbm_scoring_model'];
                        }

                        // Mantieni link esistenti; non modificabili dalla modale
                        $sanitized['url'] = $sanitized['url'] ?? '';
                        $sanitized['url_docente'] = $sanitized['url_docente'] ?? '';
                        $sanitized['url_studenti'] = $sanitized['url_studenti'] ?? '';

                        // Aggiorna nel database
                        // updateRow(tabella, campo chiave, valore chiave, dati)
                        $dbAdapter->updateRow('TEST', 'id_test', $testId, $sanitized);
                        $successMessage = "Test aggiornato con successo!";

                        // Ricarica test
                        $tests = $dbAdapter->findWhere('TEST', ['id_uda' => $udaId]);
                    }
                }
                break;
        }
    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Test e attività - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .test-card {
            transition: all 0.3s;
            border-left: 4px solid #0d6efd;
        }
        .test-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .test-card.kahoot {
            border-left-color: #46178f;
        }
        .test-card.google-forms {
            border-left-color: #7248b9;
        }
        .btn-github {
            background-color: #663399;
            border-color: #663399;
            color: #fff;
        }
        .btn-github:hover {
            background-color: #5a2d85;
            border-color: #5a2d85;
            color: #fff;
        }
        .platform-badge {
            font-size: 0.8rem;
        }
        .test-card.highlight-flash {
            animation: highlightFlash 1.6s ease-out 1;
        }
        @keyframes highlightFlash {
            0%   { box-shadow: 0 0 0 rgba(255, 215, 0, 0.8); background-color: #fffce8; }
            50%  { box-shadow: 0 0 12px rgba(255, 215, 0, 0.9); background-color: #fff4b8; }
            100% { box-shadow: 0 0 0 rgba(255, 215, 0, 0); background-color: transparent; }
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
    $pageTitle = '<i class="bi bi-clipboard-check"></i> Gestione Test e attivita';
    $pageSubtitle = 'UDA: ' . ($uda->titolo ?? '');

    // Link di navigazione (restano nella barra)
    $headerActions = '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">'
        . '<i class="bi bi-arrow-left"></i> Torna alla UDA</a>';

    // Pulsanti azione (vanno in linea con il titolo) - organizzati in griglia 3x1
    $pageActions = '<div class="page-actions-grid">'
        . '<a href="uda_questions.php?id=' . urlencode($udaId) . '" class="btn btn-success btn-sm text-nowrap">'
        . '<i class="bi bi-question-circle"></i> Crea test con le domande</a>'
        . '<button class="btn btn-primary btn-sm text-nowrap" data-bs-toggle="modal" data-bs-target="#addTestModal" id="openAddTestBtn">'
        . '<i class="bi bi-link-45deg"></i> Collega un test esistente</button>'
        . '<a class="btn btn-github btn-sm text-nowrap" href="github_assignment_create.php?id_uda=' . urlencode($udaId) . '">'
        . '<i class="bi bi-github"></i> Crea assegnazione GitHub</a>'
        . '</div>';

    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">

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

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-4" role="tablist">
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#prerequisiti">
                    <i class="bi bi-clipboard-data"></i> Test Prerequisiti
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#intermedi">
                    <i class="bi bi-clipboard-pulse"></i> Test Intermedi
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link" data-bs-toggle="tab" href="#finali">
                    <i class="bi bi-clipboard-check"></i> Test Finali
                </a>
            </li>
            <li class="nav-item">
                <a class="nav-link active" data-bs-toggle="tab" href="#tutti">
                    <i class="bi bi-list"></i> Tutti i Test
                </a>
            </li>
        </ul>

        <!-- Tab Content -->
        <div class="tab-content">
            <?php
            // Raggruppa test per tipo
            $testPerTipo = [
                'prerequisiti' => [],
                'intermedio' => [],
                'finale' => [],
                'altro' => []
            ];

            foreach ($tests as $test) {
                $tipo = $test['tipo_test'] ?? 'altro';
                if (!isset($testPerTipo[$tipo])) {
                    $testPerTipo['altro'][] = $test;
                } else {
                    $testPerTipo[$tipo][] = $test;
                }
            }

            function renderTestCards($tests, $withAnchors = false) {
                if (empty($tests)) {
                    echo '<div class="alert alert-info">';
                    echo '<i class="bi bi-info-circle"></i> Nessun test trovato. Usa "Crea test con le domande" o "Collega un test esistente" per aggiungerne uno.';
                    echo '</div>';
                    return;
                }

                foreach ($tests as $test) {
                    $piattaforma = strtolower($test['piattaforma'] ?? '');
                    $isCbm = !empty($test['cbm_enabled']) && strtolower((string)$test['cbm_enabled']) !== 'no';
                    $cardClass = match($piattaforma) {
                        'kahoot' => 'kahoot',
                        'google-forms' => 'google-forms',
                        'socrative' => 'socrative',
                        'github' => 'github',
                        default => ''
                    };
                    $pubblicato = ($test['pubblicato'] ?? 'NO') === 'SI';
                    $classroomUrl = trim((string)($test['classroom_url'] ?? ''));
                    ?>
                    <div class="card test-card <?= $cardClass ?> mb-3" <?= $withAnchors ? 'id="test-' . htmlspecialchars($test['id_test']) . '"': '' ?>>
                        <div class="card-body">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h5 class="card-title">
                                        <?= htmlspecialchars($test['nome']) ?>
                                        <?php $tbadges = $testBadges[$test['id_test']] ?? []; ?>
                                        <?php if (!empty($udaGroups)): ?>
                                            <span class="d-inline-flex flex-wrap gap-1 ms-2">
                                                <?php foreach ($udaGroups as $grp): $b = $tbadges[$grp['id_gruppo']] ?? ['label' => 'NON CREATO', 'color' => ClassroomPublishState::COLOR_NON_CREATO, 'url' => '']; ?>
                                                    <?= ClassroomPublishState::badgeHtml($b, ($grp['nome_gruppo']) . ' ' . $b['label']) ?>
                                                <?php endforeach; ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="badge bg-secondary ms-2">Bozza</span>
                                        <?php endif; ?>
                                        <?php if ($piattaforma === 'google-forms' && ($test['risultati_importati'] ?? 'NO') === 'SI'): ?>
                                            <span class="badge bg-info ms-2">
                                                <i class="bi bi-cloud-check"></i> Risultati Importati
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($piattaforma === 'google-forms' && $isCbm): ?>
                                            <span class="badge bg-warning text-dark ms-2">Forms CBM</span>
                                        <?php endif; ?>
                                    </h5>
                                    <p class="card-text text-muted mb-2">
                                        <?= htmlspecialchars($test['descrizione'] ?? '') ?>
                                    </p>
                                    <div class="mb-2">
                                        <?php
                                            $platformColor = match($piattaforma) {
                                                'kahoot' => '#46178f',
                                                'google-forms' => '#7248b9',
                                                'socrative' => '#e67e22',
                                                'github' => '#663399',
                                                default => '#6c757d'
                                            };
                                            $platformIcon = match($piattaforma) {
                                                'kahoot' => 'stars',
                                                'google-forms' => 'file-earmark-text',
                                                'socrative' => 'chat-left-dots',
                                                'github' => 'github',
                                                default => 'circle'
                                            };
                                        ?>
                                        <span class="badge platform-badge" style="background-color: <?= $platformColor ?>">
                                            <i class="bi bi-<?= $platformIcon ?>"></i>
                                            <?= strtoupper($test['piattaforma'] ?? 'N/A') ?>
                                        </span>
                                        <?php if ($piattaforma !== 'google-classroom' && $piattaforma !== 'github'): ?>
                                            <span class="badge bg-info ms-1">
                                                <i class="bi bi-question-circle"></i> <?= $test['num_domande'] ?? 0 ?> domande
                                            </span>
                                            <span class="badge bg-warning text-dark ms-1">
                                                <i class="bi bi-clock"></i> <?= $test['durata_minuti'] ?? 0 ?> min
                                            </span>
                                        <?php endif; ?>
                                        <span class="badge bg-secondary ms-1">
                                            <i class="bi bi-star"></i> Max: <?= htmlspecialchars($test['punteggio_max'] ?: '100') ?> pt
                                        </span>
                                    </div>
                                    <!-- Link Studenti -->
                                    <?php
                                    $urlStudenti = $test['url_studenti'] ?? $test['url'] ?? ($test['url_pubblico'] ?? '');
                                    // Ordine di priorità per il link docente: campo dedicato, gestione/modifica form, pubblico come fallback.
                                    // Se non presente ma il link studenti è un Google Form view, prova a costruire l'edit.
                                    $urlDocente = $test['url_docente'] ?? ($test['url_gestione'] ?? ($test['url'] ?? ($test['url_pubblico'] ?? '')));
                                    if (empty($urlDocente) && strpos($urlStudenti, 'docs.google.com/forms') !== false && str_contains($urlStudenti, '/viewform')) {
                                        $urlDocente = str_replace('/viewform', '/edit', $urlStudenti);
                                    }
                                    // Per gli assignment GitHub il link docente è il filtro repo su GitHub (org + prefisso).
                                    if ($piattaforma === 'github') {
                                        $ghCfg = json_decode((string)($test['github_config_json'] ?? '{}'), true);
                                        $ghOrg = trim((string)(is_array($ghCfg) ? ($ghCfg['org'] ?? '') : ''));
                                        $ghPrefix = trim((string)(is_array($ghCfg) ? ($ghCfg['repo_prefix'] ?? '') : ''));
                                        if ($ghOrg !== '' && $ghPrefix !== '') {
                                            $urlDocente = 'https://github.com/search?q=' . urlencode("user:{$ghOrg} {$ghPrefix} in:name") . '&type=repositories';
                                        }
                                    }
                                    ?>
                                    <!-- Link Studenti -->
                                    <?php if (!empty($urlStudenti)): ?>
                                        <a href="<?= htmlspecialchars($urlStudenti) ?>" target="_blank" class="btn btn-sm btn-outline-success me-1">
                                            <i class="bi bi-person-check"></i> Link Studenti
                                        </a>
                                    <?php endif; ?>

                                    <!-- Link Docente -->
                                    <?php if (!empty($urlDocente)): ?>
                                        <a href="<?= htmlspecialchars($urlDocente) ?>" target="_blank" class="btn btn-sm btn-outline-primary me-1">
                                            <i class="bi bi-gear"></i> Link Docente
                                        </a>
                                    <?php endif; ?>

                                    <!-- Riepilogo GitHub -->
                                    <?php if ($piattaforma === 'github'): ?>
                                        <a href="github_assignment_review.php?test_id=<?= urlencode($test['id_test']) ?>"
                                           class="btn btn-sm btn-outline-dark me-1">
                                            <i class="bi bi-github"></i> Riepilogo GitHub
                                        </a>
                                    <?php endif; ?>

                                    <!-- Import Risultati Google Forms -->
                                    <?php if ($piattaforma === 'google-forms'): ?>
                                        <?php
                                        $risultatiImportati = ($test['risultati_importati'] ?? 'NO') === 'SI';
                                        $btnClass = $risultatiImportati ? 'btn-outline-success' : 'btn-success';
                                        $btnIcon = $risultatiImportati ? 'check-circle' : 'download';
                                        $btnText = $risultatiImportati ? 'Risultati Importati' : 'Importa Risultati';
                                        ?>
                                        <a href="import_form_results.php?test_id=<?= urlencode($test['id_test']) ?>&step=read_responses"
                                           class="btn btn-sm <?= $btnClass ?> me-1">
                                            <i class="bi bi-<?= $btnIcon ?>"></i> <?= $btnText ?>
                                        </a>
                                        <?php if ($isCbm): ?>
                                            <a href="test_cbm_analysis.php?test_id=<?= urlencode($test['id_test']) ?>"
                                               class="btn btn-sm btn-outline-warning me-1">
                                                <i class="bi bi-graph-up"></i> Analisi CBM
                                            </a>
                                        <?php endif; ?>
                                    <?php endif; ?>

                                    <!-- Import Voti Google Classroom -->
                                    <?php if ($piattaforma === 'google-classroom'): ?>
                                        <?php
                                        $risultatiImportati = ($test['risultati_importati'] ?? 'NO') === 'SI';
                                        $btnClass = $risultatiImportati ? 'btn-outline-success' : 'btn-success';
                                        $btnIcon = $risultatiImportati ? 'check-circle' : 'download';
                                        $btnText = $risultatiImportati ? 'Voti Importati' : 'Importa Voti';
                                        ?>
                                        <a href="import_classroom_grades.php?test_id=<?= urlencode($test['id_test']) ?>&step=start"
                                           class="btn btn-sm <?= $btnClass ?> me-1">
                                            <i class="bi bi-<?= $btnIcon ?>"></i> <?= $btnText ?>
                                        </a>
                                    <?php endif; ?>

                                    <!-- Import Voti Kahoot / Socrative -->
                                    <?php if ($piattaforma === 'kahoot' || $piattaforma === 'socrative'): ?>
                                        <?php
                                        $risultatiImportati = ($test['risultati_importati'] ?? 'NO') === 'SI';
                                        $btnClass = $risultatiImportati ? 'btn-outline-success' : 'btn-success';
                                        $btnIcon = $risultatiImportati ? 'check-circle' : 'upload';
                                        $btnText = $risultatiImportati ? 'Voti Importati' : 'Importa Voti';
                                        $platformParam = $piattaforma;
                                        $testUdaId = trim((string)($test['id_uda'] ?? ($uda->id_uda ?? '')));
                                        ?>
                                        <a href="import_quiz_results_excel.php?uda_id=<?= urlencode($testUdaId) ?>&platform=<?= urlencode($platformParam) ?>&test_id=<?= urlencode($test['id_test']) ?>"
                                           class="btn btn-sm <?= $btnClass ?> me-1">
                                            <i class="bi bi-<?= $btnIcon ?>"></i> <?= $btnText ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                                <div class="ms-3">
                                    <div class="btn-group-vertical" role="group">
                                        <?php
                                        // Pulsanti pubblicazione solo per test NON Google Classroom
                                        $isClassroom = ($piattaforma === 'google-classroom');
                                        if (!$isClassroom):
                                        ?>
                                            <!-- Pubblica in Bozza -->
                                            <a href="publish_test_to_classroom.php?test_id=<?= urlencode($test['id_test']) ?>&mode=draft"
                                               class="btn btn-sm btn-outline-secondary mb-1"
                                               title="Pubblica in bozza su Classroom">
                                                <i class="bi bi-file-earmark-plus"></i>
                                            </a>
                                            <!-- Pubblica Subito -->
                                            <a href="publish_test_to_classroom.php?test_id=<?= urlencode($test['id_test']) ?>&mode=publish"
                                               class="btn btn-sm btn-outline-success mb-1"
                                               title="Pubblica subito su Classroom">
                                                <i class="bi bi-send"></i>
                                            </a>
                                        <?php endif; ?>
                                        <!-- Pulsante Modifica -->
                                        <button type="button"
                                                class="btn btn-sm btn-outline-primary mb-1"
                                                data-bs-toggle="modal"
                                                data-bs-target="#editTestModal"
                                                onclick="loadTestForEdit(<?= htmlspecialchars(json_encode($test, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES) ?>)">
                                            <i class="bi bi-pencil"></i>
                                        </button>
                                        <!-- Pulsante Elimina -->
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Sicuro di voler eliminare questo test?');">
                                            <input type="hidden" name="action" value="delete_test">
                                            <input type="hidden" name="test_id" value="<?= htmlspecialchars($test['id_test']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                            </div>
                            <?php if (!empty($test['data_somministrazione'])): ?>
                                <div class="mt-2 text-muted small">
                                    <i class="bi bi-calendar-event"></i> Somministrazione: <?= htmlspecialchars($test['data_somministrazione']) ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php
                }
            }
            ?>

            <!-- Tab Prerequisiti -->
            <div class="tab-pane fade" id="prerequisiti">
                <h4 class="mb-3">Test Prerequisiti</h4>
                <p class="text-muted">Valutazione delle conoscenze di base degli studenti prima di iniziare l'UDA.</p>
                <?php renderTestCards($testPerTipo['prerequisiti']); ?>
            </div>

            <!-- Tab Intermedi -->
            <div class="tab-pane fade" id="intermedi">
                <h4 class="mb-3">Test Intermedi</h4>
                <p class="text-muted">Verifiche formative durante lo svolgimento dell'UDA.</p>
                <?php renderTestCards($testPerTipo['intermedio']); ?>
            </div>

            <!-- Tab Finali -->
            <div class="tab-pane fade" id="finali">
                <h4 class="mb-3">Test Finali</h4>
                <p class="text-muted">Valutazione sommativa al termine dell'UDA.</p>
                <?php renderTestCards($testPerTipo['finale']); ?>
            </div>

            <!-- Tab Tutti -->
            <div class="tab-pane fade show active" id="tutti">
                <h4 class="mb-3">Tutti i Test</h4>
                <?php renderTestCards($tests, true); ?>
            </div>
        </div>
    </div>

    <!-- Modal Collega Test dal Link -->
    <div class="modal fade" id="addTestModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <form method="POST" id="addTestForm">
                    <input type="hidden" name="action" value="add_test">

                    <div class="modal-header">
                        <h5 class="modal-title">
                            <i class="bi bi-link-45deg"></i> Collega un test esistente
                        </h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="row g-3">
                            <!-- Tipo Test -->
                            <div class="col-md-6">
                                <label class="form-label">Tipo Test *</label>
                                <select name="tipo_test" class="form-select" required>
                                    <option value="">Seleziona...</option>
                                    <option value="prerequisiti">Test Prerequisiti</option>
                                    <option value="intermedio">Test Intermedio</option>
                                    <option value="finale">Test Finale</option>
                                </select>
                            </div>

                            <!-- Piattaforma -->
                            <div class="col-md-6">
                                <label class="form-label">Piattaforma *</label>
                                <select name="piattaforma" class="form-select" required id="piattaformaSelect">
                                    <option value="">Seleziona...</option>
                                    <option value="google-forms">Google Forms</option>
                                    <option value="google-classroom">Google Classroom (Compito)</option>
                                    <option value="kahoot">Kahoot</option>
                                    <option value="socrative">Socrative</option>
                                    <option value="github">GitHub Classroom</option>
                                    <option value="altro">Altro</option>
                                </select>
                            </div>

                            <!-- URL Gestione (Docente) - Solo Google Forms -->
                            <div class="col-12" id="urlGestioneContainer" style="display:none;">
                                <label class="form-label">
                                    <i class="bi bi-gear"></i> URL Gestione (Link Docente) *
                                </label>
                                <div class="input-group">
                                    <input type="url" name="url_gestione" id="urlGestione" class="form-control"
                                           placeholder="https://docs.google.com/forms/d/.../edit">
                                    <button type="button" class="btn btn-outline-primary" id="btnCaricaDati">
                                        <i class="bi bi-download"></i> Carica Dati
                                    </button>
                                </div>
                                <small class="form-text text-muted">
                                    Incolla il link per modificare il form. I dati verranno caricati automaticamente.
                                </small>
                            </div>

                            <!-- CBM toggle per Google Forms -->
                            <div class="col-12" id="cbmToggleContainer" style="display:none;">
                                <div class="form-check form-switch">
                                    <input class="form-check-input" type="checkbox" name="cbm_enabled" value="1" id="cbmEnabledSwitch">
                                    <label class="form-check-label" for="cbmEnabledSwitch">
                                        Abilita Confidence-Based Assessment (CBM) su questo Google Form
                                    </label>
                                </div>
                                <small class="form-text text-muted">
                                    Verrà creato/gestito come "Forms CBM": domande di confidenza abbinate e punteggio CBM in import.
                                </small>
                            </div>

                            <!-- GitHub - Catalogo assignment dal DB -->
                            <div class="col-12" id="githubCatalogContainer" style="display:none;">
                                <div class="border rounded p-3 bg-light">
                                    <div class="small text-muted mb-2">
                                        <i class="bi bi-github"></i> Assignment GitHub creati per i gruppi di questa UDA.
                                    </div>
                                    <div class="alert d-none mb-2" id="githubCatalogStatus"></div>
                                    <div id="githubCatalogMapped">
                                        <label class="form-label">Cerca assignment</label>
                                        <input type="search" class="form-control mb-2" id="githubAssignmentSearch" placeholder="Cerca per titolo o slug..." autocomplete="off">
                                        <div class="list-group" id="githubAssignmentList" role="listbox"></div>
                                    </div>
                                    <div id="githubCatalogNomap" class="d-none">
                                        <div class="alert alert-warning py-2 mb-2">Nessun assignment GitHub per i gruppi di questa UDA.</div>
                                        <a class="btn btn-sm btn-outline-primary" href="github_assignment_create.php?id_uda=<?= htmlspecialchars(urlencode((string)$udaId), ENT_QUOTES, 'UTF-8') ?>">Crea un assignment</a>
                                    </div>
                                </div>
                            </div>

                            <!-- URL Kahoot/Altro - Campi Manuali -->
                            <div id="urlManualiContainer" style="display:none;">
                                <!-- URL Studenti -->
                                <div class="col-12">
                                    <label class="form-label">
                                        <i class="bi bi-person-check"></i> URL per Studenti *
                                    </label>
                                    <input type="url" name="url_studenti" id="urlStudenti" class="form-control"
                                           placeholder="https://kahoot.it/challenge/... oppure https://b.socrative.com/login/student/...">
                                    <small class="form-text text-muted">
                                        Link che gli studenti useranno per partecipare al test
                                    </small>
                                </div>

                                <!-- URL Docente -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">
                                        <i class="bi bi-gear"></i> URL per Docente
                                    </label>
                                    <input type="url" name="url_docente" id="urlDocente" class="form-control"
                                           placeholder="https://create.kahoot.it/... oppure link report Socrative">
                                    <small class="form-text text-muted">
                                        Link per gestire il test e visualizzare i risultati (opzionale)
                                    </small>
                                </div>

                                <!-- Nome Test - Manuale -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">Nome Test *</label>
                                    <input type="text" name="nome" id="nomeTestManuale" class="form-control" required
                                           placeholder="Es: Test Finale - Sistemi Operativi">
                                </div>

                                <!-- Descrizione - Manuale -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">Descrizione</label>
                                    <textarea name="descrizione" id="descrizioneTestManuale" class="form-control" rows="2"
                                              placeholder="Breve descrizione del test"></textarea>
                                </div>

                                <!-- Dettagli in riga -->
                                <div class="col-md-4 mt-3">
                                    <label class="form-label">Numero Domande</label>
                                    <input type="number" name="num_domande" id="numDomandeManuale" class="form-control" value="10">
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="form-label">Durata (minuti)</label>
                                    <input type="number" name="durata_minuti" id="durataMinuti" class="form-control" value="30">
                                </div>

                                <div class="col-md-4 mt-3">
                                    <label class="form-label">Punteggio Max</label>
                                    <input type="number" name="punteggio_max" id="punteggioMaxManuale" class="form-control" value="100">
                                </div>
                            </div>

                            <!-- Google Classroom - Campi Specifici -->
                            <div id="classroomContainer" style="display:none;">
                                <!-- Opzione: Crea Nuovo o Importa -->
                                <div class="col-12 mb-3">
                                    <div class="btn-group w-100" role="group">
                                        <input type="radio" class="btn-check" name="classroom_mode" id="classroomModeNew" value="new" checked>
                                        <label class="btn btn-outline-primary" for="classroomModeNew">
                                            <i class="bi bi-plus-circle"></i> Crea Nuovo Compito
                                        </label>

                                        <input type="radio" class="btn-check" name="classroom_mode" id="classroomModeImport" value="import">
                                        <label class="btn btn-outline-success" for="classroomModeImport">
                                            <i class="bi bi-download"></i> Importa Compito Esistente
                                        </label>
                                    </div>
                                </div>

                                <!-- Corso Classroom -->
                                <div class="col-12">
                                    <label class="form-label">
                                        <i class="bi bi-google"></i> Corso Google Classroom *
                                    </label>
                                    <select name="classroom_course_id" id="classroomCourseId" class="form-select">
                                        <option value="">Caricamento corsi...</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Seleziona il corso dove pubblicare/importare il compito
                                    </small>
                                </div>

                                <!-- Import Compito Esistente -->
                                <div class="col-12 mt-3" id="importAssignmentContainer" style="display:none;">
                                    <label class="form-label">
                                        <i class="bi bi-list-ul"></i> Compiti Disponibili
                                    </label>
                                    <select name="import_assignment_id" id="importAssignmentId" class="form-select">
                                        <option value="">Seleziona prima un corso...</option>
                                    </select>
                                    <small class="form-text text-muted">
                                        Seleziona un compito esistente da importare nel sistema
                                    </small>
                                    <button type="button" class="btn btn-sm btn-primary mt-2" id="btnImportaCompito">
                                        <i class="bi bi-download"></i> Carica Dati Compito
                                    </button>
                                </div>

                                <!-- Divisore -->
                                <div class="col-12 mt-3" id="classroomFormDivider">
                                    <hr>
                                </div>

                                <!-- Titolo Compito -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">Titolo Compito *</label>
                                    <input type="text" name="nome" id="nomeClassroom" class="form-control" required
                                           placeholder="Es: Compito REST API - Raccolta Dati">
                                </div>

                                <!-- Istruzioni -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">Istruzioni</label>
                                    <textarea name="descrizione" id="descrizioneClassroom" class="form-control" rows="4"
                                              placeholder="Istruzioni dettagliate per gli studenti"></textarea>
                                </div>

                                <!-- Data e Ora Consegna -->
                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Data Consegna *</label>
                                    <input type="date" name="data_somministrazione" id="dataConsegnaClassroom" class="form-control" required>
                                </div>

                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Ora Consegna</label>
                                    <input type="time" name="ora_consegna" id="oraConsegnaClassroom" class="form-control" value="23:59">
                                </div>

                                <!-- Argomento (Topic) con Autocompletamento -->
                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Argomento</label>
                                    <input type="text" name="argomento_classroom" id="argomentoClassroom"
                                           class="form-control" list="argomentiList"
                                           placeholder="Es: Unità 3 - REST API">
                                    <datalist id="argomentiList">
                                        <!-- Popolato dinamicamente con argomenti esistenti -->
                                    </datalist>
                                    <small class="form-text text-muted">
                                        Lascia vuoto per usare il titolo UDA. Usa il completamento automatico per argomenti esistenti.
                                    </small>
                                </div>

                                <!-- Punti Totali -->
                                <div class="col-md-6 mt-3">
                                    <label class="form-label">Punti Totali *</label>
                                    <input type="number" name="punteggio_max" id="punteggioClassroom" class="form-control" value="100" required>
                                </div>

                                <!-- Allegati -->
                                <div class="col-12 mt-3">
                                    <label class="form-label">Allegati</label>
                                    <div id="allegatiContainer">
                                        <div class="input-group mb-2">
                                            <select class="form-select allegato-tipo" style="max-width: 150px;">
                                                <option value="link">Link</option>
                                                <option value="drive">File Drive</option>
                                            </select>
                                            <input type="text" class="form-control allegato-valore" placeholder="URL o ID file Drive">
                                            <button type="button" class="btn btn-outline-danger btn-rimuovi-allegato" disabled>
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-primary" id="btnAggiungiAllegato">
                                        <i class="bi bi-plus"></i> Aggiungi Allegato
                                    </button>
                                    <small class="form-text text-muted d-block mt-2">
                                        Puoi allegare link esterni o file da Google Drive
                                    </small>
                                </div>

                                <!-- Campi nascosti -->
                                <input type="hidden" name="allegati_json" id="allegatiJson">
                                <input type="hidden" name="classroom_topic_id" id="classroomTopicId">
                            </div>

                            <!-- Loading spinner -->
                            <div class="col-12" id="loadingSpinner" style="display: none;">
                                <div class="text-center p-3">
                                    <div class="spinner-border text-primary" role="status">
                                        <span class="visually-hidden">Caricamento...</span>
                                    </div>
                                    <p class="mt-2 mb-0">Caricamento dettagli del form...</p>
                                </div>
                            </div>

                            <!-- Dati Automatici (mostrati dopo il caricamento) -->
                            <div id="datiAutomatici" style="display: none;">
                                <!-- URL Pubblico (Studenti) - Auto-generato -->
                                <div class="col-12">
                                    <label class="form-label">
                                        <i class="bi bi-person-check"></i> URL Pubblico (Link Studenti)
                                    </label>
                                    <input type="url" name="url" id="urlPubblico" class="form-control" readonly
                                           placeholder="Generato automaticamente">
                                    <small class="form-text text-muted">
                                        Link che gli studenti useranno per compilare il test (generato automaticamente)
                                    </small>
                                </div>

                                <!-- Nome Test - Auto-caricato -->
                                <div class="col-12">
                                    <label class="form-label">Nome Test *</label>
                                    <input type="text" name="nome" id="nomeTest" class="form-control" required
                                           placeholder="Caricato dal form">
                                </div>

                                <!-- Descrizione - Auto-caricata -->
                                <div class="col-12">
                                    <label class="form-label">Descrizione</label>
                                    <textarea name="descrizione" id="descrizioneTest" class="form-control" rows="2"
                                              placeholder="Caricata dal form"></textarea>
                                </div>

                                <!-- Dettagli in riga -->
                                <div class="col-md-4">
                                    <label class="form-label">Numero Domande</label>
                                    <input type="number" name="num_domande" id="numDomande" class="form-control" readonly>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Punteggio Massimo</label>
                                    <input type="number" name="punteggio_max" id="punteggioMax" class="form-control" readonly>
                                </div>

                                <div class="col-md-4">
                                    <label class="form-label">Soglia Sufficienza</label>
                                    <input type="number" name="soglia_sufficienza" id="sogliaSucc" class="form-control" value="60">
                                </div>

                                <!-- Campi nascosti -->
                                <input type="hidden" name="id_esterno" id="idEsterno">
                                <input type="hidden" name="durata_minuti" value="30">
                                <input type="hidden" name="pubblicato" value="NO">
                            </div>

                            <!-- Data Somministrazione (sempre visibile) -->
                            <div class="col-md-6">
                                <label class="form-label">Data Somministrazione</label>
                                <input type="date" name="data_somministrazione" class="form-control">
                                <small class="form-text text-muted">Quando verrà svolto il test</small>
                            </div>

                            <!-- Note -->
                            <div class="col-12">
                                <label class="form-label">Note</label>
                                <textarea name="note" class="form-control" rows="2"
                                          placeholder="Eventuali note aggiuntive"></textarea>
                            </div>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary" id="btnSalvaTest" disabled>
                            <i class="bi bi-save"></i> Salva Test
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script src="assets/js/catalog-picker.js?v=<?= @filemtime(__DIR__ . '/assets/js/catalog-picker.js') ?>"></script>
    <script>
    const udaGithubContext = <?= json_encode($udaGithubContext, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    document.addEventListener('DOMContentLoaded', function() {
        const piattaformaSelect = document.getElementById('piattaformaSelect');
        const urlGestioneContainer = document.getElementById('urlGestioneContainer');
        const urlManualiContainer = document.getElementById('urlManualiContainer');
        const classroomContainer = document.getElementById('classroomContainer');
        const urlGestione = document.getElementById('urlGestione');
        const urlStudenti = document.getElementById('urlStudenti');
        const urlDocenteInput = document.getElementById('urlDocente');
        const cbmToggleContainer = document.getElementById('cbmToggleContainer');
        const btnCaricaDati = document.getElementById('btnCaricaDati');
        const loadingSpinner = document.getElementById('loadingSpinner');
        const datiAutomatici = document.getElementById('datiAutomatici');
        const btnSalvaTest = document.getElementById('btnSalvaTest');
        const btnAggiungiAllegato = document.getElementById('btnAggiungiAllegato');
        const allegatiContainer = document.getElementById('allegatiContainer');

        // Gestione allegati Classroom
        btnAggiungiAllegato.addEventListener('click', function() {
            const newAllegato = document.createElement('div');
            newAllegato.className = 'input-group mb-2';
            newAllegato.innerHTML = `
                <select class="form-select allegato-tipo" style="max-width: 150px;">
                    <option value="link">Link</option>
                    <option value="drive">File Drive</option>
                </select>
                <input type="text" class="form-control allegato-valore" placeholder="URL o ID file Drive">
                <button type="button" class="btn btn-outline-danger btn-rimuovi-allegato">
                    <i class="bi bi-trash"></i>
                </button>
            `;
            allegatiContainer.appendChild(newAllegato);

            // Aggiungi evento rimozione
            newAllegato.querySelector('.btn-rimuovi-allegato').addEventListener('click', function() {
                newAllegato.remove();
            });
        });

        // Abilita rimozione per gli allegati esistenti (tranne il primo)
        document.addEventListener('click', function(e) {
            if (e.target.classList.contains('btn-rimuovi-allegato') ||
                e.target.closest('.btn-rimuovi-allegato')) {
                const allegati = allegatiContainer.querySelectorAll('.input-group');
                if (allegati.length > 1) {
                    e.target.closest('.input-group').remove();
                }
            }
        });

        // GitHub Classroom: catalogo assignment mappati (come nel wizard step 6)
        const githubCatalogContainer = document.getElementById('githubCatalogContainer');
        const githubCatalogMapped = document.getElementById('githubCatalogMapped');
        const githubCatalogNomap = document.getElementById('githubCatalogNomap');
        const githubCatalogStatus = document.getElementById('githubCatalogStatus');
        const githubAssignmentSearch = document.getElementById('githubAssignmentSearch');
        const githubAssignmentList = document.getElementById('githubAssignmentList');

        // Mostra/nascondi campo URL gestione in base alla piattaforma
        piattaformaSelect.addEventListener('change', async function() {
            // Nascondi tutti i container
            urlGestioneContainer.style.display = 'none';
            urlManualiContainer.style.display = 'none';
            classroomContainer.style.display = 'none';
            githubCatalogContainer.style.display = 'none';
            datiAutomatici.style.display = 'none';
            clearGithubHiddenFields();

            // DISABILITA tutti i campi nome, descrizione, punteggio dei container nascosti
            // Questo previene che vengano inviati nel FormData
            document.getElementById('nomeTestManuale').disabled = true;
            document.getElementById('nomeTestManuale').required = false;
            document.getElementById('nomeClassroom').disabled = true;
            document.getElementById('nomeClassroom').required = false;
            document.getElementById('nomeTest').disabled = true;
            document.getElementById('nomeTest').required = false;
            document.getElementById('descrizioneClassroom').disabled = true;
            document.getElementById('punteggioClassroom').disabled = true;
            document.getElementById('dataConsegnaClassroom').disabled = true;
            document.getElementById('dataConsegnaClassroom').required = false;
            document.getElementById('punteggioClassroom').required = false;
            urlDocenteInput.required = false;

            if (this.value === 'google-forms') {
                urlGestioneContainer.style.display = 'block';
                cbmToggleContainer.style.display = 'block';
                urlGestione.required = true;
                urlStudenti.required = false;
                urlDocenteInput.required = false;
                btnSalvaTest.disabled = true;
                // Abilita campo per Google Forms
                document.getElementById('nomeTest').disabled = false;
                document.getElementById('nomeTest').required = true;
            } else if (this.value === 'google-classroom') {
                classroomContainer.style.display = 'block';
                cbmToggleContainer.style.display = 'none';
                urlGestione.required = false;
                urlStudenti.required = false;
                urlDocenteInput.required = false;
                btnSalvaTest.disabled = false;
                // Abilita campi per Classroom
                document.getElementById('nomeClassroom').disabled = false;
                document.getElementById('nomeClassroom').required = true;
                document.getElementById('descrizioneClassroom').disabled = false;
                document.getElementById('punteggioClassroom').disabled = false;
                document.getElementById('punteggioClassroom').required = true;
                document.getElementById('dataConsegnaClassroom').disabled = false;
                document.getElementById('dataConsegnaClassroom').required = true;

                // Carica corsi Classroom
                await caricaCorsiClassroom();
            } else if (this.value === 'github') {
                urlManualiContainer.style.display = 'block';
                githubCatalogContainer.style.display = 'block';
                cbmToggleContainer.style.display = 'none';
                urlGestione.required = false;
                urlStudenti.required = true;
                urlDocenteInput.required = true;
                btnSalvaTest.disabled = false;
                // Abilita campo per inserimento manuale
                document.getElementById('nomeTestManuale').disabled = false;
                document.getElementById('nomeTestManuale').required = true;
                // Carica gli assignment della GitHub Classroom mappata
                loadGithubAssignments();
            } else if (this.value === 'kahoot' || this.value === 'socrative' || this.value === 'altro') {
                urlManualiContainer.style.display = 'block';
                cbmToggleContainer.style.display = 'none';
                urlGestione.required = false;
                urlStudenti.required = true;
                urlDocenteInput.required = false;
                btnSalvaTest.disabled = false;
                // Abilita campo per inserimento manuale
                document.getElementById('nomeTestManuale').disabled = false;
                document.getElementById('nomeTestManuale').required = true;
            } else {
                urlGestione.required = false;
                urlStudenti.required = false;
                btnSalvaTest.disabled = true;
            }
        });

        // ---- Helper GitHub Classroom (catalogo assignment mappati) ----
        function setGithubCatalogStatus(message, type = 'info') {
            if (!githubCatalogStatus) return;
            githubCatalogStatus.className = 'alert alert-' + type + ' mb-2';
            githubCatalogStatus.textContent = message || '';
            githubCatalogStatus.classList.toggle('d-none', !message);
        }

        function clearGithubHiddenFields() {
            let classroomInput = document.getElementById('hidden_github_classroom_id');
            if (!classroomInput) {
                classroomInput = document.createElement('input');
                classroomInput.type = 'hidden';
                classroomInput.name = 'github_classroom_id';
                classroomInput.id = 'hidden_github_classroom_id';
                document.getElementById('addTestForm').appendChild(classroomInput);
            }
            classroomInput.value = '';
            let assignmentInput = document.getElementById('hidden_github_assignment_id');
            if (!assignmentInput) {
                assignmentInput = document.createElement('input');
                assignmentInput.type = 'hidden';
                assignmentInput.name = 'github_assignment_id';
                assignmentInput.id = 'hidden_github_assignment_id';
                document.getElementById('addTestForm').appendChild(assignmentInput);
            }
            assignmentInput.value = '';
        }

        function fillTestFromGithubAssignment(item) {
            if (!item) return;
            document.getElementById('nomeTestManuale').value = String(item.title || item.name || '');
            document.getElementById('descrizioneTestManuale').value = String(item.description || '');
            document.getElementById('urlStudenti').value = String(item.student_url || item.url_assignment_student || item.link || '');
            document.getElementById('urlDocente').value = String(item.teacher_url || item.url_assignment_teacher || item.link || '');
            clearGithubHiddenFields();
            document.getElementById('hidden_github_classroom_id').value = String(item.github_classroom_id || '');
            document.getElementById('hidden_github_assignment_id').value = String(item.github_assignment_id || item.id || '');
            document.getElementById('nomeTestManuale').disabled = false;
            document.getElementById('nomeTestManuale').required = true;
            btnSalvaTest.disabled = false;
        }

        function renderGithubAssignments(assignments) {
            githubAssignmentList.replaceChildren();
            if (!assignments.length) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = 'Nessun assignment disponibile.';
                githubAssignmentList.appendChild(empty);
                return;
            }
            assignments.forEach(assignment => {
                const alreadyLinked = (assignment.id_uda || '') === (udaGithubContext.id_uda || '');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                if (alreadyLinked) {
                    button.disabled = true;
                    button.classList.add('disabled', 'text-muted');
                }
                const title = document.createElement('div');
                title.className = 'fw-semibold';
                title.textContent = assignment.title || assignment.slug || 'Assignment senza titolo';
                if (alreadyLinked) title.textContent += ' (già collegato)';
                button.appendChild(title);
                if (assignment.slug || assignment.github_assignment_id) {
                    const meta = document.createElement('small');
                    meta.className = 'text-muted d-block';
                    meta.textContent = assignment.slug || assignment.github_assignment_id || '';
                    button.appendChild(meta);
                }
                if (!alreadyLinked) {
                    button.addEventListener('click', () => fillTestFromGithubAssignment(assignment));
                }
                githubAssignmentList.appendChild(button);
            });
        }

        let githubPicker = null;

        async function fetchGithubAssignments() {
            const groupIds = Array.isArray(udaGithubContext.group_ids) ? udaGithubContext.group_ids : [];
            const params = new URLSearchParams();
            groupIds.forEach(id => params.append('id_gruppo[]', id));

            setGithubCatalogStatus('Caricamento assignment...', 'info');
            try {
                const response = await fetch('ajax_get_wizard_github_catalog.php?' + params.toString(), {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' }
                });
                const data = await response.json().catch(() => null);
                if (!response.ok || !data || !data.success) {
                    setGithubCatalogStatus(data?.error || ('Errore HTTP ' + response.status), 'warning');
                    return;
                }
                const assignments = Array.isArray(data.assignments) ? data.assignments : [];
                if (githubPicker) githubPicker.setItems(assignments);
                else renderGithubAssignments(assignments);
                if (!assignments.length) {
                    githubCatalogMapped.classList.add('d-none');
                    githubCatalogNomap.classList.remove('d-none');
                } else {
                    githubCatalogNomap.classList.add('d-none');
                    githubCatalogMapped.classList.remove('d-none');
                }
                setGithubCatalogStatus(assignments.length + ' assignment disponibili.', assignments.length ? 'success' : 'info');
            } catch (error) {
                setGithubCatalogStatus(error?.message || 'Impossibile caricare gli assignment.', 'warning');
                console.error(error);
            }
        }

        async function loadGithubAssignments() {
            const groupIds = Array.isArray(udaGithubContext.group_ids) ? udaGithubContext.group_ids : [];
            githubPicker = null;
            githubAssignmentList.replaceChildren();
            setGithubCatalogStatus('', 'info');
            clearGithubHiddenFields();

            if (groupIds.length === 0) {
                githubCatalogMapped.classList.add('d-none');
                githubCatalogNomap.classList.remove('d-none');
                return;
            }

            if (window.CatalogPicker) {
                githubPicker = new window.CatalogPicker({
                    searchInput: githubAssignmentSearch,
                    listContainer: githubAssignmentList,
                    renderItem: assignment => ({
                        title: assignment.title || assignment.slug || 'Assignment senza titolo',
                        metadata: assignment.slug || assignment.github_assignment_id || ''
                    }),
                    onSelect: assignment => fillTestFromGithubAssignment(assignment)
                });
            }

            await fetchGithubAssignments();
        }

        // Funzione per caricare corsi Classroom
        async function caricaCorsiClassroom() {
            const select = document.getElementById('classroomCourseId');
            select.innerHTML = '<option value="">Caricamento...</option>';

            try {
                const response = await fetch('ajax_get_classroom_courses.php');
                const rawText = await response.text();
                let result = null;

                try {
                    result = JSON.parse(rawText);
                } catch (parseError) {
                    select.innerHTML = '';
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = `Errore: risposta non valida (${response.status})`;
                    select.appendChild(option);
                    console.error('Classroom courses non-JSON response:', rawText);
                    return;
                }

                if (response.ok && result.success) {
                    select.innerHTML = '<option value="">Seleziona corso...</option>';
                    result.courses.forEach(course => {
                        const option = document.createElement('option');
                        option.value = course.id;
                        option.textContent = course.name + (course.section ? ` (${course.section})` : '');
                        select.appendChild(option);
                    });
                } else {
                    select.innerHTML = '';
                    const option = document.createElement('option');
                    option.value = '';
                    let errorMessage = result.error || `HTTP ${response.status}`;
                    if (result.detail) {
                        console.error('Classroom courses detail:', result.detail);
                        errorMessage += ' (vedi console)';
                    }
                    option.textContent = `Errore: ${errorMessage}`;
                    select.appendChild(option);
                    console.error(result.error || rawText);
                }
            } catch (error) {
                select.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = `Errore: ${error && error.message ? error.message : 'caricamento'}`;
                select.appendChild(option);
                console.error(error);
            }
        }

        // Funzione per caricare argomenti esistenti del corso selezionato
        async function caricaArgomentiEsistenti(courseId) {
            const datalist = document.getElementById('argomentiList');
            datalist.innerHTML = '';

            if (!courseId) {
                return; // Nessun corso selezionato
            }

            try {
                const response = await fetch('ajax_get_classroom_topics.php?course_id=' + encodeURIComponent(courseId));
                const result = await response.json();

                if (result.success && result.topics) {
                    // Rimuovi duplicati
                    const uniqueTopics = [...new Set(result.topics)];

                    uniqueTopics.forEach(topic => {
                        const option = document.createElement('option');
                        option.value = topic;
                        datalist.appendChild(option);
                    });
                }
            } catch (error) {
                console.error('Errore caricamento argomenti:', error);
            }
        }

        // Toggle tra Crea Nuovo e Importa
        const classroomModeNew = document.getElementById('classroomModeNew');
        const classroomModeImport = document.getElementById('classroomModeImport');
        const importAssignmentContainer = document.getElementById('importAssignmentContainer');
        const classroomFormDivider = document.getElementById('classroomFormDivider');

        classroomModeNew.addEventListener('change', function() {
            if (this.checked) {
                importAssignmentContainer.style.display = 'none';
            }
        });

        classroomModeImport.addEventListener('change', function() {
            if (this.checked) {
                importAssignmentContainer.style.display = 'block';
            }
        });

        // Quando cambia il corso, carica gli argomenti (sempre) e i compiti (se in modalità import)
        document.getElementById('classroomCourseId').addEventListener('change', async function() {
            const courseId = this.value;
            const importSelect = document.getElementById('importAssignmentId');

            // Carica sempre gli argomenti del corso selezionato per l'autocomplete
            if (courseId) {
                await caricaArgomentiEsistenti(courseId);
            }

            // Carica i compiti solo se in modalità import
            if (!courseId || classroomModeNew.checked) {
                importSelect.innerHTML = '<option value="">Seleziona prima un corso...</option>';
                return;
            }

            importSelect.innerHTML = '<option value="">Caricamento compiti...</option>';

            try {
                const response = await fetch('ajax_get_classroom_assignments.php?course_id=' + encodeURIComponent(courseId));
                const rawText = await response.text();
                let result = null;

                try {
                    result = JSON.parse(rawText);
                } catch (parseError) {
                    importSelect.innerHTML = '';
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = `Errore: risposta non valida (${response.status})`;
                    importSelect.appendChild(option);
                    console.error('Classroom assignments non-JSON response:', rawText);
                    return;
                }

                if (response.ok && result.success) {
                    importSelect.innerHTML = '<option value="">Seleziona compito...</option>';
                    result.assignments.forEach(assignment => {
                        const option = document.createElement('option');
                        option.value = assignment.id;
                        option.textContent = assignment.title;
                        option.dataset.assignmentData = JSON.stringify(assignment);
                        importSelect.appendChild(option);
                    });
                } else {
                    importSelect.innerHTML = '';
                    const option = document.createElement('option');
                    option.value = '';
                    let errorMessage = result.error || `HTTP ${response.status}`;
                    if (result.detail) {
                        console.error('Classroom assignments detail:', result.detail);
                        errorMessage += ' (vedi console)';
                    }
                    option.textContent = `Errore: ${errorMessage}`;
                    importSelect.appendChild(option);
                    console.error(result.error || rawText);
                }
            } catch (error) {
                importSelect.innerHTML = '';
                const option = document.createElement('option');
                option.value = '';
                option.textContent = `Errore: ${error && error.message ? error.message : 'caricamento'}`;
                importSelect.appendChild(option);
                console.error(error);
            }
        });

        // Importa dati compito selezionato
        document.getElementById('btnImportaCompito').addEventListener('click', function() {
            const importSelect = document.getElementById('importAssignmentId');
            const selectedOption = importSelect.options[importSelect.selectedIndex];

            if (!selectedOption.value) {
                alert('Seleziona un compito da importare');
                return;
            }

            const assignmentData = JSON.parse(selectedOption.dataset.assignmentData);
            const courseId = document.getElementById('classroomCourseId').value;

            // Popola i campi E assicurati che siano abilitati per il submit
            const nomeField = document.getElementById('nomeClassroom');
            const descrizioneField = document.getElementById('descrizioneClassroom');
            const punteggioField = document.getElementById('punteggioClassroom');
            const dataField = document.getElementById('dataConsegnaClassroom');

            nomeField.value = assignmentData.title;
            nomeField.disabled = false;  // Assicura che il campo sia abilitato
            nomeField.required = true;   // Riabilita required

            descrizioneField.value = assignmentData.description || '';
            descrizioneField.disabled = false;

            punteggioField.value = assignmentData.max_points || 100;
            punteggioField.disabled = false;
            punteggioField.required = true;

            // Data consegna
            if (assignmentData.due_date) {
                const dueDate = assignmentData.due_date.split(' ')[0];
                dataField.value = dueDate;
                dataField.disabled = false;
                dataField.required = true;

                if (assignmentData.due_date.split(' ')[1]) {
                    document.getElementById('oraConsegnaClassroom').value = assignmentData.due_date.split(' ')[1];
                }
            }

            // Salva dati assignment nel form per il POST
            // Usa campi hidden per passare l'ID assignment esistente
            let hiddenAssignmentId = document.getElementById('hidden_classroom_assignment_id');
            if (!hiddenAssignmentId) {
                hiddenAssignmentId = document.createElement('input');
                hiddenAssignmentId.type = 'hidden';
                hiddenAssignmentId.name = 'classroom_assignment_id';
                hiddenAssignmentId.id = 'hidden_classroom_assignment_id';
                document.getElementById('addTestForm').appendChild(hiddenAssignmentId);
            }
            hiddenAssignmentId.value = assignmentData.id;

            // Salva anche course_id e topic_id
            let hiddenCourseId = document.getElementById('hidden_classroom_course_id_import');
            if (!hiddenCourseId) {
                hiddenCourseId = document.createElement('input');
                hiddenCourseId.type = 'hidden';
                hiddenCourseId.name = 'classroom_course_id_import';
                hiddenCourseId.id = 'hidden_classroom_course_id_import';
                document.getElementById('addTestForm').appendChild(hiddenCourseId);
            }
            hiddenCourseId.value = courseId;

            if (assignmentData.topic_id) {
                let hiddenTopicId = document.getElementById('hidden_classroom_topic_id');
                if (!hiddenTopicId) {
                    hiddenTopicId = document.createElement('input');
                    hiddenTopicId.type = 'hidden';
                    hiddenTopicId.name = 'classroom_topic_id';
                    hiddenTopicId.id = 'hidden_classroom_topic_id';
                    document.getElementById('addTestForm').appendChild(hiddenTopicId);
                }
                hiddenTopicId.value = assignmentData.topic_id;
            }

            // Salva URL assignment
            let hiddenUrl = document.getElementById('hidden_classroom_assignment_url');
            if (!hiddenUrl) {
                hiddenUrl = document.createElement('input');
                hiddenUrl.type = 'hidden';
                hiddenUrl.name = 'classroom_assignment_url';
                hiddenUrl.id = 'hidden_classroom_assignment_url';
                document.getElementById('addTestForm').appendChild(hiddenUrl);
            }
            hiddenUrl.value = assignmentData.link;

            // Nascondi container import (mantieni modalità import per required fields)
            importAssignmentContainer.style.display = 'none';
            // NON switchiamo a "Crea Nuovo" per mantenere i campi required attivi
            // classroomModeNew.checked = true;

            // IMPORTANTE: Abilita il bottone Salva Test
            btnSalvaTest.disabled = false;

            alert('Dati compito caricati! Verifica e salva.');
        });

        // Prima del submit, serializza allegati per Classroom
        document.getElementById('addTestForm').addEventListener('submit', function(e) {
            if (piattaformaSelect.value === 'google-classroom') {
                const allegati = [];
                const allegatiInputs = allegatiContainer.querySelectorAll('.input-group');

                allegatiInputs.forEach(input => {
                    const tipo = input.querySelector('.allegato-tipo').value;
                    const valore = input.querySelector('.allegato-valore').value.trim();

                    if (valore) {
                        allegati.push({ tipo, valore });
                    }
                });

                document.getElementById('allegatiJson').value = JSON.stringify(allegati);

                // Usa il titolo UDA come topic se non specificato
                const argomento = document.getElementById('argomentoClassroom').value.trim();
                if (!argomento) {
                    document.getElementById('argomentoClassroom').value = '<?= addslashes($uda->titolo) ?>';
                }
            }
        });

        // Carica dati dal form quando si clicca il bottone
        btnCaricaDati.addEventListener('click', async function() {
            const url = urlGestione.value.trim();

            if (!url) {
                alert('Inserisci l\'URL di gestione del form');
                return;
            }

            // Mostra spinner
            loadingSpinner.style.display = 'block';
            datiAutomatici.style.display = 'none';
            btnCaricaDati.disabled = true;

            try {
                const response = await fetch('ajax_get_form_details.php?url=' + encodeURIComponent(url));
                const result = await response.json();

                if (result.success) {
                    const data = result.data;

                    // Popola i campi
                    document.getElementById('urlPubblico').value = data.url_pubblico;
                    document.getElementById('nomeTest').value = data.nome;
                    document.getElementById('descrizioneTest').value = data.descrizione;
                    document.getElementById('numDomande').value = data.num_domande;
                    document.getElementById('punteggioMax').value = data.punteggio_max;
                    document.getElementById('sogliaSucc').value = Math.round(data.punteggio_max * 0.6);
                    document.getElementById('idEsterno').value = data.id_esterno;

                    // Mostra sezione dati
                    datiAutomatici.style.display = 'block';
                    btnSalvaTest.disabled = false;

                    // Notifica successo
                    const successMsg = document.createElement('div');
                    successMsg.className = 'alert alert-success alert-dismissible fade show mt-3';
                    successMsg.innerHTML = `
                        <i class="bi bi-check-circle"></i> Dati caricati con successo!
                        <strong>${data.num_domande}</strong> domande trovate.
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    `;
                    loadingSpinner.parentElement.appendChild(successMsg);
                    setTimeout(() => successMsg.remove(), 3000);

                } else {
                    throw new Error(result.error || 'Errore durante il caricamento');
                }

            } catch (error) {
                alert('Errore: ' + error.message);
                console.error(error);
            } finally {
                loadingSpinner.style.display = 'none';
                btnCaricaDati.disabled = false;
            }
        });

        // Reset form quando si chiude il modal
        document.getElementById('addTestModal').addEventListener('hidden.bs.modal', function() {
            document.getElementById('addTestForm').reset();
            urlGestioneContainer.style.display = 'none';
            urlManualiContainer.style.display = 'none';
            classroomContainer.style.display = 'none';
            githubCatalogContainer.style.display = 'none';
            datiAutomatici.style.display = 'none';
            loadingSpinner.style.display = 'none';
            btnSalvaTest.disabled = true;
            clearGithubHiddenFields();
        });

        // Prefill da querystring (es. creazione da export Kahoot/Socrative)
        (function prefillFromQuery() {
            const params = new URLSearchParams(window.location.search);
            if (!params.get('new_test_modal')) return;

            const platform = params.get('platform') || '';
            const prefillName = params.get('prefill_name') || '';
            const prefillDescr = params.get('prefill_descr') || '';
            const prefillNum = params.get('prefill_num_domande') || '';
            const prefillMax = params.get('prefill_punteggio_max') || '';

            // Apri modal
            const modalEl = document.getElementById('addTestModal');
            const modal = new bootstrap.Modal(modalEl);

            // Selettori
            piattaformaSelect.value = platform;
            piattaformaSelect.dispatchEvent(new Event('change'));

            if (platform === 'google-forms') {
                document.getElementById('nomeTest').value = prefillName;
                document.getElementById('descrizioneTest').value = prefillDescr;
                if (prefillNum) document.getElementById('numDomande').value = prefillNum;
                if (prefillMax) document.getElementById('punteggioMax').value = prefillMax;
                document.getElementById('sogliaSucc').value = prefillMax ? Math.round(prefillMax * 0.6) : 60;
                btnSalvaTest.disabled = false;
            } else {
                // Manuale (kahoot/socrative/altro)
                document.getElementById('nomeTestManuale').value = prefillName;
                document.getElementById('descrizioneTestManuale').value = prefillDescr;
                if (prefillNum) document.getElementById('numDomandeManuale').value = prefillNum;
                if (prefillMax) document.getElementById('punteggioMaxManuale').value = prefillMax;
                btnSalvaTest.disabled = false;
            }

            modal.show();
        })();
    });
    </script>

    <!-- Modale Modifica Test -->
    <div class="modal fade" id="editTestModal" tabindex="-1" aria-labelledby="editTestModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-primary text-white">
                    <h5 class="modal-title" id="editTestModalLabel">
                        <i class="bi bi-pencil"></i> Modifica Test
                    </h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <form method="POST" id="editTestForm">
                    <input type="hidden" name="action" value="update_test">
                    <input type="hidden" name="test_id" id="edit_test_id">

                    <div class="modal-body">
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <strong>Nota:</strong> Puoi modificare solo alcune caratteristiche del test per mantenere la stabilità del sistema.
                            La piattaforma e gli URL non possono essere modificati dopo la creazione.
                        </div>

                        <div class="mb-3">
                            <label for="edit_nome" class="form-label">Nome Test *</label>
                            <input type="text" class="form-control" id="edit_nome" name="nome" required>
                        </div>

                        <div class="mb-3">
                            <label for="edit_descrizione" class="form-label">Descrizione</label>
                            <textarea class="form-control" id="edit_descrizione" name="descrizione" rows="3"></textarea>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label for="edit_num_domande" class="form-label">Numero Domande</label>
                                <input type="number" class="form-control" id="edit_num_domande" name="num_domande" min="0">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="edit_durata_minuti" class="form-label">Durata (minuti)</label>
                                <input type="number" class="form-control" id="edit_durata_minuti" name="durata_minuti" min="0">
                            </div>

                            <div class="col-md-4 mb-3">
                                <label for="edit_punteggio_max" class="form-label">Punteggio Max</label>
                                <input type="number" class="form-control" id="edit_punteggio_max" name="punteggio_max" min="0" step="0.1">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_soglia_sufficienza" class="form-label">Soglia Sufficienza (%)</label>
                                <input type="number" class="form-control" id="edit_soglia_sufficienza" name="soglia_sufficienza" min="0" max="100">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label for="edit_data_somministrazione" class="form-label">Data Somministrazione</label>
                                <input type="date" class="form-control" id="edit_data_somministrazione" name="data_somministrazione">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="edit_ora_consegna" class="form-label">Ora Consegna</label>
                                <input type="time" class="form-control" id="edit_ora_consegna" name="ora_consegna">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Piattaforma (non modificabile)</label>
                                <input type="text" class="form-control" id="edit_piattaforma_display" disabled>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="edit_note" class="form-label">Note</label>
                            <textarea class="form-control" id="edit_note" name="note" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Annulla
                        </button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-check-circle"></i> Salva Modifiche
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        // Funzione per caricare i dati del test nel form di modifica
        function loadTestForEdit(testData) {
            // Parse JSON se è una stringa
            const test = typeof testData === 'string' ? JSON.parse(testData) : testData;

            // Popola i campi del form
            document.getElementById('edit_test_id').value = test.id_test || '';
            document.getElementById('edit_nome').value = test.nome || '';
            document.getElementById('edit_descrizione').value = test.descrizione || '';
            document.getElementById('edit_num_domande').value = test.num_domande || '';
            document.getElementById('edit_durata_minuti').value = test.durata_minuti || '';
            document.getElementById('edit_punteggio_max').value = test.punteggio_max || '';
            document.getElementById('edit_soglia_sufficienza').value = test.soglia_sufficienza || '';
            document.getElementById('edit_data_somministrazione').value = test.data_somministrazione || '';
            document.getElementById('edit_ora_consegna').value = test.ora_consegna || '';
            document.getElementById('edit_note').value = test.note || '';
            document.getElementById('edit_piattaforma_display').value = (test.piattaforma || '').toUpperCase();
        }
    </script>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Evidenzia la card se c'è un anchor #test-<id>
        (function () {
            const hash = window.location.hash;
            if (hash && hash.startsWith('#test-')) {
                const el = document.querySelector(hash);
                if (el) {
                    el.scrollIntoView({behavior: 'smooth', block: 'center'});
                    el.classList.add('highlight-flash');
                    setTimeout(() => el.classList.remove('highlight-flash'), 2000);
                }
            }
        })();
    </script>
</body>
</html>
