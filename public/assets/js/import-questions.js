(function () {
    'use strict';

    const formsUrlInput = document.getElementById('formsUrlInput');
    const catalog = document.getElementById('googleFormsCatalog');
    const catalogSearch = document.getElementById('formsCatalogSearch');
    const catalogList = document.getElementById('formsCatalogList');
    const catalogStatus = document.getElementById('googleFormsCatalogStatus');
    const authorizationNotice = document.getElementById('googleFormsAuthorizationNotice');
    let forms = [];
    let catalogLoaded = false;

    function setStatus(message, type) {
        if (!catalogStatus) return;
        catalogStatus.className = `alert alert-${type || 'info'} mt-3 mb-2`;
        catalogStatus.textContent = message || '';
        catalogStatus.classList.toggle('d-none', !message);
    }

    function escapeText(value) {
        return String(value ?? '');
    }

    function formatDate(value) {
        if (!value) return 'Data non disponibile';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('it-IT', { dateStyle: 'medium' }).format(date);
    }

    function renderCatalog() {
        if (!catalogList) return;
        const query = String(catalogSearch?.value || '').trim().toLocaleLowerCase('it');
        const filtered = forms.filter(form => [form.title, form.author, form.created_at]
            .map(escapeText).join(' ').toLocaleLowerCase('it').includes(query));
        catalogList.replaceChildren();
        if (!filtered.length) {
            const empty = document.createElement('div');
            empty.className = 'list-group-item text-muted';
            empty.textContent = 'Nessun Google Form corrisponde alla ricerca.';
            catalogList.appendChild(empty);
            return;
        }
        filtered.forEach(form => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'list-group-item list-group-item-action';
            button.setAttribute('role', 'option');
            button.addEventListener('click', () => {
                if (formsUrlInput) formsUrlInput.value = form.teacher_url || `https://docs.google.com/forms/d/${form.id}/edit`;
                catalogList.querySelectorAll('.list-group-item').forEach(item => item.classList.remove('active'));
                button.classList.add('active');
            });

            const title = document.createElement('div');
            title.className = 'fw-semibold';
            title.textContent = form.title || 'Google Form senza titolo';
            const metadata = document.createElement('small');
            metadata.className = 'text-muted d-block';
            const count = form.response_count === null || form.response_count === undefined ? 'risposte n/d' : `${form.response_count} risposte`;
            metadata.textContent = `${count} · ${form.author || 'Autore n/d'} · ${formatDate(form.created_at)}`;
            button.append(title, metadata);
            catalogList.appendChild(button);
        });
    }

    async function loadFormsCatalog() {
        if (!catalog || catalogLoaded) return;
        catalog.classList.remove('d-none');
        authorizationNotice?.classList.add('d-none');
        setStatus('Caricamento dei Google Forms disponibili nel Drive…', 'info');
        try {
            const response = await fetch('ajax_list_google_forms.php', { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
            const data = await response.json();
            if (!response.ok || !data.success) {
                if (data.error_code === 'token_missing' || data.error_code === 'token_expired' || data.error_code === 'scope_missing') {
                    authorizationNotice?.classList.remove('d-none');
                }
                setStatus(data.error || 'Impossibile caricare i Google Forms.', 'warning');
                return;
            }
            forms = Array.isArray(data.forms) ? data.forms : [];
            catalogLoaded = true;
            setStatus(forms.length ? `${forms.length} Google Forms disponibili.` : 'Nessun Google Form trovato nel Drive.', forms.length ? 'success' : 'info');
            renderCatalog();
        } catch (error) {
            setStatus('Errore di comunicazione con il catalogo Google Forms.', 'danger');
        }
    }

    function initJsonLoader() {
        const input = document.getElementById('jsonFileLoader');
        const button = document.getElementById('loadJsonFileButton');
        const textarea = document.getElementById('jsonContent');
        if (!input || !button || !textarea) return;
        button.addEventListener('click', () => {
            const file = input.files?.[0];
            if (!file) {
                window.alert('Seleziona prima un file JSON.');
                return;
            }
            const reader = new FileReader();
            reader.onload = () => { textarea.value = String(reader.result || ''); textarea.dispatchEvent(new Event('input', { bubbles: true })); };
            reader.onerror = () => window.alert('Impossibile leggere il file JSON selezionato.');
            reader.readAsText(file, 'UTF-8');
        });
    }

    function previewField(card, name) {
        return card.querySelector(`[data-preview-field="${name}"]`);
    }

    function readPreviewState(card) {
        const value = name => previewField(card, name)?.value || '';
        return {
            argomento: value('argomento'),
            difficolta: value('difficolta') || '3',
            domanda: value('domanda'),
            tipo_domanda: value('tipo') === 'multipla' || value('tipo') === 'multipla_multi' ? 'multipla' : 'aperta',
            risposta_attesa: value('risposta_attesa'),
            parole_chiave: value('parole_chiave')
        };
    }

    function updatePreviewState(card, state) {
        const expected = state.tipo_domanda === 'multipla'
            ? UdaEditorUtils.serializeMultipleChoice(state.opzioni)
            : state.risposta_attesa;
        const values = {
            argomento: state.argomento,
            difficolta: state.difficolta,
            domanda: state.domanda,
            tipo: state.tipo_domanda,
            risposta_attesa: expected,
            parole_chiave: state.parole_chiave.join(',')
        };
        Object.entries(values).forEach(([name, value]) => {
            const field = previewField(card, name);
            if (field) field.value = value;
        });
        QuestionCard.update(card, Object.assign({}, state, { risposta_attesa: expected }), {
            label: card.querySelector('[data-question-card-label]')?.textContent || ''
        });
    }

    function updateImportCardState(input) {
        const card = input?.closest('[data-question-card]');
        if (!card) return;
        const selected = Boolean(input.checked);
        card.classList.toggle('is-unselected', !selected);
        card.setAttribute('aria-disabled', selected ? 'false' : 'true');
    }

    function setAllImportSelections(selected) {
        document.querySelectorAll('#question-preview-list [data-question-card-import]').forEach(input => {
            input.checked = Boolean(selected);
            updateImportCardState(input);
        });
    }

    function initImportSelection() {
        const selectAll = document.getElementById('selectAllImportQuestions');
        const deselectAll = document.getElementById('deselectAllImportQuestions');
        selectAll?.addEventListener('click', () => setAllImportSelections(true));
        deselectAll?.addEventListener('click', () => setAllImportSelections(false));
        document.querySelectorAll('#question-preview-list [data-question-card-import]').forEach(input => {
            input.addEventListener('change', () => updateImportCardState(input));
            updateImportCardState(input);
        });
    }

    function initPreviewEditor() {
        const editorRoot = document.getElementById('import-question-editor');
        const saveButton = document.getElementById('importQuestionEditSave');
        const modal = document.getElementById('importQuestionEditModal');
        if (!editorRoot || !saveButton || !modal || !window.QuestionEditor || !window.QuestionCard) return;
        const editor = QuestionEditor.mount(editorRoot);
        let targetCard = null;
        document.querySelectorAll('[data-question-card-index] [data-question-edit]').forEach(button => {
            button.addEventListener('click', event => {
                event.preventDefault();
                targetCard = button.closest('[data-question-card-index]');
                if (!targetCard) return;
                editorRoot.setEditorState(readPreviewState(targetCard));
                bootstrap.Modal.getOrCreateInstance(modal).show();
            });
        });
        saveButton.addEventListener('click', () => {
            if (!targetCard) return;
            const validation = editorRoot.validateEditor();
            if (!validation.valid) return;
            updatePreviewState(targetCard, editorRoot.getEditorState());
            bootstrap.Modal.getOrCreateInstance(modal).hide();
        });
    }

    window.loadGoogleFormsCatalog = loadFormsCatalog;
    window.setAllImportSelections = setAllImportSelections;
    window.selectExistingSource = window.selectExistingSource || function () {};
    catalogSearch?.addEventListener('input', renderCatalog);
    initJsonLoader();
    initImportSelection();
    initPreviewEditor();
})();
