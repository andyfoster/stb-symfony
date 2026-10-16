import { Controller } from '@hotwired/stimulus';

/*
 * Submits the listing filters as they change. The form targets a Turbo Frame,
 * so only the results reload and the search box keeps focus.
 */
export default class extends Controller {
    disconnect() {
        clearTimeout(this.timeout);
    }

    submit() {
        clearTimeout(this.timeout);
        this.element.requestSubmit();
    }

    debouncedSubmit() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.element.requestSubmit(), 300);
    }
}
