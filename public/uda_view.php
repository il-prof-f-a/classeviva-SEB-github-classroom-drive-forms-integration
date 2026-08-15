<?php
require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Core\Database\DatabaseFactory;
use App\Core\ProviderNeutralMappingService;
use App\Integration\GoogleDriveAPI;
use App\Utils\UdaMetadataHelper;

$udaManager = new UDAManager($config);
$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$mappingService = new ProviderNeutralMappingService(
    $dbAdapter,
    (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'))
);
$error = null;
$udaComplete = null;
$message = '';
$messageType = '';

// Colori deterministici per badge
function badgeColorLocal(string $key): string {
    $palette = ['#0d6efd','#198754','#d63384','#fd7e14','#6f42c1','#20c997','#0dcaf0','#6610f2','#e83e8c','#dc3545'];
    $idx = abs(crc32($key)) % count($palette);
    return $palette[$idx];
}

// Recupera ID dall'URL
$udaId = $_GET['id'] ?? null;

if (!$udaId) {
    header('Location: index.php');
    exit;
}

// Gestisci azioni POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    try {
        switch ($action) {
            case 'upload_file':
                if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
                    throw new Exception("Errore durante l'upload del file");
                }

                $tipoMateriale = $_POST['tipo_materiale'] ?? 'documento';
                $descrizione = $_POST['descrizione'] ?? '';

                $driveApi = new GoogleDriveAPI($config);
                $folderId = $config['google']['drive']['root_folder_id'] ?? '';

                $uploadResult = $driveApi->uploadFile(
                    $_FILES['file']['tmp_name'],
                    $_FILES['file']['name'],
                    $folderId,
                    $_FILES['file']['type']
                );

                $materialId = 'MAT_' . uniqid('CLASSROOM_MAPPINGS');
                $dbAdapter->insertRow('MATERIALI', [
                    'id_materiale' => $materialId,
                    'id_uda' => $udaId,
                    'tipo_materiale' => $tipoMateriale,
                    'nome' => $_FILES['file']['name'],
                    'descrizione' => $descrizione,
                    'url_drive' => $uploadResult['viewLink'],
                    'file_id_drive' => $uploadResult['id'],
                    'data_creazione' => date('Y-m-d H:i:s')
                ]);

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=upload_success");
                exit;

            case 'add_link':
                $tipoMateriale = $_POST['tipo_materiale'] ?? 'link';
                $titolo = $_POST['titolo'] ?? '';
                $url = $_POST['url'] ?? '';
                $descrizione = $_POST['descrizione'] ?? '';

                if (empty($url) || !filter_var($url, FILTER_VALIDATE_URL)) {
                    throw new Exception("URL non valido");
                }

                $materialId = 'MAT_' . uniqid('CLASSROOM_MAPPINGS');
                $dbAdapter->insertRow('MATERIALI', [
                    'id_materiale' => $materialId,
                    'id_uda' => $udaId,
                    'tipo_materiale' => $tipoMateriale,
                    'nome' => $titolo,
                    'descrizione' => $descrizione,
                    'url_drive' => $url,
                    'data_creazione' => date('Y-m-d H:i:s')
                ]);

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=link_success");
                exit;

            case 'delete_material':
                $materialId = $_POST['material_id'] ?? '';

                $material = $dbAdapter->findOne('MATERIALI', 'id_materiale', $materialId);

                if ($material && !empty($material['file_id_drive'])) {
                    $driveApi = new GoogleDriveAPI($config);
                    $driveApi->deleteFile($material['file_id_drive']);
                }

                $dbAdapter->deleteRow('MATERIALI', $materialId, 'id_materiale');

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=delete_material_success");
                exit;

            case 'add_obiettivo':
                $tipoObiettivo = $_POST['tipo_obiettivo'] ?? 'disciplinare';
                $codice = $_POST['codice'] ?? '';
                $descrizione = $_POST['descrizione'] ?? '';
                $competenza = $_POST['competenza'] ?? '';
                $livelloTassonomia = $_POST['livello_tassonomia'] ?? '';
                $peso = $_POST['peso'] ?? '';

                if (empty($descrizione)) {
                    throw new Exception("La descrizione dell'obiettivo è obbligatoria");
                }

                $obiettivoId = 'OBJ_' . uniqid('CLASSROOM_MAPPINGS');
                $dbAdapter->insertRow('OBIETTIVI', [
                    'id_obiettivo' => $obiettivoId,
                    'id_uda' => $udaId,
                    'tipo_obiettivo' => $tipoObiettivo,
                    'codice' => $codice,
                    'descrizione' => $descrizione,
                    'competenza' => $competenza,
                    'livello_tassonomia' => $livelloTassonomia,
                    'peso' => $peso,
                    'raggiunto' => 'NO'
                ]);

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=obiettivo_success");
                exit;

            case 'delete_obiettivo':
                $obiettivoId = $_POST['obiettivo_id'] ?? '';
                $dbAdapter->deleteRow('OBIETTIVI', $obiettivoId, 'id_obiettivo');

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=delete_obiettivo_success");
                exit;

            case 'delete_classe':
                $assegnazioneId = $_POST['assegnazione_id'] ?? '';
                $dbAdapter->deleteClasseAssegnata((string)$assegnazioneId);
                $udaManager->updateUDA($udaId, [
                    'classi_target' => UdaMetadataHelper::classTargetFromAssignments($dbAdapter->findClassiAssegnate($udaId))
                ]);

                // Redirect to avoid form resubmission
                header("Location: uda_view.php?id=" . urlencode($udaId) . "&msg=delete_classe_success");
                exit;
        }
    } catch (Exception $e) {
        $message = "Errore: " . $e->getMessage();
        $messageType = "danger";
    }
}

// Handle success messages from redirects
if (isset($_GET['msg'])) {
    switch ($_GET['msg']) {
        case 'upload_success':
            $message = "File caricato con successo!";
            $messageType = "success";
            break;
        case 'link_success':
            $message = "Link aggiunto con successo!";
            $messageType = "success";
            break;
        case 'delete_material_success':
            $message = "Materiale eliminato con successo!";
            $messageType = "success";
            break;
        case 'obiettivo_success':
            $message = "Obiettivo aggiunto con successo!";
            $messageType = "success";
            break;
        case 'delete_obiettivo_success':
            $message = "Obiettivo eliminato con successo!";
            $messageType = "success";
            break;
        case 'delete_classe_success':
            $message = "Assegnazione classe eliminata con successo!";
            $messageType = "success";
            break;
    }
}

