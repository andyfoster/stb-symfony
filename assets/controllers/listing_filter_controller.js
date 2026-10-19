import { Controller } from '@hotwired/stimulus';

/*
 * Filters the home page's listings in the browser: the search box, the
 * "For sale" / "Wanted" tabs and the category pills show and hide cards that
 * are already on the page. Nothing reloads and the address bar stays as it is.
 * The rules match ListingFilter::matches() on the server, which handles links
 * with filters in them and browsers without JavaScript.
 */
export default class extends Controller {
    static targets = ['query', 'category', 'type', 'item', 'count', 'note', 'summary', 'empty'];

    disconnect() {
        clearTimeout(this.timer);
    }

    // Typing filters as you go, after a short pause.
    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.apply(), 120);
    }

    apply() {
        clearTimeout(this.timer);
        const query = this.queryTarget.value.trim().toLowerCase();
        const squashed = query.replace(/[\s-]+/g, '');
        const categories = this.categoryTargets.filter((box) => box.checked).map((box) => box.value);
        const type = this.typeTargets.find((radio) => radio.checked)?.value ?? 'for_sale';

        const counts = {};
        let shown = 0;
        for (const item of this.itemTargets) {
            const text = item.dataset.search;
            const matches = (categories.length === 0 || categories.includes(item.dataset.category))
                && (query === '' || text.includes(query) || text.includes(squashed));
            counts[item.dataset.type] = (counts[item.dataset.type] ?? 0) + (matches ? 1 : 0);
            const visible = matches && item.dataset.type === type;
            item.hidden = !visible;
            if (visible) shown += 1;
        }

        this.countTargets.forEach((count) => {
            count.textContent = counts[count.dataset.type] ?? 0;
        });

        const narrowed = query !== '' || categories.length > 0;
        this.noteTarget.hidden = !narrowed;
        this.summaryTarget.textContent = `${shown} ${shown === 1 ? 'match' : 'matches'}${query ? ` for “${this.queryTarget.value.trim()}”` : ''}`;

        this.emptyTarget.hidden = shown > 0;
        const reason = narrowed ? 'narrowed' : type;
        this.emptyTarget.querySelectorAll('[data-empty]').forEach((message) => {
            message.hidden = message.dataset.empty !== reason;
        });
    }

    clear() {
        this.queryTarget.value = '';
        this.categoryTargets.forEach((box) => {
            box.checked = false;
        });
        this.apply();
        this.queryTarget.focus();
    }
}
