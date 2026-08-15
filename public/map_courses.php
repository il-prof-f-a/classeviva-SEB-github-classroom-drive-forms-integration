<?php
/**
 * Mappatura Corsi: Classe-Materia (ClasseViva) → Google Classroom
 *
 * Interfaccia drag&drop per associare coppie Classe-Materia a corsi Google Classroom
 */

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$mappingService = new ProviderNeutralMappingService(
    $dbAdapter,
    (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'))
);
$classeVivaAPI = new ClasseVivaAPI($config);
$googleClassroomAPI = new GoogleClassroomAPI($config);

$errorMessage = null;
$successMessage = null;

// Carica dati ClasseViva (coppie Classe-Materia)
$classiMaterieCV = [];
$errorCV = null;
try {
    $classes = $classeVivaAPI->getClassesWithTeacherSubjects();

    foreach ($classes as $class) {
        $classId = $class['id'] ?? '';
        $className = $class['name'] ?? '';
        $subjects = $class['subjects'] ?? [];

        foreach ($subjects as $subject) {
            $subjectId = $subject['subjectId'] ?? '';
            $subjectDesc = $subject['subjectDesc'] ?? '';

            if (!empty($classId) && !empty($subjectId)) {
                $classiMaterieCV[] = [
                    'id_classe' => $classId,
                    'nome_classe' => $className,
                    'id_materia' => $subjectId,
                    'nome_materia' => $subjectDesc,
                    'display' => "$className - $subjectDesc"
                ];
            }
        }
    }
} catch (Exception $e) {
    $errorCV = $e->getMessage();
}

// Carica corsi Google Classroom
$corsiGC = [];
$errorGC = null;
try {
    $corsiGC = $googleClassroomAPI->getCourses();
} catch (Exception $e) {
    $errorGC = $e->getMessage();
}

// Carica mappature esistenti
$mappatureEsistenti = [];
try {
    $mappature = $mappingService->listGoogleClassroomMappings();
    foreach ($mappature as $map) {
        if (!empty($map['id_classe_cv']) && !empty($map['id_materia_cv'])) {
            $key = $map['id_classe_cv'] . '_' . $map['id_materia_cv'];
            $mappatureEsistenti[$key] = $map;
        }
    }
} catch (Exception $e) {
    // Foglio potrebbe non esistere ancora
}

// Gestione POST - Salvataggio mappatura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mapping') {
    try {
        $idClasseCV = $_POST['id_classe_cv'] ?? '';
        $nomeClasseCV = $_POST['nome_classe_cv'] ?? '';
        $idMateriaCV = $_POST['id_materia_cv'] ?? '';
        $nomeMateriaCV = $_POST['nome_materia_cv'] ?? '';
        $idGoogleClassroom = $_POST['id_google_classroom'] ?? '';
        $nomeGoogleClassroom = $_POST['nome_google_classroom'] ?? '';

        if (empty($idClasseCV) || empty($idMateriaCV) || empty($idGoogleClassroom)) {
            throw new Exception("Dati incompleti per la mappatura");
        }

        // Verifica se mappatura esiste già
        $key = $idClasseCV . '_' . $idMateriaCV;
        $esistente = $mappatureEsistenti[$key] ?? null;

        if ($esistente) {
            // Aggiorna mappatura esistente
            $mappingService->upsertGoogleClassroomMapping([
                'classeviva_class_id' => $idClasseCV,
                'classeviva_class_name' => $nomeClasseCV,
                'classeviva_subject_id' => $idMateriaCV,
                'classeviva_subject_name' => $nomeMateriaCV,
                'google_course_id' => $idGoogleClassroom,
                'google_course_name' => $nomeGoogleClassroom,
            ]);
        } else {
            // Crea nuova mappatura
            $nuovaMappatura = [
                'id_mappatura' => 'MAP_' . uniqid(),
                'id_classe_cv' => $idClasseCV,
                'nome_classe_cv' => $nomeClasseCV,
                'id_materia_cv' => $idMateriaCV,
                'nome_materia_cv' => $nomeMateriaCV,
                'id_google_classroom' => $idGoogleClassroom,
                'nome_google_classroom' => $nomeGoogleClassroom,
                'data_creazione' => date('d/m/Y H:i'),
                'data_ultima_modifica' => date('d/m/Y H:i'),
                'stato' => 'attivo'
            ];

            $mappingService->upsertGoogleClassroomMapping([
                'classeviva_class_id' => $nuovaMappatura['id_classe_cv'],
                'classeviva_class_name' => $nuovaMappatura['nome_classe_cv'],
                'classeviva_subject_id' => $nuovaMappatura['id_materia_cv'],
                'classeviva_subject_name' => $nuovaMappatura['nome_materia_cv'],
                'google_course_id' => $nuovaMappatura['id_google_classroom'],
                'google_course_name' => $nuovaMappatura['nome_google_classroom'],
            ]);
        }

        header("Location: map_courses.php?success=mapped");
        exit;

    } catch (Exception $e) {
        $errorMessage = "Errore salvataggio: " . $e->getMessage();
    }
}

