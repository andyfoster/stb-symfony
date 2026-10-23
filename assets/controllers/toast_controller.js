import { Controller } from '@hotwired/stimulus';
import { Toast } from 'bootstrap';

export default class extends Controller {
    static values = { delay: { type: Number, default: 5000 } };

    connect() {
        this.toast = new Toast(this.element, { delay: this.delayValue, animation: false });
        this.remove = () => this.element.remove();
        this.element.addEventListener('hidden.bs.toast', this.remove);
        this.toast.show();
    }

    disconnect() {
        this.element.removeEventListener('hidden.bs.toast', this.remove);
        this.toast.dispose();
    }

    close() { this.toast.hide(); }
}
