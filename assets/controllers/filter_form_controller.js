import { Controller } from '@hotwired/stimulus';

/*
 * Submits the listing filters as they change. The form targets a Turbo Frame,
 * so only the results reload and the search box keeps focus. The controller
 * sits on a wrapper so the tabs inside the results frame can use it too.
 */
export default class extends Controller {
    static targets = ['form'];

    disconnect() {
        clearTimeout(this.timeout);
    }

    submit() {
        clearTimeout(this.timeout);
        this.formTarget.requestSubmit();
    }

    debouncedSubmit() {
        clearTimeout(this.timeout);
        this.timeout = setTimeout(() => this.formTarget.requestSubmit(), 300);
    }
}
