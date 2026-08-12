<?php
/**
 * Import Voti da Kahoot CSV
 * Importa i voti degli studenti da un file CSV esportato da Kahoot
 */

error_reporting(E_ALL);

try {
    $config = require_once __DIR__ . '/../bootstrap.php';
} catch (Exception $e) {
    die("Errore caricamento configurazione: " . $e->getMessage());
}

use App\Core\Database\DatabaseFactory;
use App\Integration\ClasseVivaAPI;

try {
    $dbAdapter = DatabaseFactory::createWithInitialization($config, true);
} catch (Exception $e) {
    die("Errore inizializzazione database: " . $e->getMessage());
}

/**
 * Calcola il voto più vicino nella scala ClasseViva
 */
function getNearestClasseVivaGrade($rawScore, $maxPoints) {
    if ($rawScore === null) {
        return 'skip';
    }
    $voto = ($rawScore / $maxPoints) * 10;
    $voto = round($voto * 2) / 2;
    $voto = max(1, min(10, $voto));
    return number_format($voto, 1);
}

/**
 * Genera l'array di voti possibili per ClasseViva
 */
function getClasseVivaGrades() {
    $grades = [];
    for ($i = 1; $i <= 10; $i += 0.5) {
        $grades[] = number_format($i, 1);
    }
    $grades[] = 'i'; // impreparato
    $grades[] = 'a'; // assente
    $grades[] = 'skip'; // non importare
    return $grades;
}

// Parametri
$testId = $_GET['test_id'] ?? null;

if (!$testId) {
    die("ID test mancante");
}

// Carica test
$test = $dbAdapter->findOne('TEST', 'id_test', $testId);
if (!$test) {
    die("Test non trovato");
}

// Verifica che sia un test Kahoot
if ($test['piattaforma'] !== 'kahoot') {
    die("Questo test non è un Kahoot");
}

$successMessage = null;
$errorMessage = null;
$csvData = [];
$matchedStudents = [];

// STEP 1: Upload e Parse CSV
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['kahoot_csv'])) {
    try {
        $file = $_FILES['kahoot_csv'];

        // Verifica errori upload
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("Errore durante l'upload del file");
        }

        // Verifica che sia un CSV
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception("Il file deve essere un CSV");
        }

        // Leggi CSV
        $handle = fopen($file['tmp_name'], 'r');
        if ($handle === false) {
            throw new Exception("Impossibile aprire il file CSV");
        }

        // Leggi header
        $header = fgetcsv($handle);
        if ($header === false) {
            throw new Exception("File CSV vuoto o non valido");
        }

        // Trova indici colonne (Kahoot CSV ha: Name, Total Score (Points), Correct Answers, Incorrect Answers, etc.)
        $nameIdx = array_search('Name', $header);
        $scoreIdx = array_search('Total Score (Points)', $header);

        if ($nameIdx === false || $scoreIdx === false) {
            // Prova nomi alternativi
            $nameIdx = array_search('Player', $header);
            if ($nameIdx === false) {
                throw new Exception("Colonna 'Name' non trovata nel CSV. Header trovati: " . implode(', ', $header));
            }
        }

        // Carica associazione UDA -> Classe
        $udaClassi = $dbAdapter->findWhere('UDA_CLASSI', ['id_uda' => $test['id_uda']]);
        if (empty($udaClassi)) {
            throw new Exception("Nessuna classe associata all'UDA. Vai su map_classes.php per associare le classi.");
        }

        // Usa prima classe associata (potremmo migliorare permettendo selezione)
        $udaClasse = $udaClassi[0];
        $idClasse = $udaClasse['id_classe_cv'];
        $idMateria = $udaClasse['id_materia_cv'];

        // Carica studenti da ClasseViva
        $classeVivaAPI = new ClasseVivaAPI($config);
        $studentiClasseViva = $classeVivaAPI->getStudentiClasse($idClasse);

        if (empty($studentiClasseViva)) {
            throw new Exception("Nessuno studente trovato in ClasseViva per la classe ID: $idClasse");
        }

        // Parse righe CSV
        while (($row = fgetcsv($handle)) !== false) {
            if (count($row) <= max($nameIdx, $scoreIdx)) {
                continue; // Riga non valida
            }

            $nomeKahoot = trim($row[$nameIdx]);
            $punteggio = floatval($row[$scoreIdx]);

            if (empty($nomeKahoot)) {
                continue;
            }

            // Match con studenti ClasseViva
            $matched = null;
            $nomeKahootLower = strtolower($nomeKahoot);

            foreach ($studentiClasseViva as $studente) {
                $nomeCV = strtolower(trim($studente['cognome'] . ' ' . $studente['nome']));
                $nomeCVInv = strtolower(trim($studente['nome'] . ' ' . $studente['cognome']));

                // Match esatto o con levenshtein
                if ($nomeKahootLower === $nomeCV || $nomeKahootLower === $nomeCVInv ||
                    levenshtein($nomeKahootLower, $nomeCV) <= 3 ||
                    levenshtein($nomeKahootLower, $nomeCVInv) <= 3) {
                    $matched = $studente;
                    break;
                }
            }

            $matchedStudents[] = [
                'kahoot_name' => $nomeKahoot,
                'kahoot_score' => $punteggio,
                'local_student' => $matched,
                'match_confidence' => $matched ? 'high' : 'none'
            ];
        }

        fclose($handle);

        if (empty($matchedStudents)) {
            throw new Exception("Nessun risultato trovato nel file CSV");
        }

        // Store data in session per step successivo
        $_SESSION['import_kahoot_data'] = [
            'test_id' => $testId,
            'id_classe' => $idClasse,
            'id_materia' => $idMateria,
            'matched_students' => $matchedStudents,
            'classe_name' => $udaClasse['nome_classe'] ?? ''
        ];

        header("Location: ?test_id=" . urlencode($testId) . "&step=review");
        exit;

    } catch (Exception $e) {
        $errorMessage = "Errore caricamento CSV: " . $e->getMessage();
    }
}

