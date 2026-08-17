<?php

require_once __DIR__ . '/../bootstrap.php';

use App\Core\UDAManager;
use App\Integration\KahootAPI;
use App\Integration\ClasseVivaAPI;
use App\Core\Database\DatabaseFactory;

$udaManager = new UDAManager($config);
$error = null;
$success = null;

$cvConfig = $config['classeviva'] ?? [];
$cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvTokenError = $cvConfig['token_error'] ?? null;
$cvEnabled = !empty($cvConfig['enabled']);
$cvHasToken = !empty($cvTokenPayload['token']);
$cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;
$cvTokenNotice = null;
if ($cvEnabled && $cvHasToken && !$cvTokenValid) {
    $cvTokenNotice = $cvTokenError
        ? "Token ClasseViva non valido: " . $cvTokenError
        : "Token ClasseViva non valido. Rigeneralo dalla pagina di configurazione integrazioni.";
}

$udaId = $_GET['id'] ?? null;
$linkOrigineDefault = $udaId ? app_url('public/uda_grades.php?id=' . urlencode((string)$udaId)) : '';
if (!$udaId) {
    header('Location: index.php');
    exit;
}

function formatGradeValue($value): string {
    if ($value === null || $value === '') {
        return '-';
    }
    if ($value === 'a') {
        return 'a';
    }
    if ($value === 'i') {
        return 'i';
    }
    if (is_numeric($value)) {
        return number_format((float)$value, 2, '.', '');
    }
    return (string)$value;
}

function isVotoPubblicato(array $voto): bool {
    return ($voto['pubblicato_registro'] ?? $voto['pubblicato'] ?? 0) == 1;
}

// Carica UDA
try {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception("UDA non trovata");
    }
    $uda = $udaComplete['uda'];
    $classiAssegnate = $udaComplete['classi_assegnate'];

    // Carica voti con informazioni studenti
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
    $votiRaw = $dbAdapter->findWhere('VOTI', ['id_uda' => $udaId]);

    // Arricchisci i voti con i dati degli studenti (nome/cognome) usando le API ClasseViva
    $voti = [];
    $classeVivaAPI = $cvReady ? new ClasseVivaAPI($config) : null;
    $studentCachePerClasse = [];

    foreach ($votiRaw as $voto) {
        // ID studente/classe come usati da ClasseViva
        $idStudenteCv = $voto['id_studente_cv'] ?? $voto['id_studente'] ?? null;
        $idClasseCv = $voto['id_classe_cv'] ?? $voto['id_classe'] ?? null;

        // Se possibile, recupera nome/cognome live da ClasseViva (con cache per classe)
        if ($idStudenteCv && $idClasseCv) {
            $classKey = (string)$idClasseCv;
            $studKey = (string)$idStudenteCv;

            if (!isset($studentCachePerClasse[$classKey])) {
                if ($classeVivaAPI && $cvReady) {
                    try {
                        $studentiClasse = $classeVivaAPI->getStudentiClasse($classKey);
                        $map = [];
                        foreach ($studentiClasse as $s) {
                            $map[(string)($s['id'] ?? '')] = $s;
                        }
                        $studentCachePerClasse[$classKey] = $map;
                    } catch (Exception $e) {
                        $studentCachePerClasse[$classKey] = [];
                    }
                } else {
                    $studentCachePerClasse[$classKey] = [];
                }
            }

            $studenteInfo = $studentCachePerClasse[$classKey][$studKey] ?? null;
            if ($studenteInfo) {
                $voto['nome_studente'] = $studenteInfo['nome'] ?? '';
                $voto['cognome_studente'] = $studenteInfo['cognome'] ?? '';
            }
        }

        // Fallback: se ancora vuoto, lascia almeno l'ID visibile
        if (empty($voto['nome_studente'] ?? '') && empty($voto['cognome_studente'] ?? '')) {
            $voto['nome_studente'] = '';
            $voto['cognome_studente'] = '[ID: ' . ($idStudenteCv ?? $voto['id_studente_cv'] ?? $voto['id_studente'] ?? 'N/D') . ']';
        }

        // Rinomina tipo_voto in tipo_valutazione per compatibilità
        $voto['tipo_valutazione'] = $voto['tipo_voto'] ?? '';
        // Allinea ID per publishGrade
        $voto['id_studente'] = $voto['id_studente_cv'] ?? ($voto['id_studente'] ?? '');
        $voto['id_classe'] = $voto['id_classe_cv'] ?? ($voto['id_classe'] ?? '');
        $voto['id_materia'] = $voto['id_materia_cv'] ?? ($voto['id_materia'] ?? '');

        $voti[] = $voto;
    }

    // Statistiche
    $stats = $udaManager->getStatistics($udaId);
} catch (Exception $e) {
    $error = $e->getMessage();
}

