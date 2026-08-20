<?php
/**
 * Step 2: Anteprima Voti Calcolati con Mappatura Studenti
 */

$formResponses = $_SESSION['form_responses'] ?? [];
$resolution = $_SESSION['resolution'] ?? ['group_id' => null, 'matches' => [], 'unmatched' => []];
$groupId = $_SESSION['group_id'] ?? ($resolution['group_id'] ?? null);
$cbmEnabled = $_SESSION['cbm_enabled'] ?? false;
$cbmParams = $_SESSION['cbm_params'] ?? null;
$cbmAvailable = $cbmEnabled && !empty($formResponses);
if ($cbmAvailable) {
    $cbmAvailable = false;
    foreach ($formResponses as $r) {
        if (!empty($r['cbm_details']) || ($r['cbm_voto_finale'] ?? null) !== null || ($r['cbm_voto_numerico'] ?? null) !== null) {
            $cbmAvailable = true;
            break;
        }
    }
}
$cbmMappingWarning = $_SESSION['cbm_mapping_warning'] ?? null;
$cbmEnabled = $_SESSION['cbm_enabled'] ?? false;
$cbmParams = $_SESSION['cbm_params'] ?? null;
$cbmTotals = $_SESSION['cbm_level_totals'] ?? null;
// mappa per email -> level counts
$cbmByStudent = [];
foreach ($formResponses as $r) {
    $cbmByStudent[strtolower($r['email'] ?? '')] = $r['cbm_level_counts'] ?? [];
}

// Serie per analisi (grafico/tabella) se CBM disponibile
$studentSeries = [];
$questionSeries = [];
$questionWeights = [];
$classicMaxGraph = 0;
if ($cbmAvailable) {
    // Aggrega studenti
    foreach ($formResponses as $r) {
        // ricava pesi max per domanda
        foreach (($r['cbm_details'] ?? []) as $qId => $detail) {
            $w = floatval($detail['weight'] ?? 1);
            if (!isset($questionWeights[$qId]) || $w > $questionWeights[$qId]) {
                $questionWeights[$qId] = $w;
            }
        }
    }
    $classicMaxGraph = max(1e-9, array_sum($questionWeights));

    foreach ($formResponses as $r) {
        $accPct = ($classicMaxGraph > 0) ? ($r['total_score'] / $classicMaxGraph) * 100 : 0;
        $cbmPct = ($classicMaxGraph > 0) ? ($r['cbm_total_score'] / $classicMaxGraph) * 100 : 0;
        $studentSeries[] = [
            'id' => $r['email'],
            'accuracy_pct' => round($accPct, 2),
            'cbm_pct' => round($cbmPct, 2),
            'cbm_voto' => $r['cbm_voto_finale'] ?? $r['cbm_voto_numerico'] ?? null,
        ];
        // Aggrega domande da cbm_details
        foreach (($r['cbm_details'] ?? []) as $qId => $detail) {
            $label = $detail['question_label'] ?: $qId;
            $w = floatval($detail['weight'] ?? 1);
            if (!isset($questionSeries[$qId])) {
                $questionSeries[$qId] = [
                    'id' => $qId,
                    'label' => $label,
                    'count' => 0,
                    'correct' => 0,
                    'cbm_total' => 0,
                    'classic_total' => 0,
                    'avg_conf' => 0,
                    'weight_total' => 0,
                ];
            }
            $questionSeries[$qId]['count']++;
            $questionSeries[$qId]['weight_total'] += $w;
            $questionSeries[$qId]['cbm_total'] += floatval($detail['cbm_score']) * $w;
            $questionSeries[$qId]['classic_total'] += floatval($detail['classic_score']) * $w;
            $questionSeries[$qId]['avg_conf'] += intval($detail['conf_level']);
            if (floatval($detail['classic_score']) > 0) {
                $questionSeries[$qId]['correct']++;
            }
        }
    }
    // Normalizza question stats
    foreach ($questionSeries as $qId => $q) {
        $count = max(1, $q['count']);
        $weightTotal = max(1e-9, $q['weight_total'] ?: $count);
        $questionSeries[$qId]['accuracy'] = round(($q['classic_total'] / $weightTotal) * 100, 2);
        $questionSeries[$qId]['cbm_avg'] = round($q['cbm_total'] / $weightTotal, 2);
        $questionSeries[$qId]['avg_conf'] = round($q['avg_conf'] / $count, 2);
    }
}

