(function (root, factory) {
    const api = factory(root.UdaEditorUtils);
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.QuestionEditor = api;
})(typeof window !== 'undefined' ? window : globalThis, function (utils) {
    function blankState() {
        return { argomento: '', difficolta: '3', domanda: '', tipo_domanda: 'aperta', risposta_attesa: '', opzioni: [], parole_chiave: [] };
    }

    function normalizeState(input) {
        const state = Object.assign(blankState(), input || {});
        state.difficolta = String(state.difficolta || '3');
        state.tipo_domanda = state.tipo_domanda === 'multipla' ? 'multipla' : 'aperta';
        state.opzioni = utils.parseOptions(state.opzioni || state.risposte || state.risposta_attesa);
        state.parole_chiave = utils.normalizeKeywords(state.parole_chiave || state.parole || '');
        return state;
    }

    function mount(root, config) {
        if (!root) throw new Error('Question editor root mancante.');
        config = config || {};
        let state = normalizeState(config.initialState);
        const fields = {
            argomento: root.querySelector('[data-question-field="argomento"]'),
            difficolta: root.querySelector('[data-question-field="difficolta"]'),
            domanda: root.querySelector('[data-question-field="domanda"]'),
            tipo: root.querySelector('[data-question-field="tipo_domanda"]'),
            risposta: root.querySelector('[data-question-field="risposta_attesa"]'),
            keywordInput: root.querySelector('[data-keyword-input]'),
            keywordChips: root.querySelector('[data-keyword-chips]'),
            options: root.querySelector('[data-question-options]'),
            openAnswer: root.querySelector('[data-answer-open]'),
            multipleAnswer: root.querySelector('[data-answer-multiple]'),
            error: root.querySelector('[data-question-error]')
        };

        function emit() { if (typeof config.onChange === 'function') config.onChange(getState()); }
        function getState() { return Object.assign({}, state, { opzioni: state.opzioni.map(option => Object.assign({}, option)), parole_chiave: state.parole_chiave.slice() }); }
        function showError(message) { if (fields.error) { fields.error.textContent = message || ''; fields.error.classList.toggle('d-none', !message); } }
        function renderKeywords() {
            if (!fields.keywordChips) return;
            fields.keywordChips.innerHTML = state.parole_chiave.map((keyword, index) => `<span class="badge rounded-pill text-bg-primary me-1 mb-1">${escapeHtml(keyword)} <button type="button" class="btn-close btn-close-white ms-1" aria-label="Rimuovi ${escapeHtml(keyword)}" data-remove-keyword="${index}"></button></span>`).join('');
        }
        function renderOptions() {
            if (!fields.options) return;
            fields.options.innerHTML = state.opzioni.map((option, index) => `
                <div class="input-group mb-2" data-option-row="${index}">
                    <span class="input-group-text"><input class="form-check-input mt-0" type="checkbox" data-option-correct="${index}" ${option.corretta ? 'checked' : ''} aria-label="Risposta corretta"></span>
                    <input type="text" class="form-control" data-option-text="${index}" value="${escapeAttribute(option.testo)}" placeholder="Inserisci un'opzione">
                    <button type="button" class="btn btn-outline-danger" data-remove-option="${index}" aria-label="Rimuovi opzione"><i class="bi bi-trash"></i></button>
                </div>`).join('');
        }
        function render() {
            if (fields.argomento) fields.argomento.value = state.argomento;
            if (fields.difficolta) fields.difficolta.value = state.difficolta;
            if (fields.domanda) fields.domanda.value = state.domanda;
            if (fields.tipo) fields.tipo.value = state.tipo_domanda;
            if (fields.risposta) fields.risposta.value = state.risposta_attesa;
            if (fields.openAnswer) fields.openAnswer.classList.toggle('d-none', state.tipo_domanda !== 'aperta');
            if (fields.multipleAnswer) fields.multipleAnswer.classList.toggle('d-none', state.tipo_domanda !== 'multipla');
            renderKeywords(); renderOptions();
        }
        function addKeyword(value) {
            const items = utils.normalizeKeywords(value);
            state.parole_chiave = utils.normalizeKeywords(state.parole_chiave.concat(items));
            if (fields.keywordInput) fields.keywordInput.value = '';
            renderKeywords(); emit();
        }
        function readFields() {
            if (fields.argomento) state.argomento = fields.argomento.value.trim();
            if (fields.difficolta) state.difficolta = fields.difficolta.value;
            if (fields.domanda) state.domanda = fields.domanda.value;
            if (fields.tipo) state.tipo_domanda = fields.tipo.value === 'multipla' ? 'multipla' : 'aperta';
            if (fields.risposta) state.risposta_attesa = fields.risposta.value;
        }
        function validate() {
            readFields();
            if (!state.domanda.trim()) return { valid: false, message: 'Inserisci il testo della domanda.' };
            if (state.tipo_domanda === 'multipla') return utils.validateMultiple(state.opzioni);
            return { valid: true };
        }
        root.addEventListener('input', event => {
            if (event.target.matches('[data-option-text]')) { state.opzioni[Number(event.target.dataset.optionText)].testo = event.target.value; emit(); return; }
            readFields(); emit();
        });
        root.addEventListener('change', event => {
            if (event.target.matches('[data-option-correct]')) { state.opzioni[Number(event.target.dataset.optionCorrect)].corretta = event.target.checked; emit(); return; }
            const previousType = state.tipo_domanda;
            readFields();
            if (state.tipo_domanda === 'multipla' && previousType !== 'multipla' && state.opzioni.length === 0) {
                state.opzioni = [{ testo: '', corretta: false }, { testo: '', corretta: false }];
            }
            render(); emit();
        });
        root.addEventListener('keydown', event => {
            if (event.target.matches('[data-keyword-input]') && (event.key === 'Enter' || event.key === ',')) { event.preventDefault(); addKeyword(event.target.value); }
        });
        root.addEventListener('click', event => {
            const removeKeyword = event.target.closest('[data-remove-keyword]');
            if (removeKeyword) { state.parole_chiave.splice(Number(removeKeyword.dataset.removeKeyword), 1); renderKeywords(); emit(); return; }
            const removeOption = event.target.closest('[data-remove-option]');
            if (removeOption) { state.opzioni.splice(Number(removeOption.dataset.removeOption), 1); renderOptions(); emit(); return; }
            if (event.target.closest('[data-add-option]')) { state.opzioni.push({ testo: '', corretta: false }); renderOptions(); emit(); }
        });
        root.setEditorState = next => { state = normalizeState(next); showError(''); render(); emit(); };
        root.getEditorState = getState;
        root.validateEditor = validate;
        root.serializeEditor = function (form) {
            const result = validate();
            showError(result.valid ? '' : result.message);
            if (!result.valid) return false;
            const values = {
                argomento: state.argomento, difficolta: state.difficolta, domanda: state.domanda,
                tipo_domanda: state.tipo_domanda, risposta_attesa: state.tipo_domanda === 'multipla' ? utils.serializeMultipleChoice(state.opzioni) : state.risposta_attesa,
                parole_chiave: state.parole_chiave.join(',')
            };
            Object.keys(values).forEach(name => {
                let input = form.querySelector(`[data-editor-hidden="${name}"]`);
                if (!input) { input = document.createElement('input'); input.type = 'hidden'; input.dataset.editorHidden = name; input.name = name; form.appendChild(input); }
                input.value = values[name];
            });
            return true;
        };
        render();
        return root;
    }

    function escapeHtml(value) { return String(value ?? '').replace(/[&<>"']/g, char => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[char])); }
    function escapeAttribute(value) { return escapeHtml(value); }
    return { mount, normalizeState, blankState };
});
