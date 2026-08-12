<?php
/**
 * Step intermedio: configurazione/calcolo CBM
 *
 * Qui si impostano i parametri di scoring CBM prima di passare all'anteprima.
 * Il calcolo dettagliato verrà applicato in un passaggio successivo (preview/publish).
 */

$cbmParams = $_SESSION['cbm_params'] ?? [
    'c1_correct' => 1,
    'c1_wrong' => 0,
    'c2_correct' => 2,
    'c2_wrong' => -2,
    'c3_correct' => 3,
    'c3_wrong' => -6,
    'bonus_max' => 1,
    'malus_max' => -2,
    'adj_on_min' => -0.5,
    'pass_threshold' => 4.99,
];
$mappingWarnings = [];

// Se non ci sono mapping CBM salvati, avvisa che useremo solo punteggio classico finché non c'è mapping domanda/confidenza
$cbmMappingRows = $dbAdapter->findWhere('TEST_CBM_MAPPING', ['id_test' => $testId]);
if (empty($cbmMappingRows)) {
    $mappingWarnings[] = "Nessun mapping domanda/confidenza salvato per questo test. Il calcolo CBM userà il punteggio classico finché non saranno disponibili le domande di confidenza.";
}
?>

<div class="card">
    <div class="card-header bg-info text-white">
        <h5 class="mb-0"><i class="bi bi-sliders"></i> Configurazione Confidence-Based Assessment (CBM)</h5>
    </div>
    <div class="card-body">
        <?php if (!empty($mappingWarnings)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                <?php foreach ($mappingWarnings as $warn): ?>
                    <div><?= htmlspecialchars($warn) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" action="?step=cbm_config&test_id=<?= htmlspecialchars($testId) ?>&uda_id=<?= htmlspecialchars($udaId) ?>">
            <div class="row g-3">
                <div class="col-md-4">
                    <h6 class="text-muted">Livello C1 (poco sicuro)</h6>
                    <label class="form-label small">Punteggio se corretto</label>
                    <input type="number" step="0.1" class="form-control" name="c1_correct" value="<?= htmlspecialchars($cbmParams['c1_correct']) ?>">
                    <label class="form-label small mt-2">Penalità se errato</label>
                    <input type="number" step="0.1" class="form-control" name="c1_wrong" value="<?= htmlspecialchars($cbmParams['c1_wrong']) ?>">
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted">Livello C2 (abbastanza sicuro)</h6>
                    <label class="form-label small">Punteggio se corretto</label>
                    <input type="number" step="0.1" class="form-control" name="c2_correct" value="<?= htmlspecialchars($cbmParams['c2_correct']) ?>">
                    <label class="form-label small mt-2">Penalità se errato</label>
                    <input type="number" step="0.1" class="form-control" name="c2_wrong" value="<?= htmlspecialchars($cbmParams['c2_wrong']) ?>">
                </div>
                <div class="col-md-4">
                    <h6 class="text-muted">Livello C3 (molto sicuro)</h6>
                    <label class="form-label small">Punteggio se corretto</label>
                    <input type="number" step="0.1" class="form-control" name="c3_correct" value="<?= htmlspecialchars($cbmParams['c3_correct']) ?>">
                    <label class="form-label small mt-2">Penalità se errato</label>
                    <input type="number" step="0.1" class="form-control" name="c3_wrong" value="<?= htmlspecialchars($cbmParams['c3_wrong']) ?>">
                </div>
            </div>

            <hr class="my-4">
            <div class="row g-3">
                <div class="col-md-3">
                    <h6 class="text-muted">Bonus/Malus</h6>
                    <label class="form-label small">Bonus massimo</label>
                    <input type="number" step="0.1" class="form-control" name="bonus_max" value="<?= htmlspecialchars($cbmParams['bonus_max']) ?>">
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted invisible">.</h6>
                    <label class="form-label small">Malus massimo</label>
                    <input type="number" step="0.1" class="form-control" name="malus_max" value="<?= htmlspecialchars($cbmParams['malus_max']) ?>">
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted invisible">.</h6>
                    <label class="form-label small">Adj su spezzata minima</label>
                    <input type="number" step="0.1" class="form-control" name="adj_on_min" value="<?= htmlspecialchars($cbmParams['adj_on_min']) ?>">
                </div>
                <div class="col-md-3">
                    <h6 class="text-muted">Soglia</h6>
                    <label class="form-label small">Soglia (voto) sotto cui il CBM non incide</label>
                    <input type="number" step="0.1" class="form-control" name="pass_threshold" value="<?= htmlspecialchars($cbmParams['pass_threshold']) ?>">
                </div>
            </div>

            <div class="mt-4 d-flex justify-content-between align-items-center">
                <div class="text-muted small">
                    I parametri verranno applicati nel calcolo CBM durante l'anteprima. Se manca il mapping confidenza, verrà usato il punteggio classico.
                </div>
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-arrow-right-circle"></i> Procedi all'anteprima
                </button>
            </div>
        </form>
    </div>
</div>
