<?php

define('REQUIRES_CLASSEVIVA', true);
/**
 * Pubblicazione Valutazioni su ClasseViva
 *
 * Permette di:
 * - Visualizzare valutazioni non ancora pubblicate (rubrica + laboratorio)
 * - Pubblicare annotazioni su ClasseViva
 * - Tracciare stato pubblicazione
 */

error_reporting(E_ALL);

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\StudentiManager;
use App\Integration\ClasseVivaAPI;

$db = DatabaseFactory::createWithInitialization($config, true);
$dbAdapter = $db;

$cvConfig = $config['classeviva'] ?? [];
$cvTokenPayload = is_array($cvConfig['token'] ?? null) ? $cvConfig['token'] : [];
$cvTokenValid = $cvConfig['token_valid'] ?? false;
$cvTokenError = $cvConfig['token_error'] ?? null;
$cvEnabled = !empty($cvConfig['enabled']);
$cvHasToken = !empty($cvTokenPayload['token']);
$cvReady = $cvEnabled && $cvHasToken && $cvTokenValid;
$cvTokenNotice = null;

if (!$cvReady) {
    if (!$cvEnabled) {
        $cvTokenNotice = 'Integrazione ClasseViva disabilitata. Attivala per pubblicare le valutazioni.';
    } elseif (!$cvHasToken) {
        $cvTokenNotice = 'Token ClasseViva mancante. Rigeneralo dalla pagina Integrazioni.';
    } else {
        $cvTokenNotice = $cvTokenError
            ? "Token ClasseViva non valido: {$cvTokenError}"
            : 'Token ClasseViva non valido o scaduto. Rigeneralo dalla pagina Integrazioni.';
    }
}

$cvAPI = $cvReady ? new ClasseVivaAPI($config) : null;
$studentiManager = $cvReady && $cvAPI ? new StudentiManager($db, $cvAPI, $config) : null;

$message = null;
$error = null;
$valutazioniDaPubblicare = [];
$stats = [
    'rubrica' => 0,
    'laboratorio' => 0,
    'totale' => 0
];

// Filtra per UDA o classe se specificato
$filtroUDA = $_GET['id_uda'] ?? null;
$filtroClasse = $_GET['id_classe'] ?? null;

// Azione
$action = $_POST['action'] ?? null;

