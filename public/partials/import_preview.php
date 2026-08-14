<?php if (!empty($previewQuestions)): ?>
<section id="importPreviewSection" class="mb-4" data-preview-mode="true" aria-labelledby="importPreviewTitle">
    <form method="POST" id="importForm">
        <input type="hidden" name="action" value="import_selected">
        <input type="hidden" name="modalita" value="<?= htmlspecialchars($modalita) ?>">
        <input type="hidden" name="return_to" value="<?= htmlspecialchars($returnTo ?? '') ?>">

        <div class="card mb-4">
            <div class="card-header bg-success text-white d-flex justify-content-between align-items-center">
                <div>
                    <h5 class="mb-0" id="importPreviewTitle"><i class="bi bi-list-check"></i> Anteprima domande da importare</h5>
                    <small>Spunta le domande da importare e controlla l’anteprima prima di confermare.</small>
                </div>
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="badge bg-light text-success">Totale trovate: <?= count($previewQuestions) ?></span>
                    <button type="button" class="btn btn-sm btn-light text-success" id="selectAllImportQuestions">
                        <i class="bi bi-check2-all"></i> Seleziona tutti
                    </button>
                    <button type="button" class="btn btn-sm btn-outline-light" id="deselectAllImportQuestions">
                        <i class="bi bi-square"></i> Deseleziona tutti
                    </button>
                </div>
            </div>
            <div class="card-body" id="question-preview-list">
                <?php foreach ($previewQuestions as $index => $domanda):
                    $questionCardData = $domanda;
                    $questionCardData['tipo_domanda'] = in_array(($domanda['tipo'] ?? 'aperta'), ['multipla', 'multipla_multi', 'chiusa'], true) ? 'multipla' : 'aperta';
                    $questionCardData['opzioni'] = is_array($domanda['opzioni'] ?? null) ? $domanda['opzioni'] : (is_array($domanda['risposte'] ?? null) ? $domanda['risposte'] : []);
                    // In questa anteprima le card sono solo selezionabili: le azioni
                    // di modifica/cancellazione restano disponibili nelle altre pagine.
                    $questionCardActions = 'none';
                    $questionCardSelectable = true;
                    $questionCardSelectionName = "questions[{$index}][import]";
                    $questionCardIndex = $index;
                    $questionCardLabel = 'Domanda ' . ($index + 1);
                    include __DIR__ . '/question_card.php';
                ?>
                    <?php foreach ([
                        'argomento' => $domanda['argomento'] ?? 'Generale',
                        'domanda' => $domanda['domanda'] ?? '',
                        'tipo' => $domanda['tipo'] ?? 'aperta',
                        'risposta_attesa' => $domanda['risposta_attesa'] ?? '',
                        'parole_chiave' => $domanda['parole_chiave'] ?? '',
                        'difficolta' => $domanda['difficolta'] ?? 3,
                        'ordine_consigliato' => $domanda['ordine_consigliato'] ?? ($index + 1),
                        'tempo_risposta_min' => $domanda['tempo_risposta_min'] ?? 3,
                        'note' => $domanda['note'] ?? '',
                    ] as $field => $value): ?>
                        <input type="hidden" name="questions[<?= $index ?>][<?= htmlspecialchars($field, ENT_QUOTES) ?>]" value="<?= htmlspecialchars((string)$value, ENT_QUOTES) ?>" data-preview-field="<?= htmlspecialchars($field, ENT_QUOTES) ?>">
                    <?php endforeach; ?>
                    <input type="hidden" name="questions[<?= $index ?>][collegata_a]" value="">
                <?php endforeach; ?>
            </div>
        </div>

        <div class="d-grid gap-2 d-md-flex justify-content-md-end mb-5">
            <a href="import_questions.php?id=<?= urlencode($udaId) ?>&return_to=<?= urlencode($returnTo ?? '') ?>" class="btn btn-outline-secondary">
                <i class="bi bi-x-circle"></i> Annulla
            </a>
            <button type="submit" class="btn btn-success btn-lg">
                <i class="bi bi-cloud-upload"></i> Importa le domande selezionate
            </button>
        </div>
    </form>
</section>
<?php endif; ?>
