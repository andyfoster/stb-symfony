import { Controller } from '@hotwired/stimulus';

// Load the catalogue in background batches. Filtering never makes a request.
export default class extends Controller {
    static targets = ['query', 'category', 'type', 'item', 'count', 'note', 'summary', 'empty', 'grid', 'pagination', 'loading', 'retry'];
    static values = { url: String };

    connect() {
        this.next = this.urlValue;
        this.paginationTargets.forEach((nav) => { nav.hidden = true; });
        this.abort = new AbortController();
        this.load();
    }

    disconnect() {
        this.abort.abort();
    }

    async load() {
        if (this.busy || !this.next) return;
        this.busy = true;
        this.loadingTarget.hidden = false;
        this.retryTarget.hidden = true;
        try {
            while (this.next && !this.abort.signal.aborted) {
                const response = await fetch(this.next, { signal: this.abort.signal, headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error('Could not load listings');
                const batch = await response.json();
                const template = document.createElement('template');
                template.innerHTML = batch.html;
                const known = new Set(this.itemTargets.map((item) => item.dataset.listingId));
                for (const card of template.content.querySelectorAll('[data-listing-id]')) {
                    if (!known.has(card.dataset.listingId)) this.gridTarget.insertBefore(card, this.emptyTarget);
                }
                this.next = batch.next;
                this.apply();
            }
            // Initial server-filtered cards and background batches share one alphabetical list.
            this.itemTargets.sort((a, b) => a.querySelector('.card-link').textContent.localeCompare(b.querySelector('.card-link').textContent))
                .forEach((card) => this.gridTarget.insertBefore(card, this.emptyTarget));
        } catch (error) {
            if (error.name !== 'AbortError') this.retryTarget.hidden = false;
        } finally {
            this.busy = false;
            this.loadingTarget.hidden = true;
        }
    }

    search() {
        this.apply();
    }

    apply() {
        const query = this.queryTarget.value.trim().toLowerCase();
        const squashed = query.replace(/[\s-]+/g, '');
        const categories = this.categoryTargets.filter((box) => box.checked).map((box) => box.value);
        const type = this.typeTargets.find((radio) => radio.checked)?.value ?? 'for_sale';
        const counts = {};
        let shown = 0;
        for (const item of this.itemTargets) {
            const matches = (categories.length === 0 || categories.includes(item.dataset.category))
                && (query === '' || item.dataset.search.includes(query) || item.dataset.search.includes(squashed));
            counts[item.dataset.type] = (counts[item.dataset.type] ?? 0) + Number(matches);
            item.hidden = !(matches && item.dataset.type === type);
            if (!item.hidden) shown++;
        }
        this.countTargets.forEach((count) => { count.textContent = counts[count.dataset.type] ?? 0; });
        const narrowed = query !== '' || categories.length > 0;
        this.noteTarget.hidden = !narrowed;
        this.summaryTarget.textContent = `${shown} ${shown === 1 ? 'match' : 'matches'}${query ? ` for “${this.queryTarget.value.trim()}”` : ''}`;
        this.emptyTarget.hidden = shown > 0 || Boolean(this.next);
        this.emptyTarget.querySelectorAll('[data-empty]').forEach((message) => {
            message.hidden = message.dataset.empty !== (narrowed ? 'narrowed' : type);
        });
    }

    clear() {
        this.queryTarget.value = '';
        this.categoryTargets.forEach((box) => { box.checked = false; });
        this.apply();
        this.queryTarget.focus();
    }
}
