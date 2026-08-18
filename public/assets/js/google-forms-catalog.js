(function (root) {
    'use strict';

    // Helper condiviso per il catalogo Google Forms usato sia in import_questions.php
    // sia nello step 6 del wizard (uda_create.php): stesso rendering, stesso ordinamento
    // (pubblicati in Classroom in cima) e stessa formattazione data.

    function formatDate(value) {
        if (!value) return 'Data non disponibile';
        const date = new Date(value);
        return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('it-IT', { dateStyle: 'medium' }).format(date);
    }

    function renderItem(form) {
        const count = (form.response_count === null || form.response_count === undefined)
            ? 'risposte n/d'
            : form.response_count + ' risposte';
        return {
            title: form.title || 'Google Form senza titolo',
            metadata: (form.published_in_classroom ? 'Pubblicato in Classroom · ' : '')
                + count + ' · ' + (form.author || 'Autore n/d') + ' · ' + formatDate(form.created_at),
            highlight: !!form.published_in_classroom
        };
    }

    function sortPublishedFirst(forms) {
        return (Array.isArray(forms) ? forms : []).slice()
            .sort(function (a, b) { return (b.published_in_classroom ? 1 : 0) - (a.published_in_classroom ? 1 : 0); });
    }

    root.GoogleFormsCatalogHelper = {
        formatDate: formatDate,
        renderItem: renderItem,
        sortPublishedFirst: sortPublishedFirst
    };
}(typeof window !== 'undefined' ? window : globalThis));