try {
    if ($action === 'pubblica_selezionate') {
        if (!$cvReady || !$cvAPI) {
            $msg = $cvTokenNotice ?? 'Token ClasseViva non disponibile per la pubblicazione.';
            throw new Exception($msg);
        }

        // Pubblica valutazioni selezionate
        $idsRubrica = $_POST['rubrica_ids'] ?? [];
        $idsLaboratorio = $_POST['lab_ids'] ?? [];

        $pubblicateOk = 0;
        $errori = [];

        // Pubblica rubriche orali
        foreach ($idsRubrica as $idVoto) {
            try {
                $valutazione = $dbAdapter->findOne('VOTI', ['id_voto' => $idVoto]);

                if (!$valutazione || ($valutazione['pubblicato_registro'] ?? 0) == 1) {
                    continue; // Già pubblicata o non trovata
                }

                // Recupera dati studente
                $idStudenteCV = $valutazione['id_studente_cv'] ?? $valutazione['id_studente'] ?? '';
                $studente = $studentiManager->getStudente($idStudenteCV);

                if (!$studente) {
                    $errori[] = "Studente non trovato: $idStudenteCV";
                    continue;
                }

                // Costruisci testo annotazione
                $votoFinale = $valutazione['voto_numerico'] ?? 0;
                $testoAnnotazione = "Valutazione orale UDA: voto {$votoFinale}/10";

                // Aggiungi giudizio se presente
                $giudizio = $valutazione['giudizio_esteso'] ?? '';
                if ($giudizio) {
                    $testoAnnotazione .= " - " . substr($giudizio, 0, 100);
                }

                // Determina tipo annotazione (positiva/negativa/neutra)
                $tipoAnnotazione = 'neutral';
                if ($votoFinale >= 7) {
                    $tipoAnnotazione = 'positive';
                } elseif ($votoFinale < 6) {
                    $tipoAnnotazione = 'negative';
                }

                // Pubblica su ClasseViva
                $annotationData = [
                    'student_id' => $idStudenteCV,
                    'class_id' => $valutazione['id_classe'] ?? '',
                    'subject_id' => $config['classeviva']['subject_id'] ?? '',
                    'text' => $testoAnnotazione,
                    'date' => date('Y-m-d'),
                    'type' => $tipoAnnotazione,
                    'visible_to_student' => true
                ];

                $response = $cvAPI->publishAnnotation($annotationData);

                // Aggiorna database
                $dbAdapter->updateRow('VOTI',
                    ['id_voto' => $idVoto],
                    [
                        'pubblicato_registro' => 1,
                        'data_pubblicazione' => date('Y-m-d H:i:s'),
                        'id_annotazione_cv' => $response['id'] ?? null
                    ]
                );

                $pubblicateOk++;

            } catch (Exception $e) {
                $errori[] = "Errore pubblicazione voto $idVoto: " . $e->getMessage();
                error_log("Errore pubblicazione voto $idVoto: " . $e->getMessage());
            }
        }

        // Pubblica laboratorio
        foreach ($idsLaboratorio as $idLab) {
            try {
                $valutazione = $dbAdapter->findOne('VALUTAZIONI_LABORATORIO', ['id_valutazione' => $idLab]);

                if (!$valutazione || ($valutazione['pubblicato'] ?? 0) == 1) {
                    continue;
                }

                // Recupera dati studente
                $idStudenteCV = $valutazione['id_studente_cv'] ?? $valutazione['id_studente'] ?? '';
                $studente = $studentiManager->getStudente($idStudenteCV);

                if (!$studente) {
                    $errori[] = "Studente non trovato: $idStudenteCV";
                    continue;
                }

                // Costruisci testo annotazione basato su evidenze
                $evidenzePos = json_decode($valutazione['evidenze_positive'] ?? '[]', true);
                $evidenzeNeg = json_decode($valutazione['evidenze_negative'] ?? '[]', true);

                $numPos = count($evidenzePos);
                $numNeg = count($evidenzeNeg);

                $testoAnnotazione = "Laboratorio UDA: {$numPos} evidenze positive, {$numNeg} negative";

                // Aggiungi dettagli competenze
                if ($numPos > 0) {
                    $competenzaTop = $evidenzePos[0]['competenza'] ?? '';
                    $testoAnnotazione .= " - Punti di forza: " . substr($competenzaTop, 0, 50);
                }

                // Determina tipo
                $tipoAnnotazione = 'neutral';
                if ($numPos > $numNeg * 2) {
                    $tipoAnnotazione = 'positive';
                } elseif ($numNeg > $numPos * 2) {
                    $tipoAnnotazione = 'negative';
                }

                // Pubblica
                $annotationData = [
                    'student_id' => $idStudenteCV,
                    'class_id' => $valutazione['id_classe_cv'] ?? '',
                    'subject_id' => $config['classeviva']['subject_id'] ?? '',
                    'text' => $testoAnnotazione,
                    'date' => date('Y-m-d'),
                    'type' => $tipoAnnotazione,
                    'visible_to_student' => true
                ];

                $response = $cvAPI->publishAnnotation($annotationData);

                // Aggiorna database
                $dbAdapter->updateRow('VALUTAZIONI_LABORATORIO',
                    ['id_valutazione' => $idLab],
                    [
                        'pubblicato' => 1,
                        'data_pubblicazione' => date('Y-m-d H:i:s'),
                        'id_annotazione_cv' => $response['id'] ?? null
                    ]
                );

                $pubblicateOk++;

            } catch (Exception $e) {
                $errori[] = "Errore pubblicazione laboratorio $idLab: " . $e->getMessage();
                error_log("Errore pubblicazione laboratorio $idLab: " . $e->getMessage());
            }
        }

        // Messaggio finale
        if ($pubblicateOk > 0) {
            $message = "✓ $pubblicateOk valutazioni pubblicate con successo su ClasseViva!";
        }

        if (!empty($errori)) {
            $error = "Alcuni errori durante la pubblicazione:\n" . implode("\n", array_slice($errori, 0, 5));
            if (count($errori) > 5) {
                $error .= "\n... e altri " . (count($errori) - 5) . " errori.";
            }
        }
    }

    // Recupera valutazioni da pubblicare

    // Rubriche orali non pubblicate (dal foglio VOTI)
    $votiTutti = $dbAdapter->findAll('VOTI');
    $rubricheNonPubblicate = array_filter($votiTutti, function($r) use ($filtroUDA, $filtroClasse) {
        $tipoOrale = ($r['tipo_valutazione'] ?? '') === 'orale';
        $nonPubblicata = ($r['pubblicato_registro'] ?? 0) == 0;
        $matchUDA = !$filtroUDA || ($r['id_uda'] ?? '') === $filtroUDA;
        $matchClasse = !$filtroClasse || ($r['id_classe'] ?? '') === $filtroClasse;
        return $tipoOrale && $nonPubblicata && $matchUDA && $matchClasse;
    });

    // Laboratorio non pubblicato
    $labTutte = $dbAdapter->findAll('VALUTAZIONI_LABORATORIO');
    $labNonPubblicate = array_filter($labTutte, function($l) use ($filtroUDA, $filtroClasse) {
        $nonPubblicata = ($l['pubblicato'] ?? 0) == 0;
        $matchUDA = !$filtroUDA || ($l['id_uda'] ?? '') === $filtroUDA;
        $matchClasse = !$filtroClasse || ($l['id_classe_cv'] ?? '') === $filtroClasse;
        return $nonPubblicata && $matchUDA && $matchClasse;
    });

    // Combina e raggruppa per studente
    foreach ($rubricheNonPubblicate as $rubrica) {
        $idStudente = $rubrica['id_studente'] ?? '';

        if (!isset($valutazioniDaPubblicare[$idStudente])) {
            $valutazioniDaPubblicare[$idStudente] = [
                'studente' => null,
                'rubriche' => [],
                'laboratorio' => []
            ];
        }

        $valutazioniDaPubblicare[$idStudente]['rubriche'][] = $rubrica;
        $stats['rubrica']++;
    }

    foreach ($labNonPubblicate as $lab) {
        $idStudente = $lab['id_studente'] ?? '';

        if (!isset($valutazioniDaPubblicare[$idStudente])) {
            $valutazioniDaPubblicare[$idStudente] = [
                'studente' => null,
                'rubriche' => [],
                'laboratorio' => []
            ];
        }

        $valutazioniDaPubblicare[$idStudente]['laboratorio'][] = $lab;
        $stats['laboratorio']++;
    }

    $stats['totale'] = $stats['rubrica'] + $stats['laboratorio'];

    // Recupera nomi studenti
    foreach ($valutazioniDaPubblicare as $idStudente => &$gruppo) {
        if ($studentiManager) {
            try {
                $studente = $studentiManager->getStudente($idStudente);
                $gruppo['studente'] = $studente;
                continue;
            } catch (Exception $e) {
                // fallback in caso di errori (magari token scaduto a runtime)
            }
        }

        $gruppo['studente'] = [
            'id' => $idStudente,
            'nome_completo' => 'Studente ' . substr($idStudente, -4)
        ];
    }
    unset($gruppo);

    // Ordina per cognome studente
    uasort($valutazioniDaPubblicare, function($a, $b) {
        $nomeA = $a['studente']['cognome'] ?? $a['studente']['nome_completo'] ?? '';
        $nomeB = $b['studente']['cognome'] ?? $b['studente']['nome_completo'] ?? '';
        return strcmp($nomeA, $nomeB);
    });

} catch (Exception $e) {
    $error = "Errore: " . $e->getMessage();
    error_log("Errore pubblicazione_cv.php: " . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Pubblicazione Valutazioni ClasseViva</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .valutazione-card {
            border-left: 4px solid #0d6efd;
            transition: all 0.2s;
        }
        .valutazione-card:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .valutazione-rubrica {
            border-left-color: #0d6efd;
        }
        .valutazione-laboratorio {
            border-left-color: #198754;
        }
        .badge-tipo {
            font-size: 0.8rem;
        }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-send"></i> Pubblicazione su ClasseViva';
    $pageSubtitle = 'Pubblica le valutazioni come annotazioni sul registro ClasseViva';
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
                <i class="bi bi-exclamation-triangle"></i> <?= nl2br(htmlspecialchars($error)) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($cvTokenNotice): ?>
            <div class="alert alert-warning border-0 shadow-sm">
                <i class="bi bi-exclamation-triangle-fill"></i>
                <?= htmlspecialchars($cvTokenNotice) ?>
                <br>
                <small class="text-muted">
                    Aggiorna le credenziali nella <a href="user_integrations.php#classeviva-section">pagina Integrazioni</a>.
                </small>
                <?php include __DIR__ . '/partials/classeviva_quick_login.php'; ?>
            </div>
        <?php endif; ?>

        <!-- Info -->
        <div class="alert alert-info">
            <h5 class="alert-heading"><i class="bi bi-info-circle"></i> Come Funziona</h5>
            <p class="mb-0">
                Le valutazioni vengono pubblicate come <strong>annotazioni</strong> su ClasseViva.
                Le annotazioni sono visibili agli studenti e alle famiglie nel registro elettronico.
                Ogni valutazione pubblicata viene marcata per evitare duplicati.
            </p>
        </div>

        <div class="row">
            <!-- Statistiche -->
            <div class="col-md-3">
                <div class="card mb-4">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="bi bi-graph-up"></i> Statistiche</h5>
                    </div>
                    <div class="card-body">
                        <div class="text-center mb-3">
                            <h2 class="display-4"><?= $stats['totale'] ?></h2>
                            <p class="text-muted">Valutazioni da pubblicare</p>
                        </div>

                        <hr>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span><i class="bi bi-chat-left-text text-primary"></i> Rubriche:</span>
                                <strong><?= $stats['rubrica'] ?></strong>
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="d-flex justify-content-between">
                                <span><i class="bi bi-plus-slash-minus text-success"></i> Laboratorio:</span>
                                <strong><?= $stats['laboratorio'] ?></strong>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Info Annotazioni -->
                <div class="card">
                    <div class="card-header bg-info text-white">
                        <h6 class="mb-0"><i class="bi bi-lightbulb"></i> Tipi Annotazione</h6>
                    </div>
                    <div class="card-body small">
                        <p><span class="badge bg-success">Positiva</span> Voto ≥ 7 o evidenze + prevalenti</p>
                        <p><span class="badge bg-warning">Neutra</span> Voto 6 o evidenze bilanciate</p>
                        <p><span class="badge bg-danger mb-0">Negativa</span> Voto &lt; 6 o evidenze - prevalenti</p>
                    </div>
                </div>
            </div>

            <!-- Lista Valutazioni -->
            <div class="col-md-9">
                <?php if (empty($valutazioniDaPubblicare)): ?>
                    <div class="alert alert-success">
                        <i class="bi bi-check-circle"></i> <strong>Tutto pubblicato!</strong>
                        Non ci sono valutazioni da pubblicare in questo momento.
                    </div>
                <?php else: ?>
                    <form method="post">
                        <input type="hidden" name="action" value="pubblica_selezionate">

                        <div class="card mb-3">
                            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                                <h5 class="mb-0">
                                    <i class="bi bi-list-check"></i> Valutazioni da Pubblicare (<?= count($valutazioniDaPubblicare) ?> studenti)
                                </h5>
                                <div>
                                    <button type="button" class="btn btn-light btn-sm me-2" onclick="selezionaTutte()">
                                        <i class="bi bi-check-all"></i> Seleziona/Deseleziona Tutto
                                    </button>
                                    <button type="submit" class="btn btn-warning btn-sm">
                                        <i class="bi bi-cloud-upload"></i> Pubblica Selezionate
                                    </button>
                                </div>
                            </div>
                            <div class="card-body">
                                <?php foreach ($valutazioniDaPubblicare as $idStudente => $gruppo): ?>
                                    <?php
                                    $studente = $gruppo['studente'];
                                    $nomeCompleto = $studente['nome_completo'] ?? 'Studente sconosciuto';
                                    $rubriche = $gruppo['rubriche'];
                                    $laboratorio = $gruppo['laboratorio'];
                                    ?>

                                    <div class="card mb-3">
                                        <div class="card-header">
                                            <h6 class="mb-0">
                                                <i class="bi bi-person"></i> <?= htmlspecialchars($nomeCompleto) ?>
                                                <span class="badge bg-secondary ms-2">
                                                    <?= count($rubriche) + count($laboratorio) ?> valutazioni
                                                </span>
                                            </h6>
                                        </div>
                                        <div class="card-body">
                                            <div class="row">
                                                <!-- Rubriche -->
                                                <?php foreach ($rubriche as $rubrica): ?>
                                                    <div class="col-md-6 mb-2">
                                                        <div class="card valutazione-card valutazione-rubrica">
                                                            <div class="card-body py-2">
                                                                <div class="form-check">
                                                                    <input class="form-check-input valutazione-check"
                                                                           type="checkbox"
                                                                           name="rubrica_ids[]"
                                                                           value="<?= htmlspecialchars($rubrica['id_voto'] ?? '') ?>"
                                                                           id="rub_<?= htmlspecialchars($rubrica['id_voto'] ?? '') ?>">
                                                                    <label class="form-check-label" for="rub_<?= htmlspecialchars($rubrica['id_voto'] ?? '') ?>">
                                                                        <strong><i class="bi bi-chat-left-text"></i> Rubrica Orale</strong>
                                                                        <span class="badge bg-primary ms-2">
                                                                            <?= htmlspecialchars($rubrica['voto_numerico'] ?? 'N/D') ?>/10
                                                                        </span>
                                                                        <div class="small text-muted">
                                                                            Data: <?= htmlspecialchars($rubrica['data_valutazione'] ?? 'N/D') ?>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>

                                                <!-- Laboratorio -->
                                                <?php foreach ($laboratorio as $lab): ?>
                                                    <?php
                                                    $evidenzePos = json_decode($lab['evidenze_positive'] ?? '[]', true);
                                                    $evidenzeNeg = json_decode($lab['evidenze_negative'] ?? '[]', true);
                                                    ?>
                                                    <div class="col-md-6 mb-2">
                                                        <div class="card valutazione-card valutazione-laboratorio">
                                                            <div class="card-body py-2">
                                                                <div class="form-check">
                                                                    <input class="form-check-input valutazione-check"
                                                                           type="checkbox"
                                                                           name="lab_ids[]"
                                                                           value="<?= htmlspecialchars($lab['id_valutazione'] ?? '') ?>"
                                                                           id="lab_<?= htmlspecialchars($lab['id_valutazione'] ?? '') ?>">
                                                                    <label class="form-check-label" for="lab_<?= htmlspecialchars($lab['id_valutazione'] ?? '') ?>">
                                                                        <strong><i class="bi bi-plus-slash-minus"></i> Laboratorio</strong>
                                                                        <span class="badge bg-success ms-1">+<?= count($evidenzePos) ?></span>
                                                                        <span class="badge bg-danger">-<?= count($evidenzeNeg) ?></span>
                                                                        <div class="small text-muted">
                                                                            Data: <?= htmlspecialchars($lab['data_valutazione'] ?? 'N/D') ?>
                                                                        </div>
                                                                    </label>
                                                                </div>
                                                            </div>
                                                        </div>
                                                    </div>
                                                <?php endforeach; ?>
                                            </div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="bi bi-cloud-upload"></i> Pubblica Valutazioni Selezionate su ClasseViva
                            </button>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        let tuttoSelezionato = false;

        function selezionaTutte() {
            tuttoSelezionato = !tuttoSelezionato;
            document.querySelectorAll('.valutazione-check').forEach(cb => {
                cb.checked = tuttoSelezionato;
            });
        }
    </script>
</body>
</html>
