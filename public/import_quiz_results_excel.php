<?php
/**
 * Import risultati quiz da file Excel (Socrative / Kahoot)
 *
 * Flusso semplificato:
 * 1. Carica file Excel + seleziona test
 * 2. Anteprima voti con mappatura studenti ClasseViva
 * 3. Importa e salva in VOTI (pubblicazione da Gestione Voti)
 */

error_reporting(E_ALL);

session_start();

$config = require_once __DIR__ . '/../bootstrap.php';

use App\Core\Database\DatabaseFactory;
use App\Core\GradeImportStudentService;
use App\Core\TeachingGroupIntegrationRepository;
use App\Core\UdaGroupRepository;
use App\Core\UDAManager;
use App\Integration\GoogleClassroomAPI;
use PhpOffice\PhpSpreadsheet\IOFactory;

$dbAdapter = DatabaseFactory::createWithInitialization($config, true);
$udaManager = new UDAManager($config);
$userId = (string)($_SESSION['user_id'] ?? ($config['user_id'] ?? 'system'));

$platform = $_GET['platform'] ?? $_POST['platform'] ?? null;
$udaId = $_GET['uda_id'] ?? $_POST['uda_id'] ?? null;
$testId = $_GET['test_id'] ?? $_POST['test_id'] ?? null;
$step = $_GET['step'] ?? $_POST['step'] ?? 'upload';
$linkOrigine = app_url(
    'public/import_quiz_results_excel.php?platform=' . urlencode((string)$platform)
    . '&uda_id=' . urlencode((string)$udaId)
    . ($testId ? '&test_id=' . urlencode((string)$testId) : '')
);

if (!$platform || !in_array($platform, ['socrative', 'kahoot'], true)) {
    die('Piattaforma non valida (deve essere socrative o kahoot)');
}

if (!$udaId) {
    die('ID UDA mancante');
}

try {
    $udaComplete = $udaManager->getUDAComplete($udaId);
    if (!$udaComplete) {
        throw new Exception('UDA non trovata');
    }
    $uda = $udaComplete['uda'];
} catch (Exception $e) {
    die('Errore caricamento UDA: ' . $e->getMessage());
}

$errorMessage = null;
$successMessage = null;
$importData = $_SESSION['import_quiz_excel_data'] ?? null;

/**
 * Trova lo studente ClasseViva che meglio corrisponde al nome utente del quiz.
 *
 * @param string $username Nome utente dal quiz (Socrative/Kahoot)
 * @param array $studenti Lista studenti ClasseViva (id, nome, cognome)
 * @return array|null Studente migliore corrispondenza o null
 */
function findBestStudentMatch(string $username, array $studenti): ?array
{
    $normalized = mb_strtolower(trim($username));
    // Rimuovi caratteri non alfabetici
    $normalized = preg_replace('/[^[:alpha:]\s]/u', ' ', $normalized);
    $tokens = preg_split('/\s+/', $normalized, -1, PREG_SPLIT_NO_EMPTY);

    if (empty($tokens)) {
        return null;
    }

    $bestScore = 0;
    $bestStudent = null;

    foreach ($studenti as $studente) {
        $nome = mb_strtolower(trim($studente['nome'] ?? ''));
        $cognome = mb_strtolower(trim($studente['cognome'] ?? ''));
        $full1 = trim($cognome . ' ' . $nome);
        $full2 = trim($nome . ' ' . $cognome);

        if ($full1 === '' && $full2 === '') {
            continue;
        }

        $score = 0;

        foreach ($tokens as $token) {
            if (mb_strlen($token) < 3) {
                continue;
            }

            if (mb_stripos($full1, $token) !== false || mb_stripos($full2, $token) !== false) {
                $score += 3;
                continue;
            }

            // fallback fuzzy leggero
            $minDist = min(
                levenshtein($token, $nome),
                levenshtein($token, $cognome)
            );
            if ($minDist <= 2) {
                $score += 2;
            }
        }

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestStudent = $studente;
        }
    }

    // soglia minima per considerare valida la corrispondenza
    if ($bestScore >= 3) {
        return $bestStudent;
    }

    return null;
}

