<?php
/**
 * Gestione Completa Associazioni ClasseViva ↔ Google Classroom
 *
 * Flusso:
 * 1. Associa (classe + materia) → Google Classroom corso
 * 2. Per ogni associazione, associa gli studenti
 */

error_reporting(E_ALL);

require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;
use App\Integration\GoogleClassroomAPI;
use App\Core\ClasseVivaTokenGuard;
use App\Utils\LocalReturnUrl;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$error_message = null;
$success_message = null;
$returnTo = LocalReturnUrl::sanitize(
    $_GET['return_to'] ?? $_POST['return_to'] ?? null,
    basename($_SERVER['PHP_SELF'] ?? 'map_classes.php')
);
$isWizardReturn = $returnTo === 'uda_create.php';

$redirectAfterMapping = static function (string $message) use ($returnTo): never {
    $target = $returnTo;
    $separator = str_contains($target, '?') ? '&' : '?';
    header('Location: ' . $target . $separator . http_build_query([
        'integration_updated' => '1',
        'success' => $message,
    ]) . ($target === 'uda_create.php' ? '#2' : ''));
    exit;
};

// Gestione azioni POST
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

            // Verifica se esiste già
            $existingMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');
            $mappingId = null;

            foreach ($existingMappings as $existing) {
                if (($existing['id_classe_cv'] ?? '') == $classId &&
                    ($existing['id_materia_cv'] ?? '') == $subjectId) {
                    $mappingId = $existing['id_mapping'];
                    break;
                }
            }

            $mappingData = [
                'id_mapping' => $mappingId ?: 'MAP_' . uniqid(),
                'id_classe_cv' => $classId,
                'nome_classe_cv' => $className,
                'id_materia_cv' => $subjectId,
                'nome_materia_cv' => $subjectName,
                'id_corso_gc' => $courseId,
                'nome_corso_gc' => $courseName,
                'data_mapping' => date('Y-m-d H:i:s'),
                'stato' => 'attivo',
                'note' => $existing['note'] ?? ''
            ];

            if ($mappingId) {
                $dbAdapter->updateClassroomMapping($mappingId, $mappingData);
                $success_message = "Mappatura aggiornata! Ora puoi associare gli studenti.";
            } else {
                $dbAdapter->insertClassroomMapping($mappingData);
                $success_message = "Mappatura creata! Ora puoi associare gli studenti.";
            }

            $redirectAfterMapping($success_message);

        } elseif ($_POST['action'] === 'save_all_mappings') {
            // Salvataggio bulk di tutte le mappature selezionate lato client
            $mappings = json_decode($_POST['mappings'] ?? '[]', true);

            if (!is_array($mappings) || empty($mappings)) {
                throw new Exception("Nessuna mappatura da salvare");
            }

            // Indicizza mappature esistenti per (classe, materia)
            $existingMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');
            $existingIndex = [];
            foreach ($existingMappings as $existing) {
                $key = ($existing['id_classe_cv'] ?? '') . '_' . ($existing['id_materia_cv'] ?? '');
                if (!empty($existing['id_mapping'])) {
                    $existingIndex[$key] = $existing;
                }
            }

            $savedCount = 0;
            foreach ($mappings as $mapping) {
                $classId = $mapping['class_id'] ?? '';
                $className = $mapping['class_name'] ?? '';
                $subjectId = $mapping['subject_id'] ?? '';
                $subjectName = $mapping['subject_name'] ?? '';
                $courseId = $mapping['course_id'] ?? '';
                $courseName = $mapping['course_name'] ?? '';

                if (empty($classId) || empty($subjectId) || empty($courseId)) {
                    // Salta record incompleti
                    continue;
                }

                $key = $classId . '_' . $subjectId;
                $existing = $existingIndex[$key] ?? null;
                $mappingId = $existing['id_mapping'] ?? ('MAP_' . uniqid());

                $mappingData = [
                    'id_mapping'      => $mappingId,
                    'id_classe_cv'    => $classId,
                    'nome_classe_cv'  => $className,
                    'id_materia_cv'   => $subjectId,
                    'nome_materia_cv' => $subjectName,
                    'id_corso_gc'     => $courseId,
                    'nome_corso_gc'   => $courseName,
                    'data_mapping'    => date('Y-m-d H:i:s'),
                    'stato'           => 'attivo',
                    'note'            => $existing['note'] ?? ''
                ];

                if ($existing) {
                    $dbAdapter->updateClassroomMapping($mappingId, $mappingData);
                } else {
                    $dbAdapter->insertClassroomMapping($mappingData);
                }

                $savedCount++;
            }

            $success_message = "Salvate {$savedCount} mappature con successo! Ora puoi associare gli studenti.";
            $redirectAfterMapping($success_message);

        } elseif ($_POST['action'] === 'delete_mapping') {
            $mappingId = $_POST['mapping_id'] ?? '';
            if (!empty($mappingId)) {
                $dbAdapter->deleteClassroomMapping($mappingId);
                $success_message = "Mappatura eliminata con successo!";
            }

            $redirectAfterMapping($success_message);
        }

    } catch (Exception $e) {
        $error_message = "Errore: " . $e->getMessage();
    }
}

