<?php

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;
use App\Utils\UdaMetadataHelper;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);

$error_message = null;
$success_message = null;
$classi = [];
$classiAssegnate = [];

$cvConfig = $config['classeviva'] ?? [];
$cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvTokenError = $cvConfig['token_error'] ?? null;
$cvEnabled = !empty($cvConfig['enabled']);
$cvHasToken = !empty($cvTokenPayload['token']);
$cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;
$cvTokenNotice = null;

if (!$cvReady) {
    if (!$cvEnabled) {
        $cvTokenNotice = 'Integrazione ClasseViva disabilitata. Abilitala per sincronizzare classi e materie.';
    } elseif (!$cvHasToken) {
        $cvTokenNotice = 'Token ClasseViva mancante. Rigeneralo dalla pagina Integrazioni.';
    } else {
        $cvTokenNotice = $cvTokenError
            ? "Token ClasseViva non valido: {$cvTokenError}"
            : 'Token ClasseViva non valido o scaduto. Rigeneralo dalla pagina Integrazioni.';
    }
}

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
    $classiAssegnate = $udaComplete['classi_assegnate'];
} catch (Exception $e) {
    $error_message = "Errore: " . $e->getMessage();
}

// Recupera classi + materie da ClasseViva (se configurato)
$classeVivaEnabled = false;
$classiMaterie = []; // Array strutturato: [class_id => ['class_data' => ..., 'subjects' => [...]]]
if ($cvReady) {
    try {
        $classeVivaAPI = new ClasseVivaAPI($config);
        $classi = $classeVivaAPI->getClassesWithTeacherSubjects();
        $classeVivaEnabled = true;
        $classiMaterie = $classi;
    } catch (Exception $e) {
        $cvTokenNotice = $cvTokenNotice ?? ('Impossibile recuperare le classi ClasseViva: ' . $e->getMessage());
    }
}

// Gestione POST per rimozione assegnazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    try {
        $assegnId = $_POST['id_assegnazione'] ?? null;

        if (!$assegnId) {
            throw new Exception("ID assegnazione mancante");
        }

        $deleted = $dbAdapter->deleteRow('CLASSI_ASSEGNATE', $assegnId, 'id_assegnazione');

        if ($deleted) {
            $udaManager->updateUDA($udaId, [
                'classi_target' => UdaMetadataHelper::classTargetFromAssignments($dbAdapter->findClassiAssegnate($udaId))
            ]);
            header("Location: uda_assign.php?id=" . urlencode($udaId) . "&msg=remove_success");
            exit;
        } else {
            throw new Exception("Impossibile rimuovere l'assegnazione");
        }

    } catch (Exception $e) {
        $error_message = "Errore durante la rimozione: " . $e->getMessage();
    }
}

