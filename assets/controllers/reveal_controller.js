import { Controller } from '@hotwired/stimulus';

/*
 * Hides something (a phone number) behind a button, so it isn't in plain
 * view for scrapers.
 */
export default class extends Controller {
    static targets = ['button', 'content'];

    show() {
        this.contentTarget.hidden = false;
        this.buttonTarget.hidden = true;
        this.contentTarget.focus();
    }
}