/**
 * Legge il file Excel a seconda della piattaforma e ritorna le righe normalizzate.
 *
 * Ogni riga:
 * [
 *   'username' => string,
 *   'punteggio_label' => string, // es. "8/10" o "N/A"
 *   'percentuale' => float,
 *   'voto' => float
 * ]
 */
function parseQuizExcel(string $filePath, string $platform): array
{
    $spreadsheet = IOFactory::load($filePath);

    $rows = [];

    if ($platform === 'socrative') {
        // Socrative: primo foglio, colonne A (nome), C (percentuale),
        // righe valide da 8 fino a riga con "Class Scoring" in colonna A
        $sheet = $spreadsheet->getActiveSheet();
        $row = 8;
        while (true) {
            $name = trim((string)$sheet->getCellByColumnAndRow(1, $row)->getValue());
            if ($name === '' || $name === null) {
                $row++;
                if ($row > 1000) {
                    break;
                }
                continue;
            }
            if (stripos($name, 'class scoring') !== false) {
                break;
            }

            $percentRaw = $sheet->getCellByColumnAndRow(3, $row)->getValue();
            $percent = is_numeric($percentRaw) ? floatval($percentRaw) : 0.0;

            $grade = round(($percent / 100.0) * 10.0, 1);
            if ($grade <= 0) {
                $grade = 1.0;
            }
            if ($grade > 10) {
                $grade = 10.0;
            }

            $rows[] = [
                'username' => $name,
                'punteggio_label' => sprintf('%.1f%%', $percent),
                'percentuale' => $percent,
                'voto' => $grade,
            ];

            $row++;
            if ($row > 2000) {
                break;
            }
        }
    } else {
        // Kahoot: foglio "Final Scores", col B nome, D corrette, E sbagliate
        $sheet = $spreadsheet->getSheetByName('Final Scores') ?: $spreadsheet->getActiveSheet();
        $row = 4;
        while (true) {
            $name = trim((string)$sheet->getCellByColumnAndRow(2, $row)->getValue());
            if ($name === '' || $name === null) {
                // prima riga vuota interrompe
                break;
            }

            $correctRaw = $sheet->getCellByColumnAndRow(4, $row)->getValue();
            $wrongRaw = $sheet->getCellByColumnAndRow(5, $row)->getValue();
            $correct = is_numeric($correctRaw) ? (int)$correctRaw : 0;
            $wrong = is_numeric($wrongRaw) ? (int)$wrongRaw : 0;
            $total = max(1, $correct + $wrong);

            $percent = ($correct / $total) * 100.0;

            $grade = round(($percent / 100.0) * 10.0, 1);
            if ($grade <= 0) {
                $grade = 1.0;
            }
            if ($grade > 10) {
                $grade = 10.0;
            }

            $rows[] = [
                'username' => $name,
                'punteggio_label' => $correct . '/' . $total,
                'percentuale' => $percent,
                'voto' => $grade,
            ];

            $row++;
            if ($row > 2000) {
                break;
            }
        }
    }

    return $rows;
}

