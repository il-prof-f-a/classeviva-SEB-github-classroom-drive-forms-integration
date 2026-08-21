<?php

/**
 * Gestione Voti - Visualizza, inserisci e gestisci tutti i voti
 */

define('REQUIRES_CLASSEVIVA', true);
require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;

error_reporting(E_ALL);

$classevivaTokenState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classevivaTokenState['ready'];
$cvTokenNotice = $classevivaTokenState['notice'] ?? null;

$classiMaterie = [];
$cv = $cvReady ? new ClasseVivaAPI($config) : null;
$dbAdapter = null;
try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
} catch (Exception $e) {
    $dbAdapter = null;
}
$successMessage = null;
$errorMessage = null;

if ($cvReady && $cv) {
    try {
        $classiMaterie = $cv->getClassesWithTeacherSubjects();
    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
    }
} else {
    $errorMessage = $errorMessage ?? $cvTokenNotice;
}

// Gestione inserimento nuovo voto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'insert_grade') {
    try {
        if (!$cvReady || !$cv) {
            throw new Exception($cvTokenNotice ?? 'Token ClasseViva non disponibile per pubblicare voti.');
        }
        $gradeData = [
            'student_id' => $_POST['student_id'],
            'class_id' => $_POST['class_id'],
            'subject_id' => $_POST['subject_id'],
            'subject_name' => $_POST['subject_name'],
            'grade_type' => $_POST['grade_type'],
            'grade_value' => $_POST['grade_value'],
            'date' => $_POST['date'],
            'notes' => $_POST['notes'] ?? '',
            'notes_2' => $_POST['notes_2'] ?? '',
            'uda_id' => $_POST['uda_id'] ?? '',
            'link_origine' => app_url(
                'public/manage_grades.php?class_id=' . urlencode((string)($_POST['class_id'] ?? ''))
                . '&subject_id=' . urlencode((string)($_POST['subject_id'] ?? ''))
            )
        ];

        $result = $cv->publishGrade($gradeData);

        if ($result['success']) {
            $successMessage = "Voto pubblicato con successo! Valore: {$result['grade_value']}, Tipo: {$result['grade_type']}";
        } else {
            $errorMessage = "Errore durante la pubblicazione del voto";
        }

    } catch (Exception $e) {
        $errorMessage = "Errore inserimento voto: " . $e->getMessage();
    }
}

// Se sono stati scelti classe e materia, recupera i voti
$grades = [];
$students = [];
$selectedClassId = $_GET['class_id'] ?? ($_POST['class_id'] ?? null);
$selectedSubjectId = $_GET['subject_id'] ?? ($_POST['subject_id'] ?? null);
$selectedClassName = '';
$selectedSubjectName = '';

