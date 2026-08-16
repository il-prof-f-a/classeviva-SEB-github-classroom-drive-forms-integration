<?php
/**
 * Creazione Assignment GitHub
 * Workflow guidato per creare assignment GitHub e collegarli alla UDA
 */

require_once '../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Models\UDA;
use App\Integration\GitHubIntegration;

$pageTitle = "Crea Assignment GitHub";

// Verifica parametro UDA
$idUda = $_GET['id_uda'] ?? '';
if (!$idUda) {
    header('Location: index.php');
    exit;
}

// Inizializza servizi
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$github = new GitHubIntegration($config);
$mappingService = new ProviderNeutralMappingService($dbAdapter, (string)($_SESSION['user_id'] ?? 'system'));

// Carica UDA direttamente dal DatabaseAdapter
$udaData = $dbAdapter->findUDAById($idUda);
if (!$udaData) {
    header('Location: index.php');
    exit;
}
$uda = UDA::fromArray($udaData);

// Carica token GitHub da sessione
$github->loadTokenFromSession();
$isAuthenticated = $github->isAuthenticated();

$successMessage = null;
$errorMessage = null;
$step = $_GET['step'] ?? 'form';
$createdTestId = trim((string)($_GET['test_id'] ?? ''));

// Flash success (post-redirect-get)
if (!empty($_SESSION['github_assignment_success_message'])) {
    $successMessage = (string)$_SESSION['github_assignment_success_message'];
    unset($_SESSION['github_assignment_success_message']);
}
if (!empty($_SESSION['github_assignment_created_test_id']) && $createdTestId === '') {
    $createdTestId = (string)$_SESSION['github_assignment_created_test_id'];
}

// Carica repository templates
$templates = $dbAdapter->findAll('GITHUB_REPO_TEMPLATES');
$activeTemplates = array_filter($templates, function($t) {
    return $t['attivo'] === 'si';
});

// Carica mappature GitHub Classroom (provider-neutral, da GRUPPI_INTEGRAZIONI)
$githubMappings = $mappingService->listGithubClassroomMappings();

// Step 1: Form preparazione dati
$formData = $_SESSION['github_assignment_form'] ?? [];

