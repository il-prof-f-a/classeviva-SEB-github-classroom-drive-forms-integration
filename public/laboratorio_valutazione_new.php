<?php

/**
 * Valutazione Attività Laboratorio - Sistema PiùOMeno
 *
 * Sistema con coda modificabile 2 ore:
 * - Selezione: UDA → Classe → Materia → Studente
 * - Evidenze +/- per indicatore (modificabili 2h)
 * - Auto-registrazione dopo 2h
 * - Costruzione voto separata
 */

error_reporting(E_ALL);

define('REQUIRES_CLASSEVIVA', true);
$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\ProviderCapabilityResolver;
use App\Core\UDAManager;
use App\Core\LaboratorioManager;
use App\Core\StudentiManager;
use App\Core\RuntimeStudentNameService;
use App\Core\UdaGroupRepository;
use App\Core\TeachingGroupRepository;
use App\Core\TeachingGroupIntegrationRepository;
use App\Integration\ClasseVivaAPI;
use App\Integration\GitHubIntegration;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));
$labManager = new LaboratorioManager($dbAdapter, $config);

// Inizializza ClasseViva API
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classeVivaState['ready'];
$cvNotice = $classeVivaState['notice'] ?? 'Token ClasseViva non disponibile.';
$cvAPI = new ClasseVivaAPI($config);
$studentiManager = new StudentiManager($dbAdapter, $cvAPI, $config);

$message = null;
$error = null;

// Parametri
$udaId = $_GET['id_uda'] ?? $_POST['uda_id'] ?? null;
$idStudenteCV = $_GET['id_studente'] ?? $_POST['id_studente'] ?? null;

// Risolvi il gruppo didattico dall'UDA (provider-neutral). La classe e la materia
// non sono più selezionabili: derivano dal gruppo, che può essere mappato o meno
// a ClasseViva.
$gruppoId = trim((string)($_GET['id_gruppo'] ?? ''));
$gruppoClasse = '';
$gruppoMateria = '';
$idClasseCV = '';
$idMateriaCV = '';
$gruppoClasseViva = false;
$gruppiUda = [];
if ($udaId) {
    $udaGroupRepo = new UdaGroupRepository($dbAdapter, $userId);
    foreach ($udaGroupRepo->listForUda((string)$udaId) as $assegnazione) {
        $gid = trim((string)($assegnazione['id_gruppo'] ?? ''));
        if ($gid !== '') {
            $gruppiUda[] = $gid;
        }
    }
    $gruppiUda = array_values(array_unique($gruppiUda));
    if ($gruppoId === '' && count($gruppiUda) === 1) {
        $gruppoId = $gruppiUda[0];
    }
    if ($gruppoId !== '') {
        $group = (new TeachingGroupRepository($dbAdapter, $userId))->findById($gruppoId);
        if ($group !== null) {
            $gruppoClasse = trim((string)($group['nome_classe'] ?? $group['nome_gruppo'] ?? ''));
            $gruppoMateria = trim((string)($group['nome_materia'] ?? ''));
        }
        foreach ((new TeachingGroupIntegrationRepository($dbAdapter, $userId))->listForGroup($gruppoId) as $integration) {
            if (($integration['provider'] ?? '') !== 'classeviva' || ($integration['stato'] ?? 'attivo') === 'disattivo') {
                continue;
            }
            $gruppoClasseViva = true;
            $idClasseCV = trim((string)($integration['external_context_id'] ?? ''));
            $idMateriaCV = trim((string)($integration['external_subject_id'] ?? ''));
            if ($gruppoClasse === '' && !empty($integration['external_name'])) {
                $gruppoClasse = trim((string)$integration['external_name']);
            }
        }
    }
}

