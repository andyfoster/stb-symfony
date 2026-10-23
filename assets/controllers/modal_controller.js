import { Controller } from '@hotwired/stimulus';
import { Modal } from 'bootstrap';

export default class extends Controller {
    static targets = ['dialog'];
    static values = { open: Boolean };

    connect() {
        this.modal = new Modal(this.dialogTarget);
        this.focusForm = () => this.dialogTarget.querySelector('textarea, input:not([type=hidden]):not([readonly]):not([disabled])')?.focus();
        this.dialogTarget.addEventListener('shown.bs.modal', this.focusForm);
        this.beforeCache = () => this.modal.hide();
        document.addEventListener('turbo:before-cache', this.beforeCache);
        if (this.openValue) this.open();
    }

    disconnect() {
        document.removeEventListener('turbo:before-cache', this.beforeCache);
        this.dialogTarget.removeEventListener('shown.bs.modal', this.focusForm);
        this.modal.hide();
        this.modal.dispose();
    }

    open() { this.modal.show(); }
    close() { this.modal.hide(); }
}