// STEP: upload & parse
if ($step === 'upload' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['results_file'])) {
    try {
        $file = $_FILES['results_file'];
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Errore durante l\'upload del file.');
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            throw new Exception('Il file deve essere in formato .xlsx');
        }

        // Risoluzione provider-neutral: gruppo + roster Classroom per il match nomi.
        $groupId = null;
        $courseId = null;
        foreach ((new UdaGroupRepository($dbAdapter, $userId))->listForUda((string)$udaId) as $assignment) {
            $candidateGroup = (string)($assignment['id_gruppo'] ?? '');
            if ($candidateGroup === '') {
                continue;
            }
            $integration = (new TeachingGroupIntegrationRepository($dbAdapter, $userId))->findForGroupProvider($candidateGroup, 'google_classroom');
            if ($integration !== null) {
                $groupId = $candidateGroup;
                $courseId = trim((string)($integration['external_context_id'] ?? ''));
                break;
            }
        }

        // Parse file Excel
        $rows = parseQuizExcel($file['tmp_name'], $platform);
        if (empty($rows)) {
            throw new Exception('Nessuna riga valida trovata nel file.');
        }

        // Risolvi i nomi utente verso id_studente interni.
        $resolution = ['group_id' => null, 'matches' => [], 'unmatched' => []];
        if ($groupId !== null && $courseId !== '') {
            try {
                $courseStudents = (new GoogleClassroomAPI($config))->getCourseStudents($courseId);
                $roster = [];
                foreach ($courseStudents as $courseStudent) {
                    $roster[] = [
                        'external_user_id' => (string)($courseStudent['id'] ?? ''),
                        'display_name' => (string)($courseStudent['name'] ?? ''),
                        'email' => (string)($courseStudent['email'] ?? ''),
                    ];
                }
                $usernames = array_map(static fn(array $r): string => (string)$r['username'], $rows);
                $resolution = (new GradeImportStudentService($dbAdapter, $userId))->resolveByName('google_classroom', $courseId, $roster, $usernames);
            } catch (Exception $e) {
                $resolution = ['group_id' => null, 'matches' => [], 'unmatched' => []];
            }
        }

        // Costruisci struttura con matching studenti
        $matchedRows = [];
        foreach ($rows as $rowIdx => $row) {
            $match = $resolution['matches'][$row['username']] ?? null;
            $studentId = ($match['id_studente'] ?? null);
            $matchedRows[] = [
                'username' => $row['username'],
                'punteggio_label' => $row['punteggio_label'],
                'percentuale' => $row['percentuale'],
                'voto' => $row['voto'],
                'student_id' => $studentId,
                'student_name' => ($studentId !== null && $studentId !== '') ? 'ID: ' . $studentId : null,
                'matched' => ($studentId !== null && $studentId !== ''),
            ];
        }

        $_SESSION['import_quiz_excel_data'] = [
            'platform' => $platform,
            'uda_id' => $udaId,
            'test_id' => $testId,
            'id_gruppo' => $groupId,
            'rows' => $matchedRows,
        ];

        $importData = $_SESSION['import_quiz_excel_data'];
        $step = 'review';

        $importData = $_SESSION['import_quiz_excel_data'];
        $step = 'review';

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        $step = 'upload';
    }
}

