<?php

session_start();

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Core\ClasseVivaTokenGuard;
use App\Core\TeachingGroupCatalogService;
use App\Core\UdaGroupRepository;
use App\Integration\ClasseVivaAPI;
use App\Utils\AcademicPeriodHelper;
use App\Utils\QuestionEditorHelper;
use App\Utils\UdaMetadataHelper;
use App\Utils\UdaIntegrationResolver;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$mappingService = new ProviderNeutralMappingService(
    $dbAdapter,
    $userId
);
$teachingGroupCatalog = new TeachingGroupCatalogService($dbAdapter, $userId);
$teachingGroupCatalogRows = $teachingGroupCatalog->listForWizard(true);
$teachingGroupCatalogIndex = [];
foreach ($teachingGroupCatalogRows as $catalogRow) {
    $catalogId = trim((string)($catalogRow['id_gruppo'] ?? ''));
    if ($catalogId !== '') {
        $teachingGroupCatalogIndex[$catalogId] = $catalogRow;
    }
}
$pickerApiKey = $_ENV['GOOGLE_API_KEY'] ?? '';
$pickerClientId = $config['google']['oauth_client_id'] ?? '';
$driveRootId = trim($config['google']['drive']['root_folder_id'] ?? '');
$driveRootConfigured = $driveRootId !== '';
$allObiettivi = $dbAdapter->findAll('OBIETTIVI');
$classroomMappings = $mappingService->listGoogleClassroomMappings();
$githubMappings = $mappingService->listGithubClassroomMappings();
$classroomMappingIndex = UdaIntegrationResolver::indexClassroomMappings($classroomMappings);
$githubMappingIndex = UdaIntegrationResolver::indexGithubMappings($githubMappings);

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

// ID UDA temporaneo per import domande durante il wizard (riciclato per sessione corrente)
$tempUdaId = $_SESSION['uda_create_temp_id'] ?? ('UDA_TMP_' . session_id());
$_SESSION['uda_create_temp_id'] = $tempUdaId;
$domandeTempCount = count($dbAdapter->findWhere('DOMANDE_INTERROGAZIONE', ['id_uda' => $tempUdaId]));

$cvClassSubjects = [];
// I suggerimenti ClasseViva sono opzionali: invoca l'API solo quando il token
// di sessione è realmente pronto. In assenza di token il wizard resta
// utilizzabile con gruppi provider-neutral e periodi configurati localmente.
$classevivaState = ClasseVivaTokenGuard::getTokenState($config);
if ($classevivaState['ready']) {
    try {
        $cvApi = new ClasseVivaAPI($config);
        $classes = $cvApi->getClassesWithTeacherSubjects();
        if (is_array($classes)) {
            foreach ($classes as $class) {
                if (!is_array($class)) {
                    continue;
                }
                $classId = $class['id'] ?? $class['classId'] ?? '';
                $className = $class['name'] ?? $class['className'] ?? '';
                $subjects = $class['subjects'] ?? [];
                if (!is_array($subjects)) {
                    continue;
                }
                foreach ($subjects as $sub) {
                    if (!is_array($sub)) {
                        continue;
                    }
                    $cvClassSubjects[] = [
                        'classId' => $classId,
                        'className' => $className,
                        'subjectId' => $sub['id'] ?? $sub['subjectId'] ?? '',
                        'subjectName' => $sub['name'] ?? $sub['subjectName'] ?? $sub['subjectDesc'] ?? ''
                    ];
                }
            }
        }
    } catch (\Throwable $e) {
        // I suggerimenti sono opzionali: il wizard resta utilizzabile.
    }
}

$error_message = null;
$success_message = null;
$integration_message = isset($_GET['integration_updated']) ? 'Mappature aggiornate. Le associazioni disponibili sono state preselezionate.' : null;

// Le date restano memorizzate per compatibilità, ma vengono calcolate dal periodo ClasseViva.
$currentYear = (int)date('Y');
$currentMonth = (int)date('n');
$startYear = $currentMonth >= 8 ? $currentYear : ($currentYear - 1);
$currentAcademicYear = $startYear . '-' . substr((string)($startYear + 1), -2);
$nextAcademicYear = ($startYear + 1) . '-' . substr((string)($startYear + 2), -2);
$classevivaPeriodConfig = $config['classeviva'] ?? [];
$periodOptionsByYear = [];
foreach ([$currentAcademicYear, $nextAcademicYear] as $academicYear) {
    try {
        $periodOptionsByYear[$academicYear] = AcademicPeriodHelper::options($academicYear, $classevivaPeriodConfig);
    } catch (\Throwable $e) {
        $periodOptionsByYear[$academicYear] = [];
    }
}

// Gestione POST per la creazione della UDA
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_uda') {

    try {
        // 1. Dati base UDA (Step 1)
        $udaId = 'UDA_' . date('Ymd') . '_' . uniqid();

        $academicYear = (string)($_POST['anno_scolastico'] ?? $currentAcademicYear);
        $periodValue = trim((string)($_POST['periodo_scolastico'] ?? ''));
        $periodOptions = $periodOptionsByYear[$academicYear] ?? [];
        if ($periodOptions === []) {
            try {
                $periodOptions = AcademicPeriodHelper::options($academicYear, $classevivaPeriodConfig);
            } catch (\Throwable $e) {
                $periodOptions = [];
            }
        }
        $selectedPeriod = null;
        foreach ($periodOptions as $period) {
            if ($period['value'] === $periodValue) {
                $selectedPeriod = $period;
                break;
            }
        }
        $postedGroupIds = is_array($_POST['id_gruppo'] ?? null) ? $_POST['id_gruppo'] : [];
        $selectedGroupRows = [];
        $selectedGroupIds = [];
        foreach ($postedGroupIds as $rawGroupId) {
            if (!is_scalar($rawGroupId)) {
                continue;
            }
            $groupId = trim((string)$rawGroupId);
            if ($groupId === '' || isset($selectedGroupIds[$groupId])) {
                continue;
            }
            // Il catalogo è costruito con listForWizard(true), quindi contiene
            // esclusivamente gruppi attivi e già appartenenti all'utente.
            $groupRow = $teachingGroupCatalogIndex[$groupId] ?? null;
            if ($groupRow === null || (string)($groupRow['stato'] ?? '') !== 'attivo') {
                throw new RuntimeException('Gruppo didattico non disponibile.');
            }
            $selectedGroupIds[$groupId] = true;
            $selectedGroupRows[] = $groupRow;
        }
        $selectedIntegration = false;
        foreach ($selectedGroupRows as $groupRow) {
            foreach (['google_classroom', 'github_classroom'] as $provider) {
                $providerLink = ($groupRow['providers'] ?? [])[$provider] ?? null;
                if (is_array($providerLink) && trim((string)($providerLink['external_context_id'] ?? '')) !== '') {
                    $selectedIntegration = true;
                    break 2;
                }
            }
        }
        $manualClassTarget = trim((string)($_POST['classi_target'] ?? ''));
        $useAssignedClassTarget = filter_var(
            $_POST['classi_target_usa_assegnazioni'] ?? false,
            FILTER_VALIDATE_BOOLEAN
        );
        $disciplineValue = trim((string)($_POST['disciplina'] ?? ''));
        if ($disciplineValue === '') {
            $subjectNames = array_map(
                static fn(array $group): string => trim((string)($group['nome_materia'] ?? '')),
                $selectedGroupRows
            );
            $disciplineValue = UdaMetadataHelper::disciplineFromSubjectNames($subjectNames) ?? '';
        }

        $udaData = [
            'id_uda' => $udaId,
            'titolo' => $_POST['titolo'] ?? '',
            'argomento' => $_POST['argomento'] ?? '',
            'disciplina' => $disciplineValue,
            'metodologia' => $_POST['metodologia'] ?? '',
            'anno_scolastico' => $_POST['anno_scolastico'] ?? '',
            'data_inizio' => $selectedPeriod['start'] ?? ($_POST['data_inizio'] ?? null),
            'data_fine' => $selectedPeriod['end'] ?? ($_POST['data_fine'] ?? null),
            'descrizione' => $_POST['descrizione'] ?? '',
            'note' => $_POST['note'] ?? '',
            'classi_target' => $manualClassTarget,
            'stato' => UdaMetadataHelper::statusAfterIntegrationSelection($selectedIntegration, (string)($_POST['stato'] ?? 'bozza'))
        ];

        // Validazione campi obbligatori
        if (empty($udaData['titolo']) || empty($udaData['argomento'])) {
            throw new Exception("I campi Titolo e Argomento sono obbligatori.");
        }

        // Inserisci UDA nel database
        $dbAdapter->insertRow('UDA_ANAGRAFICA', $udaData);

        // Se ci sono domande importate sul temp ID, riassegnale al nuovo ID
        $tempId = $_SESSION['uda_create_temp_id'] ?? null;
        if ($tempId) {
            $domandeTemp = $dbAdapter->findWhere('DOMANDE_INTERROGAZIONE', ['id_uda' => $tempId]);
            foreach ($domandeTemp as $d) {
                $dbAdapter->updateRow('DOMANDE_INTERROGAZIONE', 'id_domanda', $d['id_domanda'], array_merge($d, ['id_uda' => $udaId]));
            }
            // rigenera temp id per la prossima creazione
            $_SESSION['uda_create_temp_id'] = 'UDA_TMP_' . session_id() . '_' . uniqid();
        }

        // 2. Materiali (Step 2 - opzionale)
        if (isset($_POST['materiali_nome']) && is_array($_POST['materiali_nome'])) {
            foreach ($_POST['materiali_nome'] as $index => $nome) {
                if (!empty($nome)) {
                    $materialId = 'MAT_' . uniqid();
                    $dbAdapter->insertRow('MATERIALI', [
                        'id_materiale' => $materialId,
                        'id_uda' => $udaId,
                        'nome' => $nome,
                        'tipo_materiale' => $_POST['materiali_tipo'][$index] ?? 'documento',
                        'url_drive' => $_POST['materiali_url'][$index] ?? '',
                        'file_id_drive' => $_POST['materiali_file_id'][$index] ?? '',
                        'descrizione' => $_POST['materiali_descrizione'][$index] ?? '',
                        'data_creazione' => date('Y-m-d H:i:s')
                    ]);
                }
            }
        }

        // 3. Obiettivi (Step 3 - opzionale)
        if (isset($_POST['obiettivi_descrizione']) && is_array($_POST['obiettivi_descrizione'])) {
            foreach ($_POST['obiettivi_descrizione'] as $index => $descrizione) {
                if (!empty($descrizione)) {
                    $obiettivoId = 'OBJ_' . uniqid();
                    $dbAdapter->insertRow('OBIETTIVI', [
                        'id_obiettivo' => $obiettivoId,
                        'id_uda' => $udaId,
                        'tipo_obiettivo' => $_POST['obiettivi_tipo'][$index] ?? 'disciplinare',
                        'codice' => $_POST['obiettivi_codice'][$index] ?? '',
                        'descrizione' => $descrizione,
                        'competenza' => $_POST['obiettivi_competenza'][$index] ?? '',
                        'livello_tassonomia' => intval($_POST['obiettivi_livello'][$index] ?? 1)
                    ]);
                }
            }
        }

        // 4. Test (Step 4 - opzionale)
        if (isset($_POST['test_nome']) && is_array($_POST['test_nome'])) {
            foreach ($_POST['test_nome'] as $index => $nome) {
                if (!empty($nome)) {
                    $testId = 'TEST_' . uniqid();
                    $platform = $_POST['test_piattaforma'][$index] ?? 'google-forms';
                    $urlStud = $_POST['test_url_studenti'][$index] ?? ($_POST['test_url'][$index] ?? '');
                    $urlDoc = $_POST['test_url_docente'][$index] ?? '';
                    $urlMain = $_POST['test_url'][$index] ?? $urlStud;
                    $formId = '';
                    if ($platform === 'google-forms') {
                        $formId = extractGoogleFormIdFromValue((string)$urlDoc)
                            ?? extractGoogleFormIdFromValue((string)$urlStud)
                            ?? extractGoogleFormIdFromValue((string)$urlMain)
                            ?? '';
                    }
                    $dbAdapter->insertRow('TEST', [
                        'id_test' => $testId,
                        'id_uda' => $udaId,
                        'nome' => $nome,
                        'descrizione' => $_POST['test_descrizione'][$index] ?? '',
                        'tipo_test' => $_POST['test_tipo'][$index] ?? 'finale',
                        'piattaforma' => $platform,
                        'url' => $urlStud,
                        'url_studenti' => $urlStud,
                        'url_docente' => $urlDoc,
                        'id_esterno' => $formId,
                        'num_domande' => intval($_POST['test_domande'][$index] ?? 0),
                        'durata_minuti' => intval($_POST['test_durata'][$index] ?? 0),
                        'punteggio_max' => floatval($_POST['test_punti'][$index] ?? 100),
                        'pubblicato' => 'NO',
                        'classroom_course_id' => $_POST['test_classroom_course_id'][$index] ?? '',
                        'classroom_assignment_id' => $_POST['test_classroom_assignment_id'][$index] ?? '',
                        'classroom_topic_id' => $_POST['test_classroom_topic_id'][$index] ?? '',
                        'github_classroom_id' => $_POST['test_github_classroom_id'][$index] ?? '',
                        'github_assignment_id' => $_POST['test_github_assignment_id'][$index] ?? '',
                        'url_assignment_student' => $_POST['test_url_assignment_student'][$index] ?? '',
                        'url_assignment_teacher' => $_POST['test_url_assignment_teacher'][$index] ?? ''
                    ]);
                }
            }
        }

        // 5. Domande (Step 5 - opzionale)
        if (isset($_POST['domanda_testo']) && is_array($_POST['domanda_testo'])) {
            foreach ($_POST['domanda_testo'] as $index => $testo) {
                if (!empty($testo)) {
                    $domandaId = 'DOM_' . uniqid();
                    $questionPayload = QuestionEditorHelper::normalizePayload([
                        'argomento' => $_POST['domanda_argomento'][$index] ?? '',
                        'domanda' => $testo,
                        'tipo_domanda' => $_POST['domanda_tipo'][$index] ?? 'aperta',
                        'risposta_attesa' => $_POST['domanda_suggerimenti'][$index] ?? '',
                        'risposte' => $_POST['domanda_risposte'][$index] ?? '',
                        'parole_chiave' => $_POST['domanda_parole'][$index] ?? '',
                        'difficolta' => $_POST['domanda_livello'][$index] ?? 3,
                    ]);
                    unset($questionPayload['opzioni']);
                    $dbAdapter->insertRow('DOMANDE_INTERROGAZIONE', array_merge([
                        'id_domanda' => $domandaId,
                        'id_uda' => $udaId,
                    ], $questionPayload));
                }
            }
        }

        // 6. Gruppi didattici (opzionale): il nuovo wizard scrive solo UDA_GRUPPI.
        $udaGroups = new UdaGroupRepository($dbAdapter, $userId);
        foreach (array_keys($selectedGroupIds) as $groupId) {
            $udaGroups->assign($udaId, $groupId);
        }
        if ($useAssignedClassTarget && $selectedGroupRows !== []) {
            $assignedClassTarget = UdaMetadataHelper::classTargetFromAssignments(
                array_map(static function (array $group): array {
                    return [
                        'nome_classe' => (string)($group['nome_classe'] ?? $group['nome_gruppo'] ?? ''),
                    ];
                }, $selectedGroupRows)
            );
            if ($assignedClassTarget !== '') {
                $dbAdapter->updateRow('UDA_ANAGRAFICA', 'id_uda', $udaId, [
                    'classi_target' => $assignedClassTarget
                ]);
            }
        }

        // Redirect alla pagina di visualizzazione della nuova UDA
        header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=create_success");
        exit;

    } catch (Exception $e) {
        $error_message = "Errore durante la creazione della UDA: " . $e->getMessage();
        error_log("Errore creazione UDA: " . $e->getMessage());
    }
}