// Step 2: Salvataggio dati e preparazione istruzioni
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'prepare') {
    try {
        $assignmentName = trim($_POST['assignment_name'] ?? '');
        $assignmentType = $_POST['assignment_type'] ?? 'individual';
        $tipoTest = $_POST['tipo_test'] ?? 'altro';
        $templateId = $_POST['template_id'] ?? '';
        $visibilita = $_POST['visibilita'] ?? 'private';
        $deadline = $_POST['deadline'] ?? '';
        $descrizione = trim($_POST['descrizione'] ?? '');
        $classroomMappingId = $_POST['classroom_mapping_id'] ?? '';

        if (!$assignmentName) {
            throw new Exception("Il titolo dell'assignment è obbligatorio");
        }

        if (!$classroomMappingId) {
            throw new Exception("Seleziona un GitHub Classroom");
        }
        if (!in_array($tipoTest, ['prerequisiti', 'intermedio', 'finale', 'altro'], true)) {
            $tipoTest = 'altro';
        }

        // Trova template selezionato
        $selectedTemplate = null;
        if ($templateId) {
            foreach ($activeTemplates as $tmpl) {
                if ($tmpl['id_template'] === $templateId) {
                    $selectedTemplate = $tmpl;
                    break;
                }
            }
        }

        // Trova mapping classroom
        $selectedClassroom = null;
        foreach ($githubMappings as $mapping) {
            if ($mapping['id_mapping'] === $classroomMappingId) {
                $selectedClassroom = $mapping;
                break;
            }
        }

        // Salva dati in sessione per Step 2
        $_SESSION['github_assignment_form'] = [
            'assignment_name' => $assignmentName,
            'assignment_type' => $assignmentType,
            'tipo_test' => $tipoTest,
            'template_id' => $templateId,
            'template' => $selectedTemplate,
            'visibilita' => $visibilita,
            'deadline' => $deadline,
            'descrizione' => $descrizione,
            'classroom_mapping_id' => $classroomMappingId,
            'classroom' => $selectedClassroom,
            'id_uda' => $idUda,
            'uda_titolo' => $uda->titolo
        ];

        $step = 'instructions';

    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

// Step 3: Salvataggio link assignment creato
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_assignment') {
    try {
        $managementUrl = trim($_POST['management_url'] ?? '');
        $invitationUrl = trim($_POST['invitation_url'] ?? '');

        if (!$managementUrl) {
            throw new Exception("L'URL di gestione dell'assignment è obbligatorio");
        }

        // Valida URL
        if (!preg_match('#^https://classroom\.github\.com/#', $managementUrl)) {
            throw new Exception("URL non valido. Deve iniziare con https://classroom.github.com/");
        }

        $formData = $_SESSION['github_assignment_form'];

        // Estrai classroom_id e assignment_id dall'URL di gestione
        // URL tipo: https://classroom.github.com/classrooms/123456/assignments/789012
        $githubAssignmentId = '';
        $extractedClassroomId = '';

        if (preg_match('#classroom\.github\.com/classrooms/(\d+)/assignments/(\d+)#', $managementUrl, $matches)) {
            $extractedClassroomId = $matches[1];
            $githubAssignmentId = $matches[2];
        }

        // Link studenti (invitation) e link docente (management)
        $invitationLink = $invitationUrl;
        if (!$invitationLink && $githubAssignmentId && $isAuthenticated) {
            try {
                $assignment = $github->getAssignment($githubAssignmentId);
                $invitationLink = $assignment['invite_link'] ?? '';
            } catch (Exception $e) {
                // fallback: richiedi input manuale
                $invitationLink = '';
            }
        }
        if (!$invitationLink) {
            throw new Exception("Inserisci anche il link studenti (invitation link) oppure abilita l'autenticazione per recuperarlo via API.");
        }
        if (!preg_match('#^https://classroom\.github\.com/a/#', $invitationLink)) {
            throw new Exception("Link studenti non valido. Deve essere tipo https://classroom.github.com/a/XXXXXXX");
        }

        // Crea record assignment
        $assignmentId = 'GHAS_' . uniqid();
        $newAssignment = [
            'id_assignment' => $assignmentId,
            'id_uda' => $formData['id_uda'],
            'id_classroom_map' => $formData['classroom_mapping_id'],
            'github_assignment_id' => $githubAssignmentId,
            'assignment_name' => $formData['assignment_name'],
            'assignment_type' => $formData['assignment_type'],
            'invitation_link' => $invitationLink,
            'slug' => '',
            'deadline' => $formData['deadline'] ?: null,
            'starter_code_url' => $formData['template']['url_repository'] ?? '',
            'max_teams' => $formData['assignment_type'] === 'group' ? 50 : 0,
            'max_members' => $formData['assignment_type'] === 'group' ? 5 : 1,
            'auto_grading_config' => '',
            'pubblicato_gc' => 'no',
            'data_creazione' => date('d/m/Y H:i:s'),
            'data_pubblicazione' => null,
            'stato' => 'attivo',
            'note' => $formData['descrizione']
        ];

        $dbAdapter->insertRow('GITHUB_ASSIGNMENTS', $newAssignment);

        // Crea TEST collegato (piattaforma GitHub Classroom)
        $testId = 'TEST_' . uniqid();
        $newTest = [
            'id_test' => $testId,
            'id_uda' => $formData['id_uda'],
            'tipo_test' => $formData['tipo_test'] ?? 'altro',
            'nome' => $formData['assignment_name'],
            'descrizione' => 'Assignment GitHub Classroom: ' . $formData['descrizione'],
            'piattaforma' => 'github',
            'url' => $invitationLink,
            'url_studenti' => $invitationLink,
            'url_docente' => $managementUrl,
            'url_assignment_student' => $invitationLink,
            'url_assignment_teacher' => $managementUrl,
            'github_classroom_id' => $extractedClassroomId ?: ($formData['classroom_id'] ?? ''),
            'github_assignment_id' => $githubAssignmentId,
            'punteggio_max' => 100,
            'soglia_sufficienza' => 60,
            'data_creazione' => date('d/m/Y'),
            'data_somministrazione' => $formData['deadline'] ?: '',
            'pubblicato' => 'NO',
            'risultati_importati' => 'NO',
            'note' => $formData['descrizione'],
            'id_esterno' => $githubAssignmentId,
            'repo_default_branch' => ''
        ];
        $dbAdapter->insertRow('TEST', $newTest);

        // Pulisci sessione
        unset($_SESSION['github_assignment_form']);

        $successMessage = "Assignment GitHub creato e registrato nei Test della UDA.";

        // PRG: evita doppio inserimento su refresh e porta direttamente alla pagina di review del test appena creato
        $_SESSION['github_assignment_success_message'] = $successMessage;
        $_SESSION['github_assignment_created_test_id'] = $testId;
        header('Location: github_assignment_create.php?id_uda=' . urlencode((string)$idUda) . '&step=success&test_id=' . urlencode((string)$testId));
        exit;

    } catch (Exception $e) {
        $errorMessage = "Errore nel salvataggio: " . $e->getMessage();
        $step = 'instructions';
    }
}

// Genera slug suggerito
function generateSlug($title) {
    $slug = strtolower($title);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim($slug, '-');
    return $slug;
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .step-indicator {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2rem;
        }
        .step-item {
            flex: 1;
            text-align: center;
            padding: 1rem;
            position: relative;
        }
        .step-item:not(:last-child)::after {
            content: '';
            position: absolute;
            top: 2rem;
            right: -50%;
            width: 100%;
            height: 2px;
            background: #dee2e6;
        }
        .step-item.active {
            color: #0d6efd;
            font-weight: bold;
        }
        .step-item.active::after {
            background: #0d6efd;
        }
        .step-item.completed {
            color: #198754;
        }
        .step-item.completed::after {
            background: #198754;
        }
        .instruction-box {
            background: #f8f9fa;
            border-left: 4px solid #0d6efd;
            padding: 1.5rem;
            margin-bottom: 1rem;
        }
        .code-block {
            background: #2b2b2b;
            color: #f8f8f2;
            padding: 1rem;
            border-radius: 0.375rem;
            font-family: 'Courier New', monospace;
            margin: 1rem 0;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = $pageTitle ?? 'Assignment GitHub';
    ob_start();
    ?>
    <a href="uda_view.php?id=<?= urlencode($idUda) ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left"></i> Torna alla UDA
    </a>
    <?php
    $headerActions = ob_get_clean();
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container mt-4">
<!-- Step Indicator -->
    <div class="step-indicator">
        <div class="step-item <?= $step === 'form' ? 'active' : ($step !== 'form' ? 'completed' : '') ?>">
            <div class="step-number fs-3"><i class="bi bi-1-circle"></i></div>
            <div>Prepara Dati</div>
        </div>
        <div class="step-item <?= $step === 'instructions' ? 'active' : ($step === 'success' ? 'completed' : '') ?>">
            <div class="step-number fs-3"><i class="bi bi-2-circle"></i></div>
            <div>Crea su GitHub</div>
        </div>
        <div class="step-item <?= $step === 'success' ? 'active' : '' ?>">
            <div class="step-number fs-3"><i class="bi bi-3-circle"></i></div>
            <div>Completa</div>
        </div>
    </div>

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

    <!-- Contesto UDA -->
    <div class="alert alert-info mb-4">
        <h5><i class="bi bi-info-circle"></i> UDA: <?= htmlspecialchars($uda->titolo) ?></h5>
        <p class="mb-0">
            Stai creando un assignment GitHub Classroom che sarà automaticamente collegato a questa UDA.
        </p>
    </div>

    <?php if (!$isAuthenticated): ?>
        <div class="alert alert-warning">
            <h5><i class="bi bi-exclamation-triangle"></i> Autenticazione Richiesta</h5>
            <p>Per creare assignment GitHub è necessario autenticarsi con il tuo account GitHub.</p>
	            <a href="<?= $github->getAuthorizationUrl(null, $_SERVER['REQUEST_URI'] ?? null) ?>" class="btn btn-dark">
	                <i class="bi bi-github"></i> Autentica con GitHub
	            </a>
        </div>
    <?php endif; ?>

    <?php if (empty($githubMappings)): ?>
        <div class="alert alert-warning">
            <h5><i class="bi bi-exclamation-triangle"></i> Nessun GitHub Classroom Configurato</h5>
            <p>Prima di creare un assignment, devi configurare almeno un GitHub Classroom.</p>
            <a href="github_classroom_mapping.php" class="btn btn-primary">
                <i class="bi bi-gear"></i> Configura GitHub Classroom
            </a>
        </div>
    <?php endif; ?>

    <?php if ($step === 'form' && $isAuthenticated && !empty($githubMappings)): ?>
        <!-- Step 1: Form Preparazione Dati -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-pencil-square"></i> Step 1: Prepara i Dati dell'Assignment</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="prepare">

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Titolo Assignment *</label>
                            <input type="text" name="assignment_name" class="form-control" required
                                   placeholder="Es: Progetto Spring Boot - Gestione Utenti"
                                   value="<?= htmlspecialchars($formData['assignment_name'] ?? '') ?>">
                            <small class="form-text text-muted">
                                Sarà visibile agli studenti su GitHub Classroom
                            </small>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tipo Assignment *</label>
                            <select name="assignment_type" class="form-select" required>
                                <option value="individual" <?= ($formData['assignment_type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>
                                    Individuale
                                </option>
                                <option value="group" <?= ($formData['assignment_type'] ?? '') === 'group' ? 'selected' : '' ?>>
                                    Di Gruppo
                                </option>
                            </select>
                        </div>

                        <div class="col-md-3 mb-3">
                            <label class="form-label">Tipo Test *</label>
                            <select name="tipo_test" class="form-select" required>
                                <option value="prerequisiti" <?= ($formData['tipo_test'] ?? '') === 'prerequisiti' ? 'selected' : '' ?>>Prerequisiti</option>
                                <option value="intermedio" <?= ($formData['tipo_test'] ?? '') === 'intermedio' ? 'selected' : '' ?>>Intermedio</option>
                                <option value="finale" <?= ($formData['tipo_test'] ?? '') === 'finale' ? 'selected' : '' ?>>Finale</option>
                                <option value="altro" <?= ($formData['tipo_test'] ?? '') === 'altro' ? 'selected' : '' ?>>Altro</option>
                            </select>
                            <small class="form-text text-muted">Servirà per classificare il test nella UDA.</small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">GitHub Classroom *</label>
                            <select name="classroom_mapping_id" class="form-select" required>
                                <option value="">-- Seleziona --</option>
                                <?php foreach ($githubMappings as $mapping): ?>
                                    <option value="<?= htmlspecialchars($mapping['id_mapping']) ?>"
                                            <?= ($formData['classroom_mapping_id'] ?? '') === $mapping['id_mapping'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($mapping['classroom_name']) ?>
                                        (<?= htmlspecialchars($mapping['github_classroom_id'] ?? '') ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Repository Template</label>
                            <select name="template_id" class="form-select">
                                <option value="">-- Nessuno (repository vuoto) --</option>
                                <?php foreach ($activeTemplates as $tmpl): ?>
                                    <option value="<?= htmlspecialchars($tmpl['id_template']) ?>"
                                            <?= ($formData['template_id'] ?? '') === $tmpl['id_template'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($tmpl['nome']) ?>
                                        <?php if ($tmpl['linguaggio']): ?>
                                            (<?= htmlspecialchars($tmpl['linguaggio']) ?>)
                                        <?php endif; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <small class="form-text text-muted">
                                Codice starter che gli studenti riceveranno
                            </small>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label">Visibilità Repository Studenti</label>
                            <select name="visibilita" class="form-select">
                                <option value="private" <?= ($formData['visibilita'] ?? 'private') === 'private' ? 'selected' : '' ?>>
                                    Private (consigliato)
                                </option>
                                <option value="public" <?= ($formData['visibilita'] ?? '') === 'public' ? 'selected' : '' ?>>
                                    Public
                                </option>
                            </select>
                        </div>

                        <div class="col-md-6 mb-3">
                            <label class="form-label">Deadline (opzionale)</label>
                            <input type="datetime-local" name="deadline" class="form-control"
                                   value="<?= htmlspecialchars($formData['deadline'] ?? '') ?>">
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Descrizione/Istruzioni</label>
                        <textarea name="descrizione" class="form-control" rows="4"
                                  placeholder="Descrivi cosa devono fare gli studenti..."><?= htmlspecialchars($formData['descrizione'] ?? '') ?></textarea>
                    </div>

                    <?php if (empty($activeTemplates)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            Nessun template disponibile.
                            <a href="github_repo_templates.php" target="_blank">Aggiungi repository template</a>
                            per avere codice starter negli assignment.
                        </div>
                    <?php endif; ?>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-right"></i> Procedi allo Step 2
                        </button>
                        <a href="uda_view.php?id=<?= urlencode($idUda) ?>" class="btn btn-outline-secondary">
                            <i class="bi bi-x-circle"></i> Annulla
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($step === 'instructions'): ?>
        <?php
        $formData = $_SESSION['github_assignment_form'];
        $suggestedSlug = generateSlug($formData['assignment_name']);
        ?>
        <!-- Step 2: Istruzioni Creazione su GitHub -->
        <div class="card mb-4">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-list-check"></i> Step 2: Crea l'Assignment su GitHub Classroom</h5>
            </div>
            <div class="card-body">
                <div class="alert alert-warning">
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Importante:</strong> Segui attentamente le istruzioni qui sotto per creare l'assignment su GitHub Classroom.
                    Al termine, incolla l'URL dell'assignment nel form sotto.
                </div>

                <div class="instruction-box">
                    <h6><i class="bi bi-1-circle-fill"></i> Accedi a GitHub Classroom</h6>
                    <p>Vai su: <a href="https://classroom.github.com/classrooms" target="_blank" class="fw-bold">
                        https://classroom.github.com/classrooms
                    </a></p>
                    <p class="mb-0">Seleziona il classroom: <strong><?= htmlspecialchars($formData['classroom']['classroom_name']) ?></strong></p>
                </div>

                <div class="instruction-box">
                    <h6><i class="bi bi-2-circle-fill"></i> Crea Nuovo Assignment</h6>
                    <ol class="mb-0">
                        <li>Clicca su "New assignment"</li>
                        <li>Scegli tipo: <strong><?= $formData['assignment_type'] === 'individual' ? 'Individual' : 'Group' ?> assignment</strong></li>
                        <li>Clicca "Continue"</li>
                    </ol>
                </div>

                <div class="instruction-box">
                    <h6><i class="bi bi-3-circle-fill"></i> Configura Assignment</h6>
                    <p><strong>Copia e incolla questi valori:</strong></p>
                    <table class="table table-sm table-bordered bg-white">
                        <tr>
                            <th width="200">Assignment title:</th>
                            <td>
                                <code><?= htmlspecialchars($formData['assignment_name']) ?></code>
                                <button class="btn btn-sm btn-outline-secondary float-end" onclick="copyToClipboard('<?= htmlspecialchars($formData['assignment_name']) ?>')">
                                    <i class="bi bi-clipboard"></i> Copia
                                </button>
                            </td>
                        </tr>
                        <?php if ($formData['deadline']): ?>
                        <tr>
                            <th>Deadline:</th>
                            <td><?= date('Y-m-d H:i', strtotime($formData['deadline'])) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($formData['template']): ?>
                        <tr>
                            <th>Template repository:</th>
                            <td>
                                <code><?= htmlspecialchars($formData['template']['url_repository']) ?></code>
                                <button class="btn btn-sm btn-outline-secondary float-end" onclick="copyToClipboard('<?= htmlspecialchars($formData['template']['url_repository']) ?>')">
                                    <i class="bi bi-clipboard"></i> Copia
                                </button>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Repository visibility:</th>
                            <td><strong><?= ucfirst($formData['visibilita']) ?></strong></td>
                        </tr>
                    </table>
                </div>

                <div class="instruction-box">
                    <h6><i class="bi bi-4-circle-fill"></i> Completa la Creazione</h6>
                    <ol class="mb-0">
                        <li>Aggiungi la descrizione nel campo "Instructions for students" (opzionale)</li>
                        <li>Configura altre opzioni secondo necessità</li>
                        <li>Clicca su "Create assignment"</li>
                        <li><strong>Copia l'URL dell'assignment</strong> (sarà tipo: https://classroom.github.com/a/XXXXXXX)</li>
                    </ol>
                </div>

                <div class="alert alert-info mt-4">
                    <h6><i class="bi bi-lightbulb"></i> Suggerimento</h6>
                    <p class="mb-0">
                        L'URL dell'assignment si trova nella pagina dell'assignment su GitHub Classroom.
                        È il link che gli studenti useranno per accettare l'assignment.
                    </p>
                </div>
            </div>
        </div>

        <!-- Form Incolla URL -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-link-45deg"></i> Incolla i link dell'Assignment Creato</h5>
            </div>
            <div class="card-body">
                <form method="POST" action="">
                    <input type="hidden" name="action" value="save_assignment">

                    <div class="alert alert-info">
                        <h6><i class="bi bi-info-circle"></i> Informazioni</h6>
                        <p class="mb-2">Dopo aver creato l'assignment su GitHub Classroom:</p>
                        <ol class="mb-0">
                            <li>Clicca sull'assignment appena creato per aprirlo</li>
                            <li>Copia il link studenti (invite link) e il link docente (management)</li>
                            <li>Incollali nei campi qui sotto</li>
                        </ol>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">Link Studenti (Invite Link) *</label>
                        <input type="url" name="invitation_url" class="form-control" required
                               placeholder="https://classroom.github.com/a/XXXXXXX"
                               pattern="https://classroom\.github\.com/a/.+">
                        <small class="form-text text-muted">
                            Link che gli studenti useranno per accettare l'assignment.
                        </small>
                    </div>

                    <div class="mb-3">
                        <label class="form-label">URL Gestione Assignment (Docente) *</label>
                        <input type="url" name="management_url" class="form-control" required
                               placeholder="https://classroom.github.com/classrooms/123456/assignments/789012"
                               pattern="https://classroom\.github\.com/.+">
                        <small class="form-text text-muted">
                            <i class="bi bi-link-45deg"></i>
                            URL della pagina di gestione dell'assignment su GitHub Classroom.
                        </small>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Completa e Collega alla UDA
                        </button>
                        <a href="?id_uda=<?= urlencode($idUda) ?>&step=form" class="btn btn-outline-secondary">
                            <i class="bi bi-arrow-left"></i> Torna Indietro
                        </a>
                    </div>
                </form>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($step === 'success'): ?>
        <!-- Step 3: Successo -->
        <div class="card border-success">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-check-circle"></i> Assignment Creato con Successo!</h5>
            </div>
            <div class="card-body text-center py-5">
                <div class="mb-4">
                    <i class="bi bi-check-circle text-success" style="font-size: 5rem;"></i>
                </div>
                <h4 class="mb-3">L'assignment è stato creato e collegato alla UDA</h4>
                <p class="text-muted mb-4">
                    Il compito GitHub è stato aggiunto ai <strong>Test</strong> della UDA.
                </p>
                <div class="d-flex gap-2 justify-content-center">
                    <a href="uda_view.php?id=<?= urlencode($idUda) ?>" class="btn btn-primary">
                        <i class="bi bi-eye"></i> Visualizza UDA
                    </a>
                    <a href="uda_tests.php?id=<?= urlencode($idUda) ?>" class="btn btn-success">
                        <i class="bi bi-clipboard-check"></i> Vai ai Test
                    </a>
                    <?php if ($createdTestId !== ''): ?>
                        <a href="github_assignment_review.php?test_id=<?= urlencode($createdTestId) ?>" class="btn btn-outline-primary">
                            <i class="bi bi-list"></i> Gestione Assignment
                        </a>
                    <?php else: ?>
                        <a href="github_assignments.php" class="btn btn-outline-primary">
                            <i class="bi bi-list"></i> Gestione Assignment
                        </a>
                    <?php endif; ?>
                    <a href="?id_uda=<?= urlencode($idUda) ?>&step=form" class="btn btn-outline-secondary">
                        <i class="bi bi-plus-circle"></i> Crea Altro Assignment
                    </a>
                </div>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
function copyToClipboard(text) {
    navigator.clipboard.writeText(text).then(function() {
        // Mostra feedback
        const btn = event.target.closest('button');
        const originalHTML = btn.innerHTML;
        btn.innerHTML = '<i class="bi bi-check"></i> Copiato!';
        btn.classList.remove('btn-outline-secondary');
        btn.classList.add('btn-success');
        setTimeout(function() {
            btn.innerHTML = originalHTML;
            btn.classList.remove('btn-success');
            btn.classList.add('btn-outline-secondary');
        }, 2000);
    });
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