// Gestione messaggi da redirect
if (isset($_GET['success'])) {
    $success_message = $_GET['success'];
}

// Parametri filtro
$filterClasse = $_GET['filter_classe'] ?? null;
$highlightFilter = isset($_GET['highlight']) && $_GET['highlight'] === 'true';

// Carica mappature esistenti
$existingMappings = $dbAdapter->findAll('CLASSROOM_MAPPINGS');
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
$classeVivaTokenNotice = null;

if (($config['classeviva']['enabled'] ?? false)) {
    try {
        $classeVivaAPI = new ClasseVivaAPI($config);
        $classesWithSubjects = $classeVivaAPI->listTeacherClasses(true);

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
// Se l'API non risulta pronta mostra avviso token
$cvState = ClasseVivaTokenGuard::getTokenState($config);
if (!$cvState['ready']) {
    $classeVivaTokenNotice = $cvState['notice'] ?: 'Token ClasseViva assente. Autorizza la sessione da Integrazioni.';
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
    <title>Gestione Associazioni ClasseViva ↔ Google Classroom</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .subject-card {
            transition: all 0.2s;
            border-left: 4px solid transparent;
            margin-bottom: 1rem;
        }

        .subject-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }

        .subject-card.mapped {
            border-left-color: #198754;
            background-color: #f0f9f4;
        }

        .subject-card.unmapped {
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
            padding: 0.35rem 0.85rem;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .subject-badge {
            display: inline-block;
            padding: 0.35rem 0.85rem;
            background: #e7f1ff;
            color: #0d6efd;
            border-radius: 20px;
            font-size: 0.9rem;
            font-weight: 600;
        }

        .filter-section {
            background: #f8f9fa;
            padding: 1.5rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            position: sticky;
            top: 20px;
            z-index: 100;
        }
    </style>
    </head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-link-45deg"></i> Gestione Associazioni';
    $pageSubtitle = 'Step 1: Associa (classe + materia) -> Google Classroom | Step 2: Associa studenti per ogni materia';
    ob_start();
    if ($isWizardReturn):
        ?>
        <a class="btn btn-outline-light btn-sm me-2" href="uda_create.php?integration_updated=1#2">
            <i class="bi bi-x-lg"></i> Chiudi e torna al wizard
        </a>
        <?php
    endif;
    ?>
    <a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>
    <?php
    $headerActions = ob_get_clean();
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
        <?php if ($classeVivaTokenNotice): ?>
            <div class="alert alert-warning alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($classeVivaTokenNotice) ?>
                <a href="user_integrations.php#classeviva-section" class="btn btn-sm btn-outline-primary ms-2">
                    Vai alle integrazioni utente
                </a>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if (!$classeVivaEnabled || !$googleEnabled): ?>
            <div class="alert alert-warning">
                <h5 class="alert-heading"><i class="bi bi-exclamation-triangle"></i> Configurazione Incompleta</h5>
                <p class="mb-0">
                    <?php if (!$classeVivaEnabled): ?>
                        <i class="bi bi-x-circle"></i> ClasseViva non configurato<br>
                    <?php endif; ?>
                    <?php if (!$googleEnabled): ?>
                        <i class="bi bi-x-circle"></i> Google Classroom non configurato<br>
                    <?php endif; ?>
                    Verifica .env e config.yaml
                </p>
            </div>
        <?php else: ?>

        <!-- Statistiche -->
        <div class="stats-card">
            <div class="row">
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value"><?= $totalSubjects ?></span>
                        <span class="stat-label"><i class="bi bi-book"></i> Materie Totali</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value text-success"><?= $mappedCount ?></span>
                        <span class="stat-label"><i class="bi bi-check-circle"></i> Mappate</span>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="stat-box">
                        <span class="stat-value text-warning"><?= $unmappedCount ?></span>
                        <span class="stat-label"><i class="bi bi-exclamation-circle"></i> Da Mappare</span>
                    </div>
                </div>
            </div>
        </div>

        <!-- Alert Filtro Attivo -->
        <?php if ($highlightFilter && $filterClasse): ?>
        <div class="alert alert-info alert-dismissible fade show" role="alert">
            <h5 class="alert-heading">
                <i class="bi bi-funnel-fill"></i> Filtro Attivo
            </h5>
            <p class="mb-0">
                Stai visualizzando solo la classe <strong><?= htmlspecialchars($classesList[$filterClasse] ?? $filterClasse) ?></strong>.
                Puoi cambiare il filtro qui sotto o <a href="map_classes.php" class="alert-link">visualizzare tutte le classi</a>.
            </p>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <!-- Filtri -->
        <div class="filter-section">
            <div class="row align-items-center">
                <div class="col-md-4">
                    <label class="form-label mb-1"><i class="bi bi-funnel"></i> Filtra per Classe:</label>
                    <select id="classFilter" class="form-select">
                        <option value="">Tutte le classi</option>
                        <?php foreach ($classesList as $classId => $className): ?>
                            <option value="<?= htmlspecialchars($classId) ?>"
                                    <?= ($filterClasse && $filterClasse == $classId) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($className) ?>
                            </option>
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
                <div class="col-md-4">
                    <label class="form-label mb-1"><i class="bi bi-info-circle"></i> Legenda:</label>
                    <div>
                        <span class="badge bg-success me-2"><i class="bi bi-check"></i> Mappata</span>
                        <span class="badge bg-warning"><i class="bi bi-exclamation"></i> Da Mappare</span>
                    </div>
                </div>
            </div>
            <div class="row mt-3">
                <div class="col text-end">
                    <button type="button" class="btn btn-success" onclick="bulkSaveMappings()">
                        <i class="bi bi-save2"></i> Associa tutti i corsi selezionati
                    </button>
                </div>
            </div>
        </div>

        <!-- Lista Materie -->
        <div id="subjectsContainer">
            <?php if (empty($classeVivaSubjects)): ?>
                <div class="alert alert-warning">
                    <i class="bi bi-inbox"></i> Nessuna materia trovata su ClasseViva
                </div>
            <?php else: ?>
                <?php foreach ($classeVivaSubjects as $subject): ?>
                    <div class="card subject-card <?= $subject['mapped'] ? 'mapped' : 'unmapped' ?>"
                         data-class-id="<?= htmlspecialchars($subject['class_id']) ?>"
                         data-status="<?= $subject['mapped'] ? 'mapped' : 'unmapped' ?>">

                        <div class="card-body">
                            <div class="row align-items-center">
                                <!-- Classe e Materia -->
                                <div class="col-md-4">
                                    <div class="mb-2">
                                        <span class="class-badge">
                                            <i class="bi bi-building"></i> <?= htmlspecialchars($subject['class_name']) ?>
                                        </span>
                                    </div>
                                    <div>
                                        <span class="subject-badge">
                                            <i class="bi bi-book"></i> <?= htmlspecialchars($subject['subject_name']) ?>
                                        </span>
                                    </div>
                                    <small class="text-muted d-block mt-1">
                                        ID Materia: <?= htmlspecialchars($subject['subject_id']) ?>
                                    </small>
                                </div>

                                <!-- Associazione Google Classroom -->
                                <div class="col-md-5">
                                    <?php if ($subject['mapped']): ?>
                                        <div class="alert alert-success mb-0">
                                            <div class="d-flex justify-content-between align-items-center">
                                                <div>
                                                    <i class="bi bi-google"></i> <strong>Associato a:</strong><br>
                                                    <?= htmlspecialchars($subject['mapped_course_name']) ?>
                                                </div>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Vuoi eliminare questa associazione?')">
                                                    <input type="hidden" name="action" value="delete_mapping">
                                                    <input type="hidden" name="mapping_id" value="<?= htmlspecialchars($subject['mapping_id']) ?>">
                                                    <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <form method="POST" class="mapping-form">
                                            <input type="hidden" name="action" value="save_mapping">
                                            <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo) ?>">
                                            <input type="hidden" name="class_id" value="<?= htmlspecialchars($subject['class_id']) ?>">
                                            <input type="hidden" name="class_name" value="<?= htmlspecialchars($subject['class_name']) ?>">
                                            <input type="hidden" name="subject_id" value="<?= htmlspecialchars($subject['subject_id']) ?>">
                                            <input type="hidden" name="subject_name" value="<?= htmlspecialchars($subject['subject_name']) ?>">
                                            <input type="hidden" name="course_name" id="course_name_<?= htmlspecialchars($subject['key']) ?>">

                                            <label class="form-label small">Seleziona Corso Google Classroom:</label>
                                            <div class="input-group">
                                                <select name="course_id" class="form-select" required
                                                        onchange="updateCourseName('<?= htmlspecialchars($subject['key']) ?>', this)">
                                                    <option value="">-- Seleziona corso --</option>
                                                    <?php foreach ($googleCourses as $course): ?>
                                                        <option value="<?= htmlspecialchars($course['id']) ?>"
                                                                data-course-name="<?= htmlspecialchars($course['name']) ?>">
                                                            <?= htmlspecialchars($course['name']) ?>
                                                        </option>
                                                    <?php endforeach; ?>
                                                </select>
                                                <button type="submit" class="btn btn-primary">
                                                    <i class="bi bi-save"></i> Salva
                                                </button>
                                            </div>
                                        </form>
                                    <?php endif; ?>
                                </div>

                                <!-- Azioni -->
                                <div class="col-md-3 text-end">
                                    <?php if ($subject['mapped']): ?>
                                        <a href="map_students.php?mapping_id=<?= urlencode($subject['mapping_id']) ?>&return_to=<?= urlencode($returnTo) ?>"
                                           class="btn btn-success btn-lg">
                                            <i class="bi bi-people-fill"></i> Associa Studenti
                                        </a>
                                    <?php else: ?>
                                        <button class="btn btn-secondary btn-lg" disabled>
                                            <i class="bi bi-people-fill"></i> Associa Studenti
                                        </button>
                                        <small class="text-muted d-block mt-1">Prima associa il corso</small>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Filtri
        document.getElementById('classFilter')?.addEventListener('change', applyFilters);
        document.getElementById('statusFilter')?.addEventListener('change', applyFilters);

        function applyFilters() {
            const classFilter = document.getElementById('classFilter').value;
            const statusFilter = document.getElementById('statusFilter').value;
            const cards = document.querySelectorAll('.subject-card');

            cards.forEach(card => {
                const classId = card.dataset.classId;
                const status = card.dataset.status;
                let show = true;

                if (classFilter && classId !== classFilter) show = false;
                if (statusFilter && status !== statusFilter) show = false;

                card.style.display = show ? '' : 'none';
            });
        }

        // Applica filtro automaticamente al caricamento se presente nei parametri URL
        window.addEventListener('DOMContentLoaded', function() {
            <?php if ($filterClasse): ?>
            // Il filtro è già pre-selezionato nel select via PHP
            // Applica il filtro visualmente
            applyFilters();

            <?php if ($highlightFilter): ?>
            // Scroll all'alert se highlight attivo
            setTimeout(function() {
                const alert = document.querySelector('.alert-info');
                if (alert) {
                    alert.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
            }, 300);
            <?php endif; ?>
            <?php endif; ?>
        });

        // Aggiorna nome corso nascosto prima del submit
        function updateCourseName(key, selectElement) {
            const selectedOption = selectElement.options[selectElement.selectedIndex];
            const courseName = selectedOption.getAttribute('data-course-name') || '';
            const hidden = document.getElementById('course_name_' + key);
            if (hidden) {
                hidden.value = courseName;
            }
        }

        // Salvataggio bulk di tutte le mappature selezionate
        function bulkSaveMappings() {
            const forms = document.querySelectorAll('.mapping-form');
            const mappings = [];

            forms.forEach(form => {
                const courseSelect = form.querySelector('select[name="course_id"]');
                if (!courseSelect) return;

                const courseId = courseSelect.value;
                if (!courseId) return; // Salta se non è stato selezionato alcun corso

                const classIdInput = form.querySelector('input[name="class_id"]');
                const classNameInput = form.querySelector('input[name="class_name"]');
                const subjectIdInput = form.querySelector('input[name="subject_id"]');
                const subjectNameInput = form.querySelector('input[name="subject_name"]');
                const courseNameInput = form.querySelector('input[name="course_name"]');

                const classId = classIdInput ? classIdInput.value : '';
                const className = classNameInput ? classNameInput.value : '';
                const subjectId = subjectIdInput ? subjectIdInput.value : '';
                const subjectName = subjectNameInput ? subjectNameInput.value : '';

                let courseName = courseNameInput ? courseNameInput.value : '';
                if (!courseName) {
                    const opt = courseSelect.options[courseSelect.selectedIndex];
                    if (opt) {
                        courseName = opt.getAttribute('data-course-name') || opt.textContent || '';
                    }
                }

                if (!classId || !subjectId || !courseId) {
                    return;
                }

                mappings.push({
                    class_id: classId,
                    class_name: className,
                    subject_id: subjectId,
                    subject_name: subjectName,
                    course_id: courseId,
                    course_name: courseName
                });
            });

            if (mappings.length === 0) {
                alert('Seleziona almeno un corso Classroom nelle materie da mappare.');
                return;
            }

            if (!confirm('Vuoi associare tutti i corsi selezionati alle rispettive materie?')) {
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

            const returnInput = document.createElement('input');
            returnInput.name = 'return_to';
            returnInput.value = <?= json_encode($returnTo, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
            form.appendChild(returnInput);

            document.body.appendChild(form);
            form.submit();
        }
    </script>
</body>
</html>

