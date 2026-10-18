import { Controller } from '@hotwired/stimulus';

/*
 * Listing form: shows the textbook fields only for the Textbooks category, and
 * keeps the "quality score" hint up to date as the form is filled in.
 */
export default class extends Controller {
    static targets = ['category', 'book', 'title', 'condition', 'description', 'quality', 'qualityText'];
    static values = { textbooks: String };

    connect() {
        this.toggleBook();
        this.score();
    }

    toggleBook() {
        if (!this.hasBookTarget || !this.hasCategoryTarget) return;
        const isBook = this.categoryTarget.value === this.textbooksValue;
        this.bookTarget.hidden = !isBook;
        this.bookTarget.disabled = !isBook;
    }

    score() {
        if (!this.hasQualityTarget) return;

        const photos = this.element.querySelectorAll('.photo-slot-filled:not(.photo-slot-removed)').length;
        const missing = [];
        if (this.titleTarget.value.trim().length < 10) missing.push('a clear title');
        if (photos < 3) missing.push(photos ? `${3 - photos} more photo${photos === 2 ? '' : 's'}` : '3+ photos');
        if (this.hasConditionTarget && !this.conditionTarget.value) missing.push('condition');
        if (this.descriptionTarget.value.trim().length < 30) missing.push('a short description');

        const points = 4 - missing.length;
        this.qualityTarget.dataset.score = String(points);
        this.qualityTextTarget.textContent = missing.length
            ? `${points}/4. Add ${this.list(missing)} to stand out.`
            : '4/4. Looking great!';
    }

    list(items) {
        return items.length > 1 ? `${items.slice(0, -1).join(', ')} and ${items.at(-1)}` : items[0];
    }
}
