import { Controller } from '@hotwired/stimulus';

const KEY = 'stb-listing-view';

/*
 * Switches the listings between rows and a grid of cards, and remembers the
 * choice in this browser. The buttons are inside the results frame, so they
 * get their pressed state again whenever the frame reloads.
 */
export default class extends Controller {
    static targets = ['button'];

    connect() {
        let view = 'list';
        try {
            view = localStorage.getItem(KEY) || 'list';
        } catch {
            // Storage can be unavailable (private windows); default to the list.
        }
        this.show(view);
    }

    choose({ params: { view } }) {
        this.show(view);
        try {
            localStorage.setItem(KEY, view);
        } catch {
            // Not remembered, but still switched.
        }
    }

    buttonTargetConnected(button) {
        const active = button.dataset.viewToggleViewParam === this.view;
        button.setAttribute('aria-pressed', String(active));
        button.classList.toggle('active', active);
    }

    show(view) {
        this.view = view === 'grid' ? 'grid' : 'list';
        this.element.classList.toggle('view-grid', this.view === 'grid');
        const grid = this.element.querySelector('.listing-grid');
        grid.classList.toggle('row-cols-md-2', this.view === 'grid');
        grid.classList.toggle('row-cols-lg-3', this.view === 'grid');
        this.buttonTargets.forEach((button) => this.buttonTargetConnected(button));
    }
}
