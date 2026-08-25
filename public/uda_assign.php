<?php

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\TeachingGroupRepository;
use App\Core\UdaGroupRepository;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$groupRepo = new UdaGroupRepository($dbAdapter, $userId);
$teachingGroupRepo = new TeachingGroupRepository($dbAdapter, $userId);

$error_message = null;
$success_message = null;

// Verifica ID UDA
$udaId = $_GET['id'] ?? null;
if (!$udaId) {
    header('Location: index.php');
    exit;
}

// Carica UDA esistente
$uda = null;
try {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception("UDA non trovata");
    }
    $uda = $udaComplete['uda'];
} catch (Exception $e) {
    $error_message = "Errore: " . $e->getMessage();
}

// Gruppi disponibili e già assegnati
$allGroups = $teachingGroupRepo->listActive();
$udaAssignments = $groupRepo->listForUda($udaId);
$assignedGroupIds = [];
foreach ($udaAssignments as $assignment) {
    $gid = (string)($assignment['id_gruppo'] ?? '');
    if ($gid !== '') {
        $assignedGroupIds[$gid] = true;
    }
}

// Gestione POST per rimozione assegnazione
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'remove_assignment') {
    try {
        $assegnId = $_POST['id_assegnazione'] ?? null;
        if (!$assegnId) {
            throw new Exception("ID assegnazione mancante");
        }
        $groupRepo->delete((string)$assegnId);
        header("Location: uda_assign.php?id=" . urlencode($udaId) . "&msg=remove_success");
        exit;
    } catch (Exception $e) {
        $error_message = "Errore durante la rimozione: " . $e->getMessage();
    }
}

// Gestione POST per assegnazione gruppi
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'assign_groups') {
    try {
        $selectedGroups = $_POST['groups'] ?? [];
        if (empty($selectedGroups)) {
            throw new Exception("Seleziona almeno un gruppo didattico");
        }
        $assigned = 0;
        foreach ($selectedGroups as $groupId) {
            $groupId = (string)$groupId;
            if ($groupId === '' || isset($assignedGroupIds[$groupId])) {
                continue;
            }
            $groupRepo->assign($udaId, $groupId);
            $assignedGroupIds[$groupId] = true;
            $assigned++;
        }
        if ($assigned > 0) {
            header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=assign_success&count=" . $assigned);
            exit;
        } else {
            $error_message = "Nessun nuovo gruppo assegnato (potrebbero essere già assegnati)";
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
    $pageTitle = "<i class='bi bi-people'></i> Assegna Gruppi all'UDA";
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a class="nav-link" href="index.php">Dashboard</a>';
    if ($udaId) {
        $headerActions .= '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">Visualizza UDA</a>';
        $headerActions .= '<a href="uda_view.php?id=' . urlencode($udaId) . '" class="btn btn-outline-light btn-sm">'
            . '<i class="bi bi-arrow-left"></i> Torna all\'UDA</a>';
    }
    $pageActions = '<a href="teaching_groups.php" class="btn btn-outline-primary btn-sm"><i class="bi bi-diagram-3"></i> Gruppi didattici</a>';
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

        <div class="row">
            <!-- Gruppi Già Assegnati -->
            <div class="col-md-12 mb-4">
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Gruppi Già Assegnati (<?= count($udaAssignments) ?>)</h5>
                    </div>
                    <div class="card-body">
                        <?php if (empty($udaAssignments)): ?>
                            <p class="text-muted mb-0">Nessun gruppo ancora assegnato a questa UDA</p>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($udaAssignments as $assignment): ?>
                                    <?php
                                    $gid = (string)($assignment['id_gruppo'] ?? '');
                                    $gname = 'Gruppo ' . $gid;
                                    foreach ($allGroups as $g) {
                                        if ((string)($g['id_gruppo'] ?? '') === $gid) {
                                            $gname = (string)($g['nome_gruppo'] ?? ('Gruppo ' . $gid));
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card">
                                            <div class="card-body d-flex justify-content-between align-items-center">
                                                <h6 class="mb-0"><?= htmlspecialchars($gname) ?></h6>
                                                <form method="POST" class="d-inline" onsubmit="return confirm('Rimuovere questo gruppo dall\'UDA?');">
                                                    <input type="hidden" name="action" value="remove_assignment">
                                                    <input type="hidden" name="id_assegnazione" value="<?= htmlspecialchars($assignment['id_assegnazione'] ?? '') ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Rimuovi assegnazione">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </form>
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

        <!-- Form Assegnazione Gruppi -->
        <div class="card shadow">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-plus-circle"></i> Assegna a Nuovi Gruppi</h5>
            </div>
            <div class="card-body">
                <form method="POST" id="assignForm">
                    <input type="hidden" name="action" value="assign_groups">
                    <?php if (empty($allGroups)): ?>
                        <div class="alert alert-warning">
                            <p class="mb-0">Nessun gruppo didattico disponibile. Crealo in <a href="teaching_groups.php">Gruppi didattici</a>.</p>
                        </div>
                    <?php else: ?>
                        <p class="mb-3">Seleziona i gruppi didattici a cui assegnare questa UDA:</p>
                        <div class="row">
                            <?php foreach ($allGroups as $g): ?>
                                <?php
                                $gid = (string)($g['id_gruppo'] ?? '');
                                $gname = (string)($g['nome_gruppo'] ?? ('Gruppo ' . $gid));
                                $already = isset($assignedGroupIds[$gid]);
                                ?>
                                <div class="col-md-4 mb-3">
                                    <div class="form-check">
                                        <input class="form-check-input" type="checkbox" name="groups[]"
                                               value="<?= htmlspecialchars($gid) ?>"
                                               id="group_<?= htmlspecialchars($gid) ?>"
                                               <?= $already ? 'disabled checked' : '' ?>>
                                        <label class="form-check-label" for="group_<?= htmlspecialchars($gid) ?>">
                                            <?= htmlspecialchars($gname) ?>
                                            <?php if ($already): ?>
                                                <span class="badge bg-success ms-2">Già assegnato</span>
                                            <?php endif; ?>
                                        </label>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary btn-lg">
                                <i class="bi bi-check-circle"></i> Assegna Gruppi Selezionati
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>

        <!-- Azioni successive -->
        <div class="card mt-4">
            <div class="card-body">
                <h6 class="card-title">Prossimi Passi</h6>
                <p class="text-muted mb-3">Dopo aver assegnato i gruppi, puoi:</p>
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
</body>
</html>