// STEP: import
if ($step === 'import' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        if (!isset($_SESSION['import_quiz_excel_data'])) {
            throw new Exception('Dati di import non trovati. Ricarica il file.');
        }

        $importData = $_SESSION['import_quiz_excel_data'];
        $platform = $importData['platform'];
        $udaId = $importData['uda_id'];
        $testId = $importData['test_id'] ?? null;
        $testDateRaw = null;
        if (!empty($testId)) {
            $testRow = $dbAdapter->findOne('TEST', 'id_test', $testId);
            if (!empty($testRow)) {
                $testDateRaw = $testRow['data_somministrazione'] ?? ($testRow['data_creazione'] ?? null);
            }
        }
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
        $groupId = $importData['id_gruppo'] ?? null;

        $studentIds = $_POST['student_id'] ?? [];
        $importFlags = $_POST['import_row'] ?? [];
        $usernames = $_POST['username'] ?? [];
        $percentuali = $_POST['percentuale'] ?? [];
        $voti = $_POST['voto'] ?? [];
        $punteggi = $_POST['punteggio_label'] ?? [];

        if (empty($usernames)) {
            throw new Exception('Nessun dato da importare.');
        }

        $tipoVoto = $_POST['tipo_voto'] ?? 'scritto';

        $imported = 0;
        $skipped = 0;
        $errors = [];

        foreach ($usernames as $idx => $username) {
            $doImport = isset($importFlags[$idx]) && $importFlags[$idx] === '1';
            $studentId = $studentIds[$idx] ?? '';
            $percent = isset($percentuali[$idx]) ? floatval($percentuali[$idx]) : 0.0;
            $selectedGrade = $voti[$idx] ?? '';
            $punteggioLabel = $punteggi[$idx] ?? '';

            if (!$doImport || !$studentId) {
                $skipped++;
                continue;
            }

            try {
                // Salva voto nel DB locale
                $votoId = 'VOTO_' . uniqid();

                $descrizione = sprintf(
                    'Importato da %s per UDA %s (Punteggio: %s, %.1f%%)',
                    ucfirst($platform),
                    $uda->titolo ?? '',
                    $punteggioLabel,
                    $percent
                );

                $noteVoto = $descrizione . "\n<{$votoId}>";

                $pubblicatoFlag = 0;

                // Determina voto numerico / giudizio da salvare
                $voto = null;
                $giudizio = '';
                if ($selectedGrade === 'i') {
                    $giudizio = 'Impreparato';
                } elseif ($selectedGrade === 'a') {
                    $giudizio = 'Assente';
                } elseif ($selectedGrade !== '') {
                    $voto = floatval($selectedGrade);
                }

                $votoData = [
                    'id_voto' => $votoId,
                    'id_uda' => $udaId,
                    'id_gruppo' => $groupId,
                    'id_studente' => $studentId,
                    'tipo_voto' => $tipoVoto,
                    'voto' => $voto,
                    'giudizio' => $giudizio,
                    'descrizione' => $descrizione,
                    'data_valutazione' => $testDate,
                    'data_creazione' => date('Y-m-d H:i:s'),
                    'pubblicato' => $pubblicatoFlag,
                    'provider_pubblicazione' => null,
                    'external_publication_id' => null,
                    'num_evidenze_positive' => 0,
                    'num_evidenze_negative' => 0,
                    'num_evidenze_totali' => 0,
                    'id_utente' => $userId,
                    'link_origine' => $linkOrigine,
                ];

                $dbAdapter->insertRow('VOTI', $votoData);
                $imported++;

            } catch (Exception $e) {
                $errors[] = "Utente {$username}: " . $e->getMessage();
                $skipped++;
            }
        }

        unset($_SESSION['import_quiz_excel_data']);
        $importData = null;

        // Marca UDA se la colonna esiste e il test se presente
        try {
            $udaColumns = $dbAdapter->getColumns('UDA_ANAGRAFICA');
        } catch (Exception $e) {
            $udaColumns = [];
        }
        if (in_array('risultati_importati', $udaColumns, true)) {
            $dbAdapter->updateRow('UDA_ANAGRAFICA', 'id_uda', $udaId, ['risultati_importati' => 'SI']);
        }
        if (!empty($testId)) {
            $dbAdapter->updateRow('TEST', 'id_test', $testId, ['risultati_importati' => 'SI']);
        }

        $successMessage = "Importati {$imported} voti, {$skipped} righe saltate.";
        if (!empty($errors)) {
            $errorMessage = implode(' | ', array_slice($errors, 0, 5));
        }

        $step = 'complete';

    } catch (Exception $e) {
        $errorMessage = $e->getMessage();
        $step = 'review';
    }
}