// Gestione POST per assegnazione classi+materie
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_classes') {
    try {
        // Nuovo formato: 'assignments' è un array di "classId|subjectId"
        $selectedAssignments = $_POST['assignments'] ?? [];

        if (empty($selectedAssignments)) {
            throw new Exception("Seleziona almeno una combinazione Classe+Materia");
        }

        $assigned = 0;
        $assignedKeys = [];
        foreach ($classiAssegnate as $ca) {
            $keyClass = (string)($ca['id_classe'] ?? '');
            $keySubject = (string)($ca['id_materia_cv'] ?? '');
            if ($keyClass !== '' || $keySubject !== '') {
                $assignedKeys[$keyClass . '|' . $keySubject] = true;
            }
        }
        foreach ($selectedAssignments as $assignment) {
            // Parse: "classId|subjectId"
            $parts = explode('|', $assignment);
            if (count($parts) !== 2) {
                continue; // Formato invalido, salta
            }

            $classId = $parts[0];
            $subjectId = $parts[1];

            // Verifica se combinazione già assegnata
            $assignmentKey = $classId . '|' . $subjectId;
            if (isset($assignedKeys[$assignmentKey])) {
                continue; // Salta se già assegnata
            }

            // Recupera nomi da POST
            $className = $_POST['class_name_' . $assignment] ?? 'Classe ' . $classId;
            $subjectName = $_POST['subject_name_' . $assignment] ?? 'Materia ' . $subjectId;

            // Date specifiche per questa assegnazione (se fornite)
            $dataInizio = $_POST['data_inizio_' . $assignment] ?? ($uda->data_inizio ?? null);
            $dataFine = $_POST['data_fine_' . $assignment] ?? ($uda->data_fine ?? null);
            $note = $_POST['note_' . $assignment] ?? '';

            // Crea assegnazione con CLASSE + MATERIA
            $assegnId = 'ASSEGN_' . uniqid();
            $assegnData = [
                'id_assegnazione' => $assegnId,
                'id_uda' => $udaId,
                'id_classe' => $classId,              // ID Classe ClasseViva
                'nome_classe' => $className,
                'id_materia_cv' => $subjectId,        // ID Materia ClasseViva (NUOVO!)
                'nome_materia' => $subjectName,       // Nome Materia (cache) (NUOVO!)
                'data_assegnazione' => date('Y-m-d H:i:s'),
                'data_inizio' => $dataInizio,
                'data_fine' => $dataFine,
                'note' => $note,
                'pubblicato_classroom' => 0,
                'classroom_url' => '',
                'stato' => 'assegnata'
            ];

            $dbAdapter->insertRow('CLASSI_ASSEGNATE', $assegnData);
            $assigned++;
            $assignedKeys[$assignmentKey] = true;
        }

        if ($assigned > 0) {
            $udaManager->updateUDA($udaId, [
                'classi_target' => UdaMetadataHelper::classTargetFromAssignments($dbAdapter->findClassiAssegnate($udaId))
            ]);
            header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=assign_success&count=" . $assigned);
            exit;
        } else {
            $error_message = "Nessuna nuova assegnazione creata (potrebbero essere già assegnate)";
        }

    } catch (Exception $e) {
        $error_message = "Errore durante l'assegnazione: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Assegna Classi - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .class-card {
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
            padding: 1rem;
            margin-bottom: 1rem;
            background: #f8f9fa;
        }
        .class-card.selected {
            border-color: #0d6efd;
            background: #e7f1ff;
        }
        .class-card input[type="checkbox"] {
            width: 1.5rem;
            height: 1.5rem;
        }
        .btn-outline-danger:hover {
            transform: scale(1.05);
            transition: transform 0.2s;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = "<i class='bi bi-people'></i> Assegna Classi all'UDA";
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

        <?php if (isset($_GET['msg']) && $_GET['msg'] === 'remove_success'): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> Assegnazione rimossa con successo
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <br>
                <small class="text-muted">
                    Apri la <a href="user_integrations.php#classeviva-section">pagina Integrazioni</a> per risolvere il problema.
                </small>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Classi Già Assegnate -->
            <div class="col-md-12 mb-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Classi Già Assegnate (<?= count($classiAssegnate) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($classiAssegnate)): ?>
                            <p class="text-muted mb-0">Nessuna classe ancora assegnata a questa UDA</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($classiAssegnate as $classe): ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <h6 class="mb-1">
                                                            <?= htmlspecialchars($classe['nome_classe']) ?>
                                                            <?php if (!empty($classe['nome_materia'])): ?>
                                                                <span class="badge bg-primary"><?= htmlspecialchars($classe['nome_materia']) ?></span>
                                                            <?php endif; ?>
                                                        </h6>
                                                        <small class="text-muted">
                                                            <i class="bi bi-calendar-plus"></i> Assegnata il:
                                                            <?= htmlspecialchars(date('d/m/Y', strtotime($classe['data_assegnazione']))) ?>
                                                        </small>
                                                        <?php if (!empty($classe['data_inizio'])): ?>
                                                        <br><small class="text-muted">
                                                            <i class="bi bi-calendar-range"></i>
                                                            <?= htmlspecialchars(date('d/m/Y', strtotime($classe['data_inizio']))) ?>
                                                            <?php if (!empty($classe['data_fine'])): ?>
                                                                - <?= htmlspecialchars(date('d/m/Y', strtotime($classe['data_fine']))) ?>
                                                            <?php endif; ?>
                                                        </small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div class="d-flex align-items-start gap-2">
                                                        <?php if (($classe['pubblicato_classroom'] ?? 0) == 1): ?>
                                                            <span class="badge bg-success">Pubblicata</span>
                                                        <?php else: ?>
                                                            <span class="badge bg-secondary">Non pubblicata</span>
                                                        <?php endif; ?>
                                                        <form method="POST" class="d-inline" onsubmit="return confirm('Sei sicuro di voler rimuovere questa assegnazione?');">
                                                            <input type="hidden" name="action" value="remove_assignment">
                                                            <input type="hidden" name="id_assegnazione" value="<?= htmlspecialchars($classe['id_assegnazione']) ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Rimuovi assegnazione">
                                                                <i class="bi bi-trash"></i>
                                                            </button>
                                                        </form>
                                                    </div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Assegnazione -->
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Assegna a Nuove Classi</h5>
            </div>
            <div class="card-body">
                <?php if (!$classeVivaEnabled): ?>
                    <div class="alert alert-info">
                        <h6 class="alert-heading"><i class="bi bi-info-circle"></i> ClasseViva non configurato</h6>
                        <p class="mb-0">
                            L'integrazione con ClasseViva non è attiva. Puoi comunque assegnare manualmente
                            inserendo <strong>Classe + Materia</strong> per ogni assegnazione.
                        </p>
                        <hr class="my-2">
                        <small class="text-muted">
                            <i class="bi bi-lightbulb"></i>
                            <strong>Nota:</strong> Una UDA deve essere assegnata a una specifica materia in una classe.
                            Se insegni più materie nella stessa classe, crea assegnazioni separate.
                        </small>
                    </div>

                    <!-- Form assegnazione manuale (Classe + Materia) -->
                    <form method="POST" id="assignFormManual">
                        <input type="hidden" name="action" value="assign_classes">

                        <div id="manualClassesContainer">
                            <div class="class-card" id="manual-class-1">
                                <h6 class="text-primary mb-3">Assegnazione #1</h6>
                                <div class="row">
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label"><i class="bi bi-hash"></i> ID Classe *</label>
                                        <input type="text" class="form-control manual-class-id" data-index="1"
                                               placeholder="Es: 3A_INFO" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label"><i class="bi bi-people"></i> Nome Classe *</label>
                                        <input type="text" class="form-control manual-class-name" data-index="1"
                                               placeholder="Es: 3A Informatica" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label"><i class="bi bi-hash"></i> ID Materia *</label>
                                        <input type="text" class="form-control manual-subject-id" data-index="1"
                                               placeholder="Es: MAT_001" required>
                                    </div>
                                    <div class="col-md-3 mb-2">
                                        <label class="form-label"><i class="bi bi-book"></i> Nome Materia *</label>
                                        <input type="text" class="form-control manual-subject-name" data-index="1"
                                               placeholder="Es: Matematica" required>
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Data Inizio</label>
                                        <input type="date" class="form-control manual-date-start" data-index="1"
                                               value="<?= htmlspecialchars($uda->data_inizio ?? '') ?>">
                                    </div>
                                    <div class="col-md-6 mb-2">
                                        <label class="form-label">Data Fine</label>
                                        <input type="date" class="form-control manual-date-end" data-index="1"
                                               value="<?= htmlspecialchars($uda->data_fine ?? '') ?>">
                                    </div>
                                    <div class="col-12 mb-2">
                                        <label class="form-label">Note (opzionale)</label>
                                        <textarea class="form-control manual-notes" data-index="1" rows="2"
                                                  placeholder="Note specifiche per questa assegnazione"></textarea>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <button type="button" class="btn btn-outline-secondary mb-3" onclick="addManualClass()">
                            <i class="bi bi-plus"></i> Aggiungi Altra Assegnazione
                        </button>

                        <div class="d-flex justify-content-end">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Assegna
                            </button>
                        </div>
                    </form>

                <?php else: ?>
                    <!-- Form con classi + materie da ClasseViva -->
                    <form method="POST" id="assignForm">
                        <input type="hidden" name="action" value="assign_classes">

                        <?php if (empty($classiMaterie)): ?>
                            <div class="alert alert-warning">
                                <p class="mb-0">Nessuna classe/materia trovata in ClasseViva. Verifica la configurazione.</p>
                            </div>
                        <?php else: ?>
                            <p class="mb-3"><strong>Seleziona le combinazioni Classe + Materia a cui assegnare questa UDA:</strong></p>
                            <p class="text-muted small mb-4">
                                <i class="bi bi-info-circle"></i> Una UDA deve essere assegnata a una specifica materia in una classe.
                                Se la stessa classe compare più volte, è perché insegni materie diverse in essa.
                            </p>

                            <?php foreach ($classiMaterie as $classe): ?>
                                <?php
                                $classId = $classe['id'] ?? '';
                                $className = $classe['name'] ?? 'Classe ' . $classId;
                                $subjects = $classe['subjects'] ?? [];
                                ?>

                                <?php if (!empty($subjects)): ?>
                                <div class="card mb-3">
                                    <div class="card-header bg-light">
                                        <h6 class="mb-0">
                                            <i class="bi bi-people-fill"></i> <?= htmlspecialchars($className) ?>
                                            <span class="badge bg-secondary"><?= count($subjects) ?> materie</span>
                                        </h6>
                                    </div>
                                    <div class="card-body">
                                        <div class="row">
                                            <?php foreach ($subjects as $subject): ?>
                                                <?php
                                                $subjectId = $subject['id'] ?? $subject['subjectId'] ?? '';
                                                $subjectName = $subject['name'] ?? $subject['subjectName'] ?? 'Materia ' . $subjectId;
                                                $assignmentKey = $classId . '|' . $subjectId;

                                                // Verifica se combinazione già assegnata
                                                $alreadyAssigned = false;
                                                foreach ($classiAssegnate as $ca) {
                                                    if (($ca['id_classe'] ?? '') === $classId && ($ca['id_materia_cv'] ?? '') === $subjectId) {
                                                        $alreadyAssigned = true;
                                                        break;
                                                    }
                                                }
                                                ?>
                                                <div class="col-md-6 mb-3">
                                                    <div class="class-card" id="card-<?= htmlspecialchars($assignmentKey) ?>">
                                                        <div class="form-check">
                                                            <input class="form-check-input" type="checkbox"
                                                                   name="assignments[]" value="<?= htmlspecialchars($assignmentKey) ?>"
                                                                   id="assignment_<?= htmlspecialchars($assignmentKey) ?>"
                                                                   <?= $alreadyAssigned ? 'disabled checked' : '' ?>
                                                                   onchange="toggleAssignmentCard('<?= htmlspecialchars($assignmentKey) ?>')">
                                                            <label class="form-check-label" for="assignment_<?= htmlspecialchars($assignmentKey) ?>">
                                                                <strong><?= htmlspecialchars($subjectName) ?></strong>
                                                                <?php if ($alreadyAssigned): ?>
                                                                    <span class="badge bg-success ms-2">Già assegnata</span>
                                                                <?php endif; ?>
                                                            </label>
                                                        </div>

                                                        <?php if (!$alreadyAssigned): ?>
                                                        <div class="class-details mt-2" id="details-<?= htmlspecialchars($assignmentKey) ?>" style="display: none;">
                                                            <hr class="my-2">
                                                            <!-- Campi nascosti per nomi -->
                                                            <input type="hidden" name="class_name_<?= htmlspecialchars($assignmentKey) ?>"
                                                                   value="<?= htmlspecialchars($className) ?>">
                                                            <input type="hidden" name="subject_name_<?= htmlspecialchars($assignmentKey) ?>"
                                                                   value="<?= htmlspecialchars($subjectName) ?>">

                                                            <div class="row">
                                                                <div class="col-6 mb-2">
                                                                    <label class="form-label small">Data Inizio</label>
                                                                    <input type="date" class="form-control form-control-sm"
                                                                           name="data_inizio_<?= htmlspecialchars($assignmentKey) ?>"
                                                                           value="<?= htmlspecialchars($uda->data_inizio ?? '') ?>">
                                                                </div>
                                                                <div class="col-6 mb-2">
                                                                    <label class="form-label small">Data Fine</label>
                                                                    <input type="date" class="form-control form-control-sm"
                                                                           name="data_fine_<?= htmlspecialchars($assignmentKey) ?>"
                                                                           value="<?= htmlspecialchars($uda->data_fine ?? '') ?>">
                                                                </div>
                                                            </div>
                                                            <div class="mb-2">
                                                                <label class="form-label small">Note (opzionale)</label>
                                                                <textarea class="form-control form-control-sm" rows="2"
                                                                          name="note_<?= htmlspecialchars($assignmentKey) ?>"
                                                                          placeholder="Note specifiche per questa assegnazione"></textarea>
                                                            </div>
                                                        </div>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                            <?php endforeach; ?>
                                        </div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            <?php endforeach; ?>

                            <div class="d-flex justify-content-end mt-3">
                                <button type="submit" class="btn btn-primary btn-lg">
                                    <i class="bi bi-check-circle"></i> Assegna Combinazioni Selezionate
                                </button>
                            </div>
                        <?php endif; ?>
                    </form>
                <?php endif; ?>
            </div>
        </div>

        <!-- Azioni successive -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="card-title">Prossimi Passi</h6>
                <p class="text-muted mb-3">Dopo aver assegnato le classi, puoi:</p>
                <a href="uda_publish.php?id=<?= urlencode($udaId) ?>" class="btn btn-success me-2">
                    <i class="bi bi-cloud-upload"></i> Pubblica su Google Classroom
                </a>
                <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-outline-secondary">
                    <i class="bi bi-eye"></i> Visualizza UDA
                </a>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Toggle visualizzazione dettagli classe+materia (assignment)
        function toggleAssignmentCard(assignmentKey) {
            const checkbox = document.getElementById('assignment_' + assignmentKey);
            const card = document.getElementById('card-' + assignmentKey);
            const details = document.getElementById('details-' + assignmentKey);

            if (checkbox && card && details) {
                if (checkbox.checked) {
                    card.classList.add('selected');
                    details.style.display = 'block';
                } else {
                    card.classList.remove('selected');
                    details.style.display = 'none';
                }
            }
        }

        // Aggiungi assegnazione manuale (classe + materia)
        let manualClassCounter = 1;
        function addManualClass() {
            manualClassCounter++;
            const container = document.getElementById('manualClassesContainer');
            const div = document.createElement('div');
            div.className = 'class-card';
            div.id = 'manual-class-' + manualClassCounter;
            div.innerHTML = `
                <div class="d-flex justify-content-between mb-2">
                    <h6 class="text-primary">Assegnazione #${manualClassCounter}</h6>
                    <button type="button" class="btn btn-sm btn-outline-danger" onclick="removeManualClass(${manualClassCounter})">
                        <i class="bi bi-x-lg"></i> Rimuovi
                    </button>
                </div>
                <div class="row">
                    <div class="col-md-3 mb-2">
                        <label class="form-label"><i class="bi bi-hash"></i> ID Classe *</label>
                        <input type="text" class="form-control manual-class-id" data-index="${manualClassCounter}"
                               placeholder="Es: 3A_INFO" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label"><i class="bi bi-people"></i> Nome Classe *</label>
                        <input type="text" class="form-control manual-class-name" data-index="${manualClassCounter}"
                               placeholder="Es: 3A Informatica" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label"><i class="bi bi-hash"></i> ID Materia *</label>
                        <input type="text" class="form-control manual-subject-id" data-index="${manualClassCounter}"
                               placeholder="Es: MAT_001" required>
                    </div>
                    <div class="col-md-3 mb-2">
                        <label class="form-label"><i class="bi bi-book"></i> Nome Materia *</label>
                        <input type="text" class="form-control manual-subject-name" data-index="${manualClassCounter}"
                               placeholder="Es: Matematica" required>
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Data Inizio</label>
                        <input type="date" class="form-control manual-date-start" data-index="${manualClassCounter}"
                               value="<?= htmlspecialchars($uda->data_inizio ?? '') ?>">
                    </div>
                    <div class="col-md-6 mb-2">
                        <label class="form-label">Data Fine</label>
                        <input type="date" class="form-control manual-date-end" data-index="${manualClassCounter}"
                               value="<?= htmlspecialchars($uda->data_fine ?? '') ?>">
                    </div>
                    <div class="col-12 mb-2">
                        <label class="form-label">Note (opzionale)</label>
                        <textarea class="form-control manual-notes" data-index="${manualClassCounter}" rows="2"
                                  placeholder="Note specifiche per questa assegnazione"></textarea>
                    </div>
                </div>
            `;
            container.appendChild(div);
        }

        function removeManualClass(id) {
            const element = document.getElementById('manual-class-' + id);
            if (element && confirm('Rimuovere questa assegnazione?')) {
                element.remove();
            }
        }

        // Handler per form manuale: converti in formato "assignments[]"
        document.getElementById('assignFormManual')?.addEventListener('submit', function(e) {
            e.preventDefault();

            // Raccogli tutti i dati dai campi
            const classIds = document.querySelectorAll('.manual-class-id');
            const classNames = document.querySelectorAll('.manual-class-name');
            const subjectIds = document.querySelectorAll('.manual-subject-id');
            const subjectNames = document.querySelectorAll('.manual-subject-name');
            const dateStarts = document.querySelectorAll('.manual-date-start');
            const dateEnds = document.querySelectorAll('.manual-date-end');
            const notes = document.querySelectorAll('.manual-notes');

            // Crea hidden inputs in formato corretto
            for (let i = 0; i < classIds.length; i++) {
                const classId = classIds[i].value.trim();
                const className = classNames[i].value.trim();
                const subjectId = subjectIds[i].value.trim();
                const subjectName = subjectNames[i].value.trim();
                const dateStart = dateStarts[i].value;
                const dateEnd = dateEnds[i].value;
                const note = notes[i].value;

                if (!classId || !className || !subjectId || !subjectName) {
                    alert('Compila tutti i campi obbligatori (*)');
                    return;
                }

                const assignmentKey = classId + '|' + subjectId;

                // Crea hidden inputs
                this.insertAdjacentHTML('beforeend', `
                    <input type="hidden" name="assignments[]" value="${assignmentKey}">
                    <input type="hidden" name="class_name_${assignmentKey}" value="${className}">
                    <input type="hidden" name="subject_name_${assignmentKey}" value="${subjectName}">
                    <input type="hidden" name="data_inizio_${assignmentKey}" value="${dateStart}">
                    <input type="hidden" name="data_fine_${assignmentKey}" value="${dateEnd}">
                    <input type="hidden" name="note_${assignmentKey}" value="${note}">
                `);
            }

            // Ora submit il form
            this.submit();
        });
    </script>
</body>
</html>