// Recupera classi e materie per importazione
// Risoluzione provider-neutral degli studenti (email -> id_studente interno).
$resolutionMatches = $resolution['matches'] ?? [];
$studentiNonMappati = [];
$studentiMappati = 0;

foreach ($formResponses as &$response) {
    $studentEmail = strtolower(trim((string)($response['email'] ?? '')));
    $match = $resolutionMatches[$studentEmail] ?? null;
    // Mappato = studente nel gruppo didattico E con identità ClasseViva.
    if ($match !== null && ($match['id_studente'] ?? '') !== '' && ($match['cv_id'] ?? '') !== '') {
        $response['mapped'] = true;
        $response['internal_student_id'] = (string)$match['id_studente'];
        $response['cv_id'] = (string)$match['cv_id'];
        $response['student_name'] = (string)($match['display_name'] ?? '');
        $studentiMappati++;
    } else {
        $response['mapped'] = false;
        $response['internal_student_id'] = null;
        $response['cv_id'] = null;
        $studentiNonMappati[] = (string)($response['email'] ?? '');
    }
}
unset($response);
// Statistiche
$totaleRisposte = count($formResponses);
$sufficienti = count(array_filter($formResponses, fn($r) => $r['voto_numerico'] >= 6));
$insufficienti = $totaleRisposte - $sufficienti;
$mediaVoti = $totaleRisposte > 0 ? array_sum(array_column($formResponses, 'voto_numerico')) / $totaleRisposte : 0;
?>

