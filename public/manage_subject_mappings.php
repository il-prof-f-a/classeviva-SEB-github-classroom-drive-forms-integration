<?php

/**
 * Gestione Associazioni Classe-Materia → Google Classroom
 * Interfaccia migliorata per associare le combinazioni (classe, materia) di ClasseViva
 * con i corsi di Google Classroom
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$mappingService = new ProviderNeutralMappingService(
    $dbAdapter,
    (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'))
);
$error_message = null;
$success_message = null;

// Gestione salvataggio mappature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save_mapping') {
            // Salva singola mappatura
            $classId = $_POST['class_id'] ?? '';
            $className = $_POST['class_name'] ?? '';
            $subjectId = $_POST['subject_id'] ?? '';
            $subjectName = $_POST['subject_name'] ?? '';
            $courseId = $_POST['course_id'] ?? '';
            $courseName = $_POST['course_name'] ?? '';

            if (empty($classId) || empty($subjectId) || empty($courseId)) {
                throw new Exception("Dati incompleti");
            }

            $mappingService->upsertGoogleClassroomMapping([
                'classeviva_class_id' => $classId,
                'classeviva_class_name' => $className,
                'classeviva_subject_id' => $subjectId,
                'classeviva_subject_name' => $subjectName,
                'google_course_id' => $courseId,
                'google_course_name' => $courseName,
            ]);
            $success_message = "Mappatura aggiornata con successo!";

            // Redirect per evitare re-submit
            header("Location: manage_subject_mappings.php?success=" . urlencode($success_message));
            exit;

        } elseif ($_POST['action'] === 'delete_mapping') {
            $mappingId = $_POST['mapping_id'] ?? '';

            if (empty($mappingId)) {
                throw new Exception("ID mappatura mancante");
            }

            $mappingService->deactivateMapping((string)$mappingId);
            $success_message = "Mappatura eliminata con successo!";

            header("Location: manage_subject_mappings.php?success=" . urlencode($success_message));
            exit;

        } elseif ($_POST['action'] === 'save_all_mappings') {
            // Salvataggio bulk
            $mappings = json_decode($_POST['mappings'] ?? '[]', true);

            if (!is_array($mappings) || empty($mappings)) {
                throw new Exception("Nessuna mappatura da salvare");
            }

            $savedCount = 0;
            foreach ($mappings as $mapping) {
                $mappingService->upsertGoogleClassroomMapping([
                    'classeviva_class_id' => $mapping['class_id'] ?? '',
                    'classeviva_class_name' => $mapping['class_name'] ?? '',
                    'classeviva_subject_id' => $mapping['subject_id'] ?? '',
                    'classeviva_subject_name' => $mapping['subject_name'] ?? '',
                    'google_course_id' => $mapping['course_id'] ?? '',
                    'google_course_name' => $mapping['course_name'] ?? '',
                ]);
                $savedCount++;
            }

            $success_message = "Salvate $savedCount mappature con successo!";
            header("Location: manage_subject_mappings.php?success=" . urlencode($success_message));
            exit;
        }

    } catch (Exception $e) {
        $error_message = "Errore: " . $e->getMessage();
    }
}

// Gestione messaggi da redirect
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Carica mappature esistenti dal database
$existingMappings = $mappingService->listGoogleClassroomMappings();

// Crea mappa per lookup veloce
$mappingsMap = [];
foreach ($existingMappings as $mapping) {
    $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
    if ($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1') {
        $key = ($mapping['id_classe_cv'] ?? '') . '_' . ($mapping['id_materia_cv'] ?? '');
        $mappingsMap[$key] = $mapping;
    }
}

// Recupera materie da ClasseViva
$classeVivaSubjects = [];
$classeVivaEnabled = false;
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$classeVivaReady = $classeVivaState['ready'];
$classeVivaNotice = $classeVivaState['notice'];

if (($config['classeviva']['enabled'] ?? false)) {
    if (!$classeVivaReady) {
        $error_message = $error_message ?? ($classeVivaNotice ?? 'Token ClasseViva mancante o non valido.');
    } else {
        try {
            $classeVivaAPI = new ClasseVivaAPI($config);
            $classesWithSubjects = $classeVivaAPI->getClassesWithTeacherSubjects();

            foreach ($classesWithSubjects as $classData) {
                $classId = $classData['id'] ?? '';
                $className = $classData['name'] ?? '';
                $subjects = $classData['subjects'] ?? [];

                foreach ($subjects as $subject) {
                    $subjectId = $subject['subjectId'] ?? $subject['id'] ?? '';
                    $subjectName = $subject['subjectDesc'] ?? $subject['name'] ?? $subject['subjectName'] ?? '';

                    if (empty($subjectId) || empty($subjectName)) {
                        continue;
                    }

                    $key = $classId . '_' . $subjectId;
                    $existingMapping = $mappingsMap[$key] ?? null;

                    $classeVivaSubjects[] = [
                        'class_id' => $classId,
                        'class_name' => $className,
                        'subject_id' => $subjectId,
                        'subject_name' => $subjectName,
                        'key' => $key,
                        'mapped' => !empty($existingMapping),
                        'mapping_id' => $existingMapping['id_mapping'] ?? null,
                        'mapped_course_id' => $existingMapping['id_corso_gc'] ?? '',
                        'mapped_course_name' => $existingMapping['nome_corso_gc'] ?? ''
                    ];
                }
            }

            $classeVivaEnabled = true;
        } catch (Exception $e) {
            $error_message = "Errore ClasseViva: " . $e->getMessage();
        }
    }
}

// Recupera corsi da Google Classroom
$googleCourses = [];
$googleEnabled = false;

if (($config['google']['classroom']['enabled'] ?? false)) {
    try {
        $googleAPI = new GoogleClassroomAPI($config);
        $googleCourses = $googleAPI->getCourses();
        $googleEnabled = true;
    } catch (Exception $e) {
        $error_message = ($error_message ? $error_message . " | " : "") . "Errore Google Classroom: " . $e->getMessage();
    }
}

// Statistiche
$totalSubjects = count($classeVivaSubjects);
$mappedCount = count(array_filter($classeVivaSubjects, fn($s) => $s['mapped']));
$unmappedCount = $totalSubjects - $mappedCount;

// Raggruppa per classe per il filtro
$classesList = [];
foreach ($classeVivaSubjects as $subject) {
    if (!isset($classesList[$subject['class_id']])) {
        $classesList[$subject['class_id']] = $subject['class_name'];
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Associazioni Classe-Materia → Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .mapping-row {
            transition: all 0.2s;
            border-left: 4px solid transparent;
        }

        .mapping-row:hover {
            background-color: #f8f9fa;
        }

        .mapping-row.mapped {
            border-left-color: #198754;
            background-color: #f0f9f4;
        }

        .mapping-row.unmapped {
            border-left-color: #ffc107;
            background-color: #fff9e6;
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 2rem;
            border-radius: 12px;
            margin-bottom: 2rem;
        }

        .stat-box {
            text-align: center;
            padding: 1rem;
            background: rgba(255, 255, 255, 0.1);
            border-radius: 8px;
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: bold;
            display: block;
        }

        .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }

        .class-badge {
            display: inline-block;
            padding: 0.25rem 0.75rem;
            background: #e7f1ff;
            color: #0d6efd;
            border-radius: 20px;
            font-size: 0.85rem;
            font-weight: 600;
        }

        .subject-name {
            font-weight: 600;
            color: #495057;
        }

        .course-select {
            min-width: 250px;
        }

        .action-buttons {
            white-space: nowrap;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
        }

        .quick-actions {
            position: sticky;
            top: 20px;
            background: white;
            padding: 1rem;
            border-radius: 8px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 1rem;
        }
    </style>
    </head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-shuffle"></i> Gestione Associazioni Classe-Materia -> Classroom';
    $pageSubtitle = 'Associa le combinazioni (classe + materia) di ClasseViva ai corsi di Google Classroom';
    $headerActions = '<a class="nav-link" href="classroom_mapping.php"><i class="bi bi-grip-horizontal"></i> Mappatura Drag&Drop</a>'
        . '<a class="nav-link" href="map_classes.php"><i class="bi bi-diagram-3"></i> Gestione Classi</a>'
        . '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container-fluid mt-4 mb-5">

    <div class="alert alert-warning border-0 shadow-sm">
    <i class="bi bi-exclamation-triangle-fill"></i>
    <strong>Pagina legacy:</strong> questa è la mappatura ClasseViva di tipo legacy.
    Usa la <a href="map_classes.php">mappatura per gruppo didattico</a> per collegare Google Classroom (e GitHub) senza dipendere da ClasseViva.
</div>
        <?php if ($error_message): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($success_message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success_message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$classeVivaEnabled || !$googleEnabled): ?>
            <div class="alert alert-warning">
                <h5 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Configurazione Incompleta</h5>
                <p class="mb-0">
                    <?php if (!$classeVivaEnabled): ?>
                        <i class="bi bi-x-circle"></i> ClasseViva non è configurato o non è stato possibile recuperare i dati.<br>
                    <?php endif; ?>
                    <?php if (!$googleEnabled): ?>
                        <i class="bi bi-x-circle"></i> Google Classroom non è configurato o non è stato possibile recuperare i corsi.<br>
                    <?php endif; ?>
                    Verifica la configurazione nel file .env e config.yaml
                </p>
            </div>
        <?php else: ?>

        <!-- Statistiche -->
        <div class="stats-card">
            <div class="row">
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value"><?= $totalSubjects ?></span>
                        <span class="stat-label">Totale Materie</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value text-success"><?= $mappedCount ?></span>
                        <span class="stat-label">Mappate</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value text-warning"><?= $unmappedCount ?></span>
                        <span class="stat-label">Da Mappare</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filtri e Azioni Rapide -->
        <div class="quick-actions">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <label class="form-label mb-1"><i class="bi bi-funnel"></i> Filtra per Classe:</label>
                    <select id="classFilter" class="form-select">
                        <option value="">Tutte le classi</option>
                        <?php foreach ($classesList as $classId => $className): ?>
                            <option value="<?= htmlspecialchars($classId) ?>"><?= htmlspecialchars($className) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label mb-1"><i class="bi bi-filter"></i> Filtra per Stato:</label>
                    <select id="statusFilter" class="form-select">
                        <option value="">Tutte</option>
                        <option value="mapped">Solo Mappate</option>
                        <option value="unmapped">Solo Non Mappate</option>
                    </select>
                </div>
                <div class="col-md-4 text-end">
                    <label class="form-label mb-1 d-block">&nbsp;</label>
                    <button type="button" class="btn btn-primary" onclick="saveAllMappings()">
                        <i class="bi bi-save"></i> Salva Tutte le Modifiche
                    </button>
                </div>
            </div>
        </div>

        <!-- Tabella Mappature -->
        <div class="card">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    <i class="bi bi-list-ul"></i> Materie e Associazioni
                </h5>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="table-light">
                            <tr>
                                <th width="20%">Classe</th>
                                <th width="25%">Materia</th>
                                <th width="35%">Corso Google Classroom</th>
                                <th width="10%" class="text-center">Stato</th>
                                <th width="10%" class="text-center">Azioni</th>
                            </tr>
                        </thead>
                        <tbody id="mappingsTableBody">
                            <?php if (empty($classeVivaSubjects)): ?>
                                <tr>
                                    <td colspan="5" class="text-center py-4">
                                        <i class="bi bi-inbox text-muted" style="font-size: 3rem;"></i>
                                        <p class="text-muted mt-2">Nessuna materia trovata su ClasseViva</p>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($classeVivaSubjects as $subject): ?>
                                    <tr class="mapping-row <?= $subject['mapped'] ? 'mapped' : 'unmapped' ?>"
                                        data-class-id="<?= htmlspecialchars($subject['class_id']) ?>"
                                        data-status="<?= $subject['mapped'] ? 'mapped' : 'unmapped' ?>"
                                        data-key="<?= htmlspecialchars($subject['key']) ?>">

                                        <td>
                                            <span class="class-badge">
                                                <i class="bi bi-building"></i>
                                                <?= htmlspecialchars($subject['class_name']) ?>
                                            </span>
                                        </td>

                                        <td>
                                            <div class="subject-name">
                                                <i class="bi bi-book"></i>
                                                <?= htmlspecialchars($subject['subject_name']) ?>
                                            </div>
                                            <small class="text-muted">ID: <?= htmlspecialchars($subject['subject_id']) ?></small>
                                        </td>

                                        <td>
                                            <select class="form-select course-select mapping-select"
                                                    data-key="<?= htmlspecialchars($subject['key']) ?>"
                                                    data-class-id="<?= htmlspecialchars($subject['class_id']) ?>"
                                                    data-class-name="<?= htmlspecialchars($subject['class_name']) ?>"
                                                    data-subject-id="<?= htmlspecialchars($subject['subject_id']) ?>"
                                                    data-subject-name="<?= htmlspecialchars($subject['subject_name']) ?>"
                                                    data-mapping-id="<?= htmlspecialchars($subject['mapping_id'] ?? '') ?>">
                                                <option value="">-- Seleziona corso --</option>
                                                <?php foreach ($googleCourses as $course): ?>
                                                    <option value="<?= htmlspecialchars($course['id']) ?>"
                                                            data-course-name="<?= htmlspecialchars($course['name']) ?>"
                                                            <?= $subject['mapped_course_id'] === $course['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($course['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>

                                        <td class="text-center">
                                            <?php if ($subject['mapped']): ?>
                                                <span class="badge bg-success">
                                                    <i class="bi bi-check-circle"></i> Mappata
                                                </span>
                                            <?php else: ?>
                                                <span class="badge bg-warning">
                                                    <i class="bi bi-exclamation-circle"></i> Non Mappata
                                                </span>
                                            <?php endif; ?>
                                        </td>

                                        <td class="text-center action-buttons">
                                            <button type="button"
                                                    class="btn btn-sm btn-primary save-single-btn"
                                                    onclick="saveSingleMapping('<?= htmlspecialchars($subject['key']) ?>')"
                                                    title="Salva questa mappatura">
                                                <i class="bi bi-save"></i>
                                            </button>
                                            <?php if ($subject['mapped']): ?>
                                                <button type="button"
                                                        class="btn btn-sm btn-danger"
                                                        onclick="deleteMapping('<?= htmlspecialchars($subject['mapping_id']) ?>', '<?= htmlspecialchars($subject['class_name'] . ' - ' . $subject['subject_name']) ?>')"
                                                        title="Elimina questa mappatura">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Help Section -->
        <div class="card mt-4">
            <div class="card-header bg-info text-white">
                <h6 class="mb-0"><i class="bi bi-question-circle"></i> Come Funziona</h6>
            </div>
            <div class="card-body">
                <ol class="mb-0">
                    <li>Ogni riga rappresenta una combinazione di <strong>Classe + Materia</strong> da ClasseViva</li>
                    <li>Seleziona il <strong>Corso Google Classroom</strong> corrispondente dal menu a tendina</li>
                    <li>Puoi salvare una singola mappatura cliccando il pulsante <i class="bi bi-save"></i> oppure salvare tutte le modifiche in una volta</li>
                    <li>Usa i filtri per visualizzare solo le classi o stati specifici</li>
                    <li>Le mappature vengono utilizzate per pubblicare le UDA nei corsi corretti di Google Classroom</li>
                </ol>
            </div>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Gestione filtri
        document.getElementById('classFilter')?.addEventListener('change', applyFilters);
        document.getElementById('statusFilter')?.addEventListener('change', applyFilters);

        function applyFilters() {
            const classFilter = document.getElementById('classFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;

            const rows = document.querySelectorAll('.mapping-row');

            rows.forEach(row => {
                const classId = row.dataset.classId;
                const status = row.dataset.status;

                let show = true;

                if (classFilter && classId !== classFilter) {
                    show = false;
                }

                if (statusFilter && status !== statusFilter) {
                    show = false;
                }

                row.style.display = show ? '' : 'none';
            });
        }

        // Salvataggio singola mappatura
        function saveSingleMapping(key) {
            const select = document.querySelector(`.mapping-select[data-key="${key}"]`);

            if (!select || !select.value) {
                alert('Seleziona prima un corso Google Classroom');
                return;
            }

            const classId = select.dataset.classId;
            const className = select.dataset.className;
            const subjectId = select.dataset.subjectId;
            const subjectName = select.dataset.subjectName;
            const courseId = select.value;
            const courseName = select.options[select.selectedIndex].dataset.courseName;

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const inputs = {
                'action': 'save_mapping',
                'class_id': classId,
                'class_name': className,
                'subject_id': subjectId,
                'subject_name': subjectName,
                'course_id': courseId,
                'course_name': courseName
            };

            for (const [name, value] of Object.entries(inputs)) {
                const input = document.createElement('input');
                input.type = 'hidden';
                input.name = name;
                input.value = value;
                form.appendChild(input);
            }

            document.body.appendChild(form);
            form.submit();
        }

        // Salvataggio bulk di tutte le mappature
        function saveAllMappings() {
            const selects = document.querySelectorAll('.mapping-select');
            const mappings = [];

            selects.forEach(select => {
                if (select.value) {
                    const classId = select.dataset.classId;
                    const className = select.dataset.className;
                    const subjectId = select.dataset.subjectId;
                    const subjectName = select.dataset.subjectName;
                    const courseId = select.value;
                    const courseName = select.options[select.selectedIndex].dataset.courseName;
                    const mappingId = select.dataset.mappingId;

                    mappings.push({
                        id: mappingId || null,
                        class_id: classId,
                        class_name: className,
                        subject_id: subjectId,
                        subject_name: subjectName,
                        course_id: courseId,
                        course_name: courseName
                    });
                }
            });

            if (mappings.length === 0) {
                alert('Nessuna mappatura da salvare. Seleziona almeno un corso Google Classroom.');
                return;
            }

            if (!confirm(`Vuoi salvare ${mappings.length} mappature?`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'save_all_mappings';
            form.appendChild(actionInput);

            const mappingsInput = document.createElement('input');
            mappingsInput.name = 'mappings';
            mappingsInput.value = JSON.stringify(mappings);
            form.appendChild(mappingsInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Eliminazione mappatura
        function deleteMapping(mappingId, displayName) {
            if (!confirm(`Vuoi eliminare la mappatura per "${displayName}"?`)) {
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'delete_mapping';
            form.appendChild(actionInput);

            const idInput = document.createElement('input');
            idInput.name = 'mapping_id';
            idInput.value = mappingId;
            form.appendChild(idInput);

            document.body.appendChild(form);
            form.submit();
        }

        // Highlight delle modifiche non salvate
        document.querySelectorAll('.mapping-select').forEach(select => {
            select.addEventListener('change', function() {
                const row = this.closest('.mapping-row');
                row.style.borderLeftWidth = '6px';
                row.style.borderLeftColor = '#0d6efd';

                const saveBtn = row.querySelector('.save-single-btn');
                if (saveBtn) {
                    saveBtn.classList.add('btn-warning');
                    saveBtn.classList.remove('btn-primary');
                }
            });
        });
    </script>
</body>
</html>
