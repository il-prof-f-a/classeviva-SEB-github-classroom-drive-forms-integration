<?php
/**
 * Mappatura ClasseViva <-> Google Classroom
 * Permette di associare materie di ClasseViva a corsi di Google Classroom
 */

error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$error_message = null;
$success_message = null;

// Carica mappature esistenti dal database
$existingMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');

// Recupera materie da ClasseViva

$classeVivaSubjects = [];
$classeVivaNotice = null;
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$classeVivaEnabled = $classeVivaState['ready'];
$classeVivaNotice = $classeVivaState['notice'];

if ($classeVivaEnabled) {
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

                $classeVivaSubjects[] = [
                    'id' => $classId . '_' . $subjectId,
                    'class_id' => $classId,
                    'class_name' => $className,
                    'subject_id' => $subjectId,
                    'subject_name' => $subjectName,
                    'display_name' => $className . ' - ' . $subjectName
                ];
            }
        }
    } catch (Exception $e) {
        $error_message = "Errore ClasseViva: " . $e->getMessage();
        $classeVivaEnabled = false;
        $classeVivaNotice = $e->getMessage();
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
        // Fallback a dati mock se l'API non è disponibile
        $error_message = "Avviso Google Classroom: " . $e->getMessage() . " - Usando dati mock.";
        $googleCourses = [
            ['id' => 'course_1', 'name' => '3C Informatica 2024-25'],
            ['id' => 'course_2', 'name' => '3D Informatica 2024-25'],
            ['id' => 'course_3', 'name' => '4D Informatica 2024-25'],
            ['id' => 'course_4', 'name' => '5C Informatica 2024-25'],
        ];
        $googleEnabled = true; // Abilita comunque con dati mock
    }
}

