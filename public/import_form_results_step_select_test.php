<?php
/**
 * Step 1: Selezione Test Google Forms
 */

// Recupera tutti i test Google Forms
$allTests = $dbAdapter->findAll('TEST');
$googleFormsTests = array_filter($allTests, function($t) {
    return ($t['piattaforma'] ?? '') === 'google-forms';
});

// Raggruppa per UDA
$testsByUDA = [];
foreach ($googleFormsTests as $test) {
    $uda_id = $test['id_uda'] ?? 'unknown';
    if (!isset($testsByUDA[$uda_id])) {
        $testsByUDA[$uda_id] = [];
    }
    $testsByUDA[$uda_id][] = $test;
}
?>

<div class="card">
    <div class="card-header bg-primary text-white">
        <h5 class="mb-0"><i class="bi bi-list-check"></i> Seleziona Test Google Forms</h5>
    </div>
    <div class="card-body">
        <?php if (empty($googleFormsTests)): ?>
            <div class="alert alert-warning">
                <i class="bi bi-exclamation-triangle"></i>
                Nessun test Google Forms trovato nel database.
                <a href="generate_google_form.php" class="alert-link">Genera un nuovo test</a>.
            </div>
        <?php else: ?>
            <p class="text-muted">
                Seleziona il test di cui vuoi importare i risultati e pubblicare i voti su ClasseViva.
            </p>

            <?php foreach ($testsByUDA as $uda_id => $tests): ?>
                <?php
                try {
                    $udaComplete = $udaManager->getUDAComplete($uda_id);
                    $udaObj = $udaComplete['uda'] ?? null;
                    $udaTitle = $udaObj ? $udaObj->titolo : 'UDA Sconosciuta';
                } catch (Exception $e) {
                    $udaTitle = 'UDA ID: ' . $uda_id;
                }
                ?>

                <h6 class="mt-4 mb-3 text-primary">
                    <i class="bi bi-folder"></i> <?= htmlspecialchars($udaTitle) ?>
                </h6>

                <div class="list-group mb-3">
                    <?php foreach ($tests as $test): ?>
                        <?php
                        $isImported = ($test['risultati_importati'] ?? 'NO') === 'SI';
                        $badgeClass = $isImported ? 'bg-success' : 'bg-warning';
                        $badgeText = $isImported ? 'Risultati già importati' : 'Da importare';
                        ?>

                        <div class="list-group-item">
                            <div class="d-flex justify-content-between align-items-start">
                                <div class="flex-grow-1">
                                    <h6 class="mb-1">
                                        <i class="bi bi-file-earmark-text"></i>
                                        <?= htmlspecialchars($test['nome']) ?>
                                    </h6>
                                    <p class="mb-1 text-muted small">
                                        <?= htmlspecialchars($test['descrizione'] ?? '') ?>
                                    </p>
                                    <div>
                                        <span class="badge bg-info text-dark">
                                            <?= ucfirst($test['tipo_test'] ?? 'finale') ?>
                                        </span>
                                        <span class="badge <?= $badgeClass ?>">
                                            <?= $badgeText ?>
                                        </span>
                                        <span class="badge bg-secondary">
                                            <?= $test['num_domande'] ?? 'N/D' ?> domande
                                        </span>
                                        <span class="badge bg-secondary">
                                            Max <?= $test['punteggio_max'] ?? '100' ?> punti
                                        </span>
                                    </div>
                                </div>
                                <div class="ms-3">
                                    <form method="POST" action="?step=read_responses">
                                        <input type="hidden" name="test_id" value="<?= htmlspecialchars($test['id_test']) ?>">
                                        <a href="<?= htmlspecialchars($test['url'] ?? '#') ?>"
                                           target="_blank"
                                           class="btn btn-sm btn-outline-primary me-1"
                                           title="Visualizza Form">
                                            <i class="bi bi-box-arrow-up-right"></i>
                                        </a>
                                        <button type="submit" class="btn btn-sm btn-primary">
                                            <i class="bi bi-download"></i> Importa Risultati
                                        </button>
                                    </form>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<div class="mt-4">
    <div class="card bg-light">
        <div class="card-body">
            <h6><i class="bi bi-info-circle"></i> Come funziona</h6>
            <ol class="mb-0 small">
                <li>Seleziona il test di cui vuoi importare i risultati</li>
                <li>Il sistema leggerà automaticamente le risposte da Google Forms</li>
                <li>Calcolerà i voti in base ai punteggi ottenuti</li>
                <li>Potrai visualizzare un'anteprima prima della pubblicazione</li>
                <li>I voti verranno pubblicati su ClasseViva e salvati nel database</li>
                <li>Riceverai un'email riepilogativa con tutti i voti pubblicati</li>
            </ol>
        </div>
    </div>
</div>
