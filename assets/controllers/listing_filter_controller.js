import { Controller } from '@hotwired/stimulus';

// Submit the complete filter to the server; it searches all listings, not just this page.
export default class extends Controller {
    disconnect() {
        clearTimeout(this.timer);
    }

    search() {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.submit(), 500);
    }

    change() {
        this.submit();
    }

    apply() {
        clearTimeout(this.timer);
    }

    submit() {
        clearTimeout(this.timer);
        this.element.querySelector('form').requestSubmit();
    }
}
