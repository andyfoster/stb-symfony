import { Controller } from '@hotwired/stimulus';

/*
 * Listing form: shows the textbook fields only for the Textbooks category and
 * the condition only for things for sale, and keeps the "quality score" hint
 * up to date as the form is filled in.
 */
export default class extends Controller {
    static targets = ['category', 'book', 'type', 'conditionField', 'title', 'description', 'quality', 'qualityText'];
    static values = { textbooks: String };

    connect() {
        this.toggleBook();
        this.toggleCondition();
        this.score();
    }

    get wanted() {
        return this.hasTypeTarget && this.typeTarget.querySelector('input:checked')?.value === 'wanted';
    }

    toggleCondition() {
        if (!this.hasConditionFieldTarget) return;
        this.conditionFieldTarget.hidden = this.wanted;
        this.conditionFieldTarget.disabled = this.wanted;
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
        if (!this.wanted && !this.element.querySelector('.condition-input:checked')) missing.push('the condition');
        if (this.descriptionTarget.value.trim().length < 30) missing.push('a short description');

        const total = this.wanted ? 3 : 4;
        const points = total - missing.length;
        this.qualityTarget.classList.toggle('quality-done', !missing.length);
        this.qualityTextTarget.textContent = missing.length
            ? `${points}/${total}. Add ${this.list(missing)} to stand out.`
            : `${total}/${total}. Looking great!`;
    }

    list(items) {
        return items.length > 1 ? `${items.slice(0, -1).join(', ')} and ${items.at(-1)}` : items[0];
    }
}