// Gestione POST per salvare mappature
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_mappings') {
    try {
        $mappings = json_decode($_POST['mappings'] ?? '[]', true);

        if (!is_array($mappings)) {
            throw new Exception("Dati mappatura non validi");
        }

        // Cancella tutte le mappature esistenti e salva le nuove
        $dbAdapter->clearAllClassroomMappings();

        $savedCount = 0;
        foreach ($mappings as $mapping) {
            // Estrae classe e materia dal display name
            $parts = explode(' - ', $mapping['sourceDisplay']);
            $className = $parts[0] ?? '';
            $subjectName = $parts[1] ?? '';

            $mappingData = [
                'id_mapping' => 'MAP_' . uniqid(),
                'id_classe_cv' => $mapping['classId'],
                'nome_classe_cv' => $className,
                'id_materia_cv' => $mapping['subjectId'],
                'nome_materia_cv' => $subjectName,
                'id_corso_gc' => $mapping['courseId'],
                'nome_corso_gc' => $mapping['courseName'],
                'data_mapping' => date('Y-m-d H:i:s'),
                'stato' => 'attivo',
                'note' => ''
            ];

            if ($dbAdapter->insertClassroomMapping($mappingData)) {
                $savedCount++;
            }
        }

        $success_message = "Mappature salvate con successo! ($savedCount mappature salvate)";

        // Ricarica le mappature per aggiornare la visualizzazione
        $existingMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');

    } catch (Exception $e) {
        $error_message = "Errore durante il salvataggio: " . $e->getMessage();
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mappatura Classroom - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .mapping-container {
            min-height: 500px;
            display: flex;
            gap: 2rem;
        }

        .source-panel, .target-panel {
            flex: 1;
            background: #f8f9fa;
            border-radius: 8px;
            padding: 1.5rem;
        }

        .panel-header {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            margin-bottom: 1rem;
            border-left: 4px solid #0d6efd;
        }

        .item-list {
            display: flex;
            flex-direction: column;
            gap: 0.75rem;
            max-height: 600px;
            overflow-y: auto;
        }

        .draggable-item {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 2px solid #dee2e6;
            cursor: grab;
            transition: all 0.2s;
        }

        .draggable-item:hover {
            border-color: #0d6efd;
            box-shadow: 0 2px 8px rgba(13, 110, 253, 0.15);
        }

        .draggable-item.dragging {
            opacity: 0.5;
            cursor: grabbing;
        }

        .draggable-item .class-name {
            font-weight: 600;
            color: #495057;
            font-size: 0.9rem;
        }

        .draggable-item .subject-name {
            color: #0d6efd;
            font-size: 1rem;
        }

        .drop-zone {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 2px dashed #dee2e6;
            min-height: 80px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6c757d;
            transition: all 0.2s;
        }

        .drop-zone.drag-over {
            border-color: #0d6efd;
            background: #e7f1ff;
        }

        .mapping-item {
            background: white;
            padding: 1rem;
            border-radius: 6px;
            border: 2px solid #198754;
            margin-bottom: 0.75rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .mapping-item .source {
            flex: 1;
        }

        .mapping-item .arrow {
            padding: 0 1rem;
            color: #198754;
            font-size: 1.5rem;
        }

        .mapping-item .target {
            flex: 1;
            text-align: right;
        }

        .mapping-item .remove-btn {
            margin-left: 1rem;
        }

        .stats-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 2rem;
        }

        .stats-card .stat-item {
            display: inline-block;
            margin-right: 2rem;
        }

        .stats-card .stat-value {
            font-size: 2rem;
            font-weight: bold;
        }

        .stats-card .stat-label {
            font-size: 0.9rem;
            opacity: 0.9;
        }
    </style>
    </head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-arrow-left-right"></i> Mappatura ClasseViva <-> Google Classroom';
    $pageSubtitle = 'Associa le materie di ClasseViva ai corsi di Google Classroom con drag & drop';
    $headerActions = '<a class="nav-link" href="index.php">Dashboard</a>'
        . '<a class="nav-link" href="classroom_mapping.php">Mappatura Classroom</a>';
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container-fluid mt-4 mb-5">
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

        <!-- Statistiche -->
        <div class="stats-card">
            <div class="stat-item">
                <div class="stat-value" id="stat-classeviva"><?= count($classeVivaSubjects) ?></div>
                <div class="stat-label">Materie ClasseViva</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" id="stat-google"><?= count($googleCourses) ?></div>
                <div class="stat-label">Corsi Google Classroom</div>
            </div>
            <div class="stat-item">
                <div class="stat-value" id="stat-mapped">0</div>
                <div class="stat-label">Mappature Attive</div>
            </div>
        </div>

        <?php if (!$classeVivaEnabled || !$googleEnabled): ?>
            <div class="alert alert-warning">
                <h5 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Configurazione Incompleta</h5>
                <p class="mb-0">
                    <?php if (!$classeVivaEnabled): ?>
                        <?= htmlspecialchars($classeVivaNotice ?? 'ClasseViva non è configurato o non è stato possibile recuperare i dati.') ?><br>
                    <?php endif; ?>
                    <?php if (!$googleEnabled): ?>
                        Google Classroom non è configurato o non è stato possibile recuperare i corsi.<br>
                    <?php endif; ?>
                    Verifica la configurazione nel file .env e config.yaml
                </p>
            </div>
        <?php else: ?>

        <!-- Container principale mappatura -->
        <div class="row">
            <!-- Pannello sinistra: ClasseViva -->
            <div class="col-md-5">
                <div class="source-panel">
                    <div class="panel-header">
                        <h5 class="mb-1">
                            <i class="bi bi-journal-bookmark"></i> Materie ClasseViva
                        </h5>
                        <small class="text-muted">Trascina una materia verso destra per mapparla</small>
                    </div>

                    <div class="item-list" id="classeviva-list">
                        <?php foreach ($classeVivaSubjects as $subject): ?>
                            <div class="draggable-item"
                                 draggable="true"
                                 data-id="<?= htmlspecialchars($subject['id']) ?>"
                                 data-class-id="<?= htmlspecialchars($subject['class_id']) ?>"
                                 data-subject-id="<?= htmlspecialchars($subject['subject_id']) ?>"
                                 data-display="<?= htmlspecialchars($subject['display_name']) ?>">
                                <div class="class-name">
                                    <i class="bi bi-building"></i> <?= htmlspecialchars($subject['class_name']) ?>
                                </div>
                                <div class="subject-name">
                                    <i class="bi bi-book"></i> <?= htmlspecialchars($subject['subject_name']) ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Pannello centrale: Mappature attive -->
            <div class="col-md-7">
                <div class="target-panel">
                    <div class="panel-header">
                        <h5 class="mb-1">
                            <i class="bi bi-arrow-left-right"></i> Mappature Attive
                        </h5>
                        <small class="text-muted">Rilascia qui una materia e seleziona il corso Google Classroom</small>
                    </div>

                    <div class="drop-zone" id="drop-zone">
                        <div class="text-center">
                            <i class="bi bi-arrow-down-circle" style="font-size: 2rem;"></i>
                            <p class="mb-0">Trascina qui una materia per creare una mappatura</p>
                        </div>
                    </div>

                    <div id="mappings-container" class="mt-3">
                        <!-- Le mappature vengono aggiunte qui dinamicamente -->
                    </div>
                </div>
            </div>
        </div>

        <!-- Pulsanti azione -->
        <div class="d-flex justify-content-end gap-2 mt-4">
            <button type="button" class="btn btn-outline-secondary" onclick="clearAllMappings()">
                <i class="bi bi-trash"></i> Cancella Tutto
            </button>
            <button type="button" class="btn btn-primary btn-lg" onclick="saveMappings()">
                <i class="bi bi-save"></i> Salva Mappature
            </button>
        </div>

        <?php endif; ?>
    </div>

    <!-- Modal per selezionare corso Google Classroom -->
    <div class="modal fade" id="selectCourseModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Seleziona Corso Google Classroom</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <p class="text-muted mb-3">Seleziona il corso di Google Classroom da associare a:</p>
                    <div class="alert alert-info" id="source-display"></div>

                    <div class="list-group">
                        <?php foreach ($googleCourses as $course): ?>
                            <button type="button"
                                    class="list-group-item list-group-item-action"
                                    data-course-id="<?= htmlspecialchars($course['id']) ?>"
                                    data-course-name="<?= htmlspecialchars($course['name']) ?>"
                                    onclick="selectCourse(this)">
                                <i class="bi bi-google"></i> <?= htmlspecialchars($course['name']) ?>
                            </button>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let mappings = [];
        let currentDraggedItem = null;
        let pendingMapping = null;

        const modal = new bootstrap.Modal(document.getElementById('selectCourseModal'));

        // Carica mappature esistenti dal database
        <?php if (!empty($existingMappings)): ?>
        const existingMappingsData = <?= json_encode($existingMappings) ?>;

        // Converte le mappature dal database nel formato dell'applicazione
        existingMappingsData.forEach(function(dbMapping) {
            var stato = (dbMapping.stato || 'attivo').toString().toLowerCase().trim();
            if (stato === '' || stato === 'attivo' || stato === 'active' || stato === '1') {
                const mapping = {
                    sourceId: (dbMapping.id_classe_cv || '') + '_' + (dbMapping.id_materia_cv || ''),
                    classId: dbMapping.id_classe_cv,
                    subjectId: dbMapping.id_materia_cv,
                    sourceDisplay: (dbMapping.nome_classe_cv || '') + ' - ' + (dbMapping.nome_materia_cv || ''),
                    courseId: dbMapping.id_corso_gc,
                    courseName: dbMapping.nome_corso_gc
                };
                mappings.push(mapping);
            }
        });

        // Renderizza le mappature caricate
        renderMappings();
        updateStats();
        <?php endif; ?>

        // Drag and Drop handlers
        document.querySelectorAll('.draggable-item').forEach(item => {
            item.addEventListener('dragstart', handleDragStart);
            item.addEventListener('dragend', handleDragEnd);
        });

        const dropZone = document.getElementById('drop-zone');
        dropZone.addEventListener('dragover', handleDragOver);
        dropZone.addEventListener('dragleave', handleDragLeave);
        dropZone.addEventListener('drop', handleDrop);

        function handleDragStart(e) {
            this.classList.add('dragging');
            currentDraggedItem = {
                id: this.dataset.id,
                classId: this.dataset.classId,
                subjectId: this.dataset.subjectId,
                display: this.dataset.display
            };
            e.dataTransfer.effectAllowed = 'move';
        }

        function handleDragEnd(e) {
            this.classList.remove('dragging');
        }

        function handleDragOver(e) {
            if (e.preventDefault) {
                e.preventDefault();
            }
            this.classList.add('drag-over');
            e.dataTransfer.dropEffect = 'move';
            return false;
        }

        function handleDragLeave(e) {
            this.classList.remove('drag-over');
        }

        function handleDrop(e) {
            if (e.stopPropagation) {
                e.stopPropagation();
            }

            this.classList.remove('drag-over');

            if (currentDraggedItem) {
                // Mostra modal per selezionare corso
                pendingMapping = currentDraggedItem;
                document.getElementById('source-display').textContent = currentDraggedItem.display;
                modal.show();
            }

            return false;
        }

        function selectCourse(btn) {
            const courseId = btn.dataset.courseId;
            const courseName = btn.dataset.courseName;

            if (pendingMapping) {
                addMapping(pendingMapping, courseId, courseName);
                pendingMapping = null;
            }

            modal.hide();
        }

        function addMapping(source, courseId, courseName) {
            // Verifica se già mappato
            const existing = mappings.find(m => m.sourceId === source.id);
            if (existing) {
                alert('Questa materia è già mappata. Rimuovi prima la mappatura esistente.');
                return;
            }

            const mapping = {
                sourceId: source.id,
                classId: source.classId,
                subjectId: source.subjectId,
                sourceDisplay: source.display,
                courseId: courseId,
                courseName: courseName
            };

            mappings.push(mapping);
            renderMappings();
            updateStats();
        }

        function removeMapping(sourceId) {
            mappings = mappings.filter(m => m.sourceId !== sourceId);
            renderMappings();
            updateStats();
        }

        function renderMappings() {
            const container = document.getElementById('mappings-container');

            if (mappings.length === 0) {
                container.innerHTML = '<p class="text-muted text-center mt-3">Nessuna mappatura configurata</p>';
                return;
            }

            container.innerHTML = mappings.map(m => `
                <div class="mapping-item">
                    <div class="source">
                        <small class="text-muted d-block">ClasseViva</small>
                        <strong>${escapeHtml(m.sourceDisplay)}</strong>
                    </div>
                    <div class="arrow">
                        <i class="bi bi-arrow-right-circle-fill"></i>
                    </div>
                    <div class="target">
                        <small class="text-muted d-block">Google Classroom</small>
                        <strong>${escapeHtml(m.courseName)}</strong>
                    </div>
                    <button class="btn btn-sm btn-outline-danger remove-btn"
                            onclick="removeMapping('${escapeHtml(m.sourceId)}')"
                            title="Rimuovi mappatura">
                        <i class="bi bi-trash"></i>
                    </button>
                </div>
            `).join('');
        }

        function updateStats() {
            document.getElementById('stat-mapped').textContent = mappings.length;
        }

        function clearAllMappings() {
            if (confirm('Sei sicuro di voler cancellare tutte le mappature?')) {
                mappings = [];
                renderMappings();
                updateStats();
            }
        }

        function saveMappings() {
            if (mappings.length === 0) {
                alert('Nessuna mappatura da salvare');
                return;
            }

            const form = document.createElement('form');
            form.method = 'POST';
            form.style.display = 'none';

            const actionInput = document.createElement('input');
            actionInput.name = 'action';
            actionInput.value = 'save_mappings';
            form.appendChild(actionInput);

            const mappingsInput = document.createElement('input');
            mappingsInput.name = 'mappings';
            mappingsInput.value = JSON.stringify(mappings);
            form.appendChild(mappingsInput);

            document.body.appendChild(form);
            form.submit();
        }

        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
    </script>
</body>
</html>

