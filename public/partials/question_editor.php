<?php
$questionEditorId = $questionEditorId ?? 'question-editor';
?>
<style>
    .question-keywords-input { display: flex; flex-wrap: wrap; gap: .25rem; align-items: center; min-height: 38px; }
    .question-keywords-input input:focus { outline: none; box-shadow: none; }
    .question-editor [data-question-options] .input-group-text { min-width: 42px; justify-content: center; }
</style>
<div class="question-editor" id="<?= htmlspecialchars($questionEditorId) ?>" data-question-editor>
    <div class="row g-3">
        <div class="col-md-6">
            <label class="form-label">Argomento *</label>
            <input type="text" class="form-control" data-question-field="argomento" required placeholder="Es. Scheduling, processi, thread">
        </div>
        <div class="col-md-3">
            <label class="form-label">Difficoltà *</label>
            <select class="form-select" data-question-field="difficolta" required>
                <option value="1">1 - Molto facile</option>
                <option value="2">2 - Facile</option>
                <option value="3" selected>3 - Media</option>
                <option value="4">4 - Difficile</option>
                <option value="5">5 - Molto difficile</option>
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label">Tipo domanda *</label>
            <select class="form-select" data-question-field="tipo_domanda" required>
                <option value="aperta">Risposta aperta</option>
                <option value="multipla">Risposta multipla</option>
            </select>
        </div>
        <div class="col-12">
            <label class="form-label">Testo domanda *</label>
            <textarea class="form-control" rows="3" data-question-field="domanda" required placeholder="Scrivi qui la domanda..."></textarea>
        </div>
        <div class="col-12" data-answer-open>
            <label class="form-label">Risposta attesa</label>
            <textarea class="form-control" rows="4" data-question-field="risposta_attesa" placeholder="Descrivi la risposta attesa..."></textarea>
        </div>
        <div class="col-12 d-none" data-answer-multiple>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <div>
                    <label class="form-label mb-0">Opzioni di risposta</label>
                    <div class="small text-muted">Seleziona una o più risposte corrette.</div>
                </div>
                <button type="button" class="btn btn-outline-primary btn-sm" data-add-option><i class="bi bi-plus-circle"></i> Aggiungi opzione</button>
            </div>
            <div data-question-options></div>
        </div>
        <div class="col-12">
            <label class="form-label">Parole chiave</label>
            <div class="form-control question-keywords-input" data-keyword-container>
                <div data-keyword-chips></div>
                <input type="text" class="border-0 flex-grow-1" data-keyword-input placeholder="Scrivi e premi Invio o virgola">
            </div>
            <div class="small text-muted">Le parole chiave aiutano a valutare la risposta.</div>
        </div>
        <div class="col-12">
            <div class="alert alert-danger d-none mb-0" data-question-error role="alert"></div>
        </div>
    </div>
</div>