// Import da Kahoot CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['kahoot_csv'])) {
    try {
        $kahootAPI = new KahootAPI($config);

        // Upload temporaneo
        $tmpPath = $_FILES['kahoot_csv']['tmp_name'];
        $results = $kahootAPI->importResultsFromCSV($tmpPath);
        $results = $kahootAPI->convertScoresToGrades($results);

        // Salva voti
        foreach ($results as $result) {
            $udaManager->addVoto($udaId, [
                'id_studente' => 'STUD_' . uniqid(), // TODO: Match con studenti reali
                'nome_studente' => $result['nome'],
                'tipo_valutazione' => 'test',
                'voto' => $result['voto'],
                'giudizio' => "Test Kahoot - {$result['percentuale']}% ({$result['risposte_corrette']}/{$result['risposte_totali']})",
                'link_origine' => $linkOrigineDefault
            ]);
        }

        $success = count($results) . " voti importati da Kahoot";
        header("refresh:2;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore import Kahoot: " . $e->getMessage();
    }
}

// Inserimento manuale voto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_voto'])) {
    try {
        $votoData = [
            'id_studente' => $_POST['id_studente'] ?? '',
            'nome_studente' => $_POST['nome_studente'] ?? '',
            'cognome_studente' => $_POST['cognome_studente'] ?? '',
            'tipo_valutazione' => $_POST['tipo_valutazione'] ?? 'orale',
            'voto' => (float)$_POST['voto'],
            'giudizio' => $_POST['giudizio'] ?? '',
            'link_origine' => $linkOrigineDefault
        ];

        $udaManager->addVoto($udaId, $votoData);
        $success = "Voto aggiunto con successo";
        header("refresh:1;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore aggiunta voto: " . $e->getMessage();
    }
}

// Modifica voto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_voto'])) {
    try {
        $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

        $votoId = $_POST['voto_id'] ?? '';
        $votoData = [
            'voto' => (float)$_POST['voto'],
            'tipo_voto' => $_POST['tipo_valutazione'] ?? '',
            'descrizione' => $_POST['descrizione'] ?? '',
            'giudizio' => $_POST['giudizio'] ?? '',
            'data_valutazione' => $_POST['data_valutazione'] ?? date('Y-m-d')
        ];

        $dbAdapter->updateRow('VOTI', 'id_voto', $votoId, $votoData);
        $success = "Voto modificato con successo";
        header("refresh:1;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore modifica voto: " . $e->getMessage();
    }
}

// Cancella voto
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_voto'])) {
    try {
        $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

        $votoId = $_POST['voto_id'] ?? '';
        $dbAdapter->deleteRow('VOTI', $votoId, 'id_voto');
        $success = "Voto cancellato con successo";
        header("refresh:1;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore cancellazione voto: " . $e->getMessage();
    }
}

// Cancella voti dell'UDA (se selezionati, solo quelli; altrimenti tutti)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_all_voti'])) {
    try {
        $dbAdapter = DatabaseFactory::createWithInitialization($config, true);

        $deleted = 0;
        $selectedIds = $_POST['voti_ids'] ?? [];
        $selectedIds = array_values(array_filter(array_map('strval', (array)$selectedIds)));
        $allowedIds = [];
        foreach ($voti as $voto) {
            $allowedIds[(string)$voto['id_voto']] = true;
        }

        if (!empty($selectedIds)) {
            foreach ($selectedIds as $votoId) {
                if (!isset($allowedIds[$votoId])) {
                    continue;
                }
                try {
                    $dbAdapter->deleteRow('VOTI', $votoId, 'id_voto');
                    $deleted++;
                } catch (Exception $e) {
                    // Continua anche se uno fallisce
                }
            }
        } else {
            foreach ($voti as $voto) {
                try {
                    $dbAdapter->deleteRow('VOTI', $voto['id_voto'], 'id_voto');
                    $deleted++;
                } catch (Exception $e) {
                    // Continua anche se uno fallisce
                }
            }
        }

        $success = "{$deleted} voti cancellati con successo";
        header("refresh:1;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore cancellazione voti: " . $e->getMessage();
    }
}