<div class="row">
    <div class="col-md-9">
        <div class="card">
            <div class="card-header bg-success text-white">
                <h5 class="mb-0"><i class="bi bi-eye"></i> Anteprima Voti e Mappatura Studenti</h5>
            </div>
            <div class="card-body">
                <?php if (empty($formResponses)): ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle"></i>
                        Nessuna risposta trovata.
                    </div>
                <?php else: ?>
                    <!-- Alert Studenti Non Mappati -->
                    <?php if (!empty($studentiNonMappati)): ?>
                        <div class="alert alert-warning">
                            <h6><i class="bi bi-exclamation-triangle"></i> Studenti Non Mappati (<?= count($studentiNonMappati) ?>)</h6>
                            <p class="mb-2">I seguenti studenti non sono stati trovati nella mappatura Google Classroom ↔ ClasseViva:</p>
                            <ul class="mb-2 small">
                                <?php foreach (array_slice($studentiNonMappati, 0, 5) as $email): ?>
                                    <li><?= htmlspecialchars($email) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($studentiNonMappati) > 5): ?>
                                    <li><em>... e altri <?= count($studentiNonMappati) - 5 ?></em></li>
                                <?php endif; ?>
                            </ul>
                            <div class="d-flex gap-2">
                                <a href="classroom_mapping.php" class="btn btn-sm btn-warning" target="_blank">
                                    <i class="bi bi-link-45deg"></i> Vai alla Mappatura Classroom
                                </a>
                                <span class="text-muted small align-self-center">
                                    I voti per studenti non mappati NON verranno importati
                                </span>
                            </div>
                        </div>
                    <?php endif; ?>

                    <p class="text-muted mb-3">
                        <strong>Test:</strong> <?= htmlspecialchars($test['nome']) ?><br>
                        <strong>Risposte ricevute:</strong> <?= $totaleRisposte ?>
                    </p>
                    <?php if ($cbmMappingWarning): ?>
                        <div class="alert alert-warning d-flex align-items-center gap-2">
                            <i class="bi bi-exclamation-triangle"></i>
                            <div><?= htmlspecialchars($cbmMappingWarning) ?></div>
                        </div>
                    <?php endif; ?>

                    <?php if ($cbmEnabled): ?>
                        <div class="alert alert-info d-flex align-items-center gap-2">
                            <i class="bi bi-activity"></i>
                            <div>
                                <strong>CBM attivo:</strong> i parametri di scoring verranno applicati nel calcolo CBM durante il salvataggio.<br>
                                <?php if (!empty($cbmParams)): ?>
                                    <small class="text-muted">C1: <?= $cbmParams['c1_correct'] ?? 1 ?>/<?= $cbmParams['c1_wrong'] ?? 0 ?>,
                                        C2: <?= $cbmParams['c2_correct'] ?? 2 ?>/<?= $cbmParams['c2_wrong'] ?? -2 ?>,
                                        C3: <?= $cbmParams['c3_correct'] ?? 3 ?>/<?= $cbmParams['c3_wrong'] ?? -6 ?></small>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="?step=publish_grades" id="publishForm">
                        <input type="hidden" name="test_id" value="<?= htmlspecialchars($testId) ?>">
                        <input type="hidden" name="uda_id" value="<?= htmlspecialchars($udaId) ?>">

                        <!-- Configurazione Importazione -->
                        <div class="card mb-3 bg-light">
                            <div class="card-body">
                                <h6 class="mb-3">
                                    <i class="bi bi-gear"></i> Configurazione Importazione
                                    <?php if ($groupId): ?>
                                        <span class="badge bg-info">Gruppo didattico rilevato</span>
                                    <?php endif; ?>
                                </h6>
                                <div class="row g-3">
                                    <div class="col-md-4">
                                        <label class="form-label">Tipo Voto *</label>
                                        <select name="grade_type" class="form-select" required id="gradeTypeSelect">
                                            <option value="S" selected>✍️ Scritto</option>
                                            <option value="O">🗣️ Orale</option>
                                            <option value="P">🛠️ Pratico</option>
                                        </select>
                                        <small class="form-text text-muted">Dove verrà registrato il voto</small>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="mb-3 d-flex justify-content-between align-items-center">
                            <button type="button" class="btn btn-sm btn-outline-primary" onclick="toggleAllMapped()">
                                <i class="bi bi-check-all"></i> Seleziona Solo Mappati
                            </button>
                            <span class="badge bg-secondary">
                                <span id="selectedCount">0</span> studenti selezionati
                            </span>
                        </div>

                        <div class="table-responsive">
                            <table class="table table-sm table-hover">
                                <thead class="table-light">
                                    <tr>
                                        <th width="40">
                                            <input type="checkbox" id="checkAll" onclick="toggleAllResponses()" title="Seleziona tutti">
                                        </th>
                                        <th>Email Google</th>
                                        <th>Studente associato</th>
                                        <th class="text-center">Punteggio</th>
                                        <th class="text-center">%</th>
                                        <th class="text-center">Voto</th>
                                        <?php if ($cbmAvailable): ?>
                                        <th class="text-center">CBM %</th>
                                        <th class="text-center">Voto CBM</th>
                                        <?php endif; ?>
                                        <th class="text-center">Stato</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($formResponses as $response): ?>
                                        <?php
                                        $votoBase = $response['cbm_voto_finale'] !== null ? $response['cbm_voto_finale'] : ($response['cbm_voto_numerico'] !== null ? $response['cbm_voto_numerico'] : $response['voto_numerico']);
                                        $rowClass = $votoBase >= 6 ? 'grade-sufficient' : 'grade-insufficient';
                                        $isSufficiente = ($response['cbm_voto_finale'] !== null)
                                            ? ($response['cbm_voto_finale'] >= 6)
                                            : ($response['sufficiente'] ?? false);
                                        $isMapped = $response['mapped'] ?? false;
                                        $feedbackLabel = $response['cbm_feedback_label'] ?? '';
                                        $feedbackText = $response['cbm_feedback_text'] ?? '';
                                        $feedbackAction1 = $response['cbm_feedback_action1'] ?? '';
                                        $feedbackAction2 = $response['cbm_feedback_action2'] ?? '';
                                        $hasFeedback = $cbmAvailable && ($feedbackLabel !== '' || $feedbackText !== '' || $feedbackAction1 !== '' || $feedbackAction2 !== '');
                                        ?>
                                        <tr class="grade-preview <?= $rowClass ?>" data-mapped="<?= $isMapped ? '1' : '0' ?>"
                                            data-email="<?= htmlspecialchars(strtolower($response['email'])) ?>">
                                            <td>
                                                <input type="checkbox"
                                                       name="selected_responses[]"
                                                       value="<?= htmlspecialchars($response['response_id']) ?>"
                                                       class="response-checkbox"
                                                       <?= $isMapped ? 'checked' : 'disabled' ?>
                                                       onchange="updateSelectedCount()">
                                            </td>
                                            <td>
                                                <small class="text-muted"><?= htmlspecialchars($response['email']) ?></small>
                                                <?php if (!empty($response['timestamp'])): ?>
                                                    <br><small class="text-muted">Inviato: <?= date('d/m/Y H:i', strtotime($response['timestamp'])) ?></small>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <?php if ($isMapped): ?>
                                                    <input type="hidden" name="student_mapping[<?= htmlspecialchars($response['response_id']) ?>]" value="<?= htmlspecialchars($response['internal_student_id'] ?? '') ?>">
                                                    <span class="badge bg-success"><i class="bi bi-check-circle"></i> <?= htmlspecialchars($response['student_name'] ?? '') !== '' ? htmlspecialchars($response['student_name'] ?? '') : 'Mappato' ?></span>
                                                    <small class="text-muted d-block">CV: <?= htmlspecialchars($response['cv_id'] ?? '') ?></small>
                                                <?php else: ?>
                                                    <span class="badge bg-warning text-dark">Non mappato</span>
                                                <?php endif; ?>
                                            </td>
                                            <td class="text-center">
                                                <small><?= $response['total_score'] ?> / <?= $response['max_score'] ?></small>
                                            </td>
                                            <td class="text-center">
                                                <?= round($response['percentuale'], 1) ?>%
                                            </td>
                                            <td class="text-center">
                                                <select name="voto_finale[<?= htmlspecialchars($response['response_id']) ?>]"
                                                        class="form-select form-select-sm voto-select"
                                                        style="width: 85px; font-weight: bold;">
                                                    <?php
                                                    // Genera voti da 1.0 a 10.0 con step 0.5 + a (assente) e i (impreparato)
                                                    $votoCalcolato = $response['cbm_voto_finale'] !== null ? $response['cbm_voto_finale'] : ($response['cbm_voto_numerico'] !== null ? $response['cbm_voto_numerico'] : $response['voto_numerico']);
                                                    // Arrotonda al mezzo punto più vicino
                                                    $votoArrotondato = round($votoCalcolato * 2) / 2;

                                                    // Opzioni speciali
                                                    echo "<option value=\"a\">a (Assente)</option>";
                                                    echo "<option value=\"i\">i (Impreparato)</option>";

                                                    // Voti numerici da 1.0 a 10.0
                                                    for ($v = 1.0; $v <= 10.0; $v += 0.5) {
                                                        $selected = (abs($v - $votoArrotondato) < 0.01) ? 'selected' : '';
                                                        echo "<option value=\"$v\" $selected>" . number_format($v, 1) . "</option>";
                                                    }
                                                    ?>
                                                </select>
                                                <small class="text-muted d-block mt-1">
                                                    Calc: <?= $response['cbm_voto_finale'] !== null ? htmlspecialchars(number_format($response['cbm_voto_finale'], 2)) . ' (CBM finale)' : ($response['cbm_voto_numerico'] !== null ? htmlspecialchars(number_format($response['cbm_voto_numerico'], 2)) . ' (CBM)' : htmlspecialchars(number_format($response['voto_numerico'], 2))) ?>
                                                    <?php if ($response['cbm_voto_numerico'] !== null): ?>
                                                        <br><span class="text-muted">Classico: <?= htmlspecialchars($response['voto_numerico']) ?></span>
                                                    <?php endif; ?>
                                                </small>
                                            </td>
                                            <?php if ($cbmAvailable): ?>
                                            <td class="text-center">
                                                <?= $response['cbm_percentuale'] !== null ? round($response['cbm_percentuale'], 1) . '%' : '-' ?>
                                            </td>
                                            <td class="text-center">
                                                <span class="badge bg-secondary">
                                                    <?= $response['cbm_voto_finale'] !== null ? htmlspecialchars(number_format($response['cbm_voto_finale'], 2)) : ($response['cbm_voto_numerico'] !== null ? htmlspecialchars(number_format($response['cbm_voto_numerico'], 2)) : '-') ?>
                                                </span>
                                                <?php if ($hasFeedback): ?>
                                                    <div class="cbm-feedback small text-muted mt-1 text-start">
                                                        <?php if ($feedbackLabel !== ''): ?>
                                                            <div class="cbm-feedback-label fw-semibold text-dark"><?= htmlspecialchars($feedbackLabel) ?></div>
                                                        <?php endif; ?>
                                                        <div class="cbm-feedback-details">
                                                            <?php if ($feedbackText !== ''): ?>
                                                                <div><?= nl2br(htmlspecialchars($feedbackText)) ?></div>
                                                            <?php endif; ?>
                                                            <?php if ($feedbackAction1 !== '' || $feedbackAction2 !== ''): ?>
                                                                <div class="mt-1">
                                                                    <strong>Azioni:</strong>
                                                                    <?= htmlspecialchars(trim($feedbackAction1)) ?>
                                                                    <?php if ($feedbackAction1 !== '' && $feedbackAction2 !== ''): ?>
                                                                        |
                                                                    <?php endif; ?>
                                                                    <?= htmlspecialchars(trim($feedbackAction2)) ?>
                                                                </div>
                                                            <?php endif; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>
                                            </td>
                                            <?php endif; ?>
                                            <td class="text-center">
                                                <?php if ($isSufficiente): ?>
                                                    <span class="badge bg-success">Suff.</span>
                                                <?php else: ?>
                                                    <span class="badge bg-danger">Insuff.</span>
                                                <?php endif; ?>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mt-4">
                            <a href="?step=select_test" class="btn btn-secondary">
                                <i class="bi bi-arrow-left"></i> Indietro
                            </a>
                            <div class="d-flex gap-2 align-items-center">
                                <span class="text-muted small" id="publishInfo">
                                    Seleziona classe, materia e tipo voto
                                </span>
                                <button type="submit" class="btn btn-success btn-lg" id="publishBtn" disabled>
                                    <i class="bi bi-cloud-upload"></i> Importa <span id="publishCount">0</span> Voti
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
        <div class="stats-box mb-3">
            <h6 class="mb-3"><i class="bi bi-bar-chart"></i> Statistiche Voti</h6>
            <div class="mb-2">
                <div class="d-flex justify-content-between">
                    <span>Risposte:</span>
                    <strong><?= $totaleRisposte ?></strong>
                </div>
            </div>
            <div class="mb-2">
                <div class="d-flex justify-content-between">
                    <span>Sufficienti:</span>
                    <strong><?= $sufficienti ?> (<?= $totaleRisposte > 0 ? round($sufficienti/$totaleRisposte*100, 1) : 0 ?>%)</strong>
                </div>
            </div>
            <?php if ($cbmAvailable && $cbmTotals): ?>
            <div class="mb-2">
                <div class="fw-semibold text-muted small">CBM per livello (corretti/sbagliati)</div>
                <?php foreach (['c1'=>'C1', 'c2'=>'C2', 'c3'=>'C3'] as $lvlKey => $lvlLabel): ?>
                    <div class="d-flex justify-content-between small">
                        <span><?= $lvlLabel ?>:</span>
                        <span>
                            <?= intval($cbmTotals[$lvlKey]['correct'] ?? 0) ?> /
                            <?= intval($cbmTotals[$lvlKey]['wrong'] ?? 0) ?>
                        </span>
                    </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <hr class="bg-white">
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
                <p class="mb-1"><strong>Nome:</strong><br><?= htmlspecialchars($test['nome']) ?></p>
                <p class="mb-1"><strong>Tipo:</strong><br><?= ucfirst($test['tipo_test'] ?? 'finale') ?></p>
                <p class="mb-1"><strong>Domande:</strong><br><?= $test['num_domande'] ?? 'N/D' ?></p>
                <p class="mb-0"><strong>Soglia:</strong><br><?= $test['soglia_sufficienza'] ?? '60' ?> pt (60%)</p>
            </div>
        </div>

        <?php if ($cbmAvailable): ?>
        <div class="card mt-3">
            <div class="card-header bg-secondary text-white">
                <h6 class="mb-0"><i class="bi bi-bar-chart-line"></i> Analisi (CBM)</h6>
            </div>
            <div class="card-body small">
                <div class="mb-2 fw-semibold">Domande (accuratezza e CBM medio)</div>
                <table class="table table-sm table-striped mb-3">
                    <thead>
                    <tr>
                        <th>Domanda</th>
                        <th class="text-center">Accuracy%</th>
                        <th class="text-center">CBM avg</th>
                        <th class="text-center">Conf.</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($questionSeries as $q): ?>
                        <tr>
                            <td class="text-break"><?= htmlspecialchars(substr($q['label'] ?? $q['id'] ?? '', 0, 30) . "...") ?></td>
                            <td class="text-center"><?= $q['accuracy'] ?? 0 ?></td>
                            <td class="text-center"><?= $q['cbm_avg'] ?? 0 ?></td>
                            <td class="text-center"><?= $q['avg_conf'] ?? 0 ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <div class="mb-2 fw-semibold">Accuracy vs CBM</div>
                <div style="height:240px;">
                    <canvas id="cbmScatter"></canvas>
                </div>
                <small class="text-muted">Clicca uno studente per evidenziare il punto.</small>
                <div class="alert alert-light border mt-3">
                    <div class="fw-semibold mb-1">CBM per studente (click riga studente)</div>
                    <div id="cbmStudentPanel" class="small text-muted">Seleziona uno studente per vedere i conteggi C1/C2/C3.</div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($cbmAvailable): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1"></script>
