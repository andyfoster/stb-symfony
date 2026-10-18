import { Controller } from '@hotwired/stimulus';

/*
 * Listing form: shows the textbook fields only for the Textbooks category.
 */
export default class extends Controller {
    static targets = ['category', 'book'];
    static values = { textbooks: String };

    connect() {
        this.toggleBook();
    }

    toggleBook() {
        if (!this.hasBookTarget || !this.hasCategoryTarget) return;
        const isBook = this.categoryTarget.value === this.textbooksValue;
        this.bookTarget.hidden = !isBook;
        this.bookTarget.disabled = !isBook;
    }
}