// STEP 2: Review e conferma
$step = $_GET['step'] ?? 'upload';

if ($step === 'review') {
    if (!isset($_SESSION['import_kahoot_data'])) {
        header("Location: ?test_id=" . urlencode($testId));
        exit;
    }

    $importData = $_SESSION['import_kahoot_data'];
    $matchedStudents = $importData['matched_students'];
}

// STEP 3: Importa voti
if ($step === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Leggi dati POST dal form
        $studentIds = $_POST['student_id'] ?? [];
        $importGrades = $_POST['import_grade'] ?? [];
        $tipoVoto = $_POST['tipo_voto'] ?? 'orale';

        if (empty($studentIds) || empty($importGrades)) {
            throw new Exception("Nessun dato di import ricevuto");
        }

        if (!isset($_SESSION['import_kahoot_data'])) {
            throw new Exception("Dati di import non trovati. Ricarica il file CSV.");
        }

        $importData = $_SESSION['import_kahoot_data'];
        $idClasse = $importData['id_classe'];
        $idMateria = $importData['id_materia'];

        $imported = 0;
        $skipped = 0;
        $errors = [];
        $linkOrigine = app_url('public/import_kahoot_grades.php?test_id=' . urlencode((string)$testId));
        $testDateRaw = $test['data_somministrazione'] ?? ($test['data_creazione'] ?? null);
        $testDate = null;
        if (!empty($testDateRaw)) {
            $ts = strtotime((string)$testDateRaw);
            if ($ts) {
                $testDate = date('Y-m-d', $ts);
            }
        }
        if (!$testDate) {
            $testDate = date('Y-m-d');
        }

        // Itera sui voti selezionati
        foreach ($studentIds as $idx => $studentId) {
            $selectedGrade = $importGrades[$idx] ?? 'skip';

            // Salta se "-non importare voto-"
            if ($selectedGrade === 'skip') {
                $skipped++;
                continue;
            }

            try {
                // Assicurati che lo studente esista nella tabella STUDENTI
                $studenteEsistente = $dbAdapter->findOne('STUDENTI', 'id_studente_cv', $studentId);

                if (!$studenteEsistente) {
                    // Carica info studente da ClasseViva
                    $classeVivaAPI = new ClasseVivaAPI($config);
                    $studentiClasseViva = $classeVivaAPI->getStudentiClasse($idClasse);

                    $studenteInfo = null;
                    foreach ($studentiClasseViva as $s) {
                        if ($s['id'] === $studentId) {
                            $studenteInfo = $s;
                            break;
                        }
                    }

                    if ($studenteInfo) {
                        // Aggiungi lo studente
                        $studenteData = [
                            'id_studente_cv' => $studentId,
                            'id_classe_cv' => $idClasse,
                            'nome_classe' => $importData['classe_name'],
                            'data_sincronizzazione' => date('Y-m-d H:i:s'),
                            'attivo' => 1
                        ];

                        try {
                            $dbAdapter->insertRow('STUDENTI', $studenteData);
                        } catch (Exception $e) {
                            // Studente già esistente, continua
                        }
                    }
                }

                // Prepara voto/giudizio
                $voto = null;
                $giudizio = '';

                if ($selectedGrade === 'i') {
                    $giudizio = 'Impreparato';
                } elseif ($selectedGrade === 'a') {
                    $giudizio = 'Assente';
                } else {
                    // Voto numerico
                    $voto = floatval($selectedGrade);
                }

                // Salva voto
                $votoData = [
                    'id_voto' => 'VOTO_' . uniqid(),
                    'id_uda' => $test['id_uda'],
                    'id_studente_cv' => $studentId,
                    'id_classe_cv' => $idClasse,
                    'id_materia_cv' => $idMateria,
                    'tipo_voto' => $tipoVoto,
                    'voto' => $voto,
                    'giudizio' => $giudizio,
                    'descrizione' => 'Importato da Kahoot: ' . $test['nome'],
                    'data_valutazione' => $testDate,
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'pubblicato' => 0,
                    'id_annotazione_cv' => null,
                    'num_evidenze_positive' => 0,
                    'num_evidenze_negative' => 0,
                    'num_evidenze_totali' => 0,
                    'link_origine' => $linkOrigine
                ];

                $dbAdapter->insertRow('VOTI', $votoData);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "Errore per studente ID " . $studentId . ": " . $e->getMessage();
                $skipped++;
            }
        }

        // Marca test come importato
        $dbAdapter->updateRow('TEST', 'id_test', $testId, ['risultati_importati' => 'SI']);

        // Clear session data
        unset($_SESSION['import_kahoot_data']);

        $successMessage = "Importati $imported voti. $skipped saltati.";
        if (!empty($errors)) {
            $errorMessage = "Alcuni errori: " . implode(', ', array_slice($errors, 0, 5));
        }

        $step = 'complete';

    } catch (Exception $e) {
        $errorMessage = "Errore importazione: " . $e->getMessage();
        $step = 'review';
    }
}