<?php endif; ?>

<script>
const studentSeries = <?= json_encode($studentSeries) ?>;
let scatterChart = null;
let currentPoints = [];
let selectedStudentKey = null;
let selectedStudentIndex = null;
const studentIndexByKey = new Map();


// Inizializzazione al caricamento
window.addEventListener('DOMContentLoaded', function() {
    updateSelectedCount();
    renderScatter(null);

    // Highlight on row click
    document.querySelectorAll('.grade-preview').forEach(row => {
        row.addEventListener('click', (event) => {
            const email = row.dataset.email;
            if (event.target.closest('input, select, button, a, label, textarea')) {
                highlightStudent(email, false);
                return;
            }
            highlightStudent(email, true);
        });
    });
});

document.getElementById('gradeTypeSelect').addEventListener('change', checkPublishButton);

function checkPublishButton() {
    const gradeType = document.getElementById('gradeTypeSelect').value;
    const publishBtn = document.getElementById('publishBtn');
    const publishInfo = document.getElementById('publishInfo');

    const canPublish = !!gradeType;
    publishBtn.disabled = !canPublish;

    if (canPublish) {
        const gradeTypeName = gradeType === 'S' ? 'Scritto' : (gradeType === 'O' ? 'Orale' : 'Pratico');
        publishInfo.textContent = `Come voto ${gradeTypeName}`;
        publishInfo.className = 'text-success small fw-bold';
    } else {
        publishInfo.textContent = 'Seleziona il tipo voto';
        publishInfo.className = 'text-muted small';
    }

    updateSelectedCount();
}