// Rileva se l'elenco studenti dipende dalle sole credenziali GitHub Classroom.
$githubStudentListAuthNeeded = false;
$githubAuthUrl = null;
if ($gruppoId !== '') {
    $activeProviders = [];
    foreach ((new TeachingGroupIntegrationRepository($dbAdapter, $userId))->listForGroup($gruppoId) as $integration) {
        if (($integration['stato'] ?? 'attivo') !== 'disattivo') {
            $activeProviders[(string)($integration['provider'] ?? '')] = true;
        }
    }
    $activeProviders = array_keys($activeProviders);
    if (count($activeProviders) === 1 && ($activeProviders[0] ?? '') === 'github_classroom') {
        $githubIntegration = new GitHubIntegration($config);
        if (!$githubIntegration->loadTokenFromSession() || !$githubIntegration->isAuthenticated()) {
            $githubStudentListAuthNeeded = true;
            try {
                $githubAuthUrl = $githubIntegration->getAuthorizationUrl(null, (string)($_SERVER['REQUEST_URI'] ?? 'laboratorio_valutazione_new.php'));
            } catch (Throwable $ignored) {
                $githubAuthUrl = null;
            }
        }
    }
}

// Calcola timestamp 2 ore fa
$duehOraFa = date('Y-m-d H:i:s', strtotime('-2 hours'));