if ($selectedClassId && $selectedSubjectId) {
    if (!$cvReady || !$cv) {
        $errorMessage = $cvTokenNotice ?? 'Token ClasseViva non disponibile per recuperare voti.';
    } else {
        try {
            // Recupera studenti della classe
            $students = $cv->getStudentiClasse($selectedClassId);

            // Trova nome classe e materia
            foreach ($classiMaterie as $classe) {
                if ($classe['id'] === $selectedClassId) {
                    $selectedClassName = $classe['name'];
                    foreach ($classe['subjects'] as $materia) {
                        if ($materia['id'] === $selectedSubjectId) {
                            $selectedSubjectName = $materia['name'];
                            break 2;
                        }
                    }
                }
            }

            // Recupera voti per ogni studente
            foreach ($students as $student) {
                $studentGrades = $cv->getStudentGrades(
                    $student['id'],
                    $selectedClassId,
                    $selectedSubjectId
                );

                // Aggiungi anche studenti senza voti per permettere inserimento
                $grades[$student['id']] = [
                    'student' => $student,
                    'grades' => $studentGrades
                ];
            }

        } catch (Exception $e) {
            $errorMessage = "Errore recupero voti: " . $e->getMessage();
        }
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Voti - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        body {
            background-color: #f5f5f5;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }

        .main-container {
            max-width: 1600px;
            margin: 30px auto;
            padding: 0 15px;
        }

        .filter-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            margin-bottom: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }

        .grades-table {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,.1);
        }

        .grade-badge {
            cursor: pointer;
            transition: transform 0.2s;
            position: relative;
        }

        .grade-badge:hover {
            transform: scale(1.1);
            z-index: 100;
        }

        .grade-badge.platform-inserted {
            background-color: #17a2b8 !important;
            border: 2px solid #0c7489;
        }

        .badge {
            padding: 6px 12px;
            margin: 2px;
        }

        .btn-insert-grade {
            padding: 4px 8px;
            font-size: 12px;
        }

        .quick-insert-btns button {
            margin: 2px;
            padding: 3px 8px;
            font-size: 11px;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-card-checklist"></i> Gestione Voti';
    $cvUser = $config['classeviva']['token']['ident'] ?? 'Docente';
    $pageSubtitle = 'Docente: ' . $cvUser;
    $headerActions = '<a href="verify_grades.php" class="btn btn-warning btn-sm">'
        . '<i class="fas fa-sync-alt me-1"></i>Gestione voti Classe Viva</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="main-container">
        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle me-2"></i>
                <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="fas fa-exclamation-triangle-fill me-2"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <br>
                <small class="text-muted">
                    Apri <a href="user_integrations.php#classeviva-section">Integrazioni</a> per aggiornare il token.
                </small>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <!-- Filtri -->
        <div class="filter-card">
            <h5 class="mb-3"><i class="fas fa-filter me-2"></i>Filtri</h5>
            <form method="GET" class="row g-3">
                <div class="col-md-5">
                    <label for="class_id" class="form-label">Classe</label>
                    <select name="class_id" id="class_id" class="form-select" required onchange="updateSubjects()">
                        <option value="">Seleziona una classe...</option>
                        <?php foreach ($classiMaterie as $class): ?>
                            <option value="<?= htmlspecialchars($class['id']) ?>"
                                    <?= $class['id'] === $selectedClassId ? 'selected' : '' ?>
                                    data-subjects='<?= \App\Core\Security\OutputEncoder::json($class['subjects']) ?>'>
                                <?= htmlspecialchars($class['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-5">
                    <label for="subject_id" class="form-label">Materia</label>
                    <select name="subject_id" id="subject_id" class="form-select" required>
                        <option value="">Seleziona prima una classe...</option>
                    </select>
                </div>

                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-search me-2"></i>Cerca
                    </button>
                </div>
            </form>
        </div>

        <!-- Tabella Voti -->
        <?php if (!empty($grades)): ?>
            <div class="grades-table">
                <div class="table-responsive">
                    <table class="table table-striped table-hover mb-0">
                        <thead class="table-dark">
                            <tr>
                                <th>Studente</th>
                                <th class="text-center">Orali</th>
                                <th class="text-center">Scritti</th>
                                <th class="text-center">Pratici</th>
                                <th class="text-center">Media</th>
                                <th class="text-center">Azioni</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($grades as $studentId => $data): ?>
                                <?php
                                $student = $data['student'];
                                $studentGrades = $data['grades'];
                                $averages = $cv->calculateGradeAverage($studentGrades);
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= htmlspecialchars($student['cognome'] . ' ' . $student['nome']) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <?php foreach ($studentGrades['orale'] as $grade): ?>
                                            <?= renderGradeBadge($grade, $dbAdapter) ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php foreach ($studentGrades['scritto'] as $grade): ?>
                                            <?= renderGradeBadge($grade, $dbAdapter) ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-center">
                                        <?php foreach ($studentGrades['pratico'] as $grade): ?>
                                            <?= renderGradeBadge($grade, $dbAdapter) ?>
                                        <?php endforeach; ?>
                                    </td>
                                    <td class="text-center">
                                        <strong><?= number_format($averages['generale'], 2) ?></strong>
                                    </td>
                                    <td class="text-center">
                                        <button class="btn btn-success btn-sm btn-insert-grade"
                                                onclick="showInsertGradeModal('<?= htmlspecialchars($studentId) ?>', '<?= htmlspecialchars($student['cognome'] . ' ' . $student['nome']) ?>')">
                                            <i class="fas fa-plus me-1"></i>Inserisci
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div class="mt-3 text-muted">
                <small>
                    <i class="fas fa-info-circle me-1"></i>
                    <span class="badge bg-info">Badge azzurro</span> = Voto inserito automaticamente dalla piattaforma
                </small>
            </div>
        <?php elseif ($selectedClassId && $selectedSubjectId): ?>
            <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>
                Nessun voto trovato per questa classe e materia.
            </div>
        <?php endif; ?>
    </div>

    <!-- Modal Inserimento Voto -->
    <div class="modal fade" id="insertGradeModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST" id="insertGradeForm">
                    <input type="hidden" name="action" value="insert_grade">
                    <input type="hidden" name="student_id" id="modal_student_id">
                    <input type="hidden" name="class_id" value="<?= htmlspecialchars($selectedClassId) ?>">
                    <input type="hidden" name="subject_id" value="<?= htmlspecialchars($selectedSubjectId) ?>">
                    <input type="hidden" name="subject_name" value="<?= htmlspecialchars($selectedSubjectName) ?>">

                    <div class="modal-header">
                        <h5 class="modal-title">Inserisci Voto</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <div class="mb-3">
                            <label class="form-label"><strong>Studente:</strong></label>
                            <div id="modal_student_name" class="form-control-plaintext"></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><strong>Classe:</strong></label>
                            <div class="form-control-plaintext"><?= htmlspecialchars($selectedClassName) ?></div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label"><strong>Materia:</strong></label>
                            <div class="form-control-plaintext"><?= htmlspecialchars($selectedSubjectName) ?></div>
                        </div>

                        <div class="mb-3">
                            <label for="grade_type" class="form-label">Tipo Voto *</label>
                            <select name="grade_type" id="grade_type" class="form-select" required>
                                <option value="orale">Orale</option>
                                <option value="scritto">Scritto</option>
                                <option value="pratico">Pratico</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="grade_value" class="form-label">Valore Voto *</label>
                            <div class="input-group">
                                <input type="text" name="grade_value" id="grade_value" class="form-control" required
                                       placeholder="1-10, 'a' (assente), 'i' (impreparato)">
                                <button type="button" class="btn btn-outline-secondary dropdown-toggle" data-bs-toggle="dropdown">
                                    Rapido
                                </button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><h6 class="dropdown-header">Valori Numerici</h6></li>
                                    <?php for ($v = 10; $v >= 4; $v -= 0.5): ?>
                                        <li><a class="dropdown-item" href="#" onclick="setGradeValue('<?= $v ?>')"><?= $v ?></a></li>
                                    <?php endfor; ?>
                                    <li><hr class="dropdown-divider"></li>
                                    <li><h6 class="dropdown-header">Valori Speciali</h6></li>
                                    <li><a class="dropdown-item" href="#" onclick="setGradeValue('i')">Impreparato (i)</a></li>
                                    <li><a class="dropdown-item" href="#" onclick="setGradeValue('a')">Assente (a)</a></li>
                                </ul>
                            </div>
                            <small class="text-muted">Inserisci un valore numerico (1-10), 'a' per assente, 'i' per impreparato</small>
                        </div>

                        <div class="mb-3">
                            <label for="date" class="form-label">Data *</label>
                            <input type="date" name="date" id="date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="mb-3">
                            <label for="notes" class="form-label">Note interne</label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"
                                      placeholder="Annotazioni interne..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="notes_2" class="form-label">Note per la famiglia</label>
                            <textarea name="notes_2" id="notes_2" class="form-control" rows="3"
                                      placeholder="Nota visibile alla famiglia..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="uda_id" class="form-label">ID UDA (opzionale)</label>
                            <input type="text" name="uda_id" id="uda_id" class="form-control"
                                   placeholder="Collegamento a UDA specifica">
                        </div>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save me-1"></i>Pubblica Voto
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Dettagli Voto -->
    <div class="modal fade" id="gradeDetailsModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Dettagli Voto</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body grade-modal-content" id="gradeDetailsContent">
                    <!-- Contenuto caricato dinamicamente -->
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-warning btn-action" onclick="editGrade()">
                        <i class="fas fa-edit me-1"></i>Modifica
                    </button>
                    <button type="button" class="btn btn-danger btn-action" onclick="deleteGrade()">
                        <i class="fas fa-trash me-1"></i>Elimina
                    </button>
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Chiudi</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mappa classi -> materie
        const classSubjectsMap = {};
        <?php foreach ($classiMaterie as $class): ?>
            classSubjectsMap[<?= \App\Core\Security\OutputEncoder::json((string)$class['id']) ?>] = <?= \App\Core\Security\OutputEncoder::json($class['subjects']) ?>;
        <?php endforeach; ?>

        const selectedSubjectId = '<?= $selectedSubjectId ?? '' ?>';

        function updateSubjects() {
            const classSelect = document.getElementById('class_id');
            const subjectSelect = document.getElementById('subject_id');
            const selectedClassId = classSelect.value;

            subjectSelect.innerHTML = '<option value="">Seleziona una materia...</option>';

            if (selectedClassId && classSubjectsMap[selectedClassId]) {
                classSubjectsMap[selectedClassId].forEach(subject => {
                    const option = document.createElement('option');
                    option.value = subject.id;
                    option.textContent = subject.name;
                    if (subject.id === selectedSubjectId) {
                        option.selected = true;
                    }
                    subjectSelect.appendChild(option);
                });
            }
        }

        // Inizializza materie se classe già selezionata
        if (document.getElementById('class_id').value) {
            updateSubjects();
        }

        // Mostra modal inserimento voto
        function showInsertGradeModal(studentId, studentName) {
            document.getElementById('modal_student_id').value = studentId;
            document.getElementById('modal_student_name').textContent = studentName;

            // Reset form
            document.getElementById('grade_value').value = '';
            document.getElementById('notes').value = '';
            document.getElementById('notes_2').value = '';
            document.getElementById('uda_id').value = '';
            document.getElementById('date').value = '<?= date('Y-m-d') ?>';

            const modal = new bootstrap.Modal(document.getElementById('insertGradeModal'));
            modal.show();
        }

        // Set grade value from dropdown
        function setGradeValue(value) {
            document.getElementById('grade_value').value = value;
        }

        // Mostra dettagli voto in modal
        function showGradeDetails(gradeData) {
            const content = document.getElementById('gradeDetailsContent');
            const data = JSON.parse(decodeURIComponent(gradeData));

            let html = `
                <div class="row">
                    <div class="col-md-6">
                        <div class="info-label">Valore</div>
                        <div class="info-value"><span class="badge bg-primary fs-5">${data.value}</span></div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-label">Tipo</div>
                        <div class="info-value">${data.type}</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-6">
                        <div class="info-label">Data</div>
                        <div class="info-value">${data.date || 'N/D'}</div>
                    </div>
                    <div class="col-md-6">
                        <div class="info-label">Slot</div>
                        <div class="info-value">${data.slot}</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="info-label">Note interne</div>
                        <div class="info-value">${data.notes || 'Nessuna nota'}</div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="info-label">Codice Descrizione</div>
                        <div class="info-value"><code>${data.description_code}</code></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-12">
                        <div class="info-label">ID Evento ClasseViva</div>
                        <div class="info-value"><code>${data.evento_id || 'N/D'}</code></div>
                    </div>
                </div>
            `;

            // Controlla se è un voto inserito dalla piattaforma
            // Cerca sia versione normale che HTML-encoded del tag
            if (data.notes && (
				data.notes.includes('<') ||
                data.notes.includes('&lt;') ||
                data.notes.includes('﹤') ||
                data.notes.toLowerCase().includes('uda_s=')
            )) {
                // Prova diversi pattern per estrarre l'ID
                let match = data.notes.match(/<uda_s=([^>]+)>/) ||
                           data.notes.match(/&lt;uda_s=([^&]+)&gt;/) ||
                           data.notes.match(/﹤uda_s=([^﹥]+)﹥/) ||
						    data.notes.match(/<([^>]+)>/) ||
                           data.notes.match(/&lt;([^&]+)&gt;/) ||
                           data.notes.match(/﹤([^﹥]+)﹥/) ||
                           data.notes.match(/uda_s=(\S+)/);

                if (match) {
                    const trackingId = match[1].trim();
                    html += `
                        <div class="alert alert-info mt-3">
                            <i class="fas fa-robot me-2"></i>
                            <strong>Voto inserito automaticamente dalla piattaforma</strong><br>
                            <small>ID Tracciabilità: <code>${trackingId}</code></small>
                        </div>
                    `;
                }
            }

            if (data.platform_inserted && data.link_origine) {
                const safeLink = encodeURI(data.link_origine);
                html += `
                    <div class="mt-3">
                        <a class="btn btn-outline-primary btn-sm" href="${safeLink}" target="_blank" rel="noopener">
                            <i class="fas fa-up-right-from-square me-1"></i> Apri origine voto
                        </a>
                    </div>
                `;
            }

            content.innerHTML = html;

            // Salva evento_id per operazioni future
            window.currentGradeEventoId = data.evento_id;
            window.currentGradeData = data;

            const modal = new bootstrap.Modal(document.getElementById('gradeDetailsModal'));
            modal.show();
        }

        // Stub per modifica voto
        function editGrade() {
            alert('Funzionalità di modifica voto in fase di sviluppo.\n\nEvent ID: ' + (window.currentGradeEventoId || 'N/D'));
        }

        // Stub per cancellazione voto
        function deleteGrade() {
            if (confirm('Sei sicuro di voler cancellare questo voto?\n\nQuesta funzionalità sarà implementata in seguito.')) {
                alert('Funzionalità di cancellazione voto in fase di sviluppo.\n\nEvent ID: ' + (window.currentGradeEventoId || 'N/D'));
            }
        }
    </script>
</body>
</html>

<?php
/**
 * Helper function per renderizzare un badge voto
 */
function renderGradeBadge($grade, $dbAdapter) {
    $value = $grade['value'] ?? '';
    $notes = $grade['notes'] ?? '';
    $date = $grade['date'] ?? '';
    $slot = $grade['slot'] ?? '';
    $type = $grade['type'] ?? '';
    $eventId = $grade['evento_id'] ?? '';

    // Determina se è un voto inserito dalla piattaforma
    // Cerca sia versione normale che HTML-encoded del tag
    $isPlatformInserted = (
        strpos($notes, '<') !== false ||
        strpos($notes, '&lt;') !== false ||
        strpos($notes, '﹤') !== false ||
        stripos($notes, 'uda_s=') !== false  // Case-insensitive come fallback
    );

    $badgeClass = $isPlatformInserted ? 'badge bg-info grade-badge platform-inserted' : 'badge bg-primary grade-badge';

    // Tooltip info
    $tooltip = "Data: {$date}\nNote interne: " . (empty($notes) ? 'Nessuna' : $notes);

    global $selectedClassId, $selectedSubjectId;
    $pageFallback = '';
    if (!empty($selectedClassId) && !empty($selectedSubjectId)) {
        $pageFallback = app_url(
            'public/manage_grades.php?class_id=' . urlencode((string)$selectedClassId)
            . '&subject_id=' . urlencode((string)$selectedSubjectId)
        );
    }

    $trackingId = extractTrackingIdFromNotes($notes);
    $linkOrigine = $isPlatformInserted ? resolveLinkOrigine($trackingId, $eventId, $dbAdapter) : '';
    if ($linkOrigine === '' && $pageFallback !== '') {
        $linkOrigine = $pageFallback;
    }

    // Dati per modal
    $gradeData = json_encode([
        'value' => $value,
        'notes' => $notes,
        'date' => $date,
        'slot' => $slot,
        'type' => $type,
        'evento_id' => $eventId,
        'description_code' => $grade['description_code'] ?? '',
        'platform_inserted' => $isPlatformInserted,
        'link_origine' => $linkOrigine
    ]);

    return sprintf(
        '<span class="%s" title="%s" onclick="showGradeDetails(\'%s\')">%s</span>',
        $badgeClass,
        htmlspecialchars($tooltip),
        htmlspecialchars(urlencode($gradeData)),
        htmlspecialchars($value)
    );
}


function extractTrackingIdFromNotes(string $notes): ?string
{
    if ($notes === '') {
        return null;
    }

    $decodedNotes = html_entity_decode($notes, ENT_QUOTES);
    $patterns = [
        '/<uda_s=([^>]+)>/i',
        '/<([^>]+)>/',
        '/uda_s=([A-Za-z0-9_\-]+)/i'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $decodedNotes, $match)) {
            return trim($match[1]);
        }
    }

    return null;
}

function resolveLinkOrigine(?string $trackingId, string $eventId, $dbAdapter): string
{
    static $cache = [];

    if (!$trackingId || !$dbAdapter) {
        return '';
    }

    if (array_key_exists($trackingId, $cache)) {
        return $cache[$trackingId] ?? '';
    }

    $linkOrigine = '';
    $fallbackLink = '';
    try {
        $row = $dbAdapter->findOne('VOTI', 'id_voto', $trackingId);
        if (!$row && $eventId !== '') {
            $row = $dbAdapter->findOne('VOTI', 'id_annotazione_cv', $eventId);
        }
        if (is_array($row)) {
            $linkOrigine = $row['link_origine'] ?? '';
            if ($linkOrigine === '') {
                $udaId = $row['id_uda'] ?? '';
                $classId = $row['id_classe_cv'] ?? '';
                $subjectId = $row['id_materia_cv'] ?? '';
                if ($udaId !== '') {
                    $fallbackLink = app_url('public/uda_grades.php?id=' . urlencode((string)$udaId));
                } elseif ($classId !== '' && $subjectId !== '') {
                    $fallbackLink = app_url(
                        'public/manage_grades.php?class_id=' . urlencode((string)$classId)
                        . '&subject_id=' . urlencode((string)$subjectId)
                    );
                }
            }
        }
    } catch (Exception $e) {
        $linkOrigine = '';
    }

    $cache[$trackingId] = $linkOrigine !== '' ? $linkOrigine : $fallbackLink;
    return $cache[$trackingId];
}
?>