function toggleAllResponses() {
    const checkAll = document.getElementById('checkAll');
    const checkboxes = document.querySelectorAll('.response-checkbox:not(:disabled)');

    checkboxes.forEach(cb => {
        cb.checked = checkAll.checked;
    });

    updateSelectedCount();
}

function toggleAllMapped() {
    const checkboxes = document.querySelectorAll('.response-checkbox:not(:disabled)');
    const allMappedChecked = Array.from(checkboxes).every(cb => cb.checked);

    checkboxes.forEach(cb => {
        cb.checked = !allMappedChecked;
    });

    updateSelectedCount();
}

function updateSelectedCount() {
    const selected = document.querySelectorAll('.response-checkbox:checked').length;
    document.getElementById('selectedCount').textContent = selected;
    document.getElementById('publishCount').textContent = selected;
    // evidenzia il primo selezionato
    const first = document.querySelector('.response-checkbox:checked');
    if (first) {
        const row = first.closest('tr');
        highlightStudent(row?.dataset?.email);
        showStudentLevels(row?.dataset?.email);
    }
}

// Aggiorna statistiche quando cambiano i voti
document.addEventListener('DOMContentLoaded', function() {
    const votoSelects = document.querySelectorAll('.voto-select');
    votoSelects.forEach(select => {
        select.addEventListener('change', updateStatistics);
    });

    // Aggiorna anche quando si selezionano/deselezionano studenti
    const checkboxes = document.querySelectorAll('.response-checkbox');
    checkboxes.forEach(cb => {
        cb.addEventListener('change', updateStatistics);
        cb.addEventListener('change', () => highlightStudent(cb.closest('tr')?.dataset?.email));
    });
});

