import { Controller } from '@hotwired/stimulus';

/*
 * A <dialog> popup: open with a button, close with ×, Escape or a click on
 * the backdrop. With open-value true it opens on page load, e.g. when a form
 * inside it came back with errors.
 */
export default class extends Controller {
    static targets = ['dialog'];
    static values = { open: Boolean };

    connect() {
        if (this.openValue) this.open();
    }

    open() {
        this.dialogTarget.showModal();
        this.dialogTarget.querySelector('textarea, input:not([type=hidden]):not([readonly]):not([disabled])')?.focus();
    }

    close() {
        this.dialogTarget.close();
    }

    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }
}
