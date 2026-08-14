(function (root, factory) {
    const api = factory();
    if (typeof module === 'object' && module.exports) {
        module.exports = api;
    }
    if (root) {
        root.CatalogPicker = api.CatalogPicker;
        root.CatalogPickerUtils = {
            filterItems: api.filterItems,
            getSelectedItem: api.getSelectedItem
        };
    }
}(typeof window !== 'undefined' ? window : (typeof globalThis !== 'undefined' ? globalThis : this), function () {
    'use strict';

    function text(value) {
        return String(value ?? '');
    }

    function filterItems(items, query) {
        const source = Array.isArray(items) ? items : [];
        const needle = text(query).trim().toLocaleLowerCase('it');
        if (!needle) return source.slice();
        return source.filter(item => [
            item?.title,
            item?.name,
            item?.author,
            item?.created_at,
            item?.description,
            item?.slug,
            item?.state,
            item?.due_date,
            item?.update_time
        ].map(text).join(' ').toLocaleLowerCase('it').includes(needle));
    }

    function getSelectedItem(items, id) {
        const wanted = text(id);
        return (Array.isArray(items) ? items : []).find(item => text(item?.id) === wanted) || null;
    }

    class CatalogPicker {
        constructor(options) {
            this.options = options || {};
            this.items = [];
            this.searchInput = this.resolve(this.options.searchInput);
            this.listContainer = this.resolve(this.options.listContainer);
            this.statusContainer = this.resolve(this.options.statusContainer);
            this.selectedId = '';
            if (this.searchInput) {
                this.searchInput.addEventListener('input', () => this.render());
            }
        }

        resolve(value) {
            if (!value || typeof document === 'undefined') return value || null;
            return typeof value === 'string' ? document.querySelector(value) : value;
        }

        setItems(items) {
            this.items = Array.isArray(items) ? items.slice() : [];
            this.selectedId = '';
            this.render();
        }

        setStatus(message, type) {
            if (!this.statusContainer) return;
            this.statusContainer.className = `alert alert-${type || 'info'} mt-3 mb-2`;
            this.statusContainer.textContent = text(message);
            this.statusContainer.classList.toggle('d-none', !message);
        }

        setLoading(message) {
            this.setStatus(message || 'Caricamento...', 'info');
            if (this.listContainer) this.listContainer.replaceChildren();
        }

        async load() {
            if (typeof this.options.loadItems !== 'function') return this.items;
            this.setLoading(this.options.loadingMessage || 'Caricamento...');
            try {
                const items = await this.options.loadItems();
                this.setItems(items);
                this.setStatus(this.items.length ? `${this.items.length} elementi disponibili.` : 'Nessun elemento disponibile.', this.items.length ? 'success' : 'info');
                return this.items;
            } catch (error) {
                this.setItems([]);
                this.setStatus(error?.message || 'Impossibile caricare il catalogo.', 'warning');
                throw error;
            }
        }

        render() {
            if (!this.listContainer || typeof document === 'undefined') return;
            const filtered = filterItems(this.items, this.searchInput?.value || '');
            this.listContainer.replaceChildren();
            if (!filtered.length) {
                const empty = document.createElement('div');
                empty.className = 'list-group-item text-muted';
                empty.textContent = this.options.emptyMessage || 'Nessun elemento corrisponde alla ricerca.';
                this.listContainer.appendChild(empty);
                return;
            }
            filtered.forEach(item => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'list-group-item list-group-item-action';
                button.setAttribute('role', 'option');
                if (text(item?.id) === this.selectedId) button.classList.add('active');
                const rendered = typeof this.options.renderItem === 'function'
                    ? this.options.renderItem(item)
                    : { title: item?.title || item?.name || 'Elemento', metadata: '' };
                const title = document.createElement('div');
                title.className = 'fw-semibold';
                title.textContent = text(rendered?.title);
                button.appendChild(title);
                if (rendered?.metadata) {
                    const metadata = document.createElement('small');
                    metadata.className = 'text-muted d-block';
                    metadata.textContent = text(rendered.metadata);
                    button.appendChild(metadata);
                }
                button.addEventListener('click', () => {
                    this.selectedId = text(item?.id);
                    this.render();
                    if (typeof this.options.onSelect === 'function') this.options.onSelect(item);
                });
                this.listContainer.appendChild(button);
            });
        }
    }

    return { CatalogPicker, filterItems, getSelectedItem };
}));