function updateStatistics() {
    const checkboxes = document.querySelectorAll('.response-checkbox:checked');
    let totalVoti = 0;
    let sumVoti = 0;
    let sufficienti = 0;

    checkboxes.forEach(cb => {
        const row = cb.closest('tr');
        const votoSelect = row.querySelector('.voto-select');
        if (votoSelect && !votoSelect.disabled) {
            const voto = parseFloat(votoSelect.value);
            totalVoti++;
            sumVoti += voto;
            if (voto >= 6) sufficienti++;
        }
    });

    const mediaVoti = totalVoti > 0 ? (sumVoti / totalVoti).toFixed(2) : 0;
    const percSufficienti = totalVoti > 0 ? ((sufficienti / totalVoti) * 100).toFixed(1) : 0;

    // Aggiorna i valori nelle statistiche (se gli elementi esistono)
    const mediaEl = document.querySelector('.stats-box .fs-4');
    if (mediaEl) mediaEl.textContent = mediaVoti;
}

// ====== GRAFICO CBM ======
function renderScatter(highlightId = null) {
    const ctx = document.getElementById('cbmScatter');
    if (!ctx || studentSeries.length === 0) return;
    const highlightKey = highlightId ? String(highlightId).toLowerCase() : null;
    if (highlightKey) {
        selectedStudentKey = highlightKey;
    }

    const colorForPoint = (x, y) => {
        // stessa logica di test_cbm_analysis (distanza da spezzata/retta 3x)
        const yMin = (x <= 66.6) ? x : (x <= 80 ? 4 * x - 200 : 9 * x - 600);
        const yAw = 3 * x;
        const diffAw = y - yAw;
        const diffMin = y - yMin;
        if (diffAw >= 0) {
            return diffAw > 30 ? '#1b5e20' : '#43a047'; // verdi sopra retta consapevolezza
        }
        if (diffMin >= 0) {
            return '#4e73df'; // leggermente sopra spezzata: blu
        }
        if (diffMin >= -20) return '#fb8c00'; // arancione vicino
        return '#e53935'; // rosso sotto
    };

    const dataPoints = studentSeries.map(s => ({
        x: s.accuracy_pct,
        y: s.cbm_pct,
        id: (s.id || '').toLowerCase()
    }));

    currentPoints = dataPoints;
    studentIndexByKey.clear();
    dataPoints.forEach((p, idx) => {
        studentIndexByKey.set(p.id, idx);
    });
    selectedStudentIndex = selectedStudentKey ? (studentIndexByKey.get(selectedStudentKey) ?? null) : null;

    // Spezzata: 0-66.6 y=x; 66.6-80 y=4x-200; 80-100 y=9x-600
    const piecewisePoints = [];
    for (let x = 0; x <= 100; x += 1) {
        let y = 0;
        if (x <= 66.6) y = x;
        else if (x <= 80) y = 4 * x - 200;
        else y = 9 * x - 600;
        piecewisePoints.push({ x, y });
    }

    if (scatterChart) {
        scatterChart.data.datasets[0].data = dataPoints;
        scatterChart.data.datasets[1].data = piecewisePoints;
        scatterChart.data.datasets[2].data = Array.from({length: 51}, (_,i) => {
            const x = i*2;
            return {x, y: Math.min(300, 3*x)};
        });
        if (selectedStudentIndex !== null) {
            scatterChart.setActiveElements([{ datasetIndex: 0, index: selectedStudentIndex }]);
        } else {
            scatterChart.setActiveElements([]);
        }
        scatterChart.update();
        return;
    }

    scatterChart = new Chart(ctx, {
        type: 'scatter',
        data: {
            datasets: [{
                label: 'Studenti',
                data: dataPoints,
                backgroundColor: ctx => {
                    const raw = ctx.raw || {};
                    if (ctx.dataIndex === selectedStudentIndex) {
                        return 'rgba(78,115,223,0.9)';
                    }
                    return colorForPoint(raw.x ?? 0, raw.y ?? 0);
                },
                pointRadius: ctx => {
                    return (ctx.dataIndex === selectedStudentIndex) ? 10 : 5;
                },
                pointHoverRadius: ctx => {
                    return (ctx.dataIndex === selectedStudentIndex) ? 13 : 7;
                },
                pointBorderWidth: ctx => {
                    return (ctx.dataIndex === selectedStudentIndex) ? 2 : 1;
                },
                pointBorderColor: ctx => {
                    return (ctx.dataIndex === selectedStudentIndex) ? '#000' : '#fff';
                },
                pointStyle: ctx => {
                    return (ctx.dataIndex === selectedStudentIndex) ? 'star' : 'circle';
                }
            },{
                label: 'Spezzata consapevolezza assente',
                data: piecewisePoints,
                type: 'line',
                borderColor: '#ff9900',
                borderWidth: 1.5,
                pointRadius: 0,
                fill: false,
                borderDash: [6,3]
            },{
                label: 'Retta consapevolezza (y=3x)',
                data: Array.from({length: 51}, (_,i) => {
                    const x = i*2;
                    return {x, y: Math.min(300, 3*x)};
                }),
                type: 'line',
                borderColor: '#1e88e5',
                borderWidth: 1,
                pointRadius: 0,
                borderDash: [4,4],
                fill: false
            }]
        },
        options: {
            maintainAspectRatio: false,
            onClick: (evt, elements) => {
                if (!elements || elements.length === 0) return;
                const el = elements[0];
                if (el.datasetIndex !== 0) return;
                const idx = el.index;
                const sid = currentPoints[idx]?.id;
                if (sid) {
                    highlightStudent(sid);
                }
            },
            scales: {
                x: { title: { display: true, text: 'Accuracy %' }, min: 0, max: 100 },
                y: { title: { display: true, text: 'CBM %' }, min: -200, max: 300 }
            },
            plugins: {
                tooltip: {
                    callbacks: {
                        label: ctx => `${ctx.raw.id}: acc ${ctx.raw.x}% / cbm ${ctx.raw.y}%`
                    }
                },
                legend: { display: true }
            }
        }
    });
}