// Carica classi disponibili da ClasseViva
$allClasses = [];
try {
    $classiviva = $dbAdapter->findAll('CLASSI_CLASSEVIVA');
    $allClasses = $classiviva;
} catch (Exception $e) {
    error_log("Errore caricamento classi: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Crea Nuova UDA - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <link rel="stylesheet" href="assets/css/question-card.css">
    <style>
        .step {
            display: none;
        }
        .step.active {
            display: block;
        }
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
            position: relative;
            overflow-x: auto;
            padding-bottom: 1rem;
        }
        .step-indicator::before {
            content: '';
            position: absolute;
            top: 20px;
            left: 8%;
            right: 8%;
            height: 2px;
            background: #dee2e6;
            z-index: -1;
        }
        .step-indicator .step-item {
            flex: 0 0 auto;
            min-width: 120px;
            text-align: center;
            padding: 0.5rem;
            position: relative;
            cursor: pointer;
        }
        .step-indicator .step-item .step-number {
            display: inline-block;
            width: 40px;
            height: 40px;
            line-height: 40px;
            border-radius: 50%;
            background: #e9ecef;
            color: #6c757d;
            font-weight: bold;
            margin-bottom: 0.5rem;
        }
        .step-indicator .step-item.completed .step-number {
            background: #198754;
            color: white;
        }
        .step-indicator .step-item.active .step-number {
            background: #0d6efd;
            color: white;
        }
        .step-indicator .step-item.skipped .step-number {
            background: #ffc107;
            color: #000;
        }
        .step-indicator .step-item small {
            display: block;
            font-size: 0.75rem;
        }
        .dynamic-item {
            background: #f8f9fa;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
        }
        .btn-skip {
            background-color: #ffc107;
            color: #000;
            border-color: #ffc107;
        }
        .btn-skip:hover {
            background-color: #ffca2c;
            border-color: #ffc720;
            color: #000;
        }
        /* Evidenziazione tenue per i form già pubblicati in Classroom (step 6 test). */
        .test-forms-list .list-group-item-info {
            background-color: rgba(13, 110, 253, 0.07);
            color: inherit;
        }
    </style>
</head>
<body class="bg-light">
    <?php
    $pageTitle = '<i class="bi bi-magic"></i> Creazione Guidata UDA';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container">
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($integration_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($integration_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <div class="card shadow">
            <div class="card-body">
                <!-- Step Indicator -->
                <div class="step-indicator">
                    <div class="step-item active" data-step="1" onclick="goToStep(1)">
                        <div class="step-number">1</div>
                        <small>Info Generali</small>
                    </div>
                    <div class="step-item" data-step="2" onclick="goToStep(2)">
                        <div class="step-number">2</div>
                        <small>Classi e integrazioni</small>
                    </div>
                    <div class="step-item" data-step="3" onclick="goToStep(3)">
                        <div class="step-number">3</div>
                        <small>Materiali</small>
                    </div>
                    <div class="step-item" data-step="4" onclick="goToStep(4)">
                        <div class="step-number">4</div>
                        <small>Obiettivi</small>
                    </div>
                    <div class="step-item" data-step="5" onclick="goToStep(5)">
                        <div class="step-number">5</div>
                        <small>Domande</small>
                    </div>
                    <div class="step-item" data-step="6" onclick="goToStep(6)">
                        <div class="step-number">6</div>
                        <small>Test</small>
                    </div>
                    <div class="step-item" data-step="7" onclick="goToStep(7)">
                        <div class="step-number">7</div>
                        <small>Riepilogo</small>
                    </div>
                </div>

                <form method="POST" id="udaForm">
                    <input type="hidden" name="action" value="create_uda">
                    <input type="hidden" name="data_inizio" id="data_inizio">
                    <input type="hidden" name="data_fine" id="data_fine">

                    <!-- Step 1: Informazioni Generali (OBBLIGATORIO) -->
                    <div class="step active" data-step="1">
                        <h3 class="mb-4"><i class="bi bi-info-circle text-primary"></i> Informazioni Generali</h3>
                        <p class="text-muted">Inserisci le informazioni di base dell'UDA. I campi con * sono obbligatori.</p>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Titolo UDA *</label>
                                <input type="text" class="form-control" name="titolo" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Argomento *</label>
                                <input type="text" class="form-control" name="argomento" required>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Disciplina</label>
                                <input list="disciplineList" class="form-control" name="disciplina" placeholder="Seleziona o digita...">
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
                                    <?php
                                    if (!empty($cvClassSubjects)) {
                                        $uniqueSubs = [];
                                        foreach ($cvClassSubjects as $cs) {
                                            $name = trim($cs['subjectName']);
                                            if ($name !== '' && !isset($uniqueSubs[$name])) {
                                                $uniqueSubs[$name] = true;
                                                echo '<option value="' . htmlspecialchars($name) . '"></option>';
                                            }
                                        }
                                    }
                                    ?>
                                </datalist>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Metodologia</label>
                                <input list="metodologiaList" class="form-control" name="metodologia" placeholder="Seleziona o digita...">
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
                            <div class="col-md-4 mb-3">
                                <label class="form-label">Anno Scolastico</label>
                                <select class="form-select" name="anno_scolastico">
                                    <option value="<?= htmlspecialchars($currentAcademicYear) ?>" selected><?= htmlspecialchars($currentAcademicYear) ?></option>
                                    <option value="<?= htmlspecialchars($nextAcademicYear) ?>"><?= htmlspecialchars($nextAcademicYear) ?></option>
                                </select>
                                <small class="form-text text-muted">Anno corrente e successivo.</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Periodo scolastico</label>
                            <select class="form-select" name="periodo_scolastico" id="periodo_scolastico" onchange="applyAcademicPeriod(this)">
                                <option value="">Seleziona un periodo (facoltativo)</option>
                                <?php foreach ($periodOptionsByYear[$currentAcademicYear] ?? [] as $period): ?>
                                    <option value="<?= htmlspecialchars($period['value']) ?>"
                                            data-start="<?= htmlspecialchars($period['start']) ?>"
                                            data-end="<?= htmlspecialchars($period['end']) ?>">
                                        <?= htmlspecialchars($period['label']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">Le date vengono calcolate automaticamente in base ai periodi configurati in ClasseViva. <a href="user_integrations.php#classeviva-section">Imposta i periodi ClasseViva</a></small>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Descrizione</label>
                            <textarea class="form-control" name="descrizione" rows="3" placeholder="Descrizione generale dell'UDA"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Note / Prerequisiti</label>
                            <textarea class="form-control" name="note" rows="2" placeholder="Note aggiuntive o prerequisiti richiesti per questa UDA"></textarea>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Classi Target / Destinatari</label>
                            <textarea class="form-control" name="classi_target" rows="2" placeholder="Es. 4 informatica, 5 informatica"></textarea>
                            <div class="form-text">Puoi indicare liberamente i destinatari. Se assegni classi nello step 2, potrai scegliere se usare il valore calcolato.</div>
                            <div class="alert alert-info py-2 mt-2 d-none" data-class-target-suggestion-panel>
                                <div class="form-check">
                                    <input class="form-check-input" type="checkbox" name="classi_target_usa_assegnazioni" value="1" id="classiTargetUseAssignments">
                                    <label class="form-check-label" for="classiTargetUseAssignments">
                                        Sostituire con <strong data-class-target-suggestion></strong>
                                    </label>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Stato</label>
                            <select class="form-select" name="stato">
                                <option value="bozza" selected>Bozza</option>
                                <option value="attiva">Attiva</option>
                                <option value="completata">Completata</option>
                                <option value="archiviata">Archiviata</option>
                            </select>
                            <small class="form-text text-muted">La presenza di una mappatura Google Classroom o GitHub porta automaticamente l'UDA in "Attiva".</small>
                        </div>

                        <div class="d-flex justify-content-end">
                            <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(1)">
                                Avanti <i class="bi bi-arrow-right"></i>
                            </button>
                        </div>
                    </div>

                    <!-- Step 2: Classi Assegnate (OPZIONALE) -->
                    <div class="step" data-step="2">
                        <h3 class="mb-4"><i class="bi bi-diagram-3" style="color: #fd7e14;"></i> Classi e integrazioni</h3>
                        <p class="text-muted">Assegna questa UDA a uno o più gruppi didattici (l'assegnazione classe-materia interna) e verifica le integrazioni disponibili.</p>

                        <div class="alert alert-info small"><i class="bi bi-info-circle"></i> L'assegnazione classe-materia è facoltativa. Ogni gruppo può essere mappato a ClasseViva, Google Classroom o GitHub Classroom; le mappature già presenti vengono preselezionate.</div>

                        <div id="teaching-group-selector" class="mb-3">
                            <!-- Template classe verrà inserito qui -->
                        </div>

                        <label class="form-label" for="teaching-group-select">Gruppi didattici (assegnazione classe-materia)</label>
                        <select id="teaching-group-select" name="id_gruppo[]" class="form-select teaching-group-select" multiple size="6" aria-describedby="teaching-group-help">
                            <?php foreach ($teachingGroupCatalogRows as $group):
                                $groupId = (string)($group['id_gruppo'] ?? '');
                                $groupName = trim((string)($group['nome_gruppo'] ?? '')) ?: $groupId;
                                $className = trim((string)($group['nome_classe'] ?? ''));
                                $subjectName = trim((string)($group['nome_materia'] ?? ''));
                                $yearName = trim((string)($group['anno_scolastico'] ?? ''));
                                $context = trim(implode(' · ', array_filter([$className, $subjectName, $yearName])));
                            ?>
                                <option value="<?= htmlspecialchars($groupId) ?>" data-group-id="<?= htmlspecialchars($groupId) ?>">
                                    <?= htmlspecialchars($groupName . ($context !== '' ? ' — ' . $context : '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div id="teaching-group-help" class="form-text">Usa Ctrl/Cmd per selezionare più gruppi. Le integrazioni configurate vengono riepilogate sotto.</div>
                        <div id="teaching-group-provider-summary" class="mb-3" aria-live="polite"></div>

                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <a class="btn btn-outline-primary" href="teaching_groups.php?return_to=uda_create.php#2">
                                <i class="bi bi-pencil-square"></i> Gestisci gruppi didattici
                            </a>
                            <?php if ($teachingGroupCatalogRows === []): ?>
                                <span class="align-self-center text-muted small">Nessun gruppo disponibile: creane uno per abilitarlo qui.</span>
                            <?php endif; ?>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(2)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <div>
                                <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(2)">
                                    Avanti <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 3: Materiali (OPZIONALE) -->
                    <div class="step" data-step="3">
                        <h3 class="mb-4"><i class="bi bi-folder-fill text-info"></i> Materiali Didattici</h3>
                        <div class="mb-3" data-wizard-integration-summary></div>
                        <p class="text-muted">Aggiungi materiali didattici (documenti, link, video, ecc.) - opzionale.</p>

                        <div class="border rounded p-3 mb-3 bg-white">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0"><i class="bi bi-google"></i> Risorse Google Classroom mappate</h6>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="loadWizardClassroomCourses(true)">
                                    <i class="bi bi-arrow-clockwise"></i> Ricarica
                                </button>
                            </div>
                            <div id="wizardClassroomImportMsg" class="alert d-none" role="alert"></div>
                            <div class="row g-2 mb-2">
                                <div class="col-md-6">
                                    <label for="wizardClassroomCourseSelect" class="form-label small">Corso</label>
                                    <select id="wizardClassroomCourseSelect" class="form-select" onchange="loadWizardClassroomResources()">
                                        <option value="">Seleziona un corso...</option>
                                    </select>
                                    <div id="wizardClassroomCourseHelp" class="form-text"></div>
                                </div>
                                <div class="col-md-6">
                                    <label for="wizardClassroomTopicSelect" class="form-label small">Argomento</label>
                                    <select id="wizardClassroomTopicSelect" class="form-select" onchange="renderWizardClassroomResources()" disabled>
                                        <option value="">Seleziona argomento...</option>
                                    </select>
                                </div>
                            </div>
                            <div id="wizardClassroomLoading" class="text-center text-muted py-2 d-none">
                                <div class="spinner-border spinner-border-sm me-2" role="status"></div> Caricamento risorse Classroom...
                            </div>
                            <div class="table-responsive border rounded" style="max-height: 300px;">
                                <table class="table table-sm align-middle mb-0">
                                    <thead class="table-light"><tr><th style="width: 36px;"><input type="checkbox" id="wizardClassroomSelectAll" class="form-check-input" onchange="toggleWizardClassroomSelectAll(this)" aria-label="Seleziona tutte le risorse"></th><th>Risorsa</th><th>Destinazione</th><th>Tipo</th></tr></thead>
                                    <tbody id="wizardClassroomResourcesBody"></tbody>
                                </table>
                            </div>
                            <div id="wizardClassroomResourcesEmpty" class="alert alert-light border mt-2 mb-0">Le risorse vengono caricate automaticamente all'apertura dello step.</div>
                            <button type="button" class="btn btn-sm btn-primary mt-2" onclick="applyWizardClassroomImport()">
                                <i class="bi bi-download"></i> Importa selezionati
                            </button>
                        </div>

                        <div id="materiali-container">
                            <!-- Template materiali verrà inserito qui -->
                        </div>

                        <button type="button" class="btn btn-outline-info btn-sm mb-2" onclick="addMaterial()">
                            <i class="bi bi-plus-circle"></i> Aggiungi Materiale
                        </button>
                        <div class="small text-muted mb-2">
                            <i class="bi bi-gear"></i>
                            Imposta la cartella Drive in
                            <a href="/uda-system/public/user_integrations.php#google-section">Integrazioni Google</a>.
                        </div>
                        <?php if (!$driveRootConfigured): ?>
                            <div class="small text-warning mb-3">
                                <i class="bi bi-exclamation-triangle"></i>
                                ID cartella Drive non configurato: i file caricati verranno salvati nella root di Drive.
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(3)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <div>
                                <button type="button" class="btn btn-skip btn-lg me-2" onclick="skipStep(3)">
                                    Salta <i class="bi bi-skip-forward"></i>
                                </button>
                                <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(3)">
                                    Avanti <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 4: Obiettivi (OPZIONALE) -->
                    <div class="step" data-step="4">
                        <h3 class="mb-4"><i class="bi bi-bullseye text-success"></i> Obiettivi Didattici e Disciplinari</h3>
                        <div class="mb-3" data-wizard-integration-summary></div>
                        <p class="text-muted">Definisci gli obiettivi dell'UDA (opzionale).</p>

                        <div id="obiettivi-container">
                            <!-- Template obiettivi verrà inserito qui -->
                        </div>

                        <button type="button" class="btn btn-outline-success btn-sm mb-3" onclick="addObiettivo()">
                            <i class="bi bi-plus-circle"></i> Aggiungi Obiettivo
                        </button>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(4)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <div>
                                <button type="button" class="btn btn-skip btn-lg me-2" onclick="skipStep(4)">
                                    Salta <i class="bi bi-skip-forward"></i>
                                </button>
                                <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(4)">
                                    Avanti <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 5: Domande (OPZIONALE) -->
                    <div class="step" data-step="5">
                        <h3 class="mb-4"><i class="bi bi-question-circle" style="color: #d63384;"></i> Domande per Interrogazioni</h3>
                        <div class="mb-3" data-wizard-integration-summary></div>
                        <p class="text-muted">Inserisci domande tipiche per le interrogazioni orali (opzionale).</p>

                        <div id="domande-container">
                            <!-- Template domande verrà inserito qui -->
                        </div>

                        <div class="d-flex flex-wrap gap-2 mb-3">
                            <button type="button" class="btn btn-sm" style="background-color: #d63384; color: white; border-color: #d63384;" onclick="addDomanda()">
                                <i class="bi bi-plus-circle"></i> Aggiungi Domanda
                            </button>
                            <a id="wizard-import-questions-link" class="btn btn-sm btn-outline-primary" href="import_questions.php?id=<?= urlencode($tempUdaId) ?>&wizard=1&return_to=uda_create.php" target="_blank">
                                <i class="bi bi-cloud-upload"></i> Importa Domande (CSV/Excel/JSON)
                            </a>
                        </div>

                        <div id="imported-questions-section" class="mb-3 d-none">
                            <div class="d-flex justify-content-between align-items-center mb-2">
                                <h6 class="mb-0"><i class="bi bi-cloud-check"></i> Domande importate <span id="imported-questions-count" class="badge bg-secondary"></span></h6>
                                <button type="button" class="btn btn-sm btn-link p-0 text-decoration-none" onclick="refreshImportedQuestions()">
                                    <i class="bi bi-arrow-clockwise"></i> Aggiorna
                                </button>
                            </div>
                            <div id="imported-questions-list" class="list-group"></div>
                        </div>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(5)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <div>
                                <button type="button" class="btn btn-skip btn-lg me-2" onclick="skipStep(5)">
                                    Salta <i class="bi bi-skip-forward"></i>
                                </button>
                                <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(5)">
                                    Avanti <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 6: Test (OPZIONALE) -->
                    <div class="step" data-step="6">
                        <h3 class="mb-4"><i class="bi bi-clipboard-check" style="color: #6f42c1;"></i> Test e Valutazioni</h3>
                        <div class="mb-3" data-wizard-integration-summary></div>
                        <p class="text-muted">Collega eventuali test o attività già esistenti (opzionale).</p>
                        <div class="alert alert-info small">
                            <i class="bi bi-info-circle"></i>
                            Qui puoi collegare un test o un'attività già esistente tramite link.
                            Dopo la creazione dell'UDA, dalla sezione <strong>Test e attività</strong>
                            potrai creare Google Forms, importare/esportare contenuti, pubblicare su Classroom
                            e gestire gli assignment GitHub.
                        </div>

                        <div id="test-container">
                            <!-- Template test verrà inserito qui -->
                        </div>

                        <button type="button" class="btn btn-sm mb-3" style="background-color: #6f42c1; color: white; border-color: #6f42c1;" onclick="addTest()">
                            <i class="bi bi-plus-circle"></i> Aggiungi Test
                        </button>

                        <div class="d-flex justify-content-between">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(6)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <div>
                                <button type="button" class="btn btn-skip btn-lg me-2" onclick="skipStep(6)">
                                    Salta <i class="bi bi-skip-forward"></i>
                                </button>
                                <button type="button" class="btn btn-primary btn-lg" onclick="nextStep(6)">
                                    Avanti <i class="bi bi-arrow-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Step 7: Riepilogo e Conferma -->
                    <div class="step" data-step="7">
                        <h3 class="mb-4"><i class="bi bi-check-circle text-success"></i> Riepilogo e Conferma</h3>
                        <p class="text-muted">Verifica i dati inseriti e crea l'UDA.</p>

                        <div id="riepilogo-content">
                            <!-- Il riepilogo verrà generato dinamicamente -->
                        </div>

                        <div class="d-flex justify-content-between mt-4">
                            <button type="button" class="btn btn-secondary btn-lg" onclick="prevStep(7)">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </button>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="bi bi-check-circle"></i> Crea UDA
                            </button>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal condivisa per la costruzione delle domande del wizard -->
    <div class="modal fade" id="wizardQuestionModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="wizardQuestionModalTitle"><i class="bi bi-plus-circle"></i> Aggiungi domanda</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <?php $questionEditorId = 'wizard-question-editor'; include __DIR__ . '/partials/question_editor.php'; ?>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal" data-wizard-question-cancel>Annulla</button>
                    <button type="button" class="btn btn-primary" id="wizardQuestionSave"><i class="bi bi-check-circle"></i> Conferma domanda</button>
                </div>
            </div>
        </div>
    </div>

    <template id="question-card-template">
        <?php
        $questionCardTemplate = true;
        $questionCardActions = 'wizard';
        $questionCardData = [];
        $questionCardLabel = '';
        include __DIR__ . '/partials/question_card.php';
        ?>
    </template>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://apis.google.com/js/api.js"></script>
    <script src="https://accounts.google.com/gsi/client" async defer></script>
    <script src="assets/js/uda-editor-utils.js"></script>
    <script src="assets/js/question-editor.js"></script>
    <script src="assets/js/question-card.js"></script>
    <script src="assets/js/catalog-picker.js"></script>
    <script>
        const academicPeriodOptions = <?= json_encode($periodOptionsByYear, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?>;
        let currentStep = 1;
        const totalSteps = 7;
        let skippedSteps = new Set();

        // Counters for dynamic items
        let materialeCounter = 0;
        let obiettivoCounter = 0;
        let testCounter = 0;
        let domandaCounter = 0;
        let selectedGroupIds = [];

        const cvClassSubjects = <?= json_encode($cvClassSubjects, JSON_UNESCAPED_UNICODE) ?>;
        const classroomMappingIndex = <?= json_encode($classroomMappingIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const githubMappingIndex = <?= json_encode($githubMappingIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const teachingGroupCatalog = <?= json_encode(array_values($teachingGroupCatalogRows), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const teachingGroupCatalogIndex = <?= json_encode($teachingGroupCatalogIndex, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const obiettiviCatalog = <?= json_encode(array_values($allObiettivi), JSON_UNESCAPED_UNICODE) ?>;
        const pickerApiKey = "<?= htmlspecialchars($pickerApiKey) ?>";
        const pickerClientId = "<?= htmlspecialchars($pickerClientId) ?>";
        let pickerInited = false;
        let tokenClient = null;
        let driveAccessToken = null;
        const driveTokenCacheKey = 'uda_drive_token';
        let domandeTempCount = <?= (int)$domandeTempCount ?>;
        const tempUdaId = "<?= htmlspecialchars($tempUdaId) ?>";
        const classroomImportIntegrationUrl = 'user_integrations.php#google-section';
        let wizardClassroomResources = [];
        let wizardClassroomCourses = [];
        let wizardClassroomLoadedSignature = '';

        document.addEventListener('DOMContentLoaded', () => {
            const discipline = document.querySelector('input[name="disciplina"]');
            if (discipline) discipline.addEventListener('input', () => { discipline.dataset.userEdited = '1'; });
            const classTarget = document.querySelector('[name="classi_target"]');
            if (classTarget) {
                classTarget.addEventListener('input', () => {
                    const checkbox = document.querySelector('[name="classi_target_usa_assegnazioni"]');
                    if (checkbox && classTarget.value.trim() !== '') checkbox.checked = false;
                });
            }
            document.querySelector('[name="classi_target_usa_assegnazioni"]')?.addEventListener('change', event => {
                event.target.dataset.userChoice = '1';
            });
            document.getElementById('teaching-group-select')?.addEventListener('change', event => {
                selectedGroupIds = Array.from(event.target.selectedOptions || [])
                    .map(option => String(option.value || '').trim())
                    .filter(Boolean);
                refreshClassTargetSuggestion();
                renderTeachingGroupProviderSummary();
                renderWizardIntegrationSummary();
                syncWizardDiscipline();
                saveWizardDraft();
                updateImportQuestionsLink();
                if (currentStep === 3) {
                    wizardClassroomLoadedSignature = '';
                    loadWizardClassroomCourses(true);
                }
            });
            restoreWizardDraft();
            const hashStep = Number.parseInt(window.location.hash.replace('#', ''), 10);
            if (Number.isInteger(hashStep) && hashStep >= 1 && hashStep <= totalSteps) {
                currentStep = hashStep;
                showStep(currentStep);
            } else {
                showStep(currentStep);
            }
            document.addEventListener('click', event => {
                if (event.target.closest('.integration-config-link')) saveWizardDraft();
            });
            document.getElementById('udaForm')?.addEventListener('submit', () => {
                try { sessionStorage.removeItem('uda_create_draft'); } catch (e) { /* storage non disponibile */ }
            });
        });

        function loadCachedDriveToken() {
            try {
                const raw = localStorage.getItem(driveTokenCacheKey);
                if (!raw) return null;
                const data = JSON.parse(raw);
                if (!data.access_token || !data.expiry) return null;
                // Rinnova se manca meno di 60s alla scadenza
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
            } catch (e) { /* ignore quota issues */ }
            driveAccessToken = token;
        }

        function clearCachedDriveToken() {
            driveAccessToken = null;
            localStorage.removeItem(driveTokenCacheKey);
        }

        function nextStep(step) {
            saveWizardDraft();
            if (step === 1) {
                const titolo = document.querySelector('input[name="titolo"]').value.trim();
                const argomento = document.querySelector('input[name="argomento"]').value.trim();

                if (!titolo || !argomento) {
                    alert('I campi Titolo e Argomento sono obbligatori!');
                    return;
                }
            }
            // Mark step as completed
            markStepCompleted(step);

            currentStep++;
            if (currentStep > totalSteps) {
                currentStep = totalSteps;
            }
            showStep(currentStep);

            // Se arriviamo al riepilogo, generalo
            if (currentStep === 7) {
                refreshTempQuestionsCount().then(generateRiepilogo).catch(generateRiepilogo);
            }
        }

        function prevStep(step) {
            currentStep--;
            if (currentStep < 1) {
                currentStep = 1;
            }
            showStep(currentStep);
        }

        function skipStep(step) {
            skippedSteps.add(step);
            markStepSkipped(step);
            nextStep(step);
        }

        function goToStep(step) {
            // Permetti di tornare agli step precedenti
            if (step <= currentStep) {
                currentStep = step;
                showStep(currentStep);

                if (currentStep === 7) {
                    refreshTempQuestionsCount().then(generateRiepilogo).catch(generateRiepilogo);
                }
            }
        }

        function showStep(stepNumber) {
            document.querySelectorAll('.step').forEach(step => {
                step.classList.remove('active');
            });
            document.querySelector(`.step[data-step="${stepNumber}"]`).classList.add('active');

            document.querySelectorAll('.step-item').forEach(item => {
                item.classList.remove('active');
            });
            document.querySelector(`.step-item[data-step="${stepNumber}"]`).classList.add('active');

            updateWizardHash(stepNumber);
            renderWizardIntegrationSummary();

            if (stepNumber === 3) {
                loadWizardClassroomCourses();
            }

            if (stepNumber === 5) {
                refreshImportedQuestions();
            }

            // Scroll to top
            window.scrollTo(0, 0);
        }

        function updateWizardHash(step) {
            const safeStep = Math.min(totalSteps, Math.max(1, Number(step) || 1));
            const url = `${window.location.pathname}${window.location.search}#${safeStep}`;
            window.history.replaceState(null, '', url);
        }

        window.addEventListener('hashchange', () => {
            const hashStep = Number.parseInt(window.location.hash.replace('#', ''), 10);
            if (!Number.isInteger(hashStep) || hashStep < 1 || hashStep > totalSteps || hashStep === currentStep) return;
            currentStep = hashStep;
            showStep(currentStep);
        });

        function markStepCompleted(step) {
            const stepItem = document.querySelector(`.step-item[data-step="${step}"]`);
            stepItem.classList.add('completed');
            stepItem.classList.remove('active', 'skipped');
            skippedSteps.delete(step);
        }

        function markStepSkipped(step) {
            const stepItem = document.querySelector(`.step-item[data-step="${step}"]`);
            stepItem.classList.add('skipped');
            stepItem.classList.remove('active');
        }

        // Add Material
        function addMaterial() {
            materialeCounter++;
            const container = document.getElementById('materiali-container');
            const item = document.createElement('div');
            item.className = 'dynamic-item';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6>Materiale ${materialeCounter}</h6>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Nome</label>
                        <input type="text" class="form-control" name="materiali_nome[]" placeholder="es. Dispensa PDF">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="materiali_tipo[]">
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
                    <div class="col-md-3 mb-2">
                        <label class="form-label">URL/Link</label>
                        <input type="url" class="form-control" name="materiali_url[]" placeholder="https://...">
                        <div class="d-flex flex-wrap gap-2 mt-1">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="openDrivePicker(this)">
                                <i class="bi bi-cloud-arrow-down"></i> Seleziona da Drive
                            </button>
                            <button type="button" class="btn btn-sm btn-outline-primary material-upload-btn" onclick="triggerMaterialUpload(this)">
                                <i class="bi bi-cloud-upload"></i> Carica su Drive
                            </button>
                        </div>
                        <input type="file" class="d-none material-drive-file" onchange="uploadMaterialFile(this)">
                        <div class="small text-danger mt-1 material-upload-error d-none"></div>
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Descrizione</label>
                        <textarea class="form-control" name="materiali_descrizione[]" rows="2"></textarea>
                    </div>
                    <input type="hidden" name="materiali_file_id[]" value="">
                </div>
            `;
            container.appendChild(item);
            return item;
        }

        function triggerMaterialUpload(button) {
            const wrapper = button.closest('.dynamic-item');
            if (!wrapper) return;
            const input = wrapper.querySelector('.material-drive-file');
            if (input) input.click();
        }

        function setMaterialUploadError(wrapper, message) {
            if (!wrapper) return;
            const errorBox = wrapper.querySelector('.material-upload-error');
            if (!errorBox) return;
            if (message) {
                errorBox.textContent = message;
                errorBox.classList.remove('d-none');
            } else {
                errorBox.textContent = '';
                errorBox.classList.add('d-none');
            }
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

        async function uploadMaterialFile(fileInput) {
            const wrapper = fileInput.closest('.dynamic-item');
            if (!wrapper) return;
            const files = fileInput.files;
            if (!files || files.length === 0) return;
            const file = files[0];
            const uploadButton = wrapper.querySelector('.material-upload-btn');
            const tipoSelect = wrapper.querySelector('select[name="materiali_tipo[]"]');

            setMaterialUploadError(wrapper, '');
            applyMaterialTypeFromMime(tipoSelect, file.type || '');
            if (uploadButton) uploadButton.disabled = true;

            let response = null;
            let rawText = '';
            try {
                const formData = new FormData();
                formData.append('file', file);

                response = await fetch('ajax_upload_drive_file.php', {
                    method: 'POST',
                    body: formData,
                    credentials: 'same-origin'
                });

                rawText = await response.text();
                let data = null;
                try {
                    data = JSON.parse(rawText);
                } catch (e) {
                    data = null;
                }

                if (!response.ok || !data || !data.success) {
                    const message = (data && data.error) ? data.error : (rawText || 'Errore upload file.');
                    throw new Error(message);
                }

                const nomeInput = wrapper.querySelector('input[name="materiali_nome[]"]');
                const urlInput = wrapper.querySelector('input[name="materiali_url[]"]');
                const fileIdInput = wrapper.querySelector('input[name="materiali_file_id[]"]');

                if (nomeInput && !nomeInput.value) nomeInput.value = data.name || file.name;
                if (urlInput) urlInput.value = data.viewLink || '';
                if (fileIdInput) fileIdInput.value = data.fileId || '';
                applyMaterialTypeFromMime(tipoSelect, data.mimeType || '');
            } catch (err) {
                const message = (err && err.message) ? err.message : 'Errore upload file.';
                setMaterialUploadError(wrapper, message);
            } finally {
                if (uploadButton) uploadButton.disabled = false;
                fileInput.value = '';
            }
        }

        // Add Obiettivo
        function addObiettivo() {
            obiettivoCounter++;
            const container = document.getElementById('obiettivi-container');
            const item = document.createElement('div');
            item.className = 'dynamic-item objective-editor';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6>Obiettivo ${obiettivoCounter}</h6>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove(); refreshClassTargetSuggestion(); renderWizardIntegrationSummary();">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="row">
                    <div class="col-12 mb-2">
                        <label class="form-label">Cerca nel catalogo</label>
                        <input type="search" class="form-control objective-search" placeholder="Scrivi una frase per filtrare codice, descrizione o competenza..." autocomplete="off">
                        <div class="list-group objective-results mt-2"></div>
                        <div class="small text-muted objective-selection mt-2">Nessun obiettivo selezionato.</div>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="obiettivi_tipo[]">
                            <option value="disciplinare">Disciplinare</option>
                            <option value="trasversale">Trasversale</option>
                            <option value="competenza">Competenza</option>
                        </select>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">Codice</label>
                        <input type="text" class="form-control" name="obiettivi_codice[]" placeholder="es. OBJ001">
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label">Livello Bloom</label>
                        <select class="form-select" name="obiettivi_livello[]">
                            <option value="1">1 - Ricordare</option>
                            <option value="2">2 - Comprendere</option>
                            <option value="3">3 - Applicare</option>
                            <option value="4">4 - Analizzare</option>
                            <option value="5">5 - Valutare</option>
                            <option value="6">6 - Creare</option>
                        </select>
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Descrizione</label>
                        <textarea class="form-control" name="obiettivi_descrizione[]" rows="2" placeholder="Descrizione dell'obiettivo"></textarea>
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Competenza</label>
                        <input type="text" class="form-control" name="obiettivi_competenza[]" placeholder="es. Competenze digitali">
                    </div>
                </div>
            `;
            container.appendChild(item);
            const search = item.querySelector('.objective-search');
            const results = item.querySelector('.objective-results');
            search.addEventListener('input', () => renderObjectiveResults(item, search.value));
            renderObjectiveResults(item, '');
        }

        function renderObjectiveResults(wrapper, query) {
            const results = wrapper.querySelector('.objective-results');
            const matches = UdaEditorUtils.filterObjectives(obiettiviCatalog, query).slice(0, 30);
            results.innerHTML = '';
            if (!matches.length) {
                results.innerHTML = '<div class="list-group-item text-muted">Nessun obiettivo trovato.</div>';
                return;
            }
            matches.forEach(objective => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                button.innerHTML = `<strong>${escapeWizardHtml(objective.codice || objective.id_obiettivo || '')}</strong> - ${escapeWizardHtml(objective.descrizione || '')}<br><small class="text-muted">${escapeWizardHtml(objective.competenza || '')}</small>`;
                button.addEventListener('click', () => selectObjective(wrapper, objective));
                results.appendChild(button);
            });
        }

        function selectObjective(wrapper, objective) {
            const tipo = wrapper.querySelector('select[name="obiettivi_tipo[]"]');
            const codice = wrapper.querySelector('input[name="obiettivi_codice[]"]');
            const descrizione = wrapper.querySelector('textarea[name="obiettivi_descrizione[]"]');
            const competenza = wrapper.querySelector('input[name="obiettivi_competenza[]"]');
            const livello = wrapper.querySelector('select[name="obiettivi_livello[]"]');
            if (tipo) tipo.value = objective.tipo_obiettivo || 'disciplinare';
            if (codice) codice.value = objective.codice || objective.id_obiettivo || '';
            if (descrizione) descrizione.value = objective.descrizione || '';
            if (competenza) competenza.value = objective.competenza || '';
            if (livello) livello.value = objective.livello_tassonomia || '1';
            wrapper.querySelector('.objective-selection').textContent = `Selezionato: ${objective.codice || objective.id_obiettivo || ''} - ${objective.descrizione || ''}`;
            wrapper.querySelector('.objective-results').innerHTML = '';
        }

        function escapeWizardHtml(value) {
            return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char]));
        }

        // Add Test
        function addTest() {
            testCounter++;
            const container = document.getElementById('test-container');
            const item = document.createElement('div');
            item.className = 'dynamic-item';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6>Test ${testCounter}</h6>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Nome Test</label>
                        <input type="text" class="form-control" name="test_nome[]" placeholder="es. Test Finale">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Tipo</label>
                        <select class="form-select" name="test_tipo[]">
                            <option value="prerequisiti">Prerequisiti</option>
                            <option value="intermedio">Intermedio</option>
                            <option value="finale">Finale</option>
                            <option value="altro">Altro</option>
                        </select>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Piattaforma</label>
                        <select class="form-select" name="test_piattaforma[]">
                            <option value="google-forms">Google Forms</option>
                            <option value="kahoot">Kahoot</option>
                            <option value="socrative">Socrative</option>
                            <option value="google-classroom">Google Classroom (Compito)</option>
                            <option value="github">GitHub Classroom</option>
                            <option value="altro">Altro</option>
                        </select>
                    </div>
                    <div class="col-12 mb-3 test-catalog-panel d-none">
                        <div class="border rounded p-3 bg-light">
                            <div class="small text-muted test-catalog-help mb-2"></div>
                            <div class="alert d-none test-catalog-status mb-2"></div>
                            <div class="test-forms-catalog d-none">
                                <label class="form-label">Cerca Google Form</label>
                                <input type="search" class="form-control test-forms-search mb-2" placeholder="Cerca per titolo, autore, data o descrizione..." autocomplete="off">
                                <div class="list-group test-forms-list" role="listbox"></div>
                                <a class="small d-none test-google-auth-link" href="user_integrations.php#google-section">Autorizza Google Drive e Forms nelle integrazioni</a>
                            </div>
                            <div class="test-classroom-catalog d-none">
                                <div class="test-classroom-mapped">
                                    <label class="form-label">Corso Google Classroom associato</label>
                                    <select class="form-select test-classroom-course mb-2">
                                        <option value="">Caricamento corso...</option>
                                    </select>
                                    <label class="form-label">Cerca compito</label>
                                    <input type="search" class="form-control test-classroom-search mb-2" placeholder="Cerca per titolo, stato o scadenza..." autocomplete="off">
                                    <div class="list-group test-classroom-list" role="listbox"></div>
                                </div>
                                <div class="test-classroom-nomap d-none">
                                    <div class="alert alert-warning py-2 mb-2">Nessun corso Google Classroom mappato alla classe-materia selezionata.</div>
                                    <a class="btn btn-sm btn-outline-primary" href="teaching_groups.php?return_to=uda_create.php#6">Vai ai mapping</a>
                                </div>
                            </div>
                            <div class="test-github-catalog d-none">
                                <div class="test-github-mapped">
                                    <label class="form-label">GitHub Classroom associata</label>
                                    <select class="form-select test-github-classroom mb-2">
                                        <option value="">Caricamento classroom...</option>
                                    </select>
                                    <label class="form-label">Cerca assignment</label>
                                    <input type="search" class="form-control test-github-search mb-2" placeholder="Cerca per titolo o slug..." autocomplete="off">
                                    <div class="list-group test-github-list" role="listbox"></div>
                                </div>
                                <div class="test-github-nomap d-none">
                                    <div class="alert alert-warning py-2 mb-2">Nessuna GitHub Classroom mappata alla classe-materia selezionata.</div>
                                    <a class="btn btn-sm btn-outline-primary" href="teaching_groups.php?return_to=uda_create.php#6">Crea la mappatura</a>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">URL Studenti</label>
                        <input type="url" class="form-control" name="test_url_studenti[]" placeholder="Link per studenti">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">URL Docente/Gestione</label>
                        <input type="url" class="form-control" name="test_url_docente[]" placeholder="Link gestione o risultati">
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Descrizione</label>
                        <textarea class="form-control" name="test_descrizione[]" rows="2"></textarea>
                    </div>
                    <input type="hidden" name="test_classroom_course_id[]" value="">
                    <input type="hidden" name="test_classroom_assignment_id[]" value="">
                    <input type="hidden" name="test_classroom_topic_id[]" value="">
                    <input type="hidden" name="test_github_classroom_id[]" value="">
                    <input type="hidden" name="test_github_assignment_id[]" value="">
                    <input type="hidden" name="test_url_assignment_student[]" value="">
                    <input type="hidden" name="test_url_assignment_teacher[]" value="">
                </div>
            `;
            container.appendChild(item);
            initWizardTestCatalog(item);
            return item;
        }

        function wizardTestField(wrapper, selector) {
            return wrapper.querySelector(selector);
        }

        function wizardClassSubjectQuery() {
            const classIds = [];
            const subjectIds = [];
            selectedGroupIds.forEach(groupId => {
                const group = teachingGroupCatalogIndex[groupId] || {};
                const classeviva = group.providers?.classeviva || null;
                if (classeviva?.external_context_id) classIds.push(String(classeviva.external_context_id));
                if (classeviva?.external_subject_id) subjectIds.push(String(classeviva.external_subject_id));
            });
            return {
                group_ids: [...selectedGroupIds],
                classIds: [...new Set(classIds)],
                subjectIds: [...new Set(subjectIds)]
            };
        }

        function wizardMappedProviderContext() {
            const googleCourses = new Map();
            const githubClassrooms = new Map();
            selectedGroupIds.forEach(groupId => {
                const group = teachingGroupCatalogIndex[groupId] || {};
                const gc = group.providers?.google_classroom || null;
                if (gc?.external_context_id) {
                    const id = String(gc.external_context_id).trim();
                    if (id) googleCourses.set(id, String(gc.external_name || id).trim() || id);
                }
                const gh = group.providers?.github_classroom || null;
                if (gh?.external_context_id) {
                    const id = String(gh.external_context_id).trim();
                    if (id) githubClassrooms.set(id, String(gh.external_name || id).trim() || id);
                }
            });
            return {
                googleCourseId: googleCourses.size === 1 ? [...googleCourses.keys()][0] : '',
                googleCourseName: googleCourses.size === 1 ? googleCourses.values().next().value : '',
                githubClassroomId: githubClassrooms.size === 1 ? [...githubClassrooms.keys()][0] : '',
                githubClassroomName: githubClassrooms.size === 1 ? githubClassrooms.values().next().value : '',
            };
        }

        function wizardCatalogUrl(path, params) {
            const query = new URLSearchParams();
            Object.entries(params || {}).forEach(([key, value]) => {
                if (Array.isArray(value)) {
                    value.forEach(item => query.append(`${key}[]`, item));
                } else if (value !== undefined && value !== null && String(value) !== '') {
                    query.set(key, value);
                }
            });
            return `${path}?${query.toString()}`;
        }

        async function fetchWizardCatalog(path, params) {
            const response = await fetch(wizardCatalogUrl(path, params), {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' }
            });
            const data = await response.json().catch(() => null);
            if (!response.ok || !data || !data.success) {
                const error = new Error(data?.error || `Errore HTTP ${response.status}`);
                error.code = data?.error_code || '';
                throw error;
            }
            return data;
        }

        function setWizardCatalogStatus(wrapper, message, type = 'info') {
            const status = wrapper.querySelector('.test-catalog-status');
            if (!status) return;
            status.className = `alert alert-${type} test-catalog-status mb-2`;
            status.textContent = message || '';
            status.classList.toggle('d-none', !message);
        }

        function setWizardCatalogOptions(select, options, placeholder) {
            if (!select) return;
            select.replaceChildren();
            const empty = document.createElement('option');
            empty.value = '';
            empty.textContent = placeholder;
            select.appendChild(empty);
            (Array.isArray(options) ? options : []).forEach(optionData => {
                const option = document.createElement('option');
                option.value = String(optionData.id || '');
                option.textContent = String(optionData.name || optionData.title || optionData.id || '');
                option.dataset.catalogItem = JSON.stringify(optionData);
                select.appendChild(option);
            });
        }

        function clearWizardTestExternalFields(wrapper) {
            [
                'test_classroom_course_id[]',
                'test_classroom_assignment_id[]',
                'test_classroom_topic_id[]',
                'test_github_classroom_id[]',
                'test_github_assignment_id[]',
                'test_url_assignment_student[]',
                'test_url_assignment_teacher[]'
            ].forEach(name => {
                const input = wrapper.querySelector(`input[name="${name}"]`);
                if (input) input.value = '';
            });
        }

        function fillWizardTestFromCatalog(wrapper, item, platform) {
            if (!item) return;
            const nameInput = wizardTestField(wrapper, 'input[name="test_nome[]"]');
            const descriptionInput = wizardTestField(wrapper, 'textarea[name="test_descrizione[]"]');
            const studentInput = wizardTestField(wrapper, 'input[name="test_url_studenti[]"]');
            const teacherInput = wizardTestField(wrapper, 'input[name="test_url_docente[]"]');
            if (nameInput) nameInput.value = String(item.title || item.name || '');
            if (descriptionInput) descriptionInput.value = String(item.description || '');
            if (studentInput) studentInput.value = String(item.student_url || item.link || '');
            if (teacherInput) teacherInput.value = String(item.teacher_url || item.link || '');
            clearWizardTestExternalFields(wrapper);

            if (platform === 'google-classroom') {
                const course = wrapper.querySelector('.test-classroom-course');
                const courseId = course?.value || '';
                const courseInput = wizardTestField(wrapper, 'input[name="test_classroom_course_id[]"]');
                const assignmentInput = wizardTestField(wrapper, 'input[name="test_classroom_assignment_id[]"]');
                const topicInput = wizardTestField(wrapper, 'input[name="test_classroom_topic_id[]"]');
                if (courseInput) courseInput.value = courseId;
                if (assignmentInput) assignmentInput.value = String(item.id || '');
                if (topicInput) topicInput.value = String(item.topic_id || '');
            }
            if (platform === 'github') {
                const classroomInput = wizardTestField(wrapper, 'input[name="test_github_classroom_id[]"]');
                const assignmentInput = wizardTestField(wrapper, 'input[name="test_github_assignment_id[]"]');
                const studentAssignmentInput = wizardTestField(wrapper, 'input[name="test_url_assignment_student[]"]');
                const teacherAssignmentInput = wizardTestField(wrapper, 'input[name="test_url_assignment_teacher[]"]');
                if (classroomInput) classroomInput.value = String(item.github_classroom_id || wrapper.querySelector('.test-github-classroom')?.value || '');
                if (assignmentInput) assignmentInput.value = String(item.github_assignment_id || item.id || '');
                if (studentAssignmentInput) studentAssignmentInput.value = String(item.student_url || '');
                if (teacherAssignmentInput) teacherAssignmentInput.value = String(item.teacher_url || '');
            }
        }

        function initWizardTestCatalog(wrapper) {
            const platformSelect = wrapper.querySelector('select[name="test_piattaforma[]"]');
            const panel = wrapper.querySelector('.test-catalog-panel');
            const formsBox = wrapper.querySelector('.test-forms-catalog');
            const classroomBox = wrapper.querySelector('.test-classroom-catalog');
            const githubBox = wrapper.querySelector('.test-github-catalog');
            const formsPicker = window.CatalogPicker && formsBox
                ? new window.CatalogPicker({
                    searchInput: wrapper.querySelector('.test-forms-search'),
                    listContainer: wrapper.querySelector('.test-forms-list'),
                    renderItem: form => ({
                        title: form.title || 'Google Form senza titolo',
                        metadata: `${form.published_in_classroom ? 'Pubblicato in Classroom · ' : ''}${form.response_count ?? 'n/d'} risposte · ${form.author || 'Autore n/d'} · ${form.created_at || 'Data n/d'}`,
                        highlight: !!form.published_in_classroom
                    }),
                })
                : null;
            const classroomPicker = window.CatalogPicker && classroomBox
                ? new window.CatalogPicker({
                    searchInput: wrapper.querySelector('.test-classroom-search'),
                    listContainer: wrapper.querySelector('.test-classroom-list'),
                    renderItem: assignment => ({
                        title: assignment.title || 'Compito senza titolo',
                        metadata: `${assignment.state || 'stato n/d'} · ${assignment.due_date || 'senza scadenza'}`
                    }),
                    onSelect: assignment => fillWizardTestFromCatalog(wrapper, assignment, 'google-classroom')
                })
                : null;
            const githubPicker = window.CatalogPicker && githubBox
                ? new window.CatalogPicker({
                    searchInput: wrapper.querySelector('.test-github-search'),
                    listContainer: wrapper.querySelector('.test-github-list'),
                    renderItem: assignment => ({
                        title: assignment.title || assignment.slug || 'Assignment senza titolo',
                        metadata: assignment.slug || assignment.github_assignment_id || ''
                    }),
                    onSelect: assignment => fillWizardTestFromCatalog(wrapper, assignment, 'github')
                })
                : null;

            const showOnly = (box, help) => {
                panel.classList.remove('d-none');
                formsBox.classList.toggle('d-none', box !== formsBox);
                classroomBox.classList.toggle('d-none', box !== classroomBox);
                githubBox.classList.toggle('d-none', box !== githubBox);
                const helpBox = wrapper.querySelector('.test-catalog-help');
                if (helpBox) helpBox.textContent = help;
            };

            const loadForms = async () => {
                showOnly(formsBox, 'Seleziona un modulo già presente nel Drive autorizzato.');
                try {
                    const ctx = wizardMappedProviderContext();
                    const data = await fetchWizardCatalog('ajax_list_google_forms.php', ctx.googleCourseId ? { course_id: ctx.googleCourseId } : {});
                    const forms = Array.isArray(data.forms) ? data.forms : [];
                    forms.sort((a, b) => (b.published_in_classroom ? 1 : 0) - (a.published_in_classroom ? 1 : 0));
                    formsPicker?.setItems(forms);
                    setWizardCatalogStatus(wrapper, forms.length + ' Google Forms disponibili.', 'success');
                } catch (error) {
                    setWizardCatalogStatus(wrapper, error.message, 'warning');
                    wrapper.querySelector('.test-google-auth-link')?.classList.remove('d-none');
                }
            };

            const loadClassroom = async () => {
                const ctx = wizardMappedProviderContext();
                const courseId = ctx.googleCourseId;
                const mappedBox = wrapper.querySelector('.test-classroom-mapped');
                const nomapBox = wrapper.querySelector('.test-classroom-nomap');
                if (!courseId) {
                    showOnly(classroomBox, '');
                    mappedBox?.classList.add('d-none');
                    nomapBox?.classList.remove('d-none');
                    setWizardCatalogStatus(wrapper, '', 'info');
                    return;
                }
                showOnly(classroomBox, 'Compiti del corso Google Classroom mappato alla classe-materia.');
                nomapBox?.classList.add('d-none');
                mappedBox?.classList.remove('d-none');
                const select = wrapper.querySelector('.test-classroom-course');
                setWizardCatalogOptions(select, [{ id: courseId, name: ctx.googleCourseName || courseId }], 'Corso mappato');
                if (select) select.disabled = true;
                const courseInput = wizardTestField(wrapper, 'input[name="test_classroom_course_id[]"]');
                if (courseInput) courseInput.value = courseId;
                try {
                    const data = await fetchWizardCatalog('ajax_get_wizard_classroom_catalog.php', { id_gruppo: [...selectedGroupIds], course_id: courseId });
                    classroomPicker?.setItems(data.assignments || []);
                    setWizardCatalogStatus(wrapper, (data.assignments || []).length + ' compiti disponibili.', 'success');
                } catch (error) {
                    setWizardCatalogStatus(wrapper, error.message, 'warning');
                }
            };

            const loadGithub = async () => {
                const ctx = wizardMappedProviderContext();
                const classroomId = ctx.githubClassroomId;
                const mappedBox = wrapper.querySelector('.test-github-mapped');
                const nomapBox = wrapper.querySelector('.test-github-nomap');
                if (!classroomId) {
                    showOnly(githubBox, '');
                    mappedBox?.classList.add('d-none');
                    nomapBox?.classList.remove('d-none');
                    setWizardCatalogStatus(wrapper, '', 'info');
                    return;
                }
                showOnly(githubBox, 'Assignment della GitHub Classroom mappata.');
                nomapBox?.classList.add('d-none');
                mappedBox?.classList.remove('d-none');
                const select = wrapper.querySelector('.test-github-classroom');
                setWizardCatalogOptions(select, [{ id: classroomId, name: ctx.githubClassroomName || classroomId }], 'Classroom mappata');
                if (select) select.disabled = true;
                const classroomInput = wizardTestField(wrapper, 'input[name="test_github_classroom_id[]"]');
                if (classroomInput) classroomInput.value = classroomId;
                try {
                    const data = await fetchWizardCatalog('ajax_get_wizard_github_catalog.php', { id_gruppo: [...selectedGroupIds], classroom_id: classroomId });
                    githubPicker?.setItems(data.assignments || []);
                    setWizardCatalogStatus(wrapper, (data.assignments || []).length + ' assignment disponibili.', 'success');
                } catch (error) {
                    setWizardCatalogStatus(wrapper, error.message, 'warning');
                }
            };

            platformSelect.addEventListener('change', () => {
                const platform = platformSelect.value;
                panel.classList.add('d-none');
                formsBox.classList.add('d-none');
                classroomBox.classList.add('d-none');
                githubBox.classList.add('d-none');
                clearWizardTestExternalFields(wrapper);
                if (platform === 'google-forms') loadForms();
                else if (platform === 'google-classroom') loadClassroom();
                else if (platform === 'github') loadGithub();
            });

            platformSelect.dispatchEvent(new Event('change'));
        }

        /* Legacy inline editor retained only as a non-executable migration reference.
        function addDomandaLegacy() {
            domandaCounter++;
            const container = document.getElementById('domande-container');
            const item = document.createElement('div');
            item.className = 'dynamic-item';
            item.innerHTML = `
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6>Domanda ${domandaCounter}</h6>
                    <button type="button" class="btn btn-sm btn-danger" onclick="this.parentElement.parentElement.remove()">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Argomento</label>
                        <input type="text" class="form-control" name="domanda_argomento[]" placeholder="es. Algoritmi">
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Difficoltà (1-5)</label>
                        <select class="form-select" name="domanda_livello[]">
                            <option value="1">1</option>
                            <option value="2">2</option>
                            <option value="3" selected>3</option>
                            <option value="4">4</option>
                            <option value="5">5</option>
                        </select>
                    </div>
                    <div class="col-md-2 mb-2">
                        <label class="form-label">Tempo (min)</label>
                        <input type="number" class="form-control" name="domanda_tempo[]" value="3" min="1">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Parole chiave</label>
                        <input type="text" class="form-control" name="domanda_parole[]" placeholder="separa con virgola">
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Testo Domanda</label>
                        <textarea class="form-control" name="domanda_testo[]" rows="2" placeholder="Inserisci la domanda"></textarea>
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Risposta attesa / Note di correzione</label>
                        <textarea class="form-control" name="domanda_suggerimenti[]" rows="2" placeholder="Punti chiave o risposta attesa"></textarea>
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Ordine consigliato</label>
                        <input type="number" class="form-control" name="domanda_ordine[]" value="0" min="0">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Collegata a (ID)</label>
                        <input type="text" class="form-control" name="domanda_collegata[]" placeholder="ID domanda correlata">
                    </div>
                    <div class="col-md-4 mb-2">
                        <label class="form-label">Note</label>
                        <input type="text" class="form-control" name="domanda_note[]" placeholder="Note interne">
                    </div>
                </div>
            `;
            container.appendChild(item);
            refreshClassTargetSuggestion();
            renderWizardIntegrationSummary();
        }
        */

        let wizardQuestionEditor = null;
        let wizardQuestionTarget = null;
        let wizardQuestionIsNew = false;

        function initWizardQuestionEditor() {
            const root = document.getElementById('wizard-question-editor');
            wizardQuestionEditor = QuestionEditor.mount(root);
            document.getElementById('wizardQuestionModal').addEventListener('hidden.bs.modal', removeWizardQuestionIfEmpty);
            document.getElementById('wizardQuestionSave').addEventListener('click', () => {
                const validation = root.validateEditor();
                if (!validation.valid || !wizardQuestionTarget) return;
                syncWizardQuestion(wizardQuestionTarget, root.getEditorState());
                wizardQuestionIsNew = false;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('wizardQuestionModal')).hide();
            });
        }

        function addDomanda() {
            domandaCounter++;
            const container = document.getElementById('domande-container');
            const template = document.getElementById('question-card-template');
            const item = QuestionCard.createFromTemplate(template, {
                argomento: '', difficolta: 3, domanda: '', tipo_domanda: 'aperta',
                risposta_attesa: '', opzioni: [], parole_chiave: []
            }, {
                label: `Domanda ${domandaCounter}`,
                onEdit: editWizardQuestion,
                onDelete: card => card.remove()
            });
            item.classList.add('wizard-question-card');
            [
                ['domanda_argomento[]', ''],
                ['domanda_livello[]', '3'],
                ['domanda_testo[]', ''],
                ['domanda_tipo[]', 'aperta'],
                ['domanda_suggerimenti[]', ''],
                ['domanda_risposte[]', ''],
                ['domanda_parole[]', '']
            ].forEach(([name, value]) => {
                const input = document.createElement('input');
                input.type = 'hidden'; input.name = name; input.value = value;
                item.appendChild(input);
            });
            container.appendChild(item);
            editWizardQuestion(item, true);
        }

        function editWizardQuestion(card, isNew = false) {
            wizardQuestionTarget = card;
            wizardQuestionIsNew = isNew;
            const state = {
                argomento: card.querySelector('[name="domanda_argomento[]"]').value,
                difficolta: card.querySelector('[name="domanda_livello[]"]').value || '3',
                domanda: card.querySelector('[name="domanda_testo[]"]').value,
                tipo_domanda: card.querySelector('[name="domanda_tipo[]"]').value || 'aperta',
                risposta_attesa: card.querySelector('[name="domanda_suggerimenti[]"]').value,
                opzioni: card.querySelector('[name="domanda_risposte[]"]').value,
                parole_chiave: card.querySelector('[name="domanda_parole[]"]').value,
            };
            document.getElementById('wizard-question-editor').setEditorState(state);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('wizardQuestionModal')).show();
        }

        function removeWizardQuestionIfEmpty() {
            if (!wizardQuestionIsNew || !wizardQuestionTarget) return;
            const card = wizardQuestionTarget;
            const text = card.querySelector('[name="domanda_testo[]"]')?.value.trim() || '';
            const expected = card.querySelector('[name="domanda_suggerimenti[]"]')?.value.trim() || '';
            const options = card.querySelector('[name="domanda_risposte[]"]')?.value.trim() || '';
            const keywords = card.querySelector('[name="domanda_parole[]"]')?.value.trim() || '';
            if (!text && !expected && !options && !keywords) card.remove();
            wizardQuestionTarget = null;
            wizardQuestionIsNew = false;
        }

        function syncWizardQuestion(card, state) {
            const optionsJson = state.tipo_domanda === 'multipla' ? UdaEditorUtils.serializeMultipleChoice(state.opzioni) : '';
            card.querySelector('[name="domanda_argomento[]"]').value = state.argomento;
            card.querySelector('[name="domanda_livello[]"]').value = state.difficolta;
            card.querySelector('[name="domanda_testo[]"]').value = state.domanda;
            card.querySelector('[name="domanda_tipo[]"]').value = state.tipo_domanda;
            card.querySelector('[name="domanda_suggerimenti[]"]').value = state.tipo_domanda === 'aperta' ? state.risposta_attesa : '';
            card.querySelector('[name="domanda_risposte[]"]').value = optionsJson;
            card.querySelector('[name="domanda_parole[]"]').value = state.parole_chiave.join(',');
            const label = card.querySelector('[data-question-card-label]')?.textContent || '';
            QuestionCard.update(card, state, { label });
            card.querySelector('.wizard-question-summary').innerHTML = `<strong>${escapeWizardHtml(state.domanda || 'Domanda non ancora compilata.')}</strong><br><span class="badge text-bg-secondary">${state.tipo_domanda === 'multipla' ? 'Risposta multipla' : 'Risposta aperta'}</span> <span class="badge text-bg-info">Difficoltà ${escapeWizardHtml(state.difficolta)}</span>`;
        }

        function saveWizardDraft() {
            const value = {
                fields: {},
                selectedGroupIds: []
            };
            ['titolo', 'argomento', 'disciplina', 'metodologia', 'anno_scolastico', 'periodo_scolastico', 'descrizione', 'note', 'classi_target', 'stato'].forEach(name => {
                const field = document.querySelector(`[name="${name}"]`);
                if (field) value.fields[name] = field.value;
            });
            value.fields.classi_target_usa_assegnazioni = !!document.querySelector('[name="classi_target_usa_assegnazioni"]')?.checked;
            value.selectedGroupIds = Array.from(document.getElementById('teaching-group-select')?.selectedOptions || [])
                .map(option => String(option.value || '').trim())
                .filter(Boolean);
            selectedGroupIds = value.selectedGroupIds.slice();
            try { sessionStorage.setItem('uda_create_draft', JSON.stringify(value)); } catch (e) { /* storage non disponibile */ }
        }

        function restoreWizardDraft() {
            let draft = null;
            try { draft = JSON.parse(sessionStorage.getItem('uda_create_draft') || 'null'); } catch (e) { draft = null; }
            if (!draft || typeof draft !== 'object') return;
            Object.entries(draft.fields || {}).forEach(([name, value]) => {
                const field = document.querySelector(`[name="${name}"]`);
                if (field && value !== undefined) field.value = value;
            });
            const targetCheckbox = document.querySelector('[name="classi_target_usa_assegnazioni"]');
            if (targetCheckbox) targetCheckbox.checked = draft.fields?.classi_target_usa_assegnazioni === true;
            if (draft.fields?.periodo_scolastico) {
                applyAcademicPeriod(document.getElementById('periodo_scolastico'));
            }
            const groupSelect = document.getElementById('teaching-group-select');
            const requestedGroups = Array.isArray(draft.selectedGroupIds) ? draft.selectedGroupIds : [];
            if (groupSelect) {
                Array.from(groupSelect.options).forEach(option => {
                    option.selected = requestedGroups.includes(String(option.value));
                });
                selectedGroupIds = Array.from(groupSelect.selectedOptions).map(option => String(option.value));
            }
            refreshClassTargetSuggestion();
            renderTeachingGroupProviderSummary();
            renderWizardIntegrationSummary();
            syncWizardDiscipline();
            updateImportQuestionsLink();
        }

        function normalizeAssignedClassTarget(names) {
            const unique = [...new Set((names || []).map(name => String(name || '').trim()).filter(Boolean))];
            if (!unique.length) return '';
            if (unique.length === 1) return unique[0];
            const parts = unique.map(name => {
                const match = name.match(/^(\d+)\s*([A-Za-zÀ-ÖØ-öø-ÿ])(?:\s+(.*))?$/u);
                return match ? { year: match[1], suffix: (match[3] || '').trim() } : null;
            });
            if (parts.some(part => !part)) return unique.join(', ');
            const years = [...new Set(parts.map(part => part.year))];
            const suffixes = [...new Set(parts.map(part => part.suffix))];
            if (years.length !== 1 || suffixes.length !== 1) return unique.join(', ');
            return suffixes[0] ? `${years[0]} ${suffixes[0]}` : years[0];
        }

        function refreshClassTargetSuggestion() {
            const panel = document.querySelector('[data-class-target-suggestion-panel]');
            const label = document.querySelector('[data-class-target-suggestion]');
            const checkbox = document.querySelector('[name="classi_target_usa_assegnazioni"]');
            const field = document.querySelector('[name="classi_target"]');
            if (!panel || !label || !checkbox || !field) return;
            const names = selectedGroupIds
                .map(groupId => teachingGroupCatalogIndex[groupId]?.nome_classe || teachingGroupCatalogIndex[groupId]?.nome_gruppo || '')
                .filter(Boolean);
            const suggestion = normalizeAssignedClassTarget(names);
            if (!suggestion) {
                panel.classList.add('d-none');
                return;
            }
            label.textContent = suggestion;
            panel.classList.remove('d-none');
            if (!field.value.trim() && !checkbox.dataset.userChoice) checkbox.checked = true;
        }

        function renderWizardIntegrationSummary() {
            document.querySelectorAll('[data-wizard-integration-summary]').forEach(container => {
                const groups = selectedGroupIds.map(id => teachingGroupCatalogIndex[id]).filter(Boolean);
                if (!groups.length) {
                    container.innerHTML = '<div class="alert alert-light border mb-0"><i class="bi bi-info-circle"></i> Nessuna classe o mappatura configurata: puoi continuare con i controlli manuali.</div>';
                    return;
                }
                const items = groups.map(group => {
                    const className = String(group.nome_classe || group.nome_gruppo || 'Classe');
                    const subjectName = String(group.nome_materia || 'Materia');
                    const classroom = group.providers?.google_classroom?.external_name || group.providers?.google_classroom?.external_context_id || 'Non configurata';
                    const github = group.providers?.github_classroom?.external_name || group.providers?.github_classroom?.external_context_id || 'Non configurata';
                    return `<li><strong>${escapeWizardHtml(className)}</strong> · ${escapeWizardHtml(subjectName)}<br><small>Google: ${escapeWizardHtml(classroom)} · GitHub: ${escapeWizardHtml(github)}</small></li>`;
                }).join('');
                container.innerHTML = `<div class="alert alert-info border mb-0"><div class="d-flex justify-content-between align-items-start gap-2"><div><strong>Assegnazioni e mappature</strong><ul class="mb-0 mt-1">${items}</ul></div><a class="btn btn-sm btn-outline-primary flex-shrink-0" href="uda_create.php#2">Modifica mappature</a></div></div>`;
            });
        }

        function renderTeachingGroupProviderSummary() {
            const container = document.getElementById('teaching-group-provider-summary');
            if (!container) return;
            const groups = selectedGroupIds.map(id => teachingGroupCatalogIndex[id]).filter(Boolean);
            if (!groups.length) {
                container.innerHTML = '<div class="alert alert-light border mb-0"><i class="bi bi-info-circle"></i> Nessun gruppo selezionato.</div>';
                return;
            }
            const providerLabels = { classeviva: 'ClasseViva', google_classroom: 'Google Classroom', github_classroom: 'GitHub Classroom' };
            container.innerHTML = groups.map(group => {
                const providers = group.providers || {};
                const badges = Object.keys(providerLabels).map(provider => {
                    const link = providers[provider];
                    return `<span class="badge ${link ? 'text-bg-success' : 'text-bg-light border text-dark'} me-1">${providerLabels[provider]}: ${link ? 'configurata' : 'non configurata'}</span>`;
                }).join('');
                const details = [group.nome_classe, group.nome_materia, group.anno_scolastico].filter(Boolean).join(' · ');
                return `<div class="border rounded p-2 mb-2"><strong>${escapeWizardHtml(group.nome_gruppo || group.id_gruppo)}</strong><div class="small text-muted">${escapeWizardHtml(details)}</div><div class="mt-1">${badges}</div></div>`;
            }).join('');
        }

        function syncWizardDiscipline() {
            const input = document.querySelector('input[name="disciplina"]');
            if (!input || input.dataset.userEdited === '1') return;
            const subjects = selectedGroupIds
                .map(groupId => String(teachingGroupCatalogIndex[groupId]?.nome_materia || '').trim())
                .filter(Boolean);
            const unique = [...new Set(subjects)];
            if (unique.length === 1) input.value = unique[0];
        }

        function updateImportQuestionsLink() {
            const link = document.getElementById('wizard-import-questions-link');
            if (!link) return;
            const courseIds = new Set();
            selectedGroupIds.forEach(groupId => {
                const gc = teachingGroupCatalogIndex[groupId]?.providers?.google_classroom || null;
                const courseId = String(gc?.external_context_id || '').trim();
                if (courseId) courseIds.add(courseId);
            });
            const base = `import_questions.php?id=${encodeURIComponent(tempUdaId)}&wizard=1&return_to=uda_create.php`;
            link.href = courseIds.size === 1
                ? `${base}&course_id=${encodeURIComponent([...courseIds][0])}`
                : base;
        }

        // --- Google Drive Picker (GIS) ---
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
                alert('Google Identity non è stato caricato. Ricarica la pagina (verifica che accounts.google.com/gsi/client non sia bloccato).');
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

        async function openDrivePicker(button) {
            if (!pickerApiKey || !pickerClientId) {
                alert('Config Picker mancante: definire GOOGLE_API_KEY e web.client_id in google_credentials.json');
                return;
            }
            // Prova a riutilizzare token memorizzato
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
                        .setCallback(data => pickerCallback(data, button))
                        .build();
                    picker.setVisible(true);
                } catch (err) {
                    alert('Errore apertura picker: ' + err);
                }
            };

            // Se abbiamo già un token, prova ad aprire subito senza prompt aggiuntivi
            if (driveAccessToken) {
                openPickerNow();
                return;
            }

            // Richiedi token una sola volta (prompt di consenso solo al primo giro)
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

        function pickerCallback(data, button) {
            if (data.action !== google.picker.Action.PICKED) return;
            const doc = data.docs[0];
            if (!doc) return;
            const url = doc.url || doc.alternateLink || '';
            const name = doc.name || doc.title || '';
            const wrapper = button.closest('.dynamic-item');
            if (!wrapper) return;
            const urlInput = wrapper.querySelector('input[name="materiali_url[]"]');
            const nomeInput = wrapper.querySelector('input[name="materiali_nome[]"]');
            const tipoSelect = wrapper.querySelector('select[name="materiali_tipo[]"]');
            const descrInput = wrapper.querySelector('textarea[name="materiali_descrizione[]"]');
            const fileIdInput = wrapper.querySelector('input[name="materiali_file_id[]"]');

            if (urlInput) urlInput.value = url;
            if (nomeInput && !nomeInput.value) nomeInput.value = name;
            if (descrInput && !descrInput.value) descrInput.value = doc.mimeType || '';
            applyMaterialTypeFromMime(tipoSelect, doc.mimeType || '', 'link');
            if (fileIdInput) fileIdInput.value = doc.id || '';
        }

        function escapeWizardImportHtml(value) {
            return String(value || '')
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#39;');
        }

        function detectWizardTestPlatform(url, isAssignment = false) {
            const normalized = String(url || '').toLowerCase().trim();
            if (!normalized) return isAssignment ? 'google-classroom' : 'altro';
            if (normalized.includes('docs.google.com/forms') || normalized.includes('forms.gle')) return 'google-forms';
            if (normalized.includes('kahoot.it') || normalized.includes('create.kahoot.it')) return 'kahoot';
            if (normalized.includes('socrative.com') || normalized.includes('b.socrative.com')) return 'socrative';
            if (normalized.includes('classroom.google.com') && isAssignment) return 'google-classroom';
            return isAssignment ? 'google-classroom' : 'altro';
        }

        function detectWizardMaterialType(url, suggested = '') {
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

        function getWizardMaterialTypeOptions(selectedValue = 'link') {
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

        function setWizardClassroomMessage(type, message, withIntegrationLink = false) {
            const box = document.getElementById('wizardClassroomImportMsg');
            if (!box) return;
            if (!message) {
                box.className = 'alert d-none';
                box.innerHTML = '';
                return;
            }
            box.className = `alert alert-${type || 'info'}`;
            if (withIntegrationLink) {
                box.innerHTML = `${escapeWizardImportHtml(message)} <a href="${classroomImportIntegrationUrl}" class="alert-link">Apri integrazioni</a>`;
            } else {
                box.textContent = message;
            }
        }

        function setWizardClassroomLoading(isLoading) {
            const loading = document.getElementById('wizardClassroomLoading');
            if (!loading) return;
            loading.classList.toggle('d-none', !isLoading);
        }

        function setWizardClassroomCourseHelp(message) {
            const help = document.getElementById('wizardClassroomCourseHelp');
            if (!help) return;
            help.textContent = message || '';
        }

        function toggleWizardClassroomSelectAll(masterCheckbox) {
            const checked = masterCheckbox ? !!masterCheckbox.checked : false;
            document.querySelectorAll('.wizard-classroom-check').forEach(input => {
                input.checked = checked;
            });
        }

        function updateWizardDestination(selectEl) {
            const row = selectEl.closest('tr');
            if (!row) return;
            const materialTypeSelect = row.querySelector('.wizard-classroom-material-type');
            if (!materialTypeSelect) return;
            materialTypeSelect.disabled = selectEl.value === 'test';
        }

        function getWizardClassroomResourceById(resourceId) {
            return wizardClassroomResources.find((item) => String(item.resource_id || '') === String(resourceId || '')) || null;
        }

        function fillWizardTopicSelect(topics, resources) {
            const topicSelect = document.getElementById('wizardClassroomTopicSelect');
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
                    const noTopicOption = document.createElement('option');
                    noTopicOption.value = '__NO_TOPIC__';
                    noTopicOption.textContent = 'Senza argomento';
                    topicSelect.appendChild(noTopicOption);
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

        function renderWizardClassroomResources() {
            const body = document.getElementById('wizardClassroomResourcesBody');
            const emptyBox = document.getElementById('wizardClassroomResourcesEmpty');
            const masterSelect = document.getElementById('wizardClassroomSelectAll');
            const topicSelect = document.getElementById('wizardClassroomTopicSelect');
            if (!body || !emptyBox || !topicSelect || !masterSelect) return;

            const selectedTopicId = String(topicSelect.value || '');
            const filtered = wizardClassroomResources.filter(resource => {
                const resourceTopic = String(resource.topic_id || '').trim();
                if (selectedTopicId === '__NO_TOPIC__') {
                    return resourceTopic === '';
                }
                if (!selectedTopicId) {
                    return false;
                }
                return resourceTopic === selectedTopicId;
            });

            body.innerHTML = '';
            if (filtered.length === 0) {
                emptyBox.classList.remove('d-none');
                masterSelect.checked = false;
                return;
            }

            emptyBox.classList.add('d-none');
            masterSelect.checked = true;

            filtered.forEach(resource => {
                const destination = 'materiale';
                const suggestedMaterialType = detectWizardMaterialType(resource.url || '', resource.suggested_material_type || '');
                const row = document.createElement('tr');
                row.dataset.resourceId = String(resource.resource_id || '');
                row.innerHTML = `
                    <td>
                        <input type="checkbox" class="form-check-input wizard-classroom-check" checked>
                    </td>
                    <td>
                        <div class="fw-semibold">${escapeWizardImportHtml(resource.title || 'Risorsa Classroom')}</div>
                        <div class="small text-muted">
                            <span class="badge bg-light text-dark border me-1">${escapeWizardImportHtml(resource.source_label || 'Classroom')}</span>
                            ${(resource.work_type ? `<span class="badge bg-light text-dark border me-1">${escapeWizardImportHtml(resource.work_type)}</span>` : '')}
                            ${destination === 'test' ? '<span class="badge bg-warning text-dark">Test suggerito</span>' : ''}
                        </div>
                        ${(resource.description ? `<div class="small text-muted mt-1">${escapeWizardImportHtml(resource.description)}</div>` : '')}
                        <div class="small mt-1">
                            <a href="${escapeWizardImportHtml(resource.url || '')}" target="_blank" rel="noopener">Apri link</a>
                        </div>
                    </td>
                    <td>
                        <select class="form-select form-select-sm wizard-classroom-destination" onchange="updateWizardDestination(this)">
                            <option value="materiale" selected>Materiale</option>
                        </select>
                    </td>
                    <td>
                        <select class="form-select form-select-sm wizard-classroom-material-type" ${destination === 'test' ? 'disabled' : ''}>
                            ${getWizardMaterialTypeOptions(suggestedMaterialType)}
                        </select>
                    </td>
                `;
                body.appendChild(row);
            });
        }

        async function loadWizardClassroomCourses(force = false) {
            const courseSelect = document.getElementById('wizardClassroomCourseSelect');
            if (!courseSelect) return false;

            const signature = [...selectedGroupIds].sort().join('|');
            if (!force && signature === wizardClassroomLoadedSignature && wizardClassroomCourses.length > 0) {
                return true;
            }

            // Determina il corso Classroom già mappato sui gruppi selezionati
            // (dati già disponibili lato client in teachingGroupCatalogIndex).
            const mappedMap = new Map();
            selectedGroupIds.forEach(groupId => {
                const group = teachingGroupCatalogIndex[groupId] || {};
                const gc = group.providers?.google_classroom || null;
                const courseId = String(gc?.external_context_id || '').trim();
                const courseName = String(gc?.external_name || '').trim();
                if (courseId && !mappedMap.has(courseId)) {
                    mappedMap.set(courseId, { id: courseId, name: courseName || courseId });
                }
            });
            const mappedCourses = [...mappedMap.values()];

            if (mappedCourses.length === 1) {
                // Corso mappato: mostralo come non modificabile e precarica le risorse.
                wizardClassroomCourses = mappedCourses;
                courseSelect.innerHTML = '';
                const option = document.createElement('option');
                option.value = mappedCourses[0].id;
                option.textContent = mappedCourses[0].name;
                option.selected = true;
                courseSelect.appendChild(option);
                courseSelect.disabled = true;
                setWizardClassroomCourseHelp('Corso mappato al gruppo selezionato: non modificabile.');
                setWizardClassroomMessage('', '');
                wizardClassroomLoadedSignature = signature;
                await loadWizardClassroomResources(mappedCourses[0].id);
                return true;
            }

            // Nessun corso mappato (o più di uno): mostra l'elenco completo dei corsi.
            courseSelect.disabled = false;
            setWizardClassroomCourseHelp('Nessun corso mappato: scegli un corso per importarne le risorse.');
            setWizardClassroomLoading(true);
            try {
                const response = await fetch('ajax_get_classroom_courses.php', { credentials: 'same-origin' });
                const result = await response.json().catch(() => null);
                if (!response.ok || !result || !result.success) {
                    throw new Error((result && result.error) ? result.error : 'Impossibile caricare i corsi.');
                }

                const courses = Array.isArray(result.courses) ? result.courses : [];
                wizardClassroomCourses = courses;
                courseSelect.innerHTML = '';
                if (courses.length === 0) {
                    const option = document.createElement('option');
                    option.value = '';
                    option.textContent = 'Nessun corso disponibile';
                    courseSelect.appendChild(option);
                    courseSelect.disabled = true;
                    setWizardClassroomMessage('warning', 'Nessun corso Google Classroom disponibile per questo account.', true);
                    wizardClassroomResources = [];
                    renderWizardClassroomResources();
                    return false;
                }

                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = 'Seleziona un corso...';
                courseSelect.appendChild(placeholder);
                courses.forEach((course, idx) => {
                    const option = document.createElement('option');
                    option.value = course.id || '';
                    option.textContent = course.name || `Corso ${idx + 1}`;
                    courseSelect.appendChild(option);
                });
                courseSelect.value = '';
                wizardClassroomResources = [];
                renderWizardClassroomResources();
                setWizardClassroomMessage('', '');
                wizardClassroomLoadedSignature = signature;
                return true;
            } catch (error) {
                setWizardClassroomMessage('warning', error.message || 'Impossibile caricare i corsi.', true);
                return false;
            } finally {
                setWizardClassroomLoading(false);
            }
        }

        async function loadWizardClassroomResources(explicitCourseId = '') {
            const courseSelect = document.getElementById('wizardClassroomCourseSelect');
            const courseId = String(explicitCourseId || '').trim() || (courseSelect ? String(courseSelect.value || '').trim() : '');
            if (!courseId) {
                wizardClassroomResources = [];
                renderWizardClassroomResources();
                return;
            }

            setWizardClassroomLoading(true);
            setWizardClassroomMessage('info', 'Caricamento argomenti e risorse...');
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
                wizardClassroomResources = resources.filter(r => {
                    const url = String(r.url || '').trim();
                    return url !== '';
                });
                fillWizardTopicSelect(topics, wizardClassroomResources);
                renderWizardClassroomResources();
                setWizardClassroomMessage('', '');
            } catch (error) {
                wizardClassroomResources = [];
                renderWizardClassroomResources();
                setWizardClassroomMessage('danger', error.message || 'Impossibile caricare le risorse Classroom.');
            } finally {
                setWizardClassroomLoading(false);
            }
        }

        function fillWizardMaterialRowFromResource(wrapper, resource, chosenMaterialType) {
            if (!wrapper || !resource) return;
            const nameInput = wrapper.querySelector('input[name="materiali_nome[]"]');
            const urlInput = wrapper.querySelector('input[name="materiali_url[]"]');
            const typeSelect = wrapper.querySelector('select[name="materiali_tipo[]"]');
            const descriptionInput = wrapper.querySelector('textarea[name="materiali_descrizione[]"]');

            if (nameInput) nameInput.value = resource.title || 'Materiale Classroom';
            if (urlInput) urlInput.value = resource.url || '';
            if (typeSelect) typeSelect.value = chosenMaterialType || detectWizardMaterialType(resource.url || '', resource.suggested_material_type || '');
            if (descriptionInput) {
                const descParts = [];
                if (resource.description) descParts.push(resource.description);
                if (resource.source_label) descParts.push(`Import da ${resource.source_label}`);
                descriptionInput.value = descParts.join(' | ');
            }
        }

        function fillWizardTestRowFromResource(wrapper, resource) {
            if (!wrapper || !resource) return;
            const nameInput = wrapper.querySelector('input[name="test_nome[]"]');
            const typeSelect = wrapper.querySelector('select[name="test_tipo[]"]');
            const platformSelect = wrapper.querySelector('select[name="test_piattaforma[]"]');
            const urlStudentiInput = wrapper.querySelector('input[name="test_url_studenti[]"]');
            const urlDocenteInput = wrapper.querySelector('input[name="test_url_docente[]"]');
            const descInput = wrapper.querySelector('textarea[name="test_descrizione[]"]');
            const classroomCourseInput = wrapper.querySelector('input[name="test_classroom_course_id[]"]');
            const classroomAssignmentInput = wrapper.querySelector('input[name="test_classroom_assignment_id[]"]');
            const classroomTopicInput = wrapper.querySelector('input[name="test_classroom_topic_id[]"]');

            const platform = detectWizardTestPlatform(resource.url || '', !!resource.is_assignment);
            if (nameInput) nameInput.value = resource.title || 'Test Classroom';
            if (typeSelect) typeSelect.value = resource.is_assignment ? 'intermedio' : 'altro';
            if (platformSelect) platformSelect.value = platform;
            if (urlStudentiInput) urlStudentiInput.value = resource.url || '';
            if (urlDocenteInput) urlDocenteInput.value = resource.url || '';
            const classroomCourseSelect = document.getElementById('wizardClassroomCourseSelect');
            const selectedCourseId = classroomCourseSelect ? (classroomCourseSelect.value || '') : '';
            if (classroomCourseInput) classroomCourseInput.value = (platform === 'google-classroom' ? selectedCourseId : '');
            if (classroomAssignmentInput) classroomAssignmentInput.value = (platform === 'google-classroom' ? (resource.source_id || '') : '');
            if (classroomTopicInput) classroomTopicInput.value = resource.topic_id || '';

            if (descInput) {
                const descParts = [];
                if (resource.description) descParts.push(resource.description);
                if (resource.source_label) descParts.push(`Import da ${resource.source_label}`);
                if (resource.work_type) descParts.push(`Tipo Classroom: ${resource.work_type}`);
                descInput.value = descParts.join(' | ');
            }
        }

        function applyWizardTopicToArgomento() {
            const topicSelect = document.getElementById('wizardClassroomTopicSelect');
            const argomentoInput = document.querySelector('input[name="argomento"]');
            if (!topicSelect || !argomentoInput) return;
            if (!topicSelect.selectedOptions || topicSelect.selectedOptions.length === 0) return;
            const topicLabel = String(topicSelect.selectedOptions[0].textContent || '').trim();
            if (!topicLabel || topicLabel.toLowerCase() === 'senza argomento') return;

            if (argomentoInput.value.trim() && argomentoInput.value.trim() !== topicLabel) {
                const shouldReplace = confirm(`Vuoi sostituire l'Argomento UDA con "${topicLabel}"?`);
                if (!shouldReplace) return;
            }
            argomentoInput.value = topicLabel;
        }

        function applyWizardCourseTitleIfMissing() {
            const titoloInput = document.querySelector('input[name="titolo"]');
            const courseSelect = document.getElementById('wizardClassroomCourseSelect');
            if (!titoloInput || !courseSelect) return;
            if (titoloInput.value.trim() !== '') return;
            const selectedOption = (courseSelect.selectedOptions && courseSelect.selectedOptions.length > 0)
                ? courseSelect.selectedOptions[0]
                : courseSelect.options[courseSelect.selectedIndex];
            const courseLabel = selectedOption ? String(selectedOption.textContent || '').trim() : '';
            if (courseLabel !== '') {
                titoloInput.value = courseLabel;
            }
        }

        function applyWizardClassroomCourseMetadata() {
            const courseSelect = document.getElementById('wizardClassroomCourseSelect');
            const course = wizardClassroomCourses.find(item => String(item.id || '') === String(courseSelect?.value || ''));
            if (!course) return;
            const disciplineInput = document.querySelector('input[name="disciplina"]');
            if (disciplineInput) disciplineInput.value = String(course.name || '').trim();
            const sectionInput = document.getElementById('classroom_section');
            const roomInput = document.getElementById('classroom_room');
            if (sectionInput) sectionInput.value = String(course.section || '').trim();
            if (roomInput) roomInput.value = String(course.room || '').trim();
        }

        function applyWizardClassroomImport() {
            const rows = document.querySelectorAll('#wizardClassroomResourcesBody tr[data-resource-id]');
            if (!rows.length) {
                alert('Nessuna risorsa disponibile da importare.');
                return;
            }

            let importedMaterials = 0;
            let importedTests = 0;
            rows.forEach(row => {
                const check = row.querySelector('.wizard-classroom-check');
                if (!check || !check.checked) return;

                const resourceId = row.dataset.resourceId || '';
                const resource = getWizardClassroomResourceById(resourceId);
                if (!resource) return;

                const destinationSelect = row.querySelector('.wizard-classroom-destination');
                const destination = destinationSelect ? destinationSelect.value : 'materiale';
                if (destination === 'test') {
                    const testRow = addTest();
                    if (testRow) {
                        fillWizardTestRowFromResource(testRow, resource);
                    }
                    importedTests++;
                } else {
                    const materialTypeSelect = row.querySelector('.wizard-classroom-material-type');
                    const chosenMaterialType = materialTypeSelect ? materialTypeSelect.value : 'link';
                    const materialRow = addMaterial();
                    fillWizardMaterialRowFromResource(materialRow, resource, chosenMaterialType);
                    importedMaterials++;
                }
            });

            const total = importedMaterials + importedTests;
            if (total === 0) {
                alert('Seleziona almeno una risorsa da importare.');
                return;
            }

            setWizardClassroomMessage('success', `Import completato: ${importedMaterials} materiali e ${importedTests} test aggiunti al wizard.`);
        }

        function applyAcademicPeriod(select) {
            const option = select && select.selectedOptions ? select.selectedOptions[0] : null;
            const start = document.getElementById('data_inizio');
            const end = document.getElementById('data_fine');
            if (start) start.value = option ? (option.dataset.start || '') : '';
            if (end) end.value = option ? (option.dataset.end || '') : '';
        }

        function refreshAcademicPeriodOptions(academicYear) {
            const select = document.getElementById('periodo_scolastico');
            if (!select) return;
            const current = select.value;
            select.innerHTML = '<option value="">Seleziona un periodo (facoltativo)</option>';
            (academicPeriodOptions[academicYear] || []).forEach(period => {
                const option = document.createElement('option');
                option.value = period.value;
                option.textContent = period.label;
                option.dataset.start = period.start;
                option.dataset.end = period.end;
                select.appendChild(option);
            });
            if ([...select.options].some(option => option.value === current)) select.value = current;
            applyAcademicPeriod(select);
        }

        document.querySelector('[name="anno_scolastico"]')?.addEventListener('change', event => {
            refreshAcademicPeriodOptions(event.target.value);
        });

        // Generate Riepilogo
        function generateRiepilogo() {
            const container = document.getElementById('riepilogo-content');
            let html = '';

            // Info Generali
            const titolo = document.querySelector('input[name="titolo"]').value;
            const argomento = document.querySelector('input[name="argomento"]').value;
            const disciplina = document.querySelector('input[name="disciplina"]').value;
            const metodologia = document.querySelector('input[name="metodologia"]').value;
            const annoScolastico = document.querySelector('[name="anno_scolastico"]').value;
            const dataInizio = document.querySelector('input[name="data_inizio"]')?.value || '';
            const dataFine = document.querySelector('input[name="data_fine"]')?.value || '';
            const periodo = document.querySelector('#periodo_scolastico option:checked')?.textContent.trim() || '';
            const descrizione = document.querySelector('textarea[name="descrizione"]').value;
            const note = document.querySelector('textarea[name="note"]').value;
            const classiTarget = document.querySelector('[name="classi_target"]')?.value.trim() || '';
            const stato = document.querySelector('select[name="stato"]').value;

            html += `
                <div class="card mb-3">
                    <div class="card-header bg-primary text-white">
                        <h6 class="mb-0"><i class="bi bi-info-circle"></i> Informazioni Generali</h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6">
                                <p><strong>Titolo:</strong> ${titolo || 'N/D'}</p>
                                <p><strong>Argomento:</strong> ${argomento || 'N/D'}</p>
                                <p><strong>Disciplina:</strong> ${disciplina || 'N/D'}</p>
                                <p><strong>Metodologia:</strong> ${metodologia || 'N/D'}</p>
                                <p><strong>Anno Scolastico:</strong> ${annoScolastico || 'N/D'}</p>
                                <p><strong>Stato:</strong> <span class="badge bg-secondary">${stato || 'bozza'}</span></p>
                            </div>
                            <div class="col-md-6">
                                <p><strong>Periodo:</strong> ${periodo || 'N/D'}</p>
                                ${dataInizio || dataFine ? `<p><strong>Intervallo:</strong> ${dataInizio || 'N/D'} - ${dataFine || 'N/D'}</p>` : ''}
                                <p><strong>Classi Target:</strong> ${escapeWizardHtml(classiTarget || 'N/D')}</p>
                            </div>
                        </div>
                        ${descrizione ? `<hr><p><strong>Descrizione:</strong><br>${descrizione}</p>` : ''}
                        ${note ? `<hr><p><strong>Note/Prerequisiti:</strong><br>${note}</p>` : ''}
                    </div>
                </div>
            `;

            // Classi
            const selectedGroups = selectedGroupIds.map(id => teachingGroupCatalogIndex[id]).filter(Boolean);
            const classiCount = selectedGroups.length;
            const classiSummary = selectedGroups.map(group => {
                const className = String(group.nome_classe || group.nome_gruppo || '');
                const subjectName = String(group.nome_materia || '');
                const classroom = group.providers?.google_classroom?.external_name || group.providers?.google_classroom?.external_context_id || 'Non configurata';
                const github = group.providers?.github_classroom?.external_name || group.providers?.github_classroom?.external_context_id || 'Non configurata';
                return `<li><strong>${escapeWizardHtml(className || 'Classe')}</strong> · ${escapeWizardHtml(subjectName || 'Materia')}<br><small>Google: ${escapeWizardHtml(classroom.trim())} · GitHub: ${escapeWizardHtml(github.trim())}</small></li>`;
            }).join('');
            html += `
                <div class="card mb-3">
                    <div class="card-header" style="background-color: #fd7e14; color: white;">
                        <h6 class="mb-0"><i class="bi bi-people"></i> Classi e integrazioni</h6>
                    </div>
                    <div class="card-body">
                        <p>${classiCount > 0 ? classiCount + ' classe/i' : 'Nessuna classe assegnata'}</p>
                        ${classiSummary ? `<ul class="mb-0">${classiSummary}</ul>` : ''}
                    </div>
                </div>
            `;

            // Materiali
            const materialiCount = document.querySelectorAll('input[name="materiali_nome[]"]').length;
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-folder-fill"></i> Materiali Didattici</h6>
                    </div>
                    <div class="card-body">
                        <p>${materialiCount > 0 ? materialiCount + ' materiale/i' : 'Nessun materiale aggiunto'}</p>
                    </div>
                </div>
            `;

            // Obiettivi
            const obiettiviCount = document.querySelectorAll('textarea[name="obiettivi_descrizione[]"]').length;
            html += `
                <div class="card mb-3">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-bullseye"></i> Obiettivi Didattici</h6>
                    </div>
                    <div class="card-body">
                        <p>${obiettiviCount > 0 ? obiettiviCount + ' obiettivo/i' : 'Nessun obiettivo definito'}</p>
                    </div>
                </div>
            `;

            // Test
            const testCount = document.querySelectorAll('input[name="test_nome[]"]').length;
            html += `
                <div class="card mb-3">
                    <div class="card-header" style="background-color: #6f42c1; color: white;">
                        <h6 class="mb-0"><i class="bi bi-clipboard-check"></i> Test e Valutazioni</h6>
                    </div>
                    <div class="card-body">
                        <p>${testCount > 0 ? testCount + ' test' : 'Nessun test configurato'}</p>
                    </div>
                </div>
            `;

            // Domande
            const domandeCount = document.querySelectorAll('input[name="domanda_testo[]"]').length;
            const domandeTot = domandeCount + domandeTempCount;
            html += `
                <div class="card mb-3">
                    <div class="card-header" style="background-color: #d63384; color: white;">
                        <h6 class="mb-0"><i class="bi bi-question-circle"></i> Domande per Interrogazioni</h6>
                    </div>
                    <div class="card-body">
                        <p>${domandeTot > 0 ? domandeTot + ' domanda/e' : 'Nessuna domanda inserita/importata'}</p>
                        ${domandeTempCount > 0 ? `<small class="text-muted">Include ${domandeTempCount} domanda/e importate sull'UDA temporanea.</small>` : ''}
                    </div>
                </div>
            `;

            container.innerHTML = html;
        }

        async function refreshTempQuestionsCount() {
            if (!tempUdaId) return domandeTempCount;
            try {
                const res = await fetch(`ajax_domande_temp_count.php?id=${encodeURIComponent(tempUdaId)}`);
                const data = await res.json();
                if (data && data.success) {
                    domandeTempCount = data.count || 0;
                }
            } catch (e) {
                console.warn('Impossibile aggiornare il conteggio domande temporanee', e);
            }
            return domandeTempCount;
        }

        async function refreshImportedQuestions() {
            if (!tempUdaId) return;
            const section = document.getElementById('imported-questions-section');
            const list = document.getElementById('imported-questions-list');
            const countBadge = document.getElementById('imported-questions-count');
            if (!section || !list || !countBadge) return;
            try {
                const res = await fetch(`ajax_get_temp_questions.php?id=${encodeURIComponent(tempUdaId)}`);
                const data = await res.json().catch(() => null);
                if (!data || !data.success) {
                    throw new Error((data && data.error) ? data.error : 'Errore caricamento domande importate');
                }
                const questions = Array.isArray(data.questions) ? data.questions : [];
                domandeTempCount = (typeof data.count === 'number') ? data.count : questions.length;
                list.innerHTML = '';
                if (questions.length === 0) {
                    section.classList.add('d-none');
                    return;
                }
                questions.forEach((q) => {
                    const item = document.createElement('div');
                    item.className = 'list-group-item py-2';
                    const tipo = (q.tipo_domanda === 'multipla') ? 'Risposta multipla' : 'Risposta aperta';
                    const difficolta = Number(q.difficolta || 0);
                    const stelle = (difficolta > 0) ? '★'.repeat(Math.min(Math.max(difficolta, 1), 5)) : '';
                    const argomento = String(q.argomento || '').trim() || 'Senza argomento';
                    item.innerHTML = `
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <div class="min-w-0">
                                <div class="fw-semibold text-break">${escapeWizardHtml(q.domanda || '(domanda vuota)')}</div>
                                <div class="small text-muted">${escapeWizardHtml(argomento)} · ${escapeWizardHtml(tipo)}${stelle ? ' · ' + escapeWizardHtml(stelle) : ''}</div>
                            </div>
                            <span class="badge bg-info text-dark small text-nowrap">importata</span>
                        </div>`;
                    list.appendChild(item);
                });
                countBadge.textContent = String(questions.length);
                section.classList.remove('d-none');
            } catch (e) {
                console.warn('Impossibile aggiornare le domande importate', e);
            }
        }

        window.addEventListener('focus', () => {
            refreshTempQuestionsCount().then(() => {
                if (currentStep === 7) generateRiepilogo();
            });
            refreshImportedQuestions();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState === 'visible') {
                refreshTempQuestionsCount().then(() => {
                    if (currentStep === 7) generateRiepilogo();
                });
                refreshImportedQuestions();
            }
        });

        window.addEventListener('uda-questions-imported', () => {
            refreshTempQuestionsCount().then(() => {
                if (currentStep === 7) generateRiepilogo();
            });
            refreshImportedQuestions();
        });

        initWizardQuestionEditor();
    </script>
</body>
</html>