?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Voti Kahoot - <?= htmlspecialchars($test['nome']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
    <style>
        .kahoot-color { background-color: #46178F; color: white; }
    </style>
</head>
<body>
    <?php
    $pageTitle = '<i class="bi bi-download"></i> Import Voti Kahoot';
    $pageSubtitle = 'Test: ' . ($test['nome'] ?? '');
    $headerActions = '<a class="nav-link" href="uda_tests.php?id=' . urlencode($test['id_uda']) . '">'
        . '<i class="bi bi-arrow-left"></i> Torna ai Test</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <!-- Messaggi -->
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

        <!-- STEP UPLOAD -->
        <?php if ($step === 'upload'): ?>
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title">Passo 1: Carica File CSV</h5>
                    <p>Esporta i risultati da Kahoot come file CSV e caricalo qui sotto.</p>

                    <div class="alert alert-info">
                        <strong><i class="bi bi-info-circle"></i> Come esportare da Kahoot:</strong>
                        <ol class="mb-0 mt-2">
                            <li>Vai su <a href="https://create.kahoot.it" target="_blank">create.kahoot.it</a></li>
                            <li>Seleziona il tuo Kahoot</li>
                            <li>Vai su "Reports" o "Risultati"</li>
                            <li>Clicca "Export" e scegli formato "Excel (.xlsx)" o "CSV"</li>
                            <li>Scarica il file e caricalo qui sotto</li>
                        </ol>
                    </div>

                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">File CSV Kahoot</label>
                            <input type="file" name="kahoot_csv" class="form-control" accept=".csv" required>
                            <small class="text-muted">Il file deve contenere le colonne "Name" e "Total Score (Points)"</small>
                        </div>
                        <button type="submit" class="btn kahoot-color">
                            <i class="bi bi-upload"></i> Carica e Analizza
                        </button>
                        <a href="uda_tests.php?id=<?= urlencode($test['id_uda']) ?>" class="btn btn-secondary">
                            Annulla
                        </a>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- STEP REVIEW -->
        <?php if ($step === 'review'): ?>
            <div class="card mb-4">
                <div class="card-body">
                    <h5 class="card-title">Passo 2: Revisiona e Correggi Voti</h5>
                    <p>Controlla che gli studenti siano stati matchati correttamente e seleziona il voto da importare per ciascuno.</p>

                    <form method="POST" action="?test_id=<?= urlencode($testId) ?>&step=import">
                        <!-- Selezione Tipo Voto -->
                        <div class="mb-3">
                            <label class="form-label"><strong>Tipo di Valutazione:</strong></label>
                            <select name="tipo_voto" class="form-select" style="max-width: 300px;" required>
                                <option value="orale" selected>Orale</option>
                                <option value="scritto">Scritto</option>
                                <option value="pratico">Pratico</option>
                            </select>
                            <small class="text-muted">Questo tipo verrà applicato a tutti i voti importati</small>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-striped">
                                <thead>
                                    <tr>
                                        <th>Nome Kahoot</th>
                                        <th>Studente Locale</th>
                                        <th>Punteggio Kahoot</th>
                                        <th>Voto Importato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php
                                    $maxPoints = floatval($test['punteggio_max'] ?? 100);
                                    if ($maxPoints == 0) $maxPoints = 100;
                                    $availableGrades = getClasseVivaGrades();

                                    foreach ($matchedStudents as $idx => $match):
                                        $suggestedGrade = getNearestClasseVivaGrade($match['kahoot_score'], $maxPoints);
                                    ?>
                                        <tr>
                                            <td><?= htmlspecialchars($match['kahoot_name']) ?></td>
                                            <td>
                                                <?php if ($match['local_student']): ?>
                                                    <span class="badge bg-success">✓</span>
                                                    <?= htmlspecialchars($match['local_student']['nome'] . ' ' . $match['local_student']['cognome']) ?>
                                                    <input type="hidden" name="student_id[<?= $idx ?>]" value="<?= htmlspecialchars($match['local_student']['id']) ?>">
                                                <?php else: ?>
                                                    <span class="badge bg-warning">!</span>
                                                    <em class="text-muted">Non trovato</em>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <strong><?= htmlspecialchars($match['kahoot_score']) ?></strong> / <?= htmlspecialchars($maxPoints) ?>
                                            </td>
                                            <td>
                                                <?php if ($match['local_student']): ?>
                                                    <select name="import_grade[<?= $idx ?>]" class="form-select form-select-sm" style="width: 150px;">
                                                        <?php foreach ($availableGrades as $grade): ?>
                                                            <?php
                                                            $label = $grade;
                                                            if ($grade === 'i') $label = 'i (impreparato)';
                                                            elseif ($grade === 'a') $label = 'a (assente)';
                                                            elseif ($grade === 'skip') $label = '-non importare voto-';

                                                            $selected = ($grade === $suggestedGrade) ? 'selected' : '';
                                                            ?>
                                                            <option value="<?= htmlspecialchars($grade) ?>" <?= $selected ?>>
                                                                <?= htmlspecialchars($label) ?>
                                                            </option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                <?php else: ?>
                                                    <em class="text-muted">-</em>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="alert alert-info mt-3">
                            <i class="bi bi-info-circle"></i>
                            <strong>Nota:</strong> I voti sono stati approssimati automaticamente alla scala ClasseViva (mezzi punti da 1.0 a 10.0).
                            Puoi modificare manualmente ciascun voto prima dell'importazione. Gli studenti con "-non importare voto-" verranno saltati.
                        </div>

                        <button type="submit" class="btn btn-success">
                            <i class="bi bi-check-circle"></i> Importa Voti Selezionati
                        </button>
                        <a href="?test_id=<?= urlencode($testId) ?>" class="btn btn-secondary">
                            Ricarica File
                        </a>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- STEP COMPLETE -->
        <?php if ($step === 'complete'): ?>
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle text-success" style="font-size: 48px;"></i>
                    <h3 class="mt-3">Importazione Completata!</h3>
                    <p>I voti sono stati importati con successo nel sistema.</p>
                    <div class="d-flex gap-2 justify-content-center">
                        <a href="uda_grades.php?id=<?= urlencode($test['id_uda']) ?>" class="btn btn-success">
                            <i class="bi bi-eye"></i> Visualizza Voti
                        </a>
                        <a href="uda_tests.php?id=<?= urlencode($test['id_uda']) ?>" class="btn kahoot-color">
                            <i class="bi bi-arrow-left"></i> Torna ai Test
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
