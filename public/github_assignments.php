<?php
/**
 * Gestione Assignment GitHub Classroom
 * Permette di collegare assignment GitHub alle UDA e gestire pubblicazione
 */

require_once '../bootstrap.php';

use App\Integration\GitHubIntegration;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;

$pageTitle = "Gestione Assignment GitHub";

// Inizializza servizi
$github = new GitHubIntegration($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);

// Carica token da sessione
$github->loadTokenFromSession();
$isAuthenticated = $github->isAuthenticated();

// Variabili
$successMessage = null;
$errorMessage = null;

// Gestione creazione nuovo assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'create_assignment') {
    try {
        $idUda = $_POST['id_uda'] ?? '';
        $idClassroomMap = $_POST['id_classroom_map'] ?? '';
        $assignmentName = $_POST['assignment_name'] ?? '';
        $assignmentType = $_POST['assignment_type'] ?? 'individual';
        $invitationLink = $_POST['invitation_link'] ?? '';
        $deadline = $_POST['deadline'] ?? '';
        $starterCodeUrl = $_POST['starter_code_url'] ?? '';
        $note = $_POST['note'] ?? '';

        // Validazione
        if (!$idUda || !$assignmentName || !$invitationLink) {
            throw new Exception("UDA, Nome Assignment e Link Invito sono obbligatori");
        }

        // Valida formato link invito
        if (!preg_match('#^https://classroom\.github\.com/a/[a-zA-Z0-9_-]+$#', $invitationLink)) {
            throw new Exception("Link invito non valido. Deve essere nel formato: https://classroom.github.com/a/xxxxx");
        }

        // Estrai slug dal link
        preg_match('#/a/([a-zA-Z0-9_-]+)$#', $invitationLink, $matches);
        $slug = $matches[1] ?? '';

        // Crea nuovo assignment
        $assignmentId = 'GHASS_' . uniqid();
        $newAssignment = [
            'id_assignment' => $assignmentId,
            'id_uda' => $idUda,
            'id_classroom_map' => $idClassroomMap,
            'github_assignment_id' => '', // Non disponibile via API
            'assignment_name' => $assignmentName,
            'assignment_type' => $assignmentType,
            'invitation_link' => $invitationLink,
            'slug' => $slug,
            'deadline' => $deadline,
            'starter_code_url' => $starterCodeUrl,
            'max_teams' => '',
            'max_members' => '',
            'auto_grading_config' => '',
            'pubblicato_gc' => 'NO',
            'data_creazione' => date('d/m/Y H:i:s'),
            'data_pubblicazione' => '',
            'stato' => 'attivo',
            'note' => $note
        ];

        $dbAdapter->insertRow('GITHUB_ASSIGNMENTS', $newAssignment);
        $successMessage = "Assignment creato con successo! ID: {$assignmentId}";

    } catch (Exception $e) {
        $errorMessage = "Errore: " . $e->getMessage();
    }
}

// Gestione eliminazione assignment
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['id'])) {
    try {
        $dbAdapter->deleteRow('GITHUB_ASSIGNMENTS', 'id_assignment', $_GET['id']);
        $successMessage = "Assignment eliminato con successo!";
    } catch (Exception $e) {
        $errorMessage = "Errore nell'eliminazione: " . $e->getMessage();
    }
}

// Gestione pubblicazione singola su Google Classroom
if (isset($_GET['action']) && $_GET['action'] === 'publish_gc' && isset($_GET['id'])) {
    try {
        // TODO: Implementare pubblicazione singola assignment su Google Classroom
        // Per ora segna solo come pubblicato
        $dbAdapter->updateRow('GITHUB_ASSIGNMENTS', 'id_assignment', $_GET['id'], [
            'pubblicato_gc' => 'SI',
            'data_pubblicazione' => date('d/m/Y H:i:s')
        ]);
        $successMessage = "Assignment segnato come pubblicato!";
    } catch (Exception $e) {
        $errorMessage = "Errore nella pubblicazione: " . $e->getMessage();
    }
}

// Carica dati per la pagina
$assignments = $dbAdapter->findAll('GITHUB_ASSIGNMENTS');
$udas = $udaManager->getAllUDAs();
$classroomMappings = $dbAdapter->findAll('GITHUB_CLASSROOMS');

// Applica filtri se presenti
$filterUda = $_GET['filter_uda'] ?? '';
$filterStato = $_GET['filter_stato'] ?? '';

