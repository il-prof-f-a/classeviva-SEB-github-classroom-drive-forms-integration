<?php
/**
 * Sincronizzazione Studenti da ClasseViva
 *
 * Permette di:
 * - Sincronizzare studenti di una classe specifica
 * - Sincronizzare tutte le classi del docente
 * - Visualizzare studenti sincronizzati
 * - Vedere statistiche sincronizzazione
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\StudentiManager;
use App\Integration\ClasseVivaAPI;

$db = DatabaseFactory::createWithInitialization($config, true);
$cvAPI = new ClasseVivaAPI($config);
$studentiManager = new StudentiManager($db, $cvAPI, $config);
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classeVivaState['ready'];
$cvNotice = $classeVivaState['notice'] ?? 'Token ClasseViva non disponibile.';

$message = null;
$error = null;
$stats = null;
$classiCV = [];
$classiSincronizzate = [];

// Azione
$action = $_POST['action'] ?? $_GET['action'] ?? null;

try {
    if (!$cvReady) {
        throw new Exception($cvNotice);
    }

    // Recupera classi dal docente
    $classiCV = $cvAPI->getClassesWithTeacherSubjects();

    // Recupera classi già sincronizzate
    $classiSincronizzate = $studentiManager->getClassiSincronizzate();

    if ($action === 'sync_classe' && isset($_POST['id_classe_cv'])) {
        // Sincronizza singola classe
        $idClasseCV = $_POST['id_classe_cv'];
        $nomeClasse = $_POST['nome_classe'] ?? $idClasseCV;

        $stats = $studentiManager->sincronizzaStudentiClasse($idClasseCV, $nomeClasse);

        $message = "Sincronizzazione completata! {$stats['sincronizzati']} studenti sincronizzati.";
        if ($stats['nuovi'] > 0) {
            $message .= " {$stats['nuovi']} nuovi studenti.";
        }
        if ($stats['disattivati'] > 0) {
            $message .= " {$stats['disattivati']} studenti disattivati.";
        }

        // Ricarica classi sincronizzate
        $classiSincronizzate = $studentiManager->getClassiSincronizzate();
    }

    if ($action === 'sync_tutte') {
        // Sincronizza tutte le classi
        $stats = $studentiManager->sincronizzaTutteLeClassi();

        $message = "Sincronizzazione completa! {$stats['classi_sincronizzate']} classi, " .
                   "{$stats['studenti_sincronizzati']} studenti totali sincronizzati.";
        if ($stats['nuovi'] > 0) {
            $message .= " {$stats['nuovi']} nuovi studenti.";
        }
        if ($stats['disattivati'] > 0) {
            $message .= " {$stats['disattivati']} studenti disattivati.";
        }

        // Ricarica classi sincronizzate
        $classiSincronizzate = $studentiManager->getClassiSincronizzate();
    }

} catch (Exception $e) {
    $error = "Errore sincronizzazione: " . $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sincronizzazione Studenti ClasseViva</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .classe-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.2s;
        }
        .classe-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .classe-sincronizzata {
            border-left-color: #198754;
        }
        .badge-materia {
            font-size: 0.75rem;
            margin-right: 4px;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-arrow-repeat"></i> Sincronizzazione Studenti';
    $pageSubtitle = 'Sincronizza gli studenti da ClasseViva per usarli nelle valutazioni UDA';
    $headerActions = '<a class="nav-link" href="index.php"><i class="bi bi-house"></i> Dashboard</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container mt-4">
<!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($message) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($error) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Info GDPR -->
        <div class="alert alert-info">
            <h5 class="alert-heading"><i class="bi bi-shield-check"></i> Gestione GDPR-Compliant</h5>
            <p class="mb-0">
                Il sistema salva <strong>solo gli ID studenti</strong> (codici ClasseViva) nel database locale.
                I nomi e cognomi sono recuperati <strong>on-demand</strong> dalle API ClasseViva quando necessario,
                garantendo la privacy secondo GDPR.
            </p>
        </div>

        <div class="row">
            <!-- Classi ClasseViva -->
            <div class="col-md-8">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                        <h5 class="mb-0"><i class="bi bi-diagram-3"></i> Classi ClasseViva (<?= count($classiCV) ?>)</h5>
                        <form method="post" style="display:inline;">
                            <input type="hidden" name="action" value="sync_tutte">
                            <button type="submit" class="btn btn-light btn-sm"
                                    onclick="return confirm('Sincronizzare TUTTE le classi? Operazione potrebbe richiedere tempo.')">
                                <i class="bi bi-arrow-repeat"></i> Sincronizza Tutte
                            </button>
                        </form>
                    </div>
                    <div class="card-body">
                        <?php if (empty($classiCV)): ?>
                            <div class="alert alert-warning">
                                <i class="bi bi-exclamation-triangle"></i> Nessuna classe trovata su ClasseViva.
                                Verifica le credenziali e l'assegnazione delle classi.
                            </div>
                        <?php else: ?>
                            <div class="row">
                                <?php foreach ($classiCV as $classe): ?>
                                    <?php
                                    $idClasseCV = $classe['id'];
                                    $nomeClasse = $classe['name'];
                                    $materie = $classe['subjects'] ?? [];

                                    // Verifica se già sincronizzata
                                    $sincronizzata = false;
                                    $dataSincronizzazione = null;
                                    $numStudenti = 0;
                                    foreach ($classiSincronizzate as $cs) {
                                        if ($cs['id_classe_cv'] === $idClasseCV) {
                                            $sincronizzata = true;
                                            $numStudenti = $cs['studenti_attivi'];
                                            break;
                                        }
                                    }
                                    ?>
                                    <div class="col-md-6 mb-3">
                                        <div class="card classe-card <?= $sincronizzata ? 'classe-sincronizzata' : '' ?> h-100">
                                            <div class="card-body">
                                                <div class="d-flex justify-content-between align-items-start mb-2">
                                                    <h5 class="card-title mb-0">
                                                        <?= htmlspecialchars($nomeClasse) ?>
                                                    </h5>
                                                    <?php if ($sincronizzata): ?>
                                                        <span class="badge bg-success">
                                                            <i class="bi bi-check-circle"></i> Sincronizzata
                                                        </span>
                                                    <?php else: ?>
                                                        <span class="badge bg-secondary">Non sincronizzata</span>
                                                    <?php endif; ?>
                                                </div>

                                                <div class="mb-2">
                                                    <small class="text-muted">
                                                        <i class="bi bi-hash"></i> ID: <?= htmlspecialchars($idClasseCV) ?>
                                                    </small>
                                                </div>

                                                <?php if (!empty($materie)): ?>
                                                    <div class="mb-3">
                                                        <small class="text-muted d-block mb-1">Materie insegnate:</small>
                                                        <?php foreach (array_slice($materie, 0, 3) as $materia): ?>
                                                            <span class="badge badge-materia bg-info">
                                                                <?= htmlspecialchars($materia['description'] ?? $materia['name'] ?? 'N/D') ?>
                                                            </span>
                                                        <?php endforeach; ?>
                                                        <?php if (count($materie) > 3): ?>
                                                            <span class="badge badge-materia bg-secondary">+<?= count($materie) - 3 ?></span>
                                                        <?php endif; ?>
                                                    </div>
                                                <?php endif; ?>

                                                <?php if ($sincronizzata): ?>
                                                    <div class="alert alert-success py-2 mb-2">
                                                        <i class="bi bi-people"></i> <?= $numStudenti ?> studenti attivi
                                                    </div>
                                                <?php endif; ?>

                                                <form method="post">
                                                    <input type="hidden" name="action" value="sync_classe">
                                                    <input type="hidden" name="id_classe_cv" value="<?= htmlspecialchars($idClasseCV) ?>">
                                                    <input type="hidden" name="nome_classe" value="<?= htmlspecialchars($nomeClasse) ?>">
                                                    <button type="submit" class="btn btn-primary btn-sm w-100">
                                                        <i class="bi bi-arrow-repeat"></i>
                                                        <?= $sincronizzata ? 'Ri-sincronizza' : 'Sincronizza' ?>
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

            <!-- Sidebar Statistiche -->
            <div class="col-md-4">
                <!-- Statistiche Globali -->
                <div class="card mb-4">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Statistiche</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h2 class="display-4"><?= count($classiSincronizzate) ?></h2>
                            <p class="text-muted">Classi sincronizzate</p>
                        </div>

                        <hr>

                        <?php
                        $totaleStudenti = 0;
                        $totaleDisattivati = 0;
                        foreach ($classiSincronizzate as $cs) {
                            $totaleStudenti += $cs['studenti_attivi'];
                            $totaleDisattivati += $cs['studenti_disattivati'];
                        }
                        ?>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span><i class="bi bi-person-check text-success"></i> Studenti attivi:</span>
                                <strong><?= $totaleStudenti ?></strong>
                            </div>
                        </div>

                        <?php if ($totaleDisattivati > 0): ?>
                            <div class="mb-3">
                                <div class="d-flex justify-content-between">
                                    <span><i class="bi bi-person-dash text-warning"></i> Studenti disattivati:</span>
                                    <strong><?= $totaleDisattivati ?></strong>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Info Utilizzo -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h5 class="mb-0"><i class="bi bi-info-circle"></i> Come Funziona</h5>
                    </div>
                    <div class="card-body">
                        <ol class="mb-0">
                            <li class="mb-2">Clicca <strong>Sincronizza</strong> su una classe per importare gli ID studenti</li>
                            <li class="mb-2">Gli studenti saranno disponibili nelle interfacce di valutazione (Rubrica, Laboratorio)</li>
                            <li class="mb-2">I nomi sono recuperati <strong>on-demand</strong> da ClasseViva quando servono</li>
                            <li class="mb-2">Ri-sincronizza periodicamente per aggiornare la lista studenti</li>
                        </ol>
                    </div>
                </div>
            </div>
        </div>

        <!-- Classi Sincronizzate Dettaglio -->
        <?php if (!empty($classiSincronizzate)): ?>
            <div class="card">
                <div class="card-header bg-secondary text-white">
                    <h5 class="mb-0"><i class="bi bi-list-check"></i> Classi Sincronizzate - Dettaglio</h5>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Classe</th>
                                    <th>ID ClasseViva</th>
                                    <th>Studenti Attivi</th>
                                    <th>Studenti Disattivati</th>
                                    <th>Azioni</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($classiSincronizzate as $cs): ?>
                                    <tr>
                                        <td><strong><?= htmlspecialchars($cs['nome_classe']) ?></strong></td>
                                        <td><code><?= htmlspecialchars($cs['id_classe_cv']) ?></code></td>
                                        <td>
                                            <span class="badge bg-success"><?= $cs['studenti_attivi'] ?></span>
                                        </td>
                                        <td>
                                            <?php if ($cs['studenti_disattivati'] > 0): ?>
                                                <span class="badge bg-warning"><?= $cs['studenti_disattivati'] ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">0</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <form method="post" style="display:inline;">
                                                <input type="hidden" name="action" value="sync_classe">
                                                <input type="hidden" name="id_classe_cv" value="<?= htmlspecialchars($cs['id_classe_cv']) ?>">
                                                <input type="hidden" name="nome_classe" value="<?= htmlspecialchars($cs['nome_classe']) ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary">
                                                    <i class="bi bi-arrow-repeat"></i> Ri-sincronizza
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