try {
    // OTTIMIZZAZIONE: Carica solo se necessario
    $udas = [];
    $indicatori = [];
    $classi = [];
    $materie = [];
    $studenti = [];

    // Carica UDA solo per il dropdown (se non preselezionata)
    if (!$udaId) {
        $udas = $udaManager->getAllUDAs();
    } else {
        // Se UDA già selezionata, carica solo quella
        $allUdas = $udaManager->getAllUDAs();
        foreach ($allUdas as $uda) {
            if ($uda->id_uda === $udaId) {
                $udas = [$uda];
                break;
            }
        }
    }

    // Carica indicatori solo quando serve (studente selezionato)
    if ($idStudenteCV) {
        $indicatori = $labManager->getIndicatoriConCategorie();
    }

    // Carica gli studenti direttamente dal roster del gruppo didattico
    // (ClasseViva → Google Classroom → GitHub Classroom), senza dipendere
    // dalla mappatura ClasseViva.
    if ($udaId && $gruppoId !== '') {
        try {
            $rosterStudents = (new RuntimeStudentNameService($dbAdapter, $userId, $config))
                ->resolveGroupStudents($gruppoId);
            foreach ($rosterStudents as $rosterStudent) {
                $studenti[] = [
                    'id' => $rosterStudent['id_studente'],
                    'id_studente_internal' => (string)($rosterStudent['id_studente_internal'] ?? ''),
                    'provider' => $rosterStudent['provider'],
                    'nome_completo' => $rosterStudent['nome_completo'],
                    'cognome' => $rosterStudent['cognome'],
                    'nome' => $rosterStudent['nome'],
                ];
            }
        } catch (Throwable $exception) {
            error_log('Errore roster laboratorio: ' . $exception->getMessage());
            $error = 'Impossibile caricare gli studenti del gruppo didattico.';
            $studenti = [];
        }
    }

    // Carica evidenze esistenti per lo studente
    $evidenzeRecenti = [];
    $evidenzeVecchie = [];

    if ($idStudenteCV && $udaId) {
        // Filtra per gruppo didattico + studente (provider-neutral).
        $evidenzeWhere = ['id_uda' => $udaId];
        if ($gruppoId !== '') {
            $evidenzeWhere['id_gruppo'] = $gruppoId;
        }
        $evidenzeWhere['id_studente'] = $idStudenteCV;
        $evidenzeStudente = $dbAdapter->findWhere('PLUSMINUS_QUEUE', $evidenzeWhere);

        // Separa recenti da vecchie (già molto più veloce con dataset ridotto)
        foreach ($evidenzeStudente as $ev) {
            $dataInserimento = $ev['data_inserimento'] ?? '';
            $isRecente = ($dataInserimento >= $duehOraFa) && (($ev['registrato'] ?? 0) == 0);

            if ($isRecente) {
                $evidenzeRecenti[] = $ev;
            } else {
                $evidenzeVecchie[] = $ev;
            }
        }
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

// Username docente corrente
$username = $_SESSION['username'] ?? 'docente';

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Laboratorio PiùOMeno</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .evidenza-btn { width: 60px; height: 60px; font-size: 32px; margin: 3px; border-radius: 8px; }
        .evidenza-btn.positiva { border: 3px solid #198754; color: #198754; background: white; }
        .evidenza-btn.negativa { border: 3px solid #dc3545; color: #dc3545; background: white; }
        .evidenza-btn.positiva.active { background: #198754; color: white; }
        .evidenza-btn.negativa.active { background: #dc3545; color: white; }
        .evidenza-btn:hover { opacity: 0.8; }

        .evidenza-vecchia { opacity: 0.4; cursor: not-allowed; }
        .evidenza-recente { border: 2px solid #0d6efd; }

        .indicatore-card { margin-bottom: 20px; border-left: 4px solid #0d6efd; }
        .categoria-badge { font-size: 0.85rem; }

        .evidenze-timeline { max-height: 400px; overflow-y: auto; }
        .evidenza-item { padding: 8px; margin: 4px 0; border-radius: 4px; }
        .evidenza-item.positiva { background: #d1e7dd; border-left: 4px solid #198754; }
        .evidenza-item.negativa { background: #f8d7da; border-left: 4px solid #dc3545; }
        .evidenza-item.vecchia { opacity: 0.6; }

        #comment-modal { display: none; position: fixed; z-index: 9999; left: 0; top: 0; width: 100%; height: 100%; background: rgba(0,0,0,0.5); }
        #comment-modal-content { background: white; margin: 15% auto; padding: 20px; width: 400px; border-radius: 8px; }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-plus-slash-minus"></i> Laboratorio PiuOMeno';
    ob_start();
    ?>
    <?php if ($gruppoClasseViva): ?>
    <a href="pubblicazione_cv.php<?php echo $udaId ? "?id_uda=" . urlencode($udaId) : ""; ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-cloud-upload"></i> Pubblica Valutazioni
    </a>
    <?php else: ?>
    <button type="button" class="btn btn-outline-light btn-sm" disabled title="Nessun mapping ClasseViva">
        <i class="bi bi-cloud-upload"></i> Pubblica Valutazioni
    </button>
    <?php endif; ?>
    <?php if ($udaId): ?>
        <a href="uda_view.php?id=<?php echo urlencode($udaId); ?>" class="btn btn-outline-light btn-sm">
            <i class="bi bi-arrow-left"></i> Torna all'UDA
        </a>
    <?php endif; ?>
    <a href="index.php" class="btn btn-outline-light btn-sm">
        <i class="bi bi-house"></i> Dashboard
    </a>
    <?php
    $headerActions = ob_get_clean();
    $headerContainerClass = 'container-fluid';
    include __DIR__ . '/partials/app_header.php';
    ?>
<div class="container-fluid mt-4">
<!-- Messaggi -->
        <?php if ($message): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($gruppoId !== '' && !$gruppoClasseViva): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span>
                    <i class="bi bi-exclamation-triangle"></i>
                    <strong>Nessun mapping ClasseViva:</strong> alcune funzionalità (pubblicazione annotazioni e voti) sono disabilitate.
                </span>
                <a href="teaching_groups.php?return_to=<?= urlencode('laboratorio_valutazione_new.php?id_uda=' . $udaId) ?>" class="btn btn-sm btn-outline-warning">
                    <i class="bi bi-link-45deg"></i> Vai ai mapping
                </a>
            </div>
        <?php endif; ?>

        <?php if ($githubStudentListAuthNeeded): ?>
            <div class="alert alert-warning d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span><i class="bi bi-github"></i> L'elenco degli studenti dipende dalle credenziali GitHub Classroom. Autorizza GitHub per visualizzarlo.</span>
                <a href="<?= htmlspecialchars((string)$githubAuthUrl) ?>" class="btn btn-sm btn-dark"><i class="bi bi-github"></i> Autorizza GitHub</a>
            </div>
        <?php endif; ?>

        <!-- Info Sistema -->
        <div class="alert alert-info">
            <h5 class="alert-heading"><i class="bi bi-info-circle"></i> Sistema PiùOMeno</h5>
            <p class="mb-0">
                Le evidenze <strong>+/-</strong> sono <strong>modificabili per 2 ore</strong> dall'inserimento.
                Dopo 2 ore vengono registrate internamente; la pubblicazione su ClasseViva richiede il mapping.
                Il voto si costruisce in un momento successivo con il pulsante dedicato.
            </p>
        </div>

        <!-- Selezione -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> Selezione</h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <!-- UDA -->
                    <div class="col-md-3">
                        <label class="form-label">UDA</label>
                        <select id="uda-select" class="form-select">
                            <option value="">-- Seleziona UDA --</option>
                            <?php foreach ($udas as $uda): ?>
                                <option value="<?= htmlspecialchars($uda->id_uda) ?>"
                                        <?= $uda->id_uda === $udaId ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($uda->titolo) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <!-- Classe + Materia (derivata dal gruppo didattico) -->
                    <?php if ($gruppoId !== ''): ?>
                        <div class="col-md-3">
                            <label class="form-label">Classe + Materia</label>
                            <div class="form-control bg-light">
                                <i class="bi bi-people"></i>
                                <?= htmlspecialchars(trim(($gruppoClasse !== '' ? $gruppoClasse . ' - ' : '') . $gruppoMateria) ?: 'Gruppo didattico') ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <!-- Studente -->
                    <?php if (!empty($studenti)): ?>
                        <div class="col-md-3">
                            <label class="form-label">Studente</label>
                            <select id="studente-select" class="form-select">
                                <option value="">-- Seleziona Studente --</option>
                                <?php foreach ($studenti as $s): ?>
                                    <option value="<?= htmlspecialchars($s['id']) ?>"
                                            data-provider="<?= htmlspecialchars($s['provider'] ?? 'internal') ?>"
                                            data-studente-internal="<?= htmlspecialchars((string)($s['id_studente_internal'] ?? '')) ?>"
                                            <?= $s['id'] === $idStudenteCV ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($s['nome_completo'] ?? ($s['cognome'] . ' ' . $s['nome'])) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    <?php endif; ?>

                    <!-- Pulsante Voto -->
                    <?php if ($idStudenteCV && $udaId): ?>
                        <div class="col-md-3 d-flex align-items-end">
                            <button id="costruisci-voto-btn" class="btn btn-warning w-100">
                                <i class="bi bi-calculator"></i> Costruisci Voto
                            </button>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <?php if ($idStudenteCV && $udaId && $gruppoId !== ''): ?>
            <div class="row">
                <!-- Colonna Indicatori -->
                <div class="col-md-9">
                    <div class="card">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0"><i class="bi bi-plus-slash-minus"></i> Evidenze</h5>
                        </div>
                        <div class="card-body p-0">
                            <?php if (empty($indicatori)): ?>
                                <div class="alert alert-warning m-3">
                                    Nessun indicatore configurato. <a href="laboratorio_setup.php">Configura indicatori</a>
                                </div>
                            <?php else: ?>
                                <table class="table table-hover mb-0">
                                    <thead class="table-light">
                                        <tr>
                                            <th style="width: 40%">Evidenze / Descrizione</th>
                                            <th style="width: 25%">Ambito</th>
                                            <th style="width: 35%" class="text-center">Livelli</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php
                                        // Flatten indicatori array
                                        $allIndicatori = [];
                                        foreach ($indicatori as $cat => $inds) {
                                            foreach ($inds as $ind) {
                                                $ind['categoria'] = $cat;
                                                $allIndicatori[] = $ind;
                                            }
                                        }

                                        foreach ($allIndicatori as $ind):
                                            // Check if this indicatore has recent vote
                                            $hasRecentVote = false;
                                            $voteValue = null;
                                            foreach ($evidenzeRecenti as $ev) {
                                                if (($ev['id_indicatore'] ?? '') === $ind['id_indicatore']) {
                                                    $hasRecentVote = true;
                                                    $voteValue = $ev['valore'] ?? null;
                                                    break;
                                                }
                                            }
                                        ?>
                                            <tr>
                                                <td>
                                                    <strong><?= htmlspecialchars($ind['nome']) ?></strong>
                                                    <?php if (!empty($ind['descrizione'])): ?>
                                                        <br><small class="text-muted"><?= htmlspecialchars($ind['descrizione']) ?></small>
                                                    <?php endif; ?>
                                                </td>
                                                <td>
                                                    <span class="badge" style="background-color: #<?= htmlspecialchars($ind['categoria_colore'] ?? '6c757d') ?>">
                                                        <?= htmlspecialchars($ind['categoria'] ?? 'Generale') ?>
                                                    </span>
                                                </td>
                                                <td class="text-center">
                                                    <div class="btn-group" role="group">
                                                        <button class="btn evidenza-btn positiva <?= ($hasRecentVote && $voteValue === '+') ? 'active' : '' ?>"
                                                                data-indicatore="<?= htmlspecialchars($ind['id_indicatore']) ?>"
                                                                data-nome="<?= htmlspecialchars($ind['nome']) ?>"
                                                                data-valore="+">
                                                            +
                                                        </button>
                                                        <button class="btn evidenza-btn negativa <?= ($hasRecentVote && $voteValue === '-') ? 'active' : '' ?>"
                                                                data-indicatore="<?= htmlspecialchars($ind['id_indicatore']) ?>"
                                                                data-nome="<?= htmlspecialchars($ind['nome']) ?>"
                                                                data-valore="-">
                                                            −
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Colonna Voti Vecchi -->
                <div class="col-md-3">
                    <div class="card">
                        <div class="card-header bg-secondary text-white">
                            <h5 class="mb-0"><i class="bi bi-clock-history"></i> Storico Voti Vecchi</h5>
                        </div>
                        <div class="card-body">
                            <div class="evidenze-timeline" id="evidenze-timeline">
                                <?php if (empty($evidenzeVecchie)): ?>
                                    <p class="text-muted small text-center">Nessun voto oltre 2h</p>
                                <?php else: ?>
                                    <!-- Solo Evidenze Vecchie (oltre 2h) -->
                                    <?php foreach ($evidenzeVecchie as $ev): ?>
                                        <div class="evidenza-item vecchia mb-2" style="<?= ($ev['valore'] ?? '') === '+' ? 'background: #d1e7dd;' : 'background: #f8d7da;' ?> padding: 8px; border-radius: 4px; border-left: 3px solid <?= ($ev['valore'] ?? '') === '+' ? '#198754' : '#dc3545' ?>">
                                            <div style="font-size: 0.85rem;">
                                                <strong><?= ($ev['valore'] ?? '') === '+' ? '✓' : '✗' ?></strong>
                                                <?= htmlspecialchars($ev['nome_indicatore'] ?? 'Indicatore') ?>
                                            </div>
                                            <small class="text-muted" style="font-size: 0.75rem;">
                                                <?= htmlspecialchars($ev['data_inserimento'] ?? '') ?>
                                                <?php if (!empty($ev['prof'])): ?>
                                                    - <?= htmlspecialchars($ev['prof']) ?>
                                                <?php endif; ?>
                                            </small>
                                            <?php if (($ev['registrato'] ?? 0) == 1): ?>
                                                <br><small class="text-success" style="font-size: 0.7rem;"><i class="bi bi-check-circle"></i> Registrato su CV</small>
                                            <?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Timestamp ultima modifica sotto la tabella evidenze -->
            <?php if (!empty($evidenzeRecenti)): ?>
                <div class="row mt-2">
                    <div class="col-md-9">
                        <div class="alert alert-info mb-0 py-2">
                            <small>
                                <i class="bi bi-info-circle"></i>
                                Ultima modifica:
                                <?php
                                // Find most recent
                                $mostRecent = null;
                                foreach ($evidenzeRecenti as $ev) {
                                    if ($mostRecent === null || ($ev['data_inserimento'] ?? '') > ($mostRecent['data_inserimento'] ?? '')) {
                                        $mostRecent = $ev;
                                    }
                                }
                                if ($mostRecent):
                                ?>
                                    <strong><?= htmlspecialchars($mostRecent['data_inserimento'] ?? '') ?></strong>
                                    da <strong><?= htmlspecialchars($mostRecent['prof'] ?? 'docente') ?></strong>
                                <?php endif; ?>
                            </small>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Commento -->
    <div id="comment-modal">
        <div id="comment-modal-content">
            <h5>Aggiungi Commento (opzionale)</h5>
            <textarea id="comment-input" class="form-control" rows="3" placeholder="Commento..."></textarea>
            <div class="mt-3 text-end">
                <button id="comment-cancel-btn" class="btn btn-secondary">Annulla</button>
                <button id="comment-submit-btn" class="btn btn-primary">Conferma</button>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/jquery@3.6.0/dist/jquery.min.js"></script>
    <script>
        const udaId = <?= \App\Core\Security\OutputEncoder::json($udaId) ?>;
        const gruppoId = <?= \App\Core\Security\OutputEncoder::json($gruppoId) ?>;
        const idClasseCV = <?= \App\Core\Security\OutputEncoder::json($idClasseCV) ?>;
        const idMateriaCV = <?= \App\Core\Security\OutputEncoder::json($idMateriaCV) ?>;
        const idStudenteCV = <?= \App\Core\Security\OutputEncoder::json($idStudenteCV) ?>;
        const username = <?= \App\Core\Security\OutputEncoder::json($username) ?>;

        let pendingEvidenza = null;

        $(document).ready(function() {
            // Cambio UDA
            $('#uda-select').change(function() {
                const uda = $(this).val();
                if (uda) {
                    window.location.href = `laboratorio_valutazione_new.php?id_uda=${encodeURIComponent(uda)}`;
                }
            });

            // Cambio Studente (il gruppo è derivato dall'UDA)
            $('#studente-select').change(function() {
                const studente = $(this).val();
                if (studente && udaId) {
                    window.location.href = `laboratorio_valutazione_new.php?id_uda=${encodeURIComponent(udaId)}&id_gruppo=${encodeURIComponent(gruppoId)}&id_studente=${encodeURIComponent(studente)}`;
                }
            });

            // Click su +/-
            $('.evidenza-btn').click(function(e) {
                e.preventDefault();

                const indicatore = $(this).data('indicatore');
                const valore = $(this).data('valore');
                const nomeIndicatore = $(this).data('nome');

                pendingEvidenza = {
                    indicatore: indicatore,
                    valore: valore,
                    nomeIndicatore: nomeIndicatore
                };

                // Mostra modal commento (click destro) o conferma diretta (click sinistro)
                if (e.button === 2 || e.ctrlKey) {
                    $('#comment-modal').show();
                    $('#comment-input').val('').focus();
                } else {
                    inserisciEvidenza('');
                }
            });

            // Modal commento
            $('#comment-submit-btn').click(function() {
                const commento = $('#comment-input').val();
                $('#comment-modal').hide();
                inserisciEvidenza(commento);
            });

            $('#comment-cancel-btn').click(function() {
                $('#comment-modal').hide();
                pendingEvidenza = null;
            });

            // Elimina evidenza
            $('.elimina-evidenza-btn').click(function() {
                const idEvidenza = $(this).data('id');
                if (confirm('Eliminare questa evidenza?')) {
                    eliminaEvidenza(idEvidenza);
                }
            });

            // Costruisci voto
            $('#costruisci-voto-btn').click(function() {
                costruisciVoto();
            });
        });

        function inserisciEvidenza(commento) {
            if (!pendingEvidenza) return;

            $.ajax({
                url: 'ajax_inserisci_evidenza.php',
                type: 'POST',
                data: {
                    id_uda: udaId,
                    id_gruppo: gruppoId,
                    id_materia_cv: idMateriaCV,
                    id_classe_cv: idClasseCV,
                    id_studente: idStudenteCV,
                    id_studente_cv: idStudenteCV,
                    id_indicatore: pendingEvidenza.indicatore,
                    nome_indicatore: pendingEvidenza.nomeIndicatore,
                    valore: pendingEvidenza.valore,
                    commento: commento,
                    prof: username
                },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore: ' + (response.message || 'Impossibile inserire evidenza'));
                    }
                },
                error: function() {
                    alert('Errore di comunicazione con il server');
                }
            });

            pendingEvidenza = null;
        }

        function eliminaEvidenza(idEvidenza) {
            $.ajax({
                url: 'ajax_elimina_evidenza.php',
                type: 'POST',
                data: { id_evidenza: idEvidenza },
                success: function(response) {
                    if (response.success) {
                        location.reload();
                    } else {
                        alert('Errore: ' + (response.message || 'Impossibile eliminare evidenza'));
                    }
                },
                error: function() {
                    alert('Errore di comunicazione con il server');
                }
            });
        }

        function costruisciVoto() {
            if (!confirm('Costruire il voto aggregando tutte le evidenze registrate?')) return;

            window.location.href = `laboratorio_griglia.php?id_uda=${encodeURIComponent(udaId)}&id_gruppo=${encodeURIComponent(gruppoId)}&id_studente=${encodeURIComponent(idStudenteCV)}`;
        }
    </script>
</body>
</html>
