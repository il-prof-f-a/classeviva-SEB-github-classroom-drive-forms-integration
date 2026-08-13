(function (root, factory) {
    const api = factory();
    if (typeof module !== 'undefined' && module.exports) module.exports = api;
    root.UdaEditorUtils = api;
})(typeof window !== 'undefined' ? window : globalThis, function () {
    function lower(value) {
        return String(value ?? '').toLocaleLowerCase('it');
    }

    function normalizeKeywords(value) {
        const values = Array.isArray(value) ? value : String(value ?? '').split(/[,;]+/);
        const seen = new Set();
        return values.map(item => String(item ?? '').trim()).filter(item => {
            if (!item) return false;
            const key = lower(item);
            if (seen.has(key)) return false;
            seen.add(key);
            return true;
        });
    }

    function filterObjectives(catalog, query) {
        const needle = lower(String(query ?? '').trim());
        if (!needle) return Array.from(catalog || []);
        return (catalog || []).filter(objective => {
            const haystack = [objective.codice, objective.descrizione, objective.competenza, objective.parole_chiave]
                .map(value => String(value ?? '')).join(' ');
            return lower(haystack).includes(needle);
        });
    }

    function parseOptions(value) {
        if (Array.isArray(value)) return value.map(normalizeOption).filter(Boolean);
        const text = String(value ?? '').trim();
        if (!text) return [];
        try {
            const decoded = JSON.parse(text);
            const list = Array.isArray(decoded) ? decoded : decoded.risposte;
            if (Array.isArray(list)) return list.map(normalizeOption).filter(Boolean);
        } catch (_) { /* legacy delimiter format below */ }
        return text.split(/\s*\|\s*|\s*;\s*/).map(item => {
            const trimmed = item.trim();
            return normalizeOption({ testo: trimmed.replace(/\*$/, '').trim(), corretta: /\*$/.test(trimmed) });
        }).filter(Boolean);
    }

    function normalizeOption(option) {
        if (typeof option === 'string') return { testo: option.trim(), corretta: false };
        if (!option || typeof option !== 'object') return null;
        const testo = String(option.testo ?? option.text ?? '').trim();
        return testo ? { testo, corretta: Boolean(option.corretta ?? option.correct) } : null;
    }

    function validateMultiple(options) {
        const clean = (options || []).map(normalizeOption).filter(Boolean);
        if (clean.length < 2) return { valid: false, message: 'Inserisci almeno due opzioni.' };
        if (!clean.some(option => option.corretta)) return { valid: false, message: 'Seleziona almeno una risposta corretta.' };
        return { valid: true, options: clean };
    }

    function serializeMultipleChoice(options) {
        const validation = validateMultiple(options);
        if (!validation.valid) throw new Error(validation.message);
        return JSON.stringify({ risposte: validation.options });
    }

    return { normalizeKeywords, filterObjectives, parseOptions, validateMultiple, serializeMultipleChoice };
});