$filteredAssignments = array_filter($assignments, function($assignment) use ($filterUda, $filterStato) {
    if ($filterUda && $assignment['id_uda'] !== $filterUda) {
        return false;
    }
    if ($filterStato && $assignment['stato'] !== $filterStato) {
        return false;
    }
    return true;
});

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $pageTitle ?> - Sistema UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = $pageTitle ?? 'Assignments GitHub';
    ob_start();
    ?>
    <a href="github_classroom_mapping.php" class="btn btn-outline-light btn-sm">
        <i class="bi bi-link-45deg"></i> Mappatura Classroom
    </a>
    <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="bi bi-arrow-left"></i> Dashboard
    </a>
    <?php
    $headerActions = ob_get_clean();
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container-fluid mt-4">
    <div class="row">
        <div class="col-md-12">
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

            <!-- Info Box -->
            <div class="alert alert-info">
                <h6 class="alert-heading"><i class="bi bi-info-circle"></i> Come Funziona</h6>
                <ol class="mb-0">
                    <li><strong>Crea l'assignment</strong> su <a href="https://classroom.github.com" target="_blank">GitHub Classroom</a></li>
                    <li><strong>Copia il link di invito</strong> (es: https://classroom.github.com/a/abc123)</li>
                    <li><strong>Usa il form qui sotto</strong> per collegarlo a una UDA</li>
                    <li><strong>Pubblica su Google Classroom</strong> quando pubblichi la UDA</li>
                </ol>
            </div>

            <!-- Filtri -->
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="bi bi-funnel"></i> Filtri</h5>
                </div>
                <div class="card-body">
                    <form method="GET" class="row g-3">
                        <div class="col-md-4">
                            <label class="form-label">Filtra per UDA</label>
                            <select name="filter_uda" class="form-select">
                                <option value="">-- Tutte le UDA --</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                            <?= $filterUda === $uda->id_uda ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($uda->titolo) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label">Filtra per Stato</label>
                            <select name="filter_stato" class="form-select">
                                <option value="">-- Tutti gli stati --</option>
                                <option value="attivo" <?= $filterStato === 'attivo' ? 'selected' : '' ?>>Attivo</option>
                                <option value="bozza" <?= $filterStato === 'bozza' ? 'selected' : '' ?>>Bozza</option>
                            </select>
                        </div>
                        <div class="col-md-4 d-flex align-items-end">
                            <button type="submit" class="btn btn-primary me-2">
                                <i class="bi bi-search"></i> Filtra
                            </button>
                            <a href="?" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Reset
                            </a>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Lista Assignment -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="bi bi-list-task"></i> Assignment Esistenti</h5>
                </div>
                <div class="card-body">
                    <?php if (empty($filteredAssignments)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i>
                            <?= $filterUda || $filterStato ? 'Nessun assignment trovato con i filtri selezionati.' : 'Nessun assignment configurato. Usa il form qui sotto per crearne uno.' ?>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($filteredAssignments as $assignment): ?>
                                <?php
                                // Trova UDA associata
                                $udaAssociata = null;
                                foreach ($udas as $uda) {
                                    if ($uda->id_uda === $assignment['id_uda']) {
                                        $udaAssociata = $uda;
                                        break;
                                    }
                                }
                                ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100 <?= $assignment['pubblicato_gc'] === 'SI' ? 'border-success' : '' ?>">
                                        <div class="card-header <?= $assignment['pubblicato_gc'] === 'SI' ? 'bg-success text-white' : 'bg-light' ?>">
                                            <div class="d-flex justify-content-between align-items-start">
                                                <h6 class="mb-0">
                                                    <i class="bi bi-<?= $assignment['assignment_type'] === 'group' ? 'people' : 'person' ?>"></i>
                                                    <?= htmlspecialchars($assignment['assignment_name']) ?>
                                                </h6>
                                                <span class="badge bg-<?= $assignment['stato'] === 'attivo' ? 'primary' : 'secondary' ?>">
                                                    <?= htmlspecialchars($assignment['stato']) ?>
                                                </span>
                                            </div>
                                        </div>
                                        <div class="card-body">
                                            <p class="mb-2">
                                                <strong>UDA:</strong><br>
                                                <small class="text-muted">
                                                    <?= $udaAssociata ? htmlspecialchars($udaAssociata->titolo) : 'N/D' ?>
                                                </small>
                                            </p>
                                            <p class="mb-2">
                                                <strong>Tipo:</strong>
                                                <span class="badge bg-info">
                                                    <?= $assignment['assignment_type'] === 'individual' ? 'Individuale' : 'Gruppo' ?>
                                                </span>
                                            </p>
                                            <?php if ($assignment['deadline']): ?>
                                                <p class="mb-2">
                                                    <strong>Scadenza:</strong>
                                                    <small><?= htmlspecialchars($assignment['deadline']) ?></small>
                                                </p>
                                            <?php endif; ?>
                                            <p class="mb-2">
                                                <strong>Link Invito:</strong><br>
                                                <div class="input-group input-group-sm">
                                                    <input type="text" class="form-control" readonly
                                                           value="<?= htmlspecialchars($assignment['invitation_link']) ?>"
                                                           id="link-<?= htmlspecialchars($assignment['id_assignment']) ?>">
                                                    <button class="btn btn-outline-secondary" type="button"
                                                            onclick="copyLink('<?= htmlspecialchars($assignment['id_assignment']) ?>')">
                                                        <i class="bi bi-clipboard"></i>
                                                    </button>
                                                </div>
                                            </p>
                                            <p class="mb-0">
                                                <strong>Google Classroom:</strong>
                                                <?php if ($assignment['pubblicato_gc'] === 'SI'): ?>
                                                    <span class="badge bg-success">
                                                        <i class="bi bi-check-circle"></i> Pubblicato
                                                        <?php if ($assignment['data_pubblicazione']): ?>
                                                            <br><small><?= htmlspecialchars($assignment['data_pubblicazione']) ?></small>
                                                        <?php endif; ?>
                                                    </span>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">
                                                        <i class="bi bi-clock"></i> Non pubblicato
                                                    </span>
                                                <?php endif; ?>
                                            </p>
                                        </div>
                                        <div class="card-footer">
                                            <div class="d-flex gap-2">
                                                <a href="<?= htmlspecialchars($assignment['invitation_link']) ?>"
                                                   target="_blank" class="btn btn-sm btn-outline-dark flex-grow-1">
                                                    <i class="bi bi-github"></i> Apri
                                                </a>
                                                <?php if ($assignment['pubblicato_gc'] !== 'SI'): ?>
                                                    <a href="?action=publish_gc&id=<?= urlencode($assignment['id_assignment']) ?>"
                                                       class="btn btn-sm btn-success"
                                                       onclick="return confirm('Pubblicare questo assignment su Google Classroom?')">
                                                        <i class="bi bi-send"></i>
                                                    </a>
                                                <?php endif; ?>
                                                <a href="?action=delete&id=<?= urlencode($assignment['id_assignment']) ?>"
                                                   class="btn btn-sm btn-outline-danger"
                                                   onclick="return confirm('Eliminare questo assignment? (Non verrà eliminato da GitHub)')">
                                                    <i class="bi bi-trash"></i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Form Nuovo Assignment -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-plus-circle"></i> Nuovo Assignment
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="create_assignment">

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">UDA *</label>
                                <select name="id_uda" class="form-select" required>
                                    <option value="">-- Seleziona UDA --</option>
                                    <?php foreach ($udas as $uda): ?>
                                        <option value="<?= htmlspecialchars($uda->id_uda) ?>">
                                            <?= htmlspecialchars($uda->titolo) ?> (<?= htmlspecialchars($uda->argomento) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Seleziona la UDA a cui collegare questo assignment</small>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Classroom Mapping (opzionale)</label>
                                <select name="id_classroom_map" class="form-select">
                                    <option value="">-- Nessuna --</option>
                                    <?php foreach ($classroomMappings as $mapping): ?>
                                        <option value="<?= htmlspecialchars($mapping['id_mapping']) ?>">
                                            <?= htmlspecialchars($mapping['classroom_name']) ?>
                                            (<?= htmlspecialchars($mapping['id_classe_cv']) ?> - <?= htmlspecialchars($mapping['id_materia_cv']) ?>)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <small class="text-muted">Collega a una mappatura classroom specifica</small>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Nome Assignment *</label>
                                <input type="text" name="assignment_name" class="form-control"
                                       placeholder="Es: Esercitazione TCP/IP" required>
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Tipo Assignment *</label>
                                <select name="assignment_type" class="form-select" required>
                                    <option value="individual">Individuale</option>
                                    <option value="group">Gruppo</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Link Invito GitHub Classroom *</label>
                            <input type="url" name="invitation_link" class="form-control"
                                   placeholder="https://classroom.github.com/a/abc123xyz" required
                                   pattern="https://classroom\.github\.com/a/[a-zA-Z0-9_-]+">
                            <small class="text-muted">
                                Formato: https://classroom.github.com/a/xxxxx
                                - Crea prima l'assignment su GitHub Classroom e copia il link di invito
                            </small>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label class="form-label">Deadline (opzionale)</label>
                                <input type="datetime-local" name="deadline" class="form-control">
                            </div>

                            <div class="col-md-6 mb-3">
                                <label class="form-label">Starter Code Repository URL (opzionale)</label>
                                <input type="url" name="starter_code_url" class="form-control"
                                       placeholder="https://github.com/org/template-repo">
                                <small class="text-muted">URL del repository template usato</small>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label class="form-label">Note (opzionale)</label>
                            <textarea name="note" class="form-control" rows="2"
                                      placeholder="Note aggiuntive sull'assignment..."></textarea>
                        </div>

                        <div class="alert alert-warning">
                            <i class="bi bi-exclamation-triangle"></i>
                            <strong>Importante:</strong> Devi prima creare l'assignment su
                            <a href="https://classroom.github.com" target="_blank">GitHub Classroom</a>,
                            poi copiare il link di invito e incollarlo qui.
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-success">
                                <i class="bi bi-save"></i> Salva Assignment
                            </button>
                            <button type="reset" class="btn btn-outline-secondary">
                                <i class="bi bi-x-circle"></i> Annulla
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
function copyLink(assignmentId) {
    const input = document.getElementById('link-' + assignmentId);
    input.select();
    document.execCommand('copy');

    // Feedback visivo
    const button = event.target.closest('button');
    const originalHTML = button.innerHTML;
    button.innerHTML = '<i class="bi bi-check"></i>';
    button.classList.add('btn-success');
    button.classList.remove('btn-outline-secondary');

    setTimeout(() => {
        button.innerHTML = originalHTML;
        button.classList.remove('btn-success');
        button.classList.add('btn-outline-secondary');
    }, 2000);
}
</script>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
