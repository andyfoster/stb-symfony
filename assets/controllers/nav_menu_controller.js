import { Controller } from '@hotwired/stimulus';

/*
 * Closes the account menu (a <details>) on Escape or a click elsewhere.
 */
export default class extends Controller {
    closeOutside(event) {
        if (!this.element.contains(event.target)) this.close();
    }

    close() {
        this.element.open = false;
    }
}