try {
    $udaComplete = $udaManager->getUDAComplete($udaId);

    if (!$udaComplete) {
        throw new Exception("UDA non trovata");
    }

    $uda = $udaComplete['uda'];
    $materiali = $udaComplete['materiali'];
    $obiettivi = $udaComplete['obiettivi'];
    $test = $udaComplete['test'];
    $classiAssegnate = $udaComplete['classi_assegnate'];
    $classiTargetDisplay = UdaMetadataHelper::classTargetFromAssignments($classiAssegnate);
    $voti = $udaComplete['voti'];
    $files = $udaComplete['files'];

    // Recupera materiali dal database
    $allMaterials = $dbAdapter->findAll('MATERIALI');
    $udaMaterials = array_filter($allMaterials, function($m) use ($udaId) {
        return ($m['id_uda'] ?? '') === $udaId;
    });

    // Carica assignment GitHub per arricchire i materiali
    $allAssignments = $dbAdapter->findAll('GITHUB_ASSIGNMENTS');
    $assignmentsMap = [];
    foreach ($allAssignments as $assignment) {
        if (($assignment['id_uda'] ?? '') === $udaId) {
            $assignmentsMap[$assignment['assignment_name']] = $assignment;
        }
    }

    // Arricchisci materiali GitHub con dati assignment
    foreach ($udaMaterials as &$mat) {
        if (($mat['tipo'] ?? '') === 'github_assignment') {
            $matTitle = $mat['titolo'] ?? $mat['nome'] ?? '';
            if (isset($assignmentsMap[$matTitle])) {
                $mat['github_assignment_data'] = $assignmentsMap[$matTitle];
            }
        }
    }
    unset($mat);

    // Carica mappature GitHub Classroom
    $allGithubMappings = $mappingService->listGithubClassroomMappings();

    // Calcola statistiche se ci sono voti
    $stats = null;
    if (!empty($voti)) {
        $stats = $udaManager->getStatistics($udaId);
    }

    // Recupera tutti i classroom mappings attivi per verificare associazioni
    $classroomMappings = $mappingService->listGoogleClassroomMappings();

    // Calcola statistiche domande
    $allDomande = $dbAdapter->findAll('DOMANDE_INTERROGAZIONE');
    $domandeCount = count(array_filter($allDomande, fn($d) => ($d['id_uda'] ?? '') === $udaId));

} catch (Exception $e) {
    $error = $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $uda->titolo ?? 'UDA' ?> - Sistema Gestione UDA</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        /* Pulsante GitHub outline con hover */
        .btn-github-outline {
            color: #663399;
            border: 1px solid #663399;
            background-color: white;
            transition: all 0.2s ease-in-out;
        }
        .btn-github-outline:hover {
            color: white;
            background-color: #663399;
            border-color: #663399;
        }
        .btn-github-outline:active {
            color: white;
            background-color: #552288;
            border-color: #552288;
        }
    </style>
    <style>
        .action-bar {
            display: flex;
            flex-wrap: wrap;
            gap: 0.5rem;
        }
        .action-bar .btn {
            flex: 1 1 calc(12.5% - 0.5rem);
            min-width: 120px;
            white-space: normal;
        }
        .action-bar .btn span {
            display: block;
        }
        .multi-line-btn {
            white-space: normal;
            text-align: center;
        }
        /* Contenitore sezione */
        .section-wrapper {
            margin-bottom: 1.5rem;
        }

        /* Barra colorata con titolo e pulsanti */
        .section-header-toggle {
            cursor: default;
            transition: all 0.2s ease;
            padding: 0.75rem 1rem !important;
            display: flex;
            justify-content: space-between;
            align-items: center;
            position: relative;
        }

        .section-header-toggle:hover {
            filter: brightness(0.9);
        }

        /* Titolo nella barra colorata */
        .section-header-toggle h6 {
            margin-bottom: 0;
            flex-grow: 1;
        }

        /* Contenitore pulsanti - impedisce il toggle quando cliccati */
        .section-header-toggle .btn-container {
            display: flex;
            gap: 0.5rem;
            z-index: 10;
        }

        .section-header-toggle .btn-container .btn,
        .section-header-toggle .btn-container .btn-group {
            pointer-events: auto;
        }

        .section-header-toggle .section-toggle-area {
            flex-grow: 1;
            min-width: 0;
            cursor: pointer;
        }

        .chevron-icon {
            transition: transform 0.3s;
            font-size: 1.2rem;
        }

        .chevron-icon.rotated {
            transform: rotate(180deg);
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-journal-text"></i> ' . ($uda->titolo ?? 'UDA');
    $pageSubtitle = $uda->argomento ?? '';
    $headerActions = '<a class="nav-link" href="index.php">Dashboard</a>'
        . '<a class="nav-link" href="uda_create.php">Nuova UDA</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <br><a href="index.php" class="btn btn-sm btn-outline-danger mt-2">Torna alla Dashboard</a>
            </div>
        <?php else: ?>
            <?php if (!empty($message)): ?>
            <div class="alert alert-<?= $messageType ?> alert-dismissible fade show" role="alert">
                <i class="bi bi-<?= $messageType === 'success' ? 'check-circle' : 'exclamation-triangle' ?>"></i>
                <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php endif; ?>
            <!-- Header UDA -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="d-flex gap-2">
                        <?php
                        $badgeClass = 'bg-secondary';
                        if ($uda->stato === 'attiva') $badgeClass = 'bg-success';
                        if ($uda->stato === 'bozza') $badgeClass = 'bg-warning text-dark';
                        if ($uda->stato === 'completata') $badgeClass = 'bg-info';
                        ?>
                        <span class="badge <?= $badgeClass ?>"><?= htmlspecialchars(ucfirst($uda->stato ?? 'N/D')) ?></span>
                        <?php if ($uda->disciplina): ?>
                            <?php $discColor = badgeColorLocal($uda->disciplina); ?>
                            <span class="badge" style="background-color: <?= $discColor ?>; color:#fff;"><?= htmlspecialchars($uda->disciplina) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($uda->anno_scolastico)): ?>
                            <span class="badge bg-dark"><?= htmlspecialchars($uda->anno_scolastico) ?></span>
                        <?php endif; ?>
                        <?php if (!empty($classiAssegnate)): ?>
                            <?php
                            $printed = [];
                            foreach ($classiAssegnate as $cls) {
                                $label = $cls['nome_classe'] ?? '';
                                if (!$label || isset($printed[$label])) continue;
                                $printed[$label] = true;
                                $color = badgeColorLocal($label);
                                echo '<span class="badge" style="background-color: ' . $color . '; color:#fff;">' . htmlspecialchars($label) . '</span>';
                            }
                            ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="row mb-4">
                <div class="col-12">
                    <div class="action-bar">
                        <a href="uda_edit.php?id=<?= urlencode($udaId) ?>" class="btn btn-primary">
                            <i class="bi bi-pencil"></i><span class="d-block small">Modifica</span>
                        </a>
                        <a href="uda_assign.php?id=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #fd7e14; border-color: #fd7e14;">
                            <i class="bi bi-people"></i><span class="d-block small">Classi</span>
                        </a>
                        <a href="materiali.php?id=<?= urlencode($udaId) ?>" class="btn btn-info">
                            <i class="bi bi-folder-fill"></i><span class="d-block small">Materiali</span>
                        </a>
                        <a href="obiettivi_seleziona.php?id_uda=<?= urlencode($udaId) ?>" class="btn btn-success">
                            <i class="bi bi-bullseye"></i><span class="d-block small">Obiettivi</span>
                        </a>
                        <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #d63384; border-color: #d63384;">
                            <i class="bi bi-question-circle"></i><span class="d-block small">Domande</span>
                        </a>
                        <a href="uda_tests.php?id=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #6f42c1; border-color: #6f42c1;">
                            <i class="bi bi-clipboard-check"></i><span class="d-block small">Test e attività</span>
                        </a>
                        <a href="rubrica_orale_v2.php?id_uda=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #20c997; border-color: #20c997;">
                            <i class="bi bi-chat-left-text"></i><span class="d-block small">Orale</span>
                        </a>
                        <?php
                        // Determina link per laboratorio griglia (prima classe/materia assegnata)
                        $laboratorioLink = 'laboratorio_valutazione_new.php?id_uda=' . urlencode($udaId);
                        if (!empty($classiAssegnate)) {
                            foreach ($classiAssegnate as $classe) {
                                if (!empty($classe['id_classe']) && !empty($classe['id_materia_cv'])) {
                                    $laboratorioParams = http_build_query([
                                        'id_uda' => $udaId,
                                        'id_classe_cv' => $classe['id_classe'],
                                        'id_materia_cv' => $classe['id_materia_cv']
                                    ]);
                                    $laboratorioLink = 'laboratorio_griglia.php?' . $laboratorioParams;
                                    break;
                                }
                            }
                        }
                        ?>
                        <a href="<?= $laboratorioLink ?>" class="btn btn-warning">
                            <i class="bi bi-plus-slash-minus"></i><span class="d-block small">Laboratorio</span>
                        </a>
                    </div>
                    <div class="action-bar mt-3 gap-2">
                        <a href="uda_publish.php?id=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #198754; border-color: #198754;">
                            <i class="bi bi-cloud-upload"></i><span class="d-block small">Pubblica</span>
                        </a>
                        <a href="uda_grades.php?id=<?= urlencode($udaId) ?>" class="btn text-white" style="background-color: #0d6efd; border-color: #0d6efd;">
                            <i class="bi bi-bar-chart"></i><span class="d-block small">Gestisci Voti</span>
                        </a>
                        <a href="uda_export.php?id=<?= urlencode($udaId) ?>" class="btn btn-dark">
                            <i class="bi bi-file-earmark-arrow-down"></i><span class="d-block small">Esporta</span>
                        </a>
                        <a href="uda_delete.php?id=<?= urlencode($udaId) ?>" class="btn btn-danger"
                           onclick="return confirm('Sei sicuro di voler eliminare questa UDA?')">
                            <i class="bi bi-trash"></i><span class="d-block small">Elimina</span>
                        </a>
                    </div>
                </div>
            </div>

            <!-- Informazioni Generali - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                            <div class="card-header bg-primary text-white section-header-toggle">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#infoCollapse"
                         aria-expanded="true"
                         aria-controls="infoCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-info-circle ms-2"></i> INFORMAZIONI GENERALI
                        </h6>
                    </div>
                    <div class="btn-container">
                        <a href="uda_edit.php?id=<?= urlencode($udaId) ?>"
                           class="btn btn-sm btn-light">
                            <i class="bi bi-pencil"></i> Modifica
                        </a>
                    </div>
                </div>
                <div class="collapse show" id="infoCollapse">
                    <div class="card-body">
                    <!-- Dettagli Editabili -->
                    <div class="row">
                        <div class="col-md-6">
                            <p><strong>ID:</strong> <?= htmlspecialchars($uda->id_uda) ?></p>
                            <p><strong>Titolo:</strong> <?= htmlspecialchars($uda->titolo ?? 'N/D') ?></p>
                            <p><strong>Argomento:</strong> <?= htmlspecialchars($uda->argomento ?? 'N/D') ?></p>
                            <p><strong>Disciplina:</strong> <?= htmlspecialchars($uda->disciplina ?? 'N/D') ?></p>
                            <p><strong>Stato:</strong>
                                <?php
                                $statoBadge = [
                                    'bozza' => '<span class="badge bg-secondary">Bozza</span>',
                                    'attiva' => '<span class="badge bg-success">Attiva</span>',
                                    'completata' => '<span class="badge bg-primary">Completata</span>',
                                    'archiviata' => '<span class="badge bg-dark">Archiviata</span>'
                                ];
                                echo $statoBadge[$uda->stato] ?? htmlspecialchars($uda->stato ?? 'N/D');
                                ?>
                            </p>
                            <p><strong>Anno scolastico:</strong> <?= htmlspecialchars($uda->anno_scolastico ?? 'N/D') ?></p>
                            <p><strong>Classi target:</strong> <?= htmlspecialchars($classiTargetDisplay !== '' ? $classiTargetDisplay : 'N/D') ?></p>
                        </div>
                        <div class="col-md-6">
                            <p><strong>Metodologia:</strong> <?= htmlspecialchars($uda->metodologia ?? 'N/D') ?></p>
                            <p><strong>Data Inizio:</strong> <?= $uda->data_inizio ? date('d/m/Y', strtotime($uda->data_inizio)) : 'N/D' ?></p>
                            <p><strong>Data Fine:</strong> <?= $uda->data_fine ? date('d/m/Y', strtotime($uda->data_fine)) : 'N/D' ?></p>
                            <p><strong>Data creazione:</strong> <?= $uda->data_creazione ? date('d/m/Y H:i', strtotime($uda->data_creazione)) : 'N/D' ?></p>
                            <p><strong>Ultima modifica:</strong> <?= isset($uda->ultima_modifica) ? date('d/m/Y H:i', strtotime($uda->ultima_modifica)) : 'N/D' ?></p>
                        </div>
                    </div>

                    <?php if ($uda->descrizione): ?>
                        <hr>
                        <p><strong>Descrizione:</strong><br><?= nl2br(htmlspecialchars($uda->descrizione)) ?></p>
                    <?php endif; ?>

                    <?php if (!empty($uda->note)): ?>
                        <hr>
                        <p><strong>Note / Prerequisiti:</strong><br><?= nl2br(htmlspecialchars($uda->note)) ?></p>
                    <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Classi Assegnate - Toggleable -->
            <?php if (!empty($classiAssegnate)): ?>
                <div class="card section-wrapper">
                    <!-- Barra colorata con titolo, chevron e pulsanti -->
                    <div class="card-header text-white section-header-toggle"
                         style="background-color: #fd7e14;">
                        <div class="section-toggle-area"
                             data-bs-toggle="collapse"
                             data-bs-target="#classiCollapse"
                             aria-expanded="false"
                             aria-controls="classiCollapse">
                            <h6 class="text-white">
                                <i class="bi bi-chevron-down chevron-icon"></i>
                                <i class="bi bi-people ms-2"></i> CLASSI ASSEGNATE (<?= count($classiAssegnate) ?>)
                            </h6>
                        </div>
                        <div class="btn-container">
                            <a href="uda_assign.php?id=<?= urlencode($udaId) ?>"
                               class="btn btn-sm btn-light">
                                <i class="bi bi-people"></i> Assegna
                            </a>
                        </div>
                    </div>
                    <div class="collapse" id="classiCollapse">
                        <div class="card-body">
                        <div class="row">
                            <?php foreach ($classiAssegnate as $classe): ?>
                                <div class="col-md-4 mb-2">
                                    <div class="card">
                                        <div class="card-body">
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <div class="flex-grow-1">
                                                    <h6>
                                                        <?= htmlspecialchars($classe['nome_classe']) ?>
                                                        <?php if (!empty($classe['nome_materia'])): ?>
                                                            <br><span class="badge bg-primary mt-1">
                                                                <i class="bi bi-book"></i> <?= htmlspecialchars($classe['nome_materia']) ?>
                                                            </span>
                                                        <?php endif; ?>
                                                    </h6>
                                                    <small class="text-muted">
                                                        Assegnata: <?= htmlspecialchars($classe['data_assegnazione']) ?>
                                                    </small>
                                                    <?php if ($classe['pubblicato_classroom']): ?>
                                                        <br><span class="badge bg-success mt-1">Pubblicata su Classroom</span>
                                                    <?php endif; ?>
                                                </div>
                                                <button type="button"
                                                        class="btn btn-sm btn-outline-danger"
                                                        onclick="confirmDeleteClasse('<?= htmlspecialchars($classe['id_assegnazione'] ?? '') ?>', '<?= htmlspecialchars($classe['nome_classe'] ?? '') ?>')"
                                                        title="Elimina assegnazione">
                                                    <i class="bi bi-trash"></i>
                                                </button>
                                            </div>
                                            <div class="d-grid gap-2">
                                                <?php
                                                // Link a map_classes.php con filtro classe
                                                $filterParams = http_build_query([
                                                    'filter_classe' => $classe['id_classe'] ?? '',
                                                    'highlight' => 'true'
                                                ]);

                                                // Link a oral_rubric.php per valutazione laboratorio
                                                $rubricParams = http_build_query([
                                                    'uda_id' => $udaId,
                                                    'id_classe' => $classe['id_classe'] ?? '',
                                                    'id_materia' => $classe['id_materia_cv'] ?? 0
                                                ]);

                                                // Verifica se esiste già un mapping per questa classe/materia
                                                $existingMapping = null;
                                                if (isset($classroomMappings)) {
                                                    foreach ($classroomMappings as $mapping) {
                                                        $stato = strtolower(trim((string)($mapping['stato'] ?? 'attivo')));
                                                        if (($mapping['id_classe_cv'] ?? '') === ($classe['id_classe'] ?? '') &&
                                                            ($mapping['id_materia_cv'] ?? '') === ($classe['id_materia_cv'] ?? '') &&
                                                            ($stato === '' || $stato === 'attivo' || $stato === 'active' || $stato === '1')) {
                                                            $existingMapping = $mapping;
                                                            break;
                                                        }
                                                    }
                                                }
                                                ?>

                                                <?php if ($existingMapping): ?>
                                                    <div class="alert alert-success py-2 px-3 mb-2">
                                                        <i class="bi bi-check-circle-fill"></i>
                                                        Associata a <strong><?= htmlspecialchars($existingMapping['classroom_name'] ?? 'Classroom') ?></strong>
                                                    </div>
                                                    <a href="map_classes.php?<?= $filterParams ?>"
                                                       class="btn btn-sm btn-outline-primary">
                                                        <i class="bi bi-pencil"></i> Modifica Associazione
                                                    </a>
                                                <?php else: ?>
                                                    <a href="map_classes.php?<?= $filterParams ?>"
                                                       class="btn btn-sm btn-primary">
                                                        <i class="bi bi-diagram-3"></i> Associa Classe → Classroom
                                                    </a>
                                                <?php endif; ?>

                                                <?php
                                                // Verifica se esiste mappatura GitHub Classroom
                                                $githubMapping = null;
                                                foreach ($allGithubMappings as $ghMap) {
                                                    if (($ghMap['id_classe_cv'] ?? '') === ($classe['id_classe'] ?? '') &&
                                                        ($ghMap['id_materia_cv'] ?? '') === ($classe['id_materia_cv'] ?? '')) {
                                                        $githubMapping = $ghMap;
                                                        break;
                                                    }
                                                }
                                                ?>

                                                <?php if ($githubMapping): ?>
                                                    <!-- Mappatura GitHub esistente -->
                                                    <div class="alert py-2 px-3 mb-2 mt-2" style="background-color: #f3e8ff; border-color: #663399; color: #663399;">
                                                        <i class="bi bi-github"></i>
                                                        GitHub: <strong><?= htmlspecialchars($githubMapping['classroom_name'] ?? 'Classroom') ?></strong>
                                                    </div>
                                                    <a href="github_classroom_mapping.php"
                                                       class="btn btn-sm btn-github-outline">
                                                        <i class="bi bi-gear"></i> Gestisci Mappatura GitHub
                                                    </a>
                                                <?php else: ?>
                                                    <!-- Nessuna mappatura GitHub -->
                                                    <a href="github_classroom_mapping.php"
                                                       class="btn btn-sm mt-2"
                                                       style="background-color: #663399; color: white; border-color: #663399;">
                                                        <i class="bi bi-github"></i> Mappa a GitHub Classroom
                                                    </a>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Materiali Didattici - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                <div class="card-header bg-info text-white section-header-toggle">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#materialiCollapse"
                         aria-expanded="false"
                         aria-controls="materialiCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-folder-fill ms-2"></i> MATERIALI DIDATTICI (<?= count($udaMaterials) ?>)
                        </h6>
                    </div>
                    <div class="btn-container">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                <i class="bi bi-cloud-upload"></i> Carica
                            </button>
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#linkModal">
                                <i class="bi bi-link-45deg"></i> Link
                            </button>
                        </div>
                    </div>
                </div>
                <div class="collapse" id="materialiCollapse">
                    <div class="card-body">
                    <div class="alert alert-info">
                        <i class="bi bi-info-circle"></i>
                        I compiti GitHub Classroom ora si gestiscono nella sezione <a href="uda_tests.php?id=<?= urlencode($uda->id_uda) ?>">Test</a>.
                        I materiali GitHub già presenti sono mostrati come legacy.
                    </div>
                    <?php if (empty($udaMaterials)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-folder-x fs-1 text-muted"></i>
                            <p class="text-muted mt-2">Nessun materiale caricato</p>
                            <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#uploadModal">
                                <i class="bi bi-plus-circle"></i> Aggiungi il primo materiale
                            </button>
                        </div>
                    <?php else: ?>
                        <div class="row">
                            <?php foreach ($udaMaterials as $mat): ?>
                                <div class="col-md-6 col-lg-4 mb-3">
                                    <div class="card h-100 border-start border-primary border-3">
                                        <div class="card-body">
                                            <?php $isLegacyGitHub = (($mat['tipo'] ?? '') === 'github_assignment'); ?>
                                            <?php
                                            $openUrl = $isLegacyGitHub
                                                ? ($mat['url'] ?? '#')
                                                : ($mat['url_drive'] ?? '#');
                                            $canOpen = !empty($openUrl) && $openUrl !== '#';
                                            ?>
                                            <div class="d-flex justify-content-between align-items-start mb-2">
                                                <h6 class="card-title mb-0">
                                                    <i class="bi bi-<?= getMaterialIcon($mat['tipo_materiale'] ?? 'documento') ?>"></i>
                                                    <?= htmlspecialchars($mat['nome'] ?? 'Senza titolo') ?>
                                                </h6>
                                                <?php if ($isLegacyGitHub): ?>
                                                    <span class="badge bg-secondary">GitHub (legacy)</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><?= htmlspecialchars($mat['tipo_materiale'] ?? 'N/A') ?></span>
                                                <?php endif; ?>
                                            </div>

                                            <?php if ($isLegacyGitHub): ?>
                                                <p class="card-text text-muted small mb-2">
                                                    Questo materiale GitHub è legacy: i nuovi compiti GitHub si gestiscono dai <a href="uda_tests.php?id=<?= urlencode($uda->id_uda) ?>">Test</a>.
                                                </p>
                                            <?php endif; ?>

                                            <?php if (!empty($mat['descrizione'])): ?>
                                            <p class="card-text text-muted small mb-2">
                                                <?= htmlspecialchars($mat['descrizione']) ?>
                                            </p>
                                            <?php endif; ?>

                                            <div class="mb-2">
                                                <small class="text-muted">
                                                    <i class="bi bi-calendar"></i>
                                                    <?= date('d/m/Y', strtotime($mat['data_creazione'] ?? 'now')) ?>
                                                </small>
                                            </div>

                                            <div class="d-flex gap-2">
                                                <a href="<?= htmlspecialchars($openUrl) ?>"
                                                   target="_blank"
                                                   class="btn btn-sm <?= $isLegacyGitHub ? 'btn-outline-dark' : 'btn-primary' ?> flex-grow-1 <?= $canOpen ? '' : 'disabled' ?>">
                                                    <i class="bi bi-box-arrow-up-right"></i> Apri
                                                </a>
                                                <?php if (!$isLegacyGitHub): ?>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger"
                                                            onclick="confirmDelete('<?= htmlspecialchars($mat['id_materiale'] ?? '') ?>', '<?= htmlspecialchars($mat['nome'] ?? '') ?>')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                <?php endif; ?>
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

            <!-- Obiettivi Didattici e Disciplinari - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                <div class="card-header bg-success text-white section-header-toggle">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#obiettiviCollapse"
                         aria-expanded="false"
                         aria-controls="obiettiviCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-bullseye ms-2"></i> OBIETTIVI DIDATTICI E DISCIPLINARI (<?= count($obiettivi) ?>)
                        </h6>
                    </div>
                    <div class="btn-container">
                        <div class="btn-group">
                            <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#addObiettivoModal">
                                <i class="bi bi-plus-circle"></i> Aggiungi
                            </button>
                            <a href="obiettivi_seleziona.php?id_uda=<?= urlencode($udaId) ?>" class="btn btn-sm btn-light">
                                <i class="bi bi-list-check"></i> Seleziona
                            </a>
                            <a href="obiettivi_import.php?id_uda=<?= urlencode($udaId) ?>" class="btn btn-sm btn-light">
                                <i class="bi bi-upload"></i> Import
                            </a>
                        </div>
                    </div>
                </div>
                <div class="collapse" id="obiettiviCollapse">
                    <div class="card-body">
                    <?php if (empty($obiettivi)): ?>
                        <div class="text-center py-4">
                            <i class="bi bi-target fs-1 text-muted"></i>
                            <p class="text-muted mt-2">Nessun obiettivo definito</p>
                            <button type="button" class="btn btn-sm btn-success" data-bs-toggle="modal" data-bs-target="#addObiettivoModal">
                                <i class="bi bi-plus-circle"></i> Aggiungi il primo obiettivo
                            </button>
                        </div>
                    <?php else: ?>
                        <?php
                        $obiettiviPerTipo = [];
                        foreach ($obiettivi as $obj) {
                            $tipo = $obj['tipo_obiettivo'] ?? 'altro';
                            $obiettiviPerTipo[$tipo][] = $obj;
                        }
                        ?>
                        <?php foreach ($obiettiviPerTipo as $tipo => $objs): ?>
                            <h6 class="mt-3 mb-2 text-capitalize border-bottom pb-2">
                                <i class="bi bi-<?= $tipo === 'disciplinare' ? 'book' : 'star' ?>"></i>
                                <?= str_replace('_', ' ', htmlspecialchars($tipo)) ?>
                                <span class="badge bg-secondary"><?= count($objs) ?></span>
                            </h6>
                            <div class="row">
                                <?php foreach ($objs as $obj): ?>
                                    <div class="col-12 mb-2">
                                        <div class="card border-start border-<?= $tipo === 'disciplinare' ? 'primary' : 'success' ?> border-3">
                                            <div class="card-body py-2">
                                                <div class="d-flex justify-content-between align-items-start">
                                                    <div class="flex-grow-1">
                                                        <div class="d-flex align-items-center mb-1">
                                                            <?php if (!empty($obj['codice'])): ?>
                                                                <span class="badge bg-dark me-2"><?= htmlspecialchars($obj['codice']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($obj['livello_tassonomia'])): ?>
                                                                <span class="badge bg-info me-2">Bloom <?= htmlspecialchars($obj['livello_tassonomia']) ?></span>
                                                            <?php endif; ?>
                                                            <?php if (!empty($obj['peso'])): ?>
                                                                <span class="badge bg-warning text-dark">Peso: <?= htmlspecialchars($obj['peso']) ?>%</span>
                                                            <?php endif; ?>
                                                        </div>
                                                        <p class="mb-1"><strong><?= htmlspecialchars($obj['descrizione']) ?></strong></p>
                                                        <?php if (!empty($obj['competenza'])): ?>
                                                            <small class="text-muted">
                                                                <i class="bi bi-award"></i> <?= htmlspecialchars($obj['competenza']) ?>
                                                            </small>
                                                        <?php endif; ?>
                                                    </div>
                                                    <button type="button"
                                                            class="btn btn-sm btn-outline-danger ms-2"
                                                            onclick="confirmDeleteObiettivo('<?= htmlspecialchars($obj['id_obiettivo'] ?? '') ?>', '<?= htmlspecialchars(substr($obj['descrizione'] ?? '', 0, 50)) ?>...')">
                                                        <i class="bi bi-trash"></i>
                                                    </button>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            </div>

            <!-- Domande per orale e test - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                <div class="card-header text-white section-header-toggle"
                     style="background-color: #d63384;">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#domandeCollapse"
                         aria-expanded="false"
                         aria-controls="domandeCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-question-circle ms-2"></i> DOMANDE PER ORALE E TEST
                            <?php if ($domandeCount > 0): ?>
                                <span class="badge bg-light text-dark ms-2"><?= $domandeCount ?></span>
                            <?php endif; ?>
                        </h6>
                    </div>
                    <div class="btn-container">
                        <a href="uda_questions.php?id=<?= urlencode($udaId) ?>"
                           class="btn btn-sm btn-light">
                            <i class="bi bi-list-ul"></i> Gestisci
                        </a>
                    </div>
                </div>
                <div class="collapse" id="domandeCollapse">
                    <div class="card-body">
                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <div class="card border-success h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-magic"></i> Generazione Automatica
                                        </h6>
                                        <p class="card-text small">
                                            Genera domande automaticamente dagli obiettivi didattici usando l'AI.
                                        </p>
                                        <a href="uda_questions_auto.php?id_uda=<?= urlencode($udaId) ?>" class="btn btn-sm btn-success">
                                            <i class="bi bi-stars"></i> Genera con AI
                                        </a>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-6 mb-3">
                                <div class="card border-primary h-100">
                                    <div class="card-body">
                                        <h6 class="card-title">
                                            <i class="bi bi-pencil"></i> Gestione Manuale
                                        </h6>
                                        <p class="card-text small">
                                            Crea, modifica ed elimina domande manualmente.
                                        </p>
                                        <a href="uda_questions.php?id=<?= urlencode($udaId) ?>" class="btn btn-sm btn-primary">
                                            <i class="bi bi-list-ul"></i> Gestisci Domande
                                        </a>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Test e Valutazione - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                <div class="card-header text-white section-header-toggle"
                     style="background-color: #6f42c1;">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#testCollapse"
                         aria-expanded="false"
                         aria-controls="testCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-clipboard-check ms-2"></i> TEST E ATTIVITA'
                        </h6>
                    </div>
                    <div class="btn-container">
                        <a href="uda_tests.php?id=<?= urlencode($udaId) ?>"
                           class="btn btn-sm btn-light">
                            <i class="bi bi-list-ul"></i> Gestisci
                        </a>
                    </div>
                </div>
                <div class="collapse" id="testCollapse">
                    <div class="card-body">
                    <?php
                    // Calcola statistiche test
                    $testCount = count($test ?? []);
                    $testPubblicati = array_filter($test, fn($t) => ($t['pubblicato'] ?? 'NO') === 'SI');
                    $testBozza = array_filter($test, fn($t) => ($t['pubblicato'] ?? 'NO') !== 'SI');
                    $testImportati = array_filter($test, fn($t) => ($t['risultati_importati'] ?? 'NO') === 'SI');

                    // Raggruppa test per tipo
                    $testPerTipo = [
                        'google-forms' => [],
                        'kahoot' => [],
                        'google-classroom' => [],
                        'altro' => []
                    ];
                    foreach ($test as $t) {
                        $piattaforma = strtolower($t['piattaforma'] ?? 'altro');
                        if (isset($testPerTipo[$piattaforma])) {
                            $testPerTipo[$piattaforma][] = $t;
                        } else {
                            $testPerTipo['altro'][] = $t;
                        }
                    }
                    ?>

                    <!-- Statistiche Globali -->
                    <?php if ($testCount > 0): ?>
                        <div class="row mb-4">
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="card border-primary">
                                    <div class="card-body text-center">
                                        <h3 class="mb-0 text-primary"><?= $testCount ?></h3>
                                        <small class="text-muted">Test Totali</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="card border-success">
                                    <div class="card-body text-center">
                                        <h3 class="mb-0 text-success"><?= count($testPubblicati) ?></h3>
                                        <small class="text-muted">Pubblicati</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="card border-secondary">
                                    <div class="card-body text-center">
                                        <h3 class="mb-0 text-secondary"><?= count($testBozza) ?></h3>
                                        <small class="text-muted">In Bozza</small>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-3 col-sm-6 mb-2">
                                <div class="card border-info">
                                    <div class="card-body text-center">
                                        <h3 class="mb-0 text-info"><?= count($testImportati) ?></h3>
                                        <small class="text-muted">Risultati Importati</small>
                                    </div>
                                </div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Elenco Test -->
                    <h6 class="mb-3 border-bottom pb-2">
                        <i class="bi bi-clipboard-data"></i> Test Configurati
                        <a href="test_wizard.php?id=<?= urlencode($udaId) ?>" class="btn btn-warning btn-sm float-end multi-line-btn">
                            <i class="bi bi-magic"></i> Wizard
                        </a>
                    </h6>

                    <?php if (empty($test)): ?>
                        <div class="alert alert-info">
                            <i class="bi bi-info-circle"></i> Nessun test configurato.
                            <a href="uda_tests.php?id=<?= urlencode($udaId) ?>" class="alert-link">Crea il tuo primo test</a>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive mb-4">
                            <table class="table table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th>Nome Test</th>
                                        <th>Piattaforma</th>
                                        <th>Tipo</th>
                                        <th>Stato</th>
                                        <th class="text-center">Azioni</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($test as $t):
                                        $piattaforma = strtolower($t['piattaforma'] ?? '');
                                        $pubblicato = ($t['pubblicato'] ?? 'NO') === 'SI';
                                        $risultatiImportati = ($t['risultati_importati'] ?? 'NO') === 'SI';
                                        $isClassroom = $piattaforma === 'google-classroom';
                                        $isCbm = !empty($t['cbm_enabled']) && strtolower((string)$t['cbm_enabled']) !== 'no';
                                    ?>
                                        <tr class="clickable-test-row"
                                            data-target="uda_tests.php?id=<?= urlencode($udaId) ?>#test-<?= htmlspecialchars($t['id_test']) ?>">
                                            <td>
                                                <strong><?= htmlspecialchars($t['nome']) ?></strong>
                                                <?php
                                                $descrizioneTest = (string)($t['descrizione'] ?? '');
                                                if ($piattaforma === 'github') {
                                                    $descrizioneTest = preg_replace('/^Assignment GitHub Classroom:\\s*/i', '', $descrizioneTest);
                                                    $descrizioneTest = trim((string)$descrizioneTest);
                                                }
                                                ?>
                                                <?php if ($descrizioneTest !== ''): ?>
                                                    <br><small class="text-muted"><?= htmlspecialchars(substr($descrizioneTest, 0, 60)) ?><?= strlen($descrizioneTest) > 60 ? '...' : '' ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge" style="background-color: <?= $piattaforma === 'kahoot' ? '#46178f' : ($piattaforma === 'google-forms' ? '#7248b9' : '#0f9d58') ?>">
                                                    <?= strtoupper($t['piattaforma'] ?? 'N/A') ?>
                                                </span>
                                                <?php if ($isCbm): ?>
                                                    <br><span class="badge bg-warning text-dark mt-1">Forms CBM</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <span class="badge bg-secondary">
                                                    <?= ucfirst($t['tipo_test'] ?? 'altro') ?>
                                                </span>
                                            </td>
                                            <td>
                                                <?php if ($pubblicato): ?>
                                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> Pubblicato</span>
                                                <?php else: ?>
                                                    <span class="badge bg-secondary"><i class="bi bi-file-earmark"></i> Bozza</span>
                                                <?php endif; ?>
                                                <?php if ($risultatiImportati): ?>
                                                    <br><span class="badge bg-info mt-1"><i class="bi bi-cloud-check"></i> Importato</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <div class="btn-group btn-group-sm" role="group">
                                                    <!-- Pulsanti Pubblicazione su Classroom (solo per non-classroom) -->
                                                    <?php if (!$isClassroom): ?>
                                                        <!-- Pubblica in Bozza -->
                                                        <a href="publish_test_to_classroom.php?test_id=<?= urlencode($t['id_test']) ?>&mode=draft"
                                                           class="btn btn-outline-secondary"
                                                           title="Pubblica in bozza su Classroom">
                                                            <i class="bi bi-file-earmark-plus"></i> Bozza
                                                        </a>
                                                        <!-- Pubblica Subito -->
                                                        <a href="publish_test_to_classroom.php?test_id=<?= urlencode($t['id_test']) ?>&mode=publish"
                                                           class="btn btn-outline-success"
                                                           title="Pubblica subito su Classroom">
                                                            <i class="bi bi-send"></i> Pubblica
                                                        </a>
                                                    <?php else: ?>
                                                        <!-- Link al compito Classroom -->
                                                        <?php if (!empty($t['url_docente'])): ?>
                                                            <a href="<?= htmlspecialchars($t['url_docente']) ?>"
                                                               target="_blank"
                                                               class="btn btn-outline-primary"
                                                               title="Apri in Classroom">
                                                                <i class="bi bi-box-arrow-up-right"></i> Classroom
                                                            </a>
                                                        <?php endif; ?>
                                                    <?php endif; ?>

                                                    <!-- Pulsante Modifica -->
                                                    <a href="uda_tests.php?id=<?= urlencode($udaId) ?>&edit_test=<?= urlencode($t['id_test']) ?>"
                                                       class="btn btn-outline-primary"
                                                       title="Modifica test">
                                                        <i class="bi bi-pencil"></i>
                                                    </a>

                                                    <!-- Pulsante Importa Voti (in base alla piattaforma) -->
                                                    <?php
                                                    $importUrl = null;
                                                    $importBtnClass = 'btn-outline-info';
                                                    $importLabel = 'Importa voti';
                                                    if ($piattaforma === 'google-forms') {
                                                        // Import voti da Google Forms
                                                        $importUrl = 'import_form_results.php?test_id=' . urlencode($t['id_test']) . '&step=read_responses';
                                                        if ($isCbm) {
                                                            $importBtnClass = 'btn-warning';
                                                            $importLabel = 'Importa (CBM)';
                                                        }
                                                    } elseif ($piattaforma === 'google-classroom') {
                                                        // Import voti da Google Classroom
                                                        $importUrl = 'import_classroom_grades.php?test_id=' . urlencode($t['id_test']) . '&step=start';
                                                    } elseif ($piattaforma === 'kahoot') {
                                                        // Import voti da file Excel Kahoot
                                                        $importUrl = 'import_quiz_results_excel.php?uda_id=' . urlencode($udaId) . '&platform=kahoot';
                                                    } elseif ($piattaforma === 'socrative') {
                                                        // Import voti da file Excel Socrative
                                                        $importUrl = 'import_quiz_results_excel.php?uda_id=' . urlencode($udaId) . '&platform=socrative';
                                                    }
                                                    ?>
                                                    <?php if ($piattaforma === 'github'): ?>
                                                        <a href="github_assignment_review.php?test_id=<?= urlencode($t['id_test']) ?>"
                                                           class="btn btn-outline-dark"
                                                           title="Riepilogo GitHub">
                                                            <i class="bi bi-github"></i> Riepilogo GitHub
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($importUrl): ?>
                                                        <a href="<?= $importUrl ?>"
                                                           class="btn <?= $importBtnClass ?>"
                                                           title="Importa voti per questo test">
                                                            <i class="bi bi-cloud-upload"></i> <?= $importLabel ?>
                                                        </a>
                                                    <?php endif; ?>
                                                    <?php if ($isCbm && $piattaforma === 'google-forms'): ?>
                                                        <a href="test_cbm_analysis.php?test_id=<?= urlencode($t['id_test']) ?>"
                                                           class="btn btn-outline-warning"
                                                           title="Analisi CBM">
                                                            <i class="bi bi-graph-up"></i> Analisi CBM
                                                        </a>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>

                    <!-- Importazione risultati quiz esterni -->
                    <div class="card mb-4">
                        <div class="card-header bg-secondary text-white">
                            <h6 class="mb-0">
                                <i class="bi bi-upload"></i> Importa Risultati Quiz
                            </h6>
                        </div>
                        <div class="card-body">
                            <p class="small text-muted mb-3">
                                Importa i risultati dei quiz svolti su Socrative o Kahoot utilizzando i template Excel predefiniti.
                            </p>
                            <div class="d-flex flex-wrap gap-2">
                                <a href="import_quiz_results_excel.php?uda_id=<?= urlencode($udaId) ?>&platform=socrative"
                                   class="btn btn-warning">
                                    <i class="bi bi-file-earmark-spreadsheet"></i> Importa risultati Socrative
                                </a>
                                <a href="import_quiz_results_excel.php?uda_id=<?= urlencode($udaId) ?>&platform=kahoot"
                                   class="btn btn-outline-primary" style="border-color: #46178f; color: #46178f;">
                                    <i class="bi bi-stars"></i> Importa risultati Kahoot
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                </div>
            </div>

            <!-- Valutazioni e Rubriche - Toggleable -->
            <div class="card section-wrapper">
                <!-- Barra colorata con titolo, chevron e pulsanti -->
                <div class="card-header text-white section-header-toggle"
                     style="background-color: #d63384;">
                    <div class="section-toggle-area"
                         data-bs-toggle="collapse"
                         data-bs-target="#valutazioniCollapse"
                         aria-expanded="false"
                         aria-controls="valutazioniCollapse">
                        <h6 class="text-white">
                            <i class="bi bi-chevron-down chevron-icon"></i>
                            <i class="bi bi-star-fill ms-2"></i> ORALE E LABORATORIO
                        </h6>
                    </div>
                </div>
                <div class="collapse" id="valutazioniCollapse">
                    <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <div class="card border-primary h-100">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="bi bi-chat-left-text"></i> Rubrica Orale
                                    </h5>
                                    <p class="card-text">
                                        Valutazione orale con rubrica multi-indicatore e calcolo voto pesato automatico.
                                    </p>
                                    <?php
                                    // Conta rubriche compilate per questa UDA
                                    $allRubriche = $dbAdapter->findAll('RUBRICA');
                                    $rubricheCount = count(array_filter($allRubriche, fn($r) => ($r['id_uda'] ?? '') === $udaId));
                                    ?>
                                    <?php if ($rubricheCount > 0): ?>
                                        <div class="alert alert-info py-2 mb-2">
                                            <i class="bi bi-info-circle"></i> <?= $rubricheCount ?> rubrich<?= $rubricheCount > 1 ? 'e compilate' : 'a compilata' ?>
                                        </div>
                                    <?php endif; ?>
                                    <a href="rubrica_orale_v2.php?id_uda=<?= urlencode($udaId) ?>" class="btn" style="background-color: #20c997; color: white; border-color: #20c997;">
                                        <i class="bi bi-box-arrow-up-right"></i> Vai alla Rubrica
                                    </a>
                                </div>
                            </div>
                        </div>

                        <div class="col-md-6 mb-3">
                            <div class="card border-success h-100">
                                <div class="card-body">
                                    <h5 class="card-title">
                                        <i class="bi bi-plus-slash-minus"></i> Laboratorio +/-
                                    </h5>
                                    <p class="card-text">
                                        Valutazione laboratorio con evidenze +/- per competenze trasversali e indicatori pesati (identico a piuomeno).
                                    </p>
                                    <?php
                                    // DEBUG: mostra cosa c'è in classiAssegnate
                                    if (!empty($classiAssegnate)) {
                                        echo "<!-- DEBUG classiAssegnate count: " . count($classiAssegnate) . " -->\n";
                                        echo "<!-- DEBUG classiAssegnate: " . print_r($classiAssegnate, true) . " -->\n";
                                    }

                                    // Determina link diretto alla prima classe/materia assegnata che ha id_materia_cv valido
                                    $directLink = null;
                                    if (!empty($classiAssegnate)) {
                                        // Trova la prima classe con id_classe e id_materia_cv validi
                                        foreach ($classiAssegnate as $classe) {
                                            if (!empty($classe['id_classe']) && !empty($classe['id_materia_cv'])) {
                                                $directLinkParams = http_build_query([
                                                    'uda_id' => $udaId,
                                                    'id_classe' => $classe['id_classe'],
                                                    'id_materia' => $classe['id_materia_cv']
                                                ]);
                                                $directLink = 'oral_rubric.php?' . $directLinkParams;
                                                break; // Usa la prima trovata
                                            }
                                        }
                                    }
                                    ?>

                                    <?php if ($directLink): ?>
                                        <a href="<?= $directLink ?>" class="btn btn-warning">
                                            <i class="bi bi-box-arrow-up-right"></i> Vai al Laboratorio
                                        </a>
                                        <?php if (count($classiAssegnate) > 1): ?>
                                            <p class="text-muted small mt-2 mb-0">
                                                <i class="bi bi-info-circle"></i> Link diretto alla prima classe/materia.
                                                Per altre classi usa i link specifici nella sezione "Classi Assegnate" qui sotto.
                                            </p>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <div class="alert alert-warning py-2 mb-2">
                                            <i class="bi bi-exclamation-triangle"></i> Assegna prima una classe/materia a questa UDA
                                        </div>
                                        <a href="uda_assign.php?id=<?= urlencode($udaId) ?>" class="btn btn-outline-success">
                                            <i class="bi bi-plus-circle"></i> Assegna Classe
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    </div>
                </div>
            </div>

            <!-- Statistiche Voti - Toggleable -->
            <?php if ($stats): ?>
                <div class="card section-wrapper">
                    <!-- Barra colorata con titolo, chevron e pulsanti -->
                    <div class="card-header bg-primary text-white section-header-toggle">
                        <div class="section-toggle-area"
                             data-bs-toggle="collapse"
                             data-bs-target="#statsCollapse"
                             aria-expanded="false"
                             aria-controls="statsCollapse">
                            <h6 class="text-white">
                                <i class="bi bi-chevron-down chevron-icon"></i>
                                <i class="bi bi-graph-up ms-2"></i> STATISTICHE VALUTAZIONI
                            </h6>
                        </div>
                    </div>
                    <div class="collapse" id="statsCollapse">
                        <div class="card-body">
                        <div class="row text-center">
                            <div class="col-md-3">
                                <h3><?= $stats['num_studenti'] ?></h3>
                                <p class="text-muted">Studenti Valutati</p>
                            </div>
                            <div class="col-md-3">
                                <h3><?= number_format($stats['media_voti'], 2) ?></h3>
                                <p class="text-muted">Media Voti</p>
                            </div>
                            <div class="col-md-3">
                                <h3 class="text-success"><?= $stats['sufficienze'] ?></h3>
                                <p class="text-muted">Sufficienze</p>
                            </div>
                            <div class="col-md-3">
                                <h3 class="text-danger"><?= $stats['insufficienze'] ?></h3>
                                <p class="text-muted">Insufficienze</p>
                            </div>
                        </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

        <?php endif; ?>
    </div>

    <!-- Modal Upload File -->
    <div class="modal fade" id="uploadModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-cloud-upload"></i> Carica File su Google Drive</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" enctype="multipart/form-data">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="upload_file">

                        <div class="mb-3">
                            <label for="upload-tipo" class="form-label">Tipo Materiale *</label>
                            <select id="upload-tipo" name="tipo_materiale" class="form-select" required>
                                <option value="documento">Documento</option>
                                <option value="presentazione">Presentazione</option>
                                <option value="video">Video</option>
                                <option value="immagine">Immagine</option>
                                <option value="foglio_calcolo">Foglio di Calcolo</option>
                                <option value="altro">Altro</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="upload-file" class="form-label">File *</label>
                            <input type="file" id="upload-file" name="file" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="upload-descrizione" class="form-label">Descrizione</label>
                            <textarea id="upload-descrizione" name="descrizione" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-upload"></i> Carica</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Aggiungi Link -->
    <div class="modal fade" id="linkModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="bi bi-link-45deg"></i> Aggiungi Link Esterno</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_link">

                        <div class="mb-3">
                            <label for="link-tipo" class="form-label">Tipo Materiale *</label>
                            <select id="link-tipo" name="tipo_materiale" class="form-select" required>
                                <option value="link">Link Generico</option>
                                <option value="video_youtube">Video YouTube</option>
                                <option value="sito_web">Sito Web</option>
                                <option value="risorsa_online">Risorsa Online</option>
                            </select>
                        </div>

                        <div class="mb-3">
                            <label for="link-titolo" class="form-label">Titolo *</label>
                            <input type="text" id="link-titolo" name="titolo" class="form-control" required>
                        </div>

                        <div class="mb-3">
                            <label for="link-url" class="form-label">URL *</label>
                            <input type="url" id="link-url" name="url" class="form-control" required placeholder="https://...">
                        </div>

                        <div class="mb-3">
                            <label for="link-descrizione" class="form-label">Descrizione</label>
                            <textarea id="link-descrizione" name="descrizione" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-plus-circle"></i> Aggiungi</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Aggiungi Obiettivo -->
    <div class="modal fade" id="addObiettivoModal" tabindex="-1">
        <div class="modal-dialog modal-lg">
            <div class="modal-content">
                <div class="modal-header bg-success text-white">
                    <h5 class="modal-title"><i class="bi bi-plus-circle"></i> Aggiungi Obiettivo Didattico</h5>
                    <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                </div>
                <form method="POST" action="">
                    <div class="modal-body">
                        <input type="hidden" name="action" value="add_obiettivo">

                        <div class="mb-3">
                            <label for="obj-tipo" class="form-label">Tipo Obiettivo *</label>
                            <select name="tipo_obiettivo" id="obj-tipo" class="form-select" required>
                                <option value="disciplinare">Obiettivo Disciplinare</option>
                                <option value="competenza trasversale">Competenza Trasversale</option>
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-6 mb-3">
                                <label for="obj-codice" class="form-label">Codice</label>
                                <input type="text" class="form-control" id="obj-codice" name="codice"
                                       placeholder="es. DISC01, COMP01">
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="obj-livello" class="form-label">Livello Tassonomia (Bloom)</label>
                                <select name="livello_tassonomia" id="obj-livello" class="form-select">
                                    <option value="">Seleziona...</option>
                                    <option value="1">1 - Ricordare</option>
                                    <option value="2">2 - Comprendere</option>
                                    <option value="3">3 - Applicare</option>
                                    <option value="4">4 - Analizzare</option>
                                    <option value="5">5 - Valutare</option>
                                    <option value="6">6 - Creare</option>
                                </select>
                            </div>
                        </div>

                        <div class="mb-3">
                            <label for="obj-descrizione" class="form-label">Descrizione Obiettivo *</label>
                            <textarea class="form-control" id="obj-descrizione" name="descrizione"
                                      rows="3" required
                                      placeholder="Descrivi l'obiettivo didattico..."></textarea>
                        </div>

                        <div class="mb-3">
                            <label for="obj-competenza" class="form-label">Competenza</label>
                            <input type="text" class="form-control" id="obj-competenza" name="competenza"
                                   placeholder="es. Analizzare sistemi informatici">
                        </div>

                        <div class="mb-3">
                            <label for="obj-peso" class="form-label">Peso (%)</label>
                            <input type="number" class="form-control" id="obj-peso" name="peso"
                                   min="0" max="100" step="5"
                                   placeholder="es. 40">
                            <small class="text-muted">Peso dell'obiettivo nella valutazione complessiva</small>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Aggiungi Obiettivo
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Form nascosto per eliminazione materiali -->
    <form id="deleteForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_material">
        <input type="hidden" name="material_id" id="delete-material-id">
    </form>

    <!-- Form nascosto per eliminazione obiettivi -->
    <form id="deleteObiettivoForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_obiettivo">
        <input type="hidden" name="obiettivo_id" id="delete-obiettivo-id">
    </form>

    <!-- Form nascosto per eliminazione assegnazioni classi -->
    <form id="deleteClasseForm" method="POST" style="display: none;">
        <input type="hidden" name="action" value="delete_classe">
        <input type="hidden" name="assegnazione_id" id="delete-assegnazione-id">
    </form>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Rende cliccabile l'intera riga del test e porta alla pagina di dettaglio con ancora
        document.querySelectorAll('.clickable-test-row').forEach(function (row) {
            row.style.cursor = 'pointer';
            row.addEventListener('click', function (event) {
                if (event.target.closest('a, button')) {
                    return; // evita conflitti con i pulsanti nella riga
                }
                const target = row.getAttribute('data-target');
                if (target) {
                    window.location.href = target;
                }
            });
        });
    </script>
    <script>
        function confirmDelete(materialId, materialTitle) {
            if (confirm('Sei sicuro di voler eliminare "' + materialTitle + '"?\n\nQuesta azione non può essere annullata.')) {
                document.getElementById('delete-material-id').value = materialId;
                document.getElementById('deleteForm').submit('CLASSROOM_MAPPINGS');
            }
        }

        function confirmForget(materialId, materialTitle) {
            if (confirm('Vuoi "dimenticare" questo assignment GitHub?\n\n"' + materialTitle + '"\n\nL\'assignment non sarà eliminato da GitHub, ma verrà rimosso dai materiali di questa UDA.')) {
                document.getElementById('delete-material-id').value = materialId;
                document.getElementById('deleteForm').submit('CLASSROOM_MAPPINGS');
            }
        }

        function confirmDeleteObiettivo(obiettivoId, descrizione) {
            if (confirm('Sei sicuro di voler eliminare questo obiettivo?\n\n' + descrizione)) {
                document.getElementById('delete-obiettivo-id').value = obiettivoId;
                document.getElementById('deleteObiettivoForm').submit('CLASSROOM_MAPPINGS');
            }
        }

        function confirmDeleteClasse(assegnazioneId, nomeClasse) {
            if (confirm('Sei sicuro di voler rimuovere l\'assegnazione della classe "' + nomeClasse + '"?\n\nQuesta azione non può essere annullata.')) {
                document.getElementById('delete-assegnazione-id').value = assegnazioneId;
                document.getElementById('deleteClasseForm').submit('CLASSROOM_MAPPINGS');
            }
        }

        // Gestione rotazione chevron per tutte le sezioni collapsibili
        document.querySelectorAll('[data-bs-toggle="collapse"]').forEach(function(element) {
            const target = element.getAttribute('data-bs-target');
            const collapseElement = document.querySelector(target);

            if (collapseElement) {
                collapseElement.addEventListener('shown.bs.collapse', function () {
                    const chevron = element.querySelector('.chevron-icon');
                    if (chevron) {
                        chevron.classList.add('rotated');
                    }
                });

                collapseElement.addEventListener('hidden.bs.collapse', function () {
                    const chevron = element.querySelector('.chevron-icon');
                    if (chevron) {
                        chevron.classList.remove('rotated');
                    }
                });
            }
        });
    </script>
</body>
</html>

<?php
// Helper function per icone
function getMaterialIcon($type) {
    $icons = [
        'documento' => 'file-text',
        'presentazione' => 'file-earmark-slides',
        'video' => 'camera-video',
        'video_youtube' => 'youtube',
        'immagine' => 'image',
        'foglio_calcolo' => 'file-earmark-spreadsheet',
        'link' => 'link-45deg',
        'sito_web' => 'globe',
        'risorsa_online' => 'cloud',
        'github_assignment' => 'github',
    ];
    return $icons[$type] ?? 'file-earmark';
}
?>