// Gestione POST - Rimozione mappatura
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_mapping') {
    try {
        $idMappatura = $_POST['id_mappatura'] ?? '';

        if (empty($idMappatura)) {
            throw new Exception("ID mappatura non specificato");
        }

        if (!$mappingService->deactivateMapping($idMappatura)) {
            throw new Exception("Mappatura non trovata");
        }

        header("Location: map_courses.php?success=removed");
        exit;

    } catch (Exception $e) {
        $errorMessage = "Errore rimozione: " . $e->getMessage();
    }
}

// Messaggi di successo
if (isset($_GET['success'])) {
    switch ($_GET['success']) {
        case 'mapped':
            $successMessage = "Mappatura salvata con successo!";
            break;
        case 'removed':
            $successMessage = "Mappatura rimossa con successo!";
            break;
    }
}

// Separa corsi mappati e non mappati
$corsiNonMappati = [];
$corsiMappati = [];

foreach ($classiMaterieCV as $corso) {
    $key = $corso['id_classe'] . '_' . $corso['id_materia'];
    if (isset($mappatureEsistenti[$key]) && $mappatureEsistenti[$key]['stato'] === 'attivo') {
        $corso['mappatura'] = $mappatureEsistenti[$key];
        $corsiMappati[] = $corso;
    } else {
        $corsiNonMappati[] = $corso;
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mappatura Corsi - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .drop-zone {
            min-height: 400px;
            border: 2px dashed #dee2e6;
            border-radius: 8px;
            padding: 20px;
            background: #f8f9fa;
            transition: all 0.3s;
        }

        .drop-zone.drag-over {
            border-color: #0d6efd;
            background: #e7f1ff;
        }

        .course-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 15px;
            margin-bottom: 10px;
            cursor: move;
            transition: all 0.2s;
            position: relative;
        }

        .course-item:hover {
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transform: translateY(-2px);
        }

        .course-item.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }

        .course-item .badge {
            font-size: 0.7rem;
            padding: 3px 6px;
        }

        .classroom-item {
            background: white;
            border: 1px solid #dee2e6;
            border-radius: 6px;
            padding: 12px 15px;
            margin-bottom: 10px;
            cursor: pointer;
            transition: all 0.2s;
        }

        .classroom-item:hover {
            background: #f8f9fa;
            border-color: #0d6efd;
        }

        .classroom-item.selected {
            background: #e7f1ff;
            border-color: #0d6efd;
            border-width: 2px;
        }

        .mapped-item {
            background: #d1e7dd;
            border-left: 4px solid #198754;
            cursor: default;
        }

        .mapped-item:hover {
            transform: none;
        }

        .stats-card {
            border-left: 4px solid #0d6efd;
        }

        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #6c757d;
        }

        .empty-state i {
            font-size: 4rem;
            margin-bottom: 20px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-diagram-3"></i> Mappatura Corsi';
    $pageSubtitle = 'Associa le coppie Classe-Materia (ClasseViva) ai corsi Google Classroom';
    $headerActions = '<a class="nav-link" href="index.php"><i class="fas fa-home me-1"></i>Dashboard</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container-fluid mt-4">
        <?php if ($errorMessage): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <i class="fas fa-exclamation-circle me-2"></i><?= htmlspecialchars($errorMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($successMessage) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php endif; ?>

        <?php if ($errorCV): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Errore caricamento ClasseViva: <?= htmlspecialchars($errorCV) ?>
        </div>
        <?php endif; ?>

        <?php if ($errorGC): ?>
        <div class="alert alert-warning">
            <i class="fas fa-exclamation-triangle me-2"></i>
            Errore caricamento Google Classroom: <?= htmlspecialchars($errorGC) ?>
            <a href="google_auth.php" class="btn btn-sm btn-primary ms-3">
                <i class="fas fa-key me-1"></i>Autorizza Google
            </a>
        </div>
        <?php endif; ?>

        <!-- Statistiche -->
        <div class="row mb-4">
            <div class="col-md-4">
                <div class="card stats-card">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Corsi ClasseViva</h6>
                        <h3 class="mb-0"><?= count($classiMaterieCV) ?></h3>
                        <small class="text-muted">Coppie Classe-Materia</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card" style="border-left-color: #198754;">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Corsi Mappati</h6>
                        <h3 class="mb-0 text-success"><?= count($corsiMappati) ?></h3>
                        <small class="text-muted">Associazioni completate</small>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="card stats-card" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <h6 class="text-muted mb-1">Da Mappare</h6>
                        <h3 class="mb-0 text-warning"><?= count($corsiNonMappati) ?></h3>
                        <small class="text-muted">In attesa di associazione</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Interfaccia Drag & Drop -->
        <div class="row">
            <!-- Colonna Sinistra: ClasseViva -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0">
                            <i class="fas fa-school me-2"></i>
                            ClasseViva - Classe + Materia
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div id="classeviva-list" class="drop-zone">
                            <?php if (empty($classiMaterieCV)): ?>
                            <div class="empty-state">
                                <i class="fas fa-inbox"></i>
                                <p>Nessun corso disponibile da ClasseViva</p>
                            </div>
                            <?php else: ?>
                                <?php foreach ($corsiNonMappati as $corso): ?>
                                <div class="course-item"
                                     draggable="true"
                                     data-id-classe="<?= htmlspecialchars($corso['id_classe']) ?>"
                                     data-nome-classe="<?= htmlspecialchars($corso['nome_classe']) ?>"
                                     data-id-materia="<?= htmlspecialchars($corso['id_materia']) ?>"
                                     data-nome-materia="<?= htmlspecialchars($corso['nome_materia']) ?>">
                                    <div class="d-flex align-items-center">
                                        <i class="fas fa-grip-vertical text-muted me-3"></i>
                                        <div class="flex-grow-1">
                                            <strong><?= htmlspecialchars($corso['nome_classe']) ?></strong>
                                            <br>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($corso['nome_materia']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>

                                <?php if (!empty($corsiMappati)): ?>
                                <hr class="my-3">
                                <h6 class="text-muted mb-3">
                                    <i class="fas fa-check-circle text-success me-2"></i>
                                    Già Mappati
                                </h6>
                                <?php foreach ($corsiMappati as $corso): ?>
                                <div class="course-item mapped-item">
                                    <div class="d-flex align-items-center justify-content-between">
                                        <div class="flex-grow-1">
                                            <strong><?= htmlspecialchars($corso['nome_classe']) ?></strong>
                                            <br>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($corso['nome_materia']) ?></span>
                                            <br>
                                            <small class="text-success">
                                                <i class="fas fa-arrow-right me-1"></i>
                                                <?= htmlspecialchars($corso['mappatura']['nome_google_classroom'] ?? 'N/A') ?>
                                            </small>
                                        </div>
                                        <form method="POST" class="d-inline" onsubmit="return confirm('Rimuovere questa mappatura?');">
                                            <input type="hidden" name="action" value="remove_mapping">
                                            <input type="hidden" name="id_mappatura" value="<?= htmlspecialchars($corso['mappatura']['id_mappatura']) ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger">
                                                <i class="fas fa-times"></i>
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Colonna Destra: Google Classroom -->
            <div class="col-md-6">
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0">
                            <i class="fab fa-google me-2"></i>
                            Google Classroom - Corsi
                        </h5>
                    </div>
                    <div class="card-body p-3">
                        <div id="google-classroom-help" class="alert alert-info mb-3">
                            <i class="fas fa-info-circle me-2"></i>
                            <strong>Trascina un corso da sinistra qui</strong>, poi seleziona il corso Google Classroom corrispondente
                        </div>

                        <div id="selected-course-display" class="d-none mb-3 p-3 border border-primary rounded bg-light">
                            <h6 class="text-primary mb-2">
                                <i class="fas fa-hand-point-right me-2"></i>
                                Corso Selezionato:
                            </h6>
                            <div id="selected-course-info"></div>
                        </div>

                        <div id="classroom-list" class="drop-zone" style="max-height: 600px; overflow-y: auto;">
                            <?php if (empty($corsiGC)): ?>
                            <div class="empty-state">
                                <i class="fab fa-google-drive"></i>
                                <p>Nessun corso disponibile da Google Classroom</p>
                                <a href="google_auth.php" class="btn btn-primary">
                                    <i class="fas fa-key me-1"></i>Autorizza Google
                                </a>
                            </div>
                            <?php else: ?>
                                <?php foreach ($corsiGC as $corso): ?>
                                <div class="classroom-item"
                                     data-id="<?= htmlspecialchars($corso['id'] ?? '') ?>"
                                     data-name="<?= htmlspecialchars($corso['name'] ?? '') ?>">
                                    <div class="d-flex align-items-center">
                                        <i class="fab fa-google-classroom text-success me-3" style="font-size: 1.5rem;"></i>
                                        <div class="flex-grow-1">
                                            <strong><?= htmlspecialchars($corso['name'] ?? 'N/A') ?></strong>
                                            <br>
                                            <small class="text-muted">
                                                <?= htmlspecialchars($corso['section'] ?? '') ?>
                                                <?php if (isset($corso['courseState'])): ?>
                                                    <span class="badge bg-<?= $corso['courseState'] === 'ACTIVE' ? 'success' : 'secondary' ?>">
                                                        <?= htmlspecialchars($corso['courseState']) ?>
                                                    </span>
                                                <?php endif; ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form nascosto per submit -->
        <form id="mapping-form" method="POST" style="display: none;">
            <input type="hidden" name="action" value="save_mapping">
            <input type="hidden" name="id_classe_cv" id="form_id_classe_cv">
            <input type="hidden" name="nome_classe_cv" id="form_nome_classe_cv">
            <input type="hidden" name="id_materia_cv" id="form_id_materia_cv">
            <input type="hidden" name="nome_materia_cv" id="form_nome_materia_cv">
            <input type="hidden" name="id_google_classroom" id="form_id_google_classroom">
            <input type="hidden" name="nome_google_classroom" id="form_nome_google_classroom">
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Variabile globale per il corso selezionato da ClasseViva
        let selectedCourse = null;

        // Drag & Drop handlers per corsi ClasseViva
        document.querySelectorAll('.course-item:not(.mapped-item)').forEach(item => {
            item.addEventListener('dragstart', function(e) {
                this.classList.add('dragging');
                e.dataTransfer.effectAllowed = 'move';

                selectedCourse = {
                    idClasse: this.dataset.idClasse,
                    nomeClasse: this.dataset.nomeClasse,
                    idMateria: this.dataset.idMateria,
                    nomeMateria: this.dataset.nomeMateria
                };
            });

            item.addEventListener('dragend', function() {
                this.classList.remove('dragging');
            });
        });

        // Drop zone per Google Classroom
        const classroomDropZone = document.getElementById('classroom-list');

        classroomDropZone.addEventListener('dragover', function(e) {
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            this.parentElement.parentElement.classList.add('drag-over');
        });

        classroomDropZone.addEventListener('dragleave', function() {
            this.parentElement.parentElement.classList.remove('drag-over');
        });

        classroomDropZone.addEventListener('drop', function(e) {
            e.preventDefault();
            this.parentElement.parentElement.classList.remove('drag-over');

            if (selectedCourse) {
                // Mostra il corso selezionato
                document.getElementById('google-classroom-help').classList.add('d-none');
                document.getElementById('selected-course-display').classList.remove('d-none');
                document.getElementById('selected-course-info').innerHTML = `
                    <strong>${selectedCourse.nomeClasse}</strong><br>
                    <span class="badge bg-secondary">${selectedCourse.nomeMateria}</span><br>
                    <small class="text-muted">Ora seleziona un corso Google Classroom qui sotto</small>
                `;

                // Evidenzia i corsi Google Classroom
                document.querySelectorAll('.classroom-item').forEach(item => {
                    item.style.borderColor = '#198754';
                    item.style.borderWidth = '2px';
                });
            }
        });

        // Click su corso Google Classroom
        document.querySelectorAll('.classroom-item').forEach(item => {
            item.addEventListener('click', function() {
                if (!selectedCourse) {
                    alert('Trascina prima un corso da ClasseViva nella zona Google Classroom');
                    return;
                }

                // Deseleziona altri
                document.querySelectorAll('.classroom-item').forEach(i => {
                    i.classList.remove('selected');
                });

                // Seleziona questo
                this.classList.add('selected');

                // Conferma e salva
                const gcId = this.dataset.id;
                const gcName = this.dataset.name;

                if (confirm(`Confermi l'associazione?\n\n${selectedCourse.nomeClasse} - ${selectedCourse.nomeMateria}\n↓\n${gcName}`)) {
                    // Popola form e submit
                    document.getElementById('form_id_classe_cv').value = selectedCourse.idClasse;
                    document.getElementById('form_nome_classe_cv').value = selectedCourse.nomeClasse;
                    document.getElementById('form_id_materia_cv').value = selectedCourse.idMateria;
                    document.getElementById('form_nome_materia_cv').value = selectedCourse.nomeMateria;
                    document.getElementById('form_id_google_classroom').value = gcId;
                    document.getElementById('form_nome_google_classroom').value = gcName;

                    document.getElementById('mapping-form').submit();
                } else {
                    // Reset selezione
                    this.classList.remove('selected');
                }
            });
        });
    </script>
</body>
</html>