// Pubblica voti su ClasseViva
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['publish_to_registro'])) {
    try {
        if (!$cvReady || !$classeVivaAPI) {
            throw new Exception("ClasseViva non abilitato o token non valido");
        }

        $selectedVoti = $_POST['voti_ids'] ?? [];
        $published = 0;

        foreach ($selectedVoti as $votoId) {
            // Trova voto
            $voto = null;
            foreach ($voti as $v) {
                if ($v['id_voto'] === $votoId) {
                    $voto = $v;
                    break;
                }
            }

            if ($voto) {
                // Prepara dati per publishGrade (REST web)
                $gradeType = $voto['tipo_valutazione'] ?? 'orale';
                $gradeType = strtolower($gradeType);
                if (!in_array($gradeType, ['orale','scritto','pratico'])) {
                    $gradeType = 'orale';
                }
                $dataValRaw = $voto['data_valutazione'] ?? ($voto['data_creazione'] ?? '');
                $dataVal = $dataValRaw ? date('Y-m-d', strtotime($dataValRaw)) : date('Y-m-d');

                $desc = $voto['descrizione'] ?? ($voto['giudizio'] ?? '');
                $noteCode = '';
                if (!empty($voto['id_voto'])) {
                    $noteCode = '<' . $voto['id_voto'] . '>';
                }

                $gradeData = [
                    'student_id' => $voto['id_studente'],
                    'class_id' => $voto['id_classe'] ?? '',
                    'subject_id' => $voto['id_materia'] ?? '',
                    'subject_name' => $voto['nome_materia'] ?? 'Materia',
                    'grade_type' => $gradeType,
                    'grade_value' => $voto['voto'],
                    'date' => $dataVal,
                    'description' => $desc,
                    'notes' => $noteCode,
                    'notes_2' => $desc,
                    'link_origine' => $voto['link_origine'] ?? $linkOrigineDefault
                ];

                $resultCv = $classeVivaAPI->publishGrade($gradeData);

                // Aggiorna DB: pubblicato=1 e id_annotazione_cv se disponibile
                $updateData = [
                    'pubblicato' => 1,
                    'id_annotazione_cv' => $resultCv['response']['evento_id'] ?? ($resultCv['response']['id_evento'] ?? '')
                ];
                try {
                    $dbAdapter->updateRow('VOTI', 'id_voto', $voto['id_voto'], $updateData);
                } catch (Exception $e) {
                    // Non bloccare la pubblicazione se l'update locale fallisce
                    error_log("Aggiornamento VOTI dopo publishGrade fallito: " . $e->getMessage());
                }
                $published++;
            }
        }

        $success = "{$published} voti pubblicati su ClasseViva";
        // ricarica per vedere stato aggiornato
        header("refresh:1;url=?id=" . urlencode($udaId));

    } catch (Exception $e) {
        $error = "Errore pubblicazione registro: " . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gestione Voti - <?= htmlspecialchars($uda->titolo ?? 'UDA') ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-card-checklist"></i> Gestione Voti';
    $pageSubtitle = $uda->titolo ?? '';
    $headerActions = '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">Visualizza UDA</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <?php if ($error): ?>
            <div class="alert alert-danger">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($success) ?>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <!-- Statistiche -->
        <?php if ($stats && $stats['num_studenti'] > 0): ?>
            <div class="row mb-4">
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= $stats['num_studenti'] ?></h3>
                            <p class="text-muted mb-0">Studenti Valutati</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3><?= number_format($stats['media_voti'], 2) ?></h3>
                            <p class="text-muted mb-0">Media Voti</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-success"><?= $stats['sufficienze'] ?></h3>
                            <p class="text-muted mb-0">Sufficienze</p>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="card text-center">
                        <div class="card-body">
                            <h3 class="text-danger"><?= $stats['insufficienze'] ?></h3>
                            <p class="text-muted mb-0">Insufficienze</p>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="row">
            <!-- Aggiungi Voti -->
            <div class="col-md-4 mb-4">
                <!-- Aggiungi Manuale -->
                <div class="card">
                    <div class="card-header bg-success text-white">
                        <h6 class="mb-0"><i class="bi bi-plus-circle"></i> Aggiungi Voto Manuale</h6>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <input type="hidden" name="add_voto" value="1">

                            <div class="mb-2">
                                <label class="form-label small">Nome Studente</label>
                                <input type="text" name="nome_studente" class="form-control form-control-sm" required>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small">Cognome Studente</label>
                                <input type="text" name="cognome_studente" class="form-control form-control-sm" required>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small">Tipo Valutazione</label>
                                <select name="tipo_valutazione" class="form-select form-select-sm" required>
                                    <option value="orale">Interrogazione Orale</option>
                                    <option value="scritto">Verifica Scritta</option>
                                    <option value="pratico">Prova Pratica</option>
                                    <option value="test">Test</option>
                                </select>
                            </div>

                            <div class="mb-2">
                                <label class="form-label small">Voto</label>
                                <input type="number" name="voto" class="form-control form-control-sm"
                                       min="1" max="10" step="0.25" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label small">Giudizio</label>
                                <textarea name="giudizio" class="form-control form-control-sm" rows="2"></textarea>
                            </div>

                            <button type="submit" class="btn btn-success btn-sm w-100">
                                <i class="bi bi-save"></i> Salva Voto
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Elenco Voti -->
            <div class="col-md-8">
                <div class="card">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Voti Registrati (<?= count($voti) ?>)</h5>
                        <?php if (!empty($voti)): ?>
                            <div class="btn-group btn-group-sm">
                                <button type="button" class="btn btn-sm btn-light" data-bs-toggle="modal" data-bs-target="#publishModal">
                                    <i class="bi bi-cloud-upload"></i> Pubblica su Registro
                                </button>
                                <button type="button" class="btn btn-sm btn-danger" data-bs-toggle="modal" data-bs-target="#deleteAllModal">
                                    <i class="bi bi-trash"></i> Cancella Voti
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                    <div class="card-body">
                        <?php if (empty($voti)): ?>
                            <p class="text-muted">Nessun voto registrato</p>
                        <?php else: ?>
                            <div class="table-responsive">
                                <table class="table table-hover table-sm">
                                    <thead>
                                        <tr>
                                            <th>Studente</th>
                                            <th>Tipo</th>
                                            <th>Voto</th>
                                            <th>Data</th>
                                            <th>Stato</th>
                                            <th>Azioni</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach ($voti as $voto): ?>
                                            <tr>
                                                <td>
                                                    <?= htmlspecialchars($voto['cognome_studente'] ?? '') ?>
                                                    <?= htmlspecialchars($voto['nome_studente'] ?? '') ?>
                                                </td>
                                                <td>
                                            <span class="badge bg-secondary">
                                                <?= htmlspecialchars(ucfirst($voto['tipo_valutazione'] ?? '')) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <?php if (!empty($voto['link_origine'])): ?>
                                                <a href="<?= htmlspecialchars($voto['link_origine']) ?>" target="_blank" class="text-decoration-none">
                                                    <strong class="text-primary"><?= htmlspecialchars(formatGradeValue($voto['voto'])) ?></strong>
                                                </a>
                                            <?php else: ?>
                                                <strong><?= htmlspecialchars(formatGradeValue($voto['voto'])) ?></strong>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars($voto['data_valutazione'] ?? '') ?></small>
                                        </td>
                                                <td>
                                                    <?php if ($voto['pubblicato']): ?>
                                                        <span class="badge bg-success">Pubblicato</span>
                                                    <?php else: ?>
                                                        <span class="badge bg-warning">Da pubblicare</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <div class="btn-group btn-group-sm" role="group">
                                                        <button type="button" class="btn btn-outline-primary btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#editModal<?= $voto['id_voto'] ?>"
                                                                title="Modifica">
                                                            <i class="bi bi-pencil"></i>
                                                        </button>
                                                        <button type="button" class="btn btn-outline-danger btn-sm"
                                                                data-bs-toggle="modal"
                                                                data-bs-target="#deleteModal<?= $voto['id_voto'] ?>"
                                                                title="Elimina">
                                                            <i class="bi bi-trash"></i>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row mt-4">
            <div class="col-12">
                <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                    <i class="bi bi-arrow-left"></i> Torna all'UDA
                </a>
            </div>
        </div>
    </div>

    <!-- Modal Pubblicazione Registro -->
    <div class="modal fade" id="publishModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="publish_to_registro" value="1">
                    <div class="modal-header">
                        <h5 class="modal-title">Pubblica Voti su ClasseViva</h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <p>Seleziona i voti da pubblicare sul registro elettronico:</p>
                        <?php
                        $hasPublishable = false;
                        foreach ($voti as $v) {
                            if (!isVotoPubblicato($v)) {
                                $hasPublishable = true;
                                break;
                            }
                        }
                        ?>
                                                <?php if (!empty($voti)): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="selectAllPublish" <?= $hasPublishable ? 'checked' : 'disabled' ?>>
                                <label class="form-check-label fw-semibold" for="selectAllPublish">
                                    Seleziona tutti
                                </label>
                                <?php if (!$hasPublishable): ?>
                                    <small class="text-muted ms-2">Nessun voto da pubblicare</small>
                                <?php endif; ?>
                            </div>
                            <?php foreach ($voti as $voto): ?>
                                <?php $isPubblicato = isVotoPubblicato($voto); ?>
                                <div class="form-check">
                                    <input class="form-check-input publish-checkbox" type="checkbox" name="voti_ids[]"
                                           value="<?= htmlspecialchars($voto['id_voto']) ?>"
                                           id="voto_<?= htmlspecialchars($voto['id_voto']) ?>"
                                           <?= $isPubblicato ? '' : 'checked' ?>
                                           >
                                    <label class="form-check-label" for="voto_<?= htmlspecialchars($voto['id_voto']) ?>">
                                        <?= htmlspecialchars($voto['cognome_studente'] . ' ' . $voto['nome_studente']) ?>
                                        - Voto: <strong><?= htmlspecialchars(formatGradeValue($voto['voto'])) ?></strong>
                                        <?php if ($isPubblicato): ?>
                                            <span class="badge bg-success ms-2">Gia pubblicato</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                Nessun voto disponibile per questa UDA.
                            </div>
                        <?php endif; ?>

                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-cloud-upload"></i> Pubblica Selezionati
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <!-- Modal Modifica e Cancellazione per ogni voto -->
    <?php foreach ($voti as $voto): ?>
        <!-- Modal Modifica Voto -->
        <div class="modal fade" id="editModal<?= $voto['id_voto'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="edit_voto" value="1">
                        <input type="hidden" name="voto_id" value="<?= htmlspecialchars($voto['id_voto']) ?>">

                        <div class="modal-header">
                            <h5 class="modal-title">Modifica Voto</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <div class="mb-3">
                                <label class="form-label">Studente</label>
                                <input type="text" class="form-control" value="<?= htmlspecialchars(($voto['cognome'] ?? '') . ' ' . ($voto['nome'] ?? '')) ?>" disabled>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Tipo Valutazione</label>
                                <select name="tipo_valutazione" class="form-select" required>
                                    <option value="orale" <?= ($voto['tipo_voto'] ?? '') === 'orale' ? 'selected' : '' ?>>Interrogazione Orale</option>
                                    <option value="scritto" <?= ($voto['tipo_voto'] ?? '') === 'scritto' ? 'selected' : '' ?>>Verifica Scritta</option>
                                    <option value="pratico" <?= ($voto['tipo_voto'] ?? '') === 'pratico' ? 'selected' : '' ?>>Prova Pratica</option>
                                    <option value="test" <?= ($voto['tipo_voto'] ?? '') === 'test' ? 'selected' : '' ?>>Test</option>
                                    <option value="compito" <?= ($voto['tipo_voto'] ?? '') === 'compito' ? 'selected' : '' ?>>Compito</option>
                                </select>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Voto</label>
                                <input type="number" name="voto" class="form-control"
                                       value="<?= htmlspecialchars($voto['voto'] ?? '') ?>"
                                       min="1" max="10" step="0.25" required>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Data Valutazione</label>
                                <input type="date" name="data_valutazione" class="form-control"
                                       value="<?= htmlspecialchars($voto['data_valutazione'] ?? date('Y-m-d')) ?>">
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nota per la famiglia</label>
                                <textarea name="giudizio" class="form-control" rows="3"><?= htmlspecialchars($voto['giudizio'] ?? '') ?></textarea>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Nota interna</label>
                                <textarea name="descrizione" class="form-control" rows="3"><?= htmlspecialchars($voto['descrizione'] ?? '') ?></textarea>
                            </div>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-save"></i> Salva Modifiche
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Modal Cancellazione Voto -->
        <div class="modal fade" id="deleteModal<?= $voto['id_voto'] ?>" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST">
                        <input type="hidden" name="delete_voto" value="1">
                        <input type="hidden" name="voto_id" value="<?= htmlspecialchars($voto['id_voto']) ?>">

                        <div class="modal-header bg-danger text-white">
                            <h5 class="modal-title">Conferma Cancellazione</h5>
                            <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                        </div>

                        <div class="modal-body">
                            <p>Sei sicuro di voler eliminare questo voto?</p>
                            <div class="alert alert-warning">
                                <strong>Studente:</strong> <?= htmlspecialchars(($voto['cognome'] ?? '') . ' ' . ($voto['nome'] ?? '')) ?><br>
                                <strong>Voto:</strong> <?= htmlspecialchars($voto['voto'] ?? '') ?><br>
                                <strong>Tipo:</strong> <?= htmlspecialchars($voto['tipo_voto'] ?? '') ?><br>
                                <strong>Data:</strong> <?= htmlspecialchars($voto['data_valutazione'] ?? '') ?>
                            </div>
                            <p class="text-danger"><strong>Questa azione non può essere annullata!</strong></p>
                        </div>

                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                            <button type="submit" class="btn btn-danger">
                                <i class="bi bi-trash"></i> Elimina Voto
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
    <!-- Modal Cancella Voti -->
    <div class="modal fade" id="deleteAllModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <form method="POST">
                    <input type="hidden" name="delete_all_voti" value="1">

                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title">
                            <i class="bi bi-trash"></i> Cancella Voti Selezionati
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>

                    <div class="modal-body">
                        <p>Seleziona i voti da cancellare. Sono preselezionati quelli gia pubblicati.</p>
                        <?php if (!empty($voti)): ?>
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" id="selectAllDelete">
                                <label class="form-check-label fw-semibold" for="selectAllDelete">
                                    Seleziona tutti
                                </label>
                            </div>
                            <?php foreach ($voti as $voto): ?>
                                <?php $isPubblicato = isVotoPubblicato($voto); ?>
                                <div class="form-check">
                                    <input class="form-check-input delete-checkbox" type="checkbox" name="voti_ids[]"
                                           value="<?= htmlspecialchars($voto['id_voto']) ?>"
                                           id="delete_<?= htmlspecialchars($voto['id_voto']) ?>"
                                           <?= $isPubblicato ? 'checked' : '' ?>>
                                    <label class="form-check-label" for="delete_<?= htmlspecialchars($voto['id_voto']) ?>">
                                        <?= htmlspecialchars($voto['cognome_studente'] . ' ' . $voto['nome_studente']) ?>
                                        - Voto: <strong><?= htmlspecialchars(formatGradeValue($voto['voto'])) ?></strong>
                                        - Data: <strong><?= htmlspecialchars($voto['data_valutazione'] ?? '-') ?></strong>
                                        - Tipo: <strong><?= htmlspecialchars($voto['tipo_valutazione'] ?? '-') ?></strong>
                                        <?php if ($isPubblicato): ?>
                                            <span class="badge bg-success ms-2">Gia pubblicato</span>
                                        <?php endif; ?>
                                    </label>
                                </div>
                            <?php endforeach; ?>
                            <div class="alert alert-warning mt-3 mb-0">
                                Questa operazione elimina i voti selezionati e non puo essere annullata.
                            </div>
                        <?php else: ?>
                            <div class="alert alert-info mb-0">
                                Nessun voto disponibile per questa UDA.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="bi bi-x-circle"></i> Annulla
                        </button>
                        <button type="submit" class="btn btn-danger" <?= empty($voti) ? 'disabled' : '' ?>>
                            <i class="bi bi-trash"></i> Elimina Selezionati
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const master = document.getElementById('selectAllPublish');
            if (!master) return;
            const items = Array.from(document.querySelectorAll('.publish-checkbox'));
            function syncMaster() {
                const enabled = items.filter(cb => !cb.disabled);
                const all = enabled.length > 0 && enabled.every(cb => cb.checked);
                const some = enabled.some(cb => cb.checked);
                master.checked = all;
                master.indeterminate = !all && some;
            }
            master.addEventListener('change', () => {
                items.forEach(cb => {
                    if (!cb.disabled) cb.checked = master.checked;
                });
                master.indeterminate = false;
            });
            items.forEach(cb => cb.addEventListener('change', syncMaster));
            syncMaster();
        })();
        (function () {
            const master = document.getElementById('selectAllDelete');
            if (!master) return;
            const items = Array.from(document.querySelectorAll('.delete-checkbox'));
            function syncMaster() {
                const all = items.length > 0 && items.every(cb => cb.checked);
                const some = items.some(cb => cb.checked);
                master.checked = all;
                master.indeterminate = !all && some;
            }
            master.addEventListener('change', () => {
                items.forEach(cb => cb.checked = master.checked);
                master.indeterminate = false;
            });
            items.forEach(cb => cb.addEventListener('change', syncMaster));
            syncMaster();
        })();
    </script>
</body>
</html>









