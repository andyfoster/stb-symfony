import { Controller } from '@hotwired/stimulus';

// Selling and wanted listings share data, but ask different questions.
export default class extends Controller {
    static targets = ['category', 'book', 'type', 'conditionField', 'title', 'description',
        'titleLabel', 'priceLabel', 'priceHelp', 'descriptionLabel', 'photoPanel', 'photoTitle', 'publish'];
    static values = { textbooks: String };

    connect() {
        this.toggleBook();
        this.changeType();
    }

    get wanted() {
        return this.typeTarget.querySelector('input:checked')?.value === 'wanted';
    }

    changeType(event) {
        const wanted = this.wanted;
        this.element.classList.toggle('is-wanted', wanted);
        this.conditionFieldTarget.hidden = wanted;
        this.conditionFieldTarget.disabled = wanted;
        this.titleLabelTarget.textContent = wanted ? 'Looking for' : 'Title';
        this.titleTarget.placeholder = wanted ? 'e.g. A CHEM191 textbook for next semester' : 'e.g. CHEM191 textbook + lab manual';
        this.priceLabelTarget.textContent = wanted ? 'Budget (NZD)' : 'Price (NZD)';
        this.priceHelpTarget.textContent = wanted
            ? 'Leave blank if flexible.'
            : 'Leave blank for offers; 0 means free.';
        this.descriptionLabelTarget.textContent = wanted ? 'Requirements' : 'Description';
        this.descriptionTarget.placeholder = wanted
            ? 'Edition, acceptable condition, when you need it, or alternatives you’d consider.'
            : 'Any wear, what’s included, and where you can hand it over.';
        this.photoTitleTarget.textContent = wanted ? 'Reference photos' : 'Photos';
        if (event && !this.element.querySelector('.photo-slot-filled:not(.photo-slot-removed)')) {
            this.photoPanelTarget.open = !wanted;
        }
        if (this.publishTarget.dataset.editing !== 'true') {
            this.publishTarget.textContent = wanted ? 'Post wanted listing' : 'Publish listing';
        }
    }

    toggleBook() {
        const isBook = this.categoryTarget.value === this.textbooksValue;
        this.bookTargets.forEach((section) => {
            section.hidden = !isBook;
            section.disabled = !isBook;
        });
    }
}
