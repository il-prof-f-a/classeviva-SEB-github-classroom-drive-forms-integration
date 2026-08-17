<?php

/**
 * Valutazione Attività Laboratorio - Sistema Più/Meno
 *
 * Griglia interattiva per assegnare evidenze +/- per ogni studente e indicatore
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\ClasseVivaTokenGuard;
use App\Core\Database\DatabaseFactory;
use App\Core\UDAManager;
use App\Core\LaboratorioManager;
use App\Core\StudentiManager;
use App\Integration\ClasseVivaAPI;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$labManager = new LaboratorioManager($dbAdapter, $config);

// Inizializza ClasseViva API e StudentiManager
$classeVivaState = ClasseVivaTokenGuard::getTokenState($config);
$cvReady = $classeVivaState['ready'];
$cvNotice = $classeVivaState['notice'] ?? null;
$cvAPI = $cvReady ? new ClasseVivaAPI($config) : null;
$studentiManager = $cvAPI ? new StudentiManager($dbAdapter, $cvAPI, $config) : null;

$message = null;
$error = null;
$valutazioniSalvate = [];

// Gestione azioni
$action = $_POST['action'] ?? $_GET['action'] ?? null;
// Supporta sia id_uda (da uda_view.php) che uda_id (form interno)
$udaId = $_POST['uda_id'] ?? $_GET['id_uda'] ?? $_GET['uda_id'] ?? null;
$attivitaId = $_POST['attivita_id'] ?? $_GET['attivita_id'] ?? null;
$idClasseCV = $_GET['id_classe_cv'] ?? $_POST['id_classe_cv'] ?? null;

try {
    $udas = $udaManager->getAllUDAs();
    $indicatori = $labManager->getIndicatoriConCategorie();

    // Carica studenti reali da ClasseViva
    // Se UDA selezionata, recupera classe dall'UDA
    if ($udaId && !$idClasseCV) {
        $udaComplete = $udaManager->getUDAComplete($udaId);
        if ($udaComplete) {
            $classiAssegnate = $udaComplete['classi_assegnate'] ?? [];
            if (!empty($classiAssegnate)) {
                $idClasseCV = $classiAssegnate[0]['id_classe'] ?? null;
            }
        }
    }

    // Recupera studenti da ClasseViva o usa fallback
    $studenti = [];
    if ($idClasseCV && $studentiManager) {
        try {
            $studenti = $studentiManager->getStudentiClasse($idClasseCV);
        } catch (Exception $e) {
            error_log("Errore caricamento studenti: " . $e->getMessage());
            $error = "Impossibile caricare studenti da ClasseViva. Sincronizza le integrazioni.";
        }
    } elseif ($idClasseCV && !$studentiManager) {
        $error = $error ?? ($cvNotice ?? 'Token ClasseViva non disponibile. Attivalo nelle integrazioni.');
    }

    // Fallback: studenti mock se ClasseViva non disponibile o non configurato
    if (empty($studenti)) {
        $studenti = [
            ['id' => 'MOCK_001', 'nome' => 'Mario', 'cognome' => 'Rossi', 'nome_completo' => 'Rossi Mario', 'nome_classe' => '3C'],
            ['id' => 'MOCK_002', 'nome' => 'Anna', 'cognome' => 'Verdi', 'nome_completo' => 'Verdi Anna', 'nome_classe' => '3C'],
            ['id' => 'MOCK_003', 'nome' => 'Luca', 'cognome' => 'Bianchi', 'nome_completo' => 'Bianchi Luca', 'nome_classe' => '3C'],
            ['id' => 'MOCK_004', 'nome' => 'Sofia', 'cognome' => 'Neri', 'nome_completo' => 'Neri Sofia', 'nome_classe' => '3C'],
        ];
    }

    if ($action === 'salva_valutazioni' && $udaId && $attivitaId) {
        // Salva valutazioni per tutti gli studenti
        $valutazioniData = $_POST['valutazioni'] ?? [];
        $count = 0;

        foreach ($valutazioniData as $studenteId => $datiValutazione) {
            // Trova nome studente
            $nomeStudente = '';
            foreach ($studenti as $s) {
                if ($s['id'] === $studenteId) {
                    $nomeStudente = $s['nome_completo'] ?? ($s['cognome'] . ' ' . $s['nome']);
                    break;
                }
            }

            // Prepara evidenze
            $evidenze = [];
            foreach ($datiValutazione as $indicatoreId => $valore) {
                if (str_starts_with($indicatoreId, 'IND_') && $valore !== '') {
                    $commento = $_POST['commenti'][$studenteId][$indicatoreId] ?? '';
                    $evidenze[$indicatoreId] = [
                        'valore' => $valore,
                        'commento' => $commento
                    ];
                }
            }

            // Salta se non ci sono evidenze
            if (empty($evidenze)) {
                continue;
            }

            // Crea valutazione
            $valutazione = $labManager->creaValutazione(
                $udaId,
                $attivitaId,
                $studenteId,
                $nomeStudente,
                '3C', // TODO: prendere dalla classe dello studente
                $evidenze
            );

            // Salva
            $labManager->salvaValutazione($valutazione);
            $valutazioniSalvate[] = $valutazione;
            $count++;
        }

        $message = "$count valutazioni salvate con successo!";
    }

    // Carica valutazioni esistenti se siamo in modalità visualizzazione
    $valutazioniEsistenti = [];
    if ($udaId && $attivitaId && !$valutazioniSalvate) {
        $valutazioniEsistenti = $labManager->getValutazioniPerAttivita($udaId, $attivitaId);
    }

} catch (Exception $e) {
    $error = $e->getMessage();
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Valutazione Laboratorio</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .griglia-valutazioni {
            overflow-x: auto;
        }
        .griglia-valutazioni table {
            min-width: 800px;
        }
        .btn-evidenza {
            width: 40px;
            height: 40px;
            font-size: 1.2em;
            font-weight: bold;
            border: 2px solid #ccc;
            background: white;
            cursor: pointer;
        }
        .btn-evidenza.positivo {
            background: #28a745;
            color: white;
            border-color: #28a745;
        }
        .btn-evidenza.negativo {
            background: #dc3545;
            color: white;
            border-color: #dc3545;
        }
        .btn-evidenza:hover {
            transform: scale(1.1);
        }
        .voto-calcolato {
            font-weight: bold;
            font-size: 1.1em;
        }
        .voto-ottimo { color: #28a745; }
        .voto-buono { color: #17a2b8; }
        .voto-sufficiente { color: #ffc107; }
        .voto-insufficiente { color: #dc3545; }
        .indicatore-header {
            writing-mode: vertical-rl;
            text-orientation: mixed;
            min-width: 60px;
            font-size: 0.85em;
        }
        .commento-icon {
            cursor: pointer;
            margin-left: 5px;
        }
        .commento-icon.has-comment {
            color: #007bff;
        }
        .sticky-header {
            position: sticky;
            top: 0;
            background: white;
            z-index: 100;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-clipboard-check"></i> Valutazione Attivita Laboratorio';
    ob_start();
    ?>
    <a href="pubblicazione_cv.php<?php echo $udaId ? "?id_uda=" . urlencode($udaId) : ""; ?>" class="btn btn-outline-light btn-sm">
        <i class="bi bi-cloud-upload"></i> Pubblica Valutazioni
    </a>
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
            <div class="alert alert-success alert-dismissible fade show d-flex justify-content-between align-items-center">
                <div>
                    <i class="bi bi-check-circle"></i> <?php echo htmlspecialchars($message); ?>
                </div>
                <?php if (strpos($message, 'salvata con successo') !== false): ?>
                    <a href="pubblicazione_cv.php<?php echo $udaId ? '?id_uda=' . urlencode($udaId) : ''; ?>" class="btn btn-sm btn-primary">
                        <i class="bi bi-cloud-upload"></i> Pubblica su ClasseViva
                    </a>
                <?php endif; ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Selezione UDA e Attività -->
        <div class="card mb-4">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0"><i class="bi bi-funnel"></i> Seleziona UDA e Attività</h5>
            </div>
            <div class="card-body">
                <form method="get" action="laboratorio_valutazione.php" id="selezioneForm">
                    <div class="row">
                        <div class="col-md-6">
                            <label class="form-label">UDA</label>
                            <select name="uda_id" class="form-select" required onchange="document.getElementById('selezioneForm').submit()">
                                <option value="">-- Seleziona UDA --</option>
                                <?php foreach ($udas as $uda): ?>
                                    <option value="<?php echo htmlspecialchars($uda->id_uda); ?>"
                                            <?php echo $udaId === $uda->id_uda ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($uda->titolo); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Attività/Materiale</label>
                            <select name="attivita_id" class="form-select" required <?php echo !$udaId ? 'disabled' : ''; ?>>
                                <option value="">-- Seleziona Attività --</option>
                                <?php if ($udaId): ?>
                                    <?php
                                    // Recupera materiali e test della UDA
                                    $materiali = $dbAdapter->findAll('MATERIALI');
                                    $test = $dbAdapter->findAll('TEST');
                                    ?>
                                    <optgroup label="Materiali">
                                        <?php foreach ($materiali as $mat): ?>
                                            <?php if (($mat['id_uda'] ?? '') === $udaId): ?>
                                                <option value="<?php echo htmlspecialchars($mat['id_materiale']); ?>"
                                                        <?php echo $attivitaId === $mat['id_materiale'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($mat['titolo'] ?? 'Materiale'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                    <optgroup label="Test">
                                        <?php foreach ($test as $t): ?>
                                            <?php if (($t['id_uda'] ?? '') === $udaId): ?>
                                                <option value="<?php echo htmlspecialchars($t['id_test']); ?>"
                                                        <?php echo $attivitaId === $t['id_test'] ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($t['titolo'] ?? 'Test'); ?>
                                                </option>
                                            <?php endif; ?>
                                        <?php endforeach; ?>
                                    </optgroup>
                                <?php endif; ?>
                            </select>
                        </div>
                    </div>
                    <div class="mt-3">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-right"></i> Carica Griglia Valutazione
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <?php if ($udaId && $attivitaId): ?>
            <!-- Griglia Valutazioni -->
            <form method="post" action="laboratorio_valutazione.php" id="valutazioniForm">
                <input type="hidden" name="action" value="salva_valutazioni">
                <input type="hidden" name="uda_id" value="<?php echo htmlspecialchars($udaId); ?>">
                <input type="hidden" name="attivita_id" value="<?php echo htmlspecialchars($attivitaId); ?>">

                <div class="card mb-4">
                    <div class="card-header bg-success text-white sticky-header">
                        <h5 class="mb-0"><i class="bi bi-grid-3x3-gap"></i> Griglia Valutazioni</h5>
                        <small>Clicca sui pulsanti per assegnare + (positivo) o - (negativo). Clicca su 💬 per aggiungere commenti.</small>
                    </div>
                    <div class="card-body griglia-valutazioni p-0">
                        <table class="table table-bordered table-hover mb-0">
                            <thead class="table-light sticky-header">
                                <tr>
                                    <th style="min-width: 150px;">Studente</th>
                                    <?php foreach ($indicatori as $ind): ?>
                                        <th class="text-center" style="min-width: 100px;">
                                            <div class="indicatore-header" title="<?php echo htmlspecialchars($ind['descrizione']); ?>">
                                                <?php echo htmlspecialchars($ind['nome']); ?>
                                                <br><small>(peso: <?php echo $ind['peso']; ?>)</small>
                                            </div>
                                        </th>
                                    <?php endforeach; ?>
                                    <th class="text-center">Voto</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($studenti as $studente): ?>
                                    <tr data-studente-id="<?php echo htmlspecialchars($studente['id']); ?>">
                                        <td>
                                            <strong><?php echo htmlspecialchars($studente['nome_completo'] ?? ($studente['cognome'] . ' ' . $studente['nome'])); ?></strong>
                                            <br><small class="text-muted"><?php echo htmlspecialchars($studente['nome_classe'] ?? $studente['classe'] ?? ''); ?></small>
                                        </td>

                                        <?php foreach ($indicatori as $ind): ?>
                                            <?php
                                            $idCella = $studente['id'] . '_' . $ind['id_indicatore'];
                                            $valoreEsistente = '';
                                            $commentoEsistente = '';

                                            // Carica valore esistente se presente
                                            foreach ($valutazioniEsistenti as $val) {
                                                if ($val->id_studente === $studente['id']) {
                                                    $evidenza = $val->evidenze[$ind['id_indicatore']] ?? null;
                                                    if ($evidenza) {
                                                        $valoreEsistente = $evidenza['valore'] ?? '';
                                                        $commentoEsistente = $evidenza['commento'] ?? '';
                                                    }
                                                    break;
                                                }
                                            }
                                            ?>
                                            <td class="text-center">
                                                <div class="btn-group" role="group">
                                                    <button type="button"
                                                            class="btn-evidenza <?php echo $valoreEsistente === '+' ? 'positivo' : ''; ?>"
                                                            data-studente="<?php echo htmlspecialchars($studente['id']); ?>"
                                                            data-indicatore="<?php echo htmlspecialchars($ind['id_indicatore']); ?>"
                                                            data-valore="+"
                                                            onclick="toggleEvidenza(this)">
                                                        +
                                                    </button>
                                                    <button type="button"
                                                            class="btn-evidenza <?php echo $valoreEsistente === '-' ? 'negativo' : ''; ?>"
                                                            data-studente="<?php echo htmlspecialchars($studente['id']); ?>"
                                                            data-indicatore="<?php echo htmlspecialchars($ind['id_indicatore']); ?>"
                                                            data-valore="-"
                                                            onclick="toggleEvidenza(this)">
                                                        -
                                                    </button>
                                                </div>
                                                <i class="bi bi-chat-dots commento-icon <?php echo $commentoEsistente ? 'has-comment' : ''; ?>"
                                                   id="icon_<?php echo $idCella; ?>"
                                                   onclick="openCommento('<?php echo $idCella; ?>', '<?php echo htmlspecialchars($studente['id']); ?>', '<?php echo htmlspecialchars($ind['id_indicatore']); ?>')">
                                                </i>
                                                <input type="hidden"
                                                       name="valutazioni[<?php echo htmlspecialchars($studente['id']); ?>][<?php echo htmlspecialchars($ind['id_indicatore']); ?>]"
                                                       id="val_<?php echo $idCella; ?>"
                                                       value="<?php echo htmlspecialchars($valoreEsistente); ?>">
                                                <input type="hidden"
                                                       name="commenti[<?php echo htmlspecialchars($studente['id']); ?>][<?php echo htmlspecialchars($ind['id_indicatore']); ?>]"
                                                       id="comm_<?php echo $idCella; ?>"
                                                       value="<?php echo htmlspecialchars($commentoEsistente); ?>">
                                            </td>
                                        <?php endforeach; ?>

                                        <td class="text-center">
                                            <span class="voto-calcolato" id="voto_<?php echo htmlspecialchars($studente['id']); ?>">-</span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-success btn-lg">
                        <i class="bi bi-save"></i> Salva Tutte le Valutazioni
                    </button>
                </div>
            </form>

            <!-- Risultati se salvati -->
            <?php if (!empty($valutazioniSalvate)): ?>
                <div class="card mt-4 border-success">
                    <div class="card-header bg-success text-white">
                        <h5 class="mb-0"><i class="bi bi-check-circle"></i> Valutazioni Salvate</h5>
                    </div>
                    <div class="card-body">
                        <div class="table-responsive">
                            <table class="table">
                                <thead>
                                    <tr>
                                        <th>Studente</th>
                                        <th>Evidenze +</th>
                                        <th>Evidenze -</th>
                                        <th>Voto</th>
                                        <th>Giudizio</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($valutazioniSalvate as $val): ?>
                                        <?php $stats = $val->getStatistiche(); ?>
                                        <tr>
                                            <td><?php echo htmlspecialchars($val->nome_studente); ?></td>
                                            <td><span class="badge bg-success"><?php echo $stats['positivi']; ?></span></td>
                                            <td><span class="badge bg-danger"><?php echo $stats['negativi']; ?></span></td>
                                            <td><strong><?php echo number_format($val->voto_calcolato, 2); ?>/10</strong></td>
                                            <td><?php echo htmlspecialchars($val->generaGiudizio()); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>

    <!-- Modal Commento -->
    <div class="modal fade" id="commentoModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title">Commento</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <textarea class="form-control" id="commentoText" rows="4" placeholder="Inserisci un commento..."></textarea>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Annulla</button>
                    <button type="button" class="btn btn-primary" onclick="salvaCommento()">Salva</button>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let currentCommentoId = null;
        const commentoModal = new bootstrap.Modal(document.getElementById('commentoModal'));

        // Pesi indicatori per calcolo voto
        const pesiIndicatori = <?php echo json_encode(array_column($indicatori, 'peso', 'id_indicatore')); ?>;

        function toggleEvidenza(btn) {
            const studente = btn.getAttribute('data-studente');
            const indicatore = btn.getAttribute('data-indicatore');
            const valore = btn.getAttribute('data-valore');
            const idCella = studente + '_' + indicatore;
            const input = document.getElementById('val_' + idCella);

            // Se già selezionato, deseleziona
            if (btn.classList.contains(valore === '+' ? 'positivo' : 'negativo')) {
                btn.classList.remove(valore === '+' ? 'positivo' : 'negativo');
                input.value = '';
            } else {
                // Deseleziona l'altro pulsante
                const btns = btn.parentElement.querySelectorAll('.btn-evidenza');
                btns.forEach(b => b.classList.remove('positivo', 'negativo'));

                // Seleziona questo
                btn.classList.add(valore === '+' ? 'positivo' : 'negativo');
                input.value = valore;
            }

            // Ricalcola voto studente
            calcolaVotoStudente(studente);
        }

        function openCommento(idCella, studente, indicatore) {
            currentCommentoId = idCella;
            const commentoInput = document.getElementById('comm_' + idCella);
            document.getElementById('commentoText').value = commentoInput.value;
            commentoModal.show();
        }

        function salvaCommento() {
            const testo = document.getElementById('commentoText').value;
            const commentoInput = document.getElementById('comm_' + currentCommentoId);
            commentoInput.value = testo;

            // Aggiorna icona
            const icon = document.getElementById('icon_' + currentCommentoId);
            if (testo) {
                icon.classList.add('has-comment');
            } else {
                icon.classList.remove('has-comment');
            }

            commentoModal.hide();
        }

        function calcolaVotoStudente(studenteId) {
            let punteggioPositivo = 0;
            let pesoTotale = 0;

            // Trova tutte le evidenze per questo studente
            const inputs = document.querySelectorAll(`input[name^="valutazioni[${studenteId}]"]`);

            inputs.forEach(input => {
                const valore = input.value;
                if (valore === '' ) return;

                // Estrai id indicatore dal name
                const match = input.name.match(/\[([^\]]+)\]$/);
                if (!match) return;

                const indicatoreId = match[1];
                const peso = pesiIndicatori[indicatoreId] || 1;

                pesoTotale += peso;
                if (valore === '+') {
                    punteggioPositivo += peso;
                }
            });

            // Calcola voto
            let voto = 0;
            if (pesoTotale > 0) {
                const percentuale = punteggioPositivo / pesoTotale;
                voto = percentuale * 10;
                voto = Math.round(voto * 4) / 4; // Arrotonda a 0.25
            }

            // Aggiorna display
            const votoSpan = document.getElementById('voto_' + studenteId);
            if (votoSpan) {
                votoSpan.textContent = voto > 0 ? voto.toFixed(2) : '-';

                // Colore in base al voto
                votoSpan.className = 'voto-calcolato';
                if (voto >= 9) votoSpan.classList.add('voto-ottimo');
                else if (voto >= 7) votoSpan.classList.add('voto-buono');
                else if (voto >= 6) votoSpan.classList.add('voto-sufficiente');
                else if (voto > 0) votoSpan.classList.add('voto-insufficiente');
            }
        }

        // Calcola voti iniziali se ci sono valutazioni caricate
        window.addEventListener('DOMContentLoaded', function() {
            <?php foreach ($studenti as $studente): ?>
                calcolaVotoStudente('<?php echo $studente['id']; ?>');
            <?php endforeach; ?>
        });
    </script>
</body>
</html>