// Calcola step corrente per la barra di avanzamento
$currentStep = 1;
if ($step === 'review') {
    $currentStep = 2;
} elseif ($step === 'import' || $step === 'complete') {
    $currentStep = 3;
}
$progressWidth = $currentStep === 1 ? '33%' : ($currentStep === 2 ? '66%' : '100%');
?>
<!DOCTYPE html>
<html lang="it">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Importa Risultati <?= htmlspecialchars(ucfirst($platform)) ?> - <?= htmlspecialchars($uda->titolo) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
</head>
<body>
    <?php
    $pageTitle = 'Importa Risultati ' . ucfirst((string)$platform);
    $pageSubtitle = 'UDA: ' . ($uda->titolo ?? '');
    $headerActions = '<a class="nav-link" href="uda_view.php?id=' . urlencode($udaId) . '">'
        . '<i class="bi bi-arrow-left"></i> Torna alla UDA</a>';
    include __DIR__ . '/partials/app_header.php';
    ?>

    <div class="container mt-4">
        <?php if ($errorMessage): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="bi bi-exclamation-triangle"></i> <?= htmlspecialchars($errorMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <?php if ($successMessage): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="bi bi-check-circle"></i> <?= htmlspecialchars($successMessage) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Progress Steps -->
        <div class="mb-4">
            <div class="d-flex justify-content-between">
                <div class="text-center <?= $currentStep === 1 ? 'text-primary fw-bold' : 'text-muted' ?>">
                    <i class="bi bi-1-circle<?= $currentStep === 1 ? '-fill' : '' ?> fs-3"></i>
                    <div>Seleziona File</div>
                </div>
                <div class="text-center <?= $currentStep === 2 ? 'text-primary fw-bold' : 'text-muted' ?>">
                    <i class="bi bi-2-circle<?= $currentStep === 2 ? '-fill' : '' ?> fs-3"></i>
                    <div>Anteprima Voti</div>
                </div>
                <div class="text-center <?= $currentStep === 3 ? 'text-primary fw-bold' : 'text-muted' ?>">
                    <i class="bi bi-3-circle<?= $currentStep === 3 ? '-fill' : '' ?> fs-3"></i>
                    <div>Importa voti</div>
                </div>
            </div>
            <div class="progress mt-2" style="height: 5px;">
                <div class="progress-bar" style="width: <?= $progressWidth ?>"></div>
            </div>
        </div>

        <?php if ($step === 'upload'): ?>
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0">
                        <i class="bi bi-upload"></i> Passo 1 - Carica File
                    </h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="platform" value="<?= htmlspecialchars($platform) ?>">
                        <input type="hidden" name="uda_id" value="<?= htmlspecialchars($udaId) ?>">
                        <input type="hidden" name="step" value="upload">

                        <div class="mb-3">
                            <label class="form-label">File risultati <?= htmlspecialchars(ucfirst($platform)) ?> (.xlsx)</label>
                            <input type="file" name="results_file" class="form-control" accept=".xlsx" required>
                            <small class="text-muted">
                                Usa il template: <?= $platform === 'socrative' ? 'templates/socrativeRisposte.xlsx' : 'templates/KahootRisposte.xlsx' ?> come riferimento per il formato.
                            </small>
                        </div>

                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-arrow-right-circle"></i> Leggi risultati
                        </button>
                    </form>
                </div>
            </div>
        <?php elseif ($step === 'review' && $importData): ?>
            <?php
            $rows = $importData['rows'] ?? [];
            

            $totaleRisposte = count($rows);
            $sufficienti = count(array_filter($rows, fn($r) => ($r['voto'] ?? 0) >= 6));
            $insufficienti = $totaleRisposte - $sufficienti;
            $mediaVoti = $totaleRisposte > 0 ? array_sum(array_column($rows, 'voto')) / $totaleRisposte : 0;

            $utentiNonMappati = array_filter($rows, fn($r) => empty($r['student_id']));

            // Dettagli "test" derivati dall'UDA e dal contesto
            $testNome = 'Import ' . ucfirst($platform) . ' UDA';
            $testTipo = 'N/D';
            $testNumDomande = 'N/D';
            $testSogliaLabel = '60%';

            // Gruppo didattico risolto in fase di upload.
            $groupId = $importData['id_gruppo'] ?? null;
            ?>
            <div class="row">
                <div class="col-md-9">
                    <div class="card mb-4">
                        <div class="card-header bg-success text-white">
                            <h5 class="mb-0">
                                <i class="bi bi-eye"></i> Passo 2 - Anteprima Voti e Mappatura Studenti
                            </h5>
                        </div>
                        <div class="card-body">
                            <?php if (empty($rows)): ?>
                                <div class="alert alert-warning">
                                    <i class="bi bi-exclamation-triangle"></i> Nessuna riga valida nel file.
                                </div>
                            <?php else: ?>
                                <?php if (!empty($utentiNonMappati)): ?>
                                    <div class="alert alert-warning">
                                        <h6 class="mb-1"><i class="bi bi-exclamation-triangle"></i> Utenti non mappati (<?= count($utentiNonMappati) ?>)</h6>
                                        <p class="mb-1 small">Alcuni nomi utente non sono stati associati automaticamente a studenti ClasseViva. Puoi correggere la mappatura dalla tendina nella colonna "Studente ClasseViva".</p>
                                        <ul class="mb-1 small">
                                            <?php foreach (array_slice($utentiNonMappati, 0, 5) as $row): ?>
                                                <li><?= htmlspecialchars($row['username']) ?></li>
                                            <?php endforeach; ?>
                                            <?php if (count($utentiNonMappati) > 5): ?>
                                                <li><em>... e altri <?= count($utentiNonMappati) - 5 ?></em></li>
                                            <?php endif; ?>
                                        </ul>
                                    </div>
                                <?php endif; ?>

                                <form method="POST">
                                    <input type="hidden" name="platform" value="<?= htmlspecialchars($platform) ?>">
                                    <input type="hidden" name="uda_id" value="<?= htmlspecialchars($udaId) ?>">
                                    <input type="hidden" name="step" value="import">

                                    <div class="card mb-3 bg-light">
                                        <div class="card-body">
                                            <h6 class="mb-3">
                                                <i class="bi bi-gear"></i> Configurazione Importazione
                                                <?php if ($groupId): ?>
                                                    <span class="badge bg-info ms-2">Gruppo didattico rilevato</span>
                                                <?php endif; ?>
                                            </h6>
                                            <div class="row g-3">
                                                <div class="col-md-4">
                                                    <label class="form-label">Tipo voto</label>
                                                    <select name="tipo_voto" class="form-select">
                                                        <option value="scritto" selected>Scritto</option>
                                                        <option value="orale">Orale</option>
                                                        <option value="pratico">Pratico</option>
                                                    </select>
                                                    <small class="form-text text-muted">
                                                        Tipo di valutazione che verrà registrata (predefinito: Scritto).
                                                    </small>
                                                </div>
                                            </div>
                                        </div>
                                    </div>

                                    <div class="table-responsive">
                                        <table class="table table-bordered table-sm align-middle">
                                            <thead class="table-light">
                                                <tr>
                                                    <th>Importa</th>
                                                    <th>Nome utente</th>
                                                    <th>Studente associato</th>
                                                    <th>Punteggio</th>
                                                    <th>%</th>
                                                    <th>Voto</th>
                                                    <th>Stato</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($rows as $idx => $row): ?>
                                                    <tr>
                                                        <td class="text-center">
                                                            <input type="checkbox"
                                                                   name="import_row[<?= $idx ?>]"
                                                                   value="1"
                                                                   class="response-checkbox"
                                                                   <?= $row['matched'] ? 'checked' : '' ?>>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($row['username']) ?>
                                                            <input type="hidden" name="username[<?= $idx ?>]" value="<?= htmlspecialchars($row['username']) ?>">
                                                        </td>
                                                        <td>
                                                            <?php if ($row['student_id']): ?>
                                                                <input type="hidden" name="student_id[<?= $idx ?>]" value="<?= htmlspecialchars($row['student_id']) ?>">
                                                                <span class="badge bg-success"><i class="bi bi-check-circle"></i> ID: <?= htmlspecialchars($row['student_id']) ?></span>
                                                            <?php else: ?>
                                                                <span class="badge bg-warning text-dark">Non mappato</span>
                                                            <?php endif; ?>
                                                        </td>
                                                        <td>
                                                            <?= htmlspecialchars($row['punteggio_label']) ?>
                                                            <input type="hidden" name="punteggio_label[<?= $idx ?>]" value="<?= htmlspecialchars($row['punteggio_label']) ?>">
                                                        </td>
                                                        <td>
                                                            <?= number_format($row['percentuale'], 1) ?>
                                                            <input type="hidden" name="percentuale[<?= $idx ?>]" value="<?= htmlspecialchars($row['percentuale']) ?>">
                                                        </td>
                                                        <td>
                                                            <select name="voto[<?= $idx ?>]"
                                                                    class="form-select form-select-sm voto-select"
                                                                    style="width: 85px; font-weight: bold;">
                                                                <?php
                                                                // Genera voti da 1.0 a 10.0 con step 0.5 + a (assente) e i (impreparato)
                                                                $votoCalcolato = $row['voto'] ?? 0;
                                                                // Arrotonda al mezzo punto più vicino
                                                                $votoArrotondato = round($votoCalcolato * 2) / 2;

                                                                // Opzioni speciali
                                                                echo '<option value="a">a (Assente)</option>';
                                                                echo '<option value="i">i (Impreparato)</option>';

                                                                // Voti numerici da 1.0 a 10.0
                                                                for ($v = 1.0; $v <= 10.0 + 0.001; $v += 0.5) {
                                                                    $selected = (abs($v - $votoArrotondato) < 0.01) ? 'selected' : '';
                                                                    $vLabel = number_format($v, 1);
                                                                    echo "<option value=\"{$v}\" {$selected}>{$vLabel}</option>";
                                                                }
                                                                ?>
                                                            </select>
                                                            <small class="text-muted d-block mt-1">
                                                                Calc: <?= number_format($row['voto'], 1) ?>
                                                            </small>
                                                        </td>
                                                        <td>
                                                            <?php if ($row['matched']): ?>
                                                                <span class="student-status badge bg-success">Mappato</span>
                                                            <?php else: ?>
                                                                <span class="student-status badge bg-warning text-dark">Da mappare</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>

                                    <div class="d-flex justify-content-between align-items-center mt-4">
                                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                                            <i class="bi bi-arrow-left"></i> Indietro
                                        </a>
                                        <div class="d-flex gap-2 align-items-center">
                                            <span class="text-muted small" id="publishInfo">
                                                Seleziona classe, materia e tipo voto
                                            </span>
                                            <button type="submit" class="btn btn-success btn-lg" id="publishBtn" disabled>
                                                <i class="bi bi-cloud-upload"></i>
                                                Importa <span id="publishCount">0</span> Voti
                                            </button>
                                        </div>
                                    </div>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="col-md-3">
                    <!-- Statistiche -->
                    <div class="stats-box mb-3" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; border-radius: 10px; padding: 16px;">
                        <h6 class="mb-3"><i class="bi bi-bar-chart"></i> Statistiche Voti</h6>
                        <div class="mb-2 d-flex justify-content-between">
                            <span>Risposte:</span>
                            <strong><?= $totaleRisposte ?></strong>
                        </div>
                        <div class="mb-2 d-flex justify-content-between">
                            <span>Sufficienti:</span>
                            <strong><?= $sufficienti ?> (<?= $totaleRisposte > 0 ? round($sufficienti / max(1, $totaleRisposte) * 100, 1) : 0 ?>%)</strong>
                        </div>
                        <hr class="bg-light">
                        <div class="d-flex justify-content-between">
                            <span>Media:</span>
                            <strong class="fs-4"><?= round($mediaVoti, 2) ?></strong>
                        </div>
                    </div>

                    <!-- Info Test -->
                    <div class="card">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="bi bi-info-circle"></i> Dettagli Test</h6>
                        </div>
                        <div class="card-body small">
                            <p class="mb-1"><strong>Nome:</strong><br><?= htmlspecialchars($testNome) ?></p>
                            <p class="mb-1"><strong>Tipo:</strong><br><?= htmlspecialchars($testTipo) ?></p>
                            <p class="mb-1"><strong>Domande:</strong><br><?= htmlspecialchars($testNumDomande) ?></p>
                            <p class="mb-0"><strong>Soglia:</strong><br><?= htmlspecialchars($testSogliaLabel) ?></p>
                        </div>
                    </div>
                </div>
            </div>
        <?php elseif ($step === 'complete'): ?>
            <div class="card">
                <div class="card-body text-center">
                    <i class="bi bi-check-circle text-success" style="font-size: 48px;"></i>
                    <h3 class="mt-3">Importazione completata!</h3>
                    <p>I voti sono stati importati e registrati nel sistema.</p>
                    <div class="d-flex justify-content-center gap-2">
                        <a href="uda_grades.php?id=<?= urlencode($udaId) ?>" class="btn btn-success">
                            <i class="bi bi-eye"></i> Vai ai voti UDA
                        </a>
                        <a href="uda_view.php?id=<?= urlencode($udaId) ?>" class="btn btn-secondary">
                            <i class="bi bi-arrow-left"></i> Torna alla UDA
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

</body>
</html>
