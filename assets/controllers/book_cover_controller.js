import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
    static targets = ['image'];

    connect() {
        if (this.imageTarget.complete) {
            this.imageTarget.naturalWidth ? this.loaded() : this.failed();
        }
    }

    loaded() {
        this.element.classList.add('has-cover');
    }

    failed() {
        this.element.classList.remove('has-cover');
        this.imageTarget.hidden = true;
        this.element.removeAttribute('title');
    }
}
