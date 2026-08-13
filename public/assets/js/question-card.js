(function (root, factory) {
    const dependency = root.UdaEditorUtils || (typeof require === 'function' ? require('./uda-editor-utils.js') : null);
    const api = factory(dependency);
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.QuestionCard = api;
})(typeof window !== 'undefined' ? window : globalThis, function (utils) {
    function normalizeState(input) {
        const value = input || {};
        const type = String(value.tipo_domanda || value.tipo || 'aperta').toLowerCase();
        return {
            argomento: String(value.argomento || ''),
            domanda: String(value.domanda || ''),
            difficolta: Math.max(1, Math.min(5, Number(value.difficolta || 3))),
            tipo_domanda: ['multipla', 'multipla_multi', 'chiusa'].includes(type) ? 'multipla' : 'aperta',
            risposta_attesa: String(value.risposta_attesa || ''),
            opzioni: utils.parseOptions(value.opzioni || value.risposte || value.risposta_attesa || ''),
            parole_chiave: utils.normalizeKeywords(value.parole_chiave || value.parole || '')
        };
    }

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, character => ({
            '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
        }[character]));
    }

    function renderOptions(card, options) {
        const container = card.querySelector('[data-question-card-options]');
        if (!container) return;
        container.innerHTML = options.map(option => `
            <div class="answer-line">
                <i class="bi ${option.corretta ? 'bi-check-square-fill text-success' : 'bi-square text-muted'} answer-icon"></i>
                <span>${escapeHtml(option.testo)}</span>
            </div>`).join('');
        container.classList.toggle('d-none', options.length === 0);
    }

    function renderKeywords(card, keywords) {
        const container = card.querySelector('[data-question-card-keywords]');
        if (!container) return;
        container.innerHTML = '<small class="text-muted">Parole chiave:</small>' + keywords.map(keyword =>
            `<span class="badge bg-light text-dark keyword-tag">${escapeHtml(keyword)}</span>`).join('');
        container.classList.toggle('d-none', keywords.length === 0);
    }

    function update(card, input, config) {
        const state = normalizeState(input);
        const options = state.opzioni;
        const label = String((config && config.label) || '');
        card.classList.remove('difficulty-1', 'difficulty-2', 'difficulty-3', 'difficulty-4', 'difficulty-5');
        card.classList.add(`difficulty-${state.difficolta}`);
        card.dataset.questionCardType = state.tipo_domanda;
        const labelNode = card.querySelector('[data-question-card-label]');
        if (labelNode) { labelNode.textContent = label; labelNode.classList.toggle('d-none', !label); }
        const questionNode = card.querySelector('[data-question-card-text]');
        if (questionNode) questionNode.textContent = state.domanda || 'Domanda non ancora compilata.';
        const typeNode = card.querySelector('[data-question-card-type-label]');
        if (typeNode) typeNode.textContent = state.tipo_domanda === 'multipla' ? 'Risposta multipla' : 'Risposta aperta';
        const difficultyNode = card.querySelector('[data-question-card-difficulty]');
        if (difficultyNode) difficultyNode.textContent = `Difficoltà: ${state.difficolta}/5`;
        renderOptions(card, state.tipo_domanda === 'multipla' ? options : []);
        const answerNode = card.querySelector('[data-question-card-open-answer]');
        const answerText = card.querySelector('[data-question-card-open-answer-text]');
        const hasAnswer = state.tipo_domanda === 'aperta' && state.risposta_attesa.trim() !== '';
        if (answerNode) answerNode.classList.toggle('d-none', !hasAnswer);
        if (answerText) answerText.innerHTML = hasAnswer ? escapeHtml(state.risposta_attesa).replace(/\n/g, '<br>') : '';
        renderKeywords(card, state.parole_chiave);
        return state;
    }

    function createFromTemplate(template, state, config) {
        if (!template || !template.content || !template.content.firstElementChild) {
            throw new Error('Template card domanda mancante.');
        }
        const card = template.content.firstElementChild.cloneNode(true);
        const options = Object.assign({}, config || {});
        update(card, state, options);
        const edit = card.querySelector('[data-question-edit]');
        if (edit && typeof options.onEdit === 'function') edit.addEventListener('click', () => options.onEdit(card));
        const remove = card.querySelector('[data-question-delete]');
        if (remove && typeof options.onDelete === 'function') remove.addEventListener('click', () => options.onDelete(card));
        return card;
    }

    return { normalizeState, update, createFromTemplate };
});