let expandedStudentKey = null;

function highlightStudent(email, toggleExpand = false) {
    if (!email) return;
    const key = String(email).toLowerCase();
    selectedStudentKey = key;
    selectedStudentIndex = studentIndexByKey.get(key) ?? null;
    if (scatterChart) {
        if (selectedStudentIndex !== null) {
            scatterChart.setActiveElements([{ datasetIndex: 0, index: selectedStudentIndex }]);
        } else {
            scatterChart.setActiveElements([]);
        }
        scatterChart.update();
    }
    showStudentLevels(key);
    // Evidenzia la riga selezionata
    document.querySelectorAll('.grade-preview').forEach(row => {
        const isMatch = row.dataset.email === key;
        row.classList.toggle('table-active', isMatch);
        if (!toggleExpand && isMatch) {
            row.classList.add('cbm-expanded');
            expandedStudentKey = key;
        } else if (toggleExpand && isMatch) {
            const shouldExpand = expandedStudentKey !== key;
            row.classList.toggle('cbm-expanded', shouldExpand);
            expandedStudentKey = shouldExpand ? key : null;
        } else {
            row.classList.remove('cbm-expanded');
        }
    });
}

function showStudentLevels(email) {
    if (!email) return;
    const panel = document.getElementById('cbmStudentPanel');
    const key = email.toLowerCase();
    const levels = <?= json_encode($cbmByStudent) ?>;
    const data = levels[key] || null;
    if (!panel || !data) {
        if (panel) panel.textContent = 'Nessun dato CBM per questo studente.';
        return;
    }
    const fmt = (n) => typeof n === 'number' ? n : 0;
    const rows = [
        {label: 'Risposte senza confidenza (C1)', key: 'c1'},
        {label: 'Bassa confidenza (C2)', key: 'c2'},
        {label: 'Alta confidenza (C3)', key: 'c3'},
    ];
    let table = `
        <div><strong>${email}</strong></div>
        <table class="table table-sm mb-0">
            <thead>
                <tr>
                    <th></th>
                    <th class="text-center text-success">✓</th>
                    <th class="text-center text-danger">✗</th>
                </tr>
            </thead>
            <tbody>`;
    rows.forEach(r => {
        const lvl = data[r.key] || {};
        table += `
            <tr>
                <td>${r.label}</td>
                <td class="text-center">${fmt(lvl.correct)}</td>
                <td class="text-center">${fmt(lvl.wrong)}</td>
            </tr>`;
    });
    table += `</tbody></table>`;
    panel.innerHTML = table;
}


</script>

