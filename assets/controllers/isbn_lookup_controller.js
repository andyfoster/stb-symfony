import { Controller } from '@hotwired/stimulus';

/*
 * "Look up" next to the ISBN field: asks our server (which asks Open Library,
 * then Google Books) and fills in the title, author and edition if empty.
 */
export default class extends Controller {
    static targets = ['isbn', 'author', 'edition', 'year', 'button', 'status'];
    static values = { url: String };

    async lookup() {
        const isbn = this.isbnTarget.value.trim();
        if (!isbn) {
            this.show('Type the ISBN first.', true);
            this.isbnTarget.focus();
            return;
        }

        this.buttonTarget.disabled = true;
        this.show('Looking it up…');

        try {
            const response = await fetch(`${this.urlValue}?${new URLSearchParams({ isbn })}`, {
                headers: { Accept: 'application/json' },
            });
            const data = await response.json();
            if (!response.ok) {
                this.show(data.error ?? 'Lookup failed. Fill the details in yourself.', true);
                return;
            }

            const title = this.element.closest('form').querySelector('[data-listing-form-target="title"]');
            this.fill(title, data.title);
            this.fill(this.authorTarget, data.author);
            this.isbnTarget.value = data.isbn;
            this.fill(this.editionTarget, data.edition);
            this.fill(this.yearTarget, data.year);
            title?.dispatchEvent(new Event('input', { bubbles: true }));

            const by = data.author ? ` by ${data.author}` : '';
            this.show(`Found: ${data.title}${by}${data.year ? ` (${data.year})` : ''}. Check the details below.`);
        } catch {
            this.show('Lookup failed. Fill the details in yourself.', true);
        } finally {
            this.buttonTarget.disabled = false;
        }
    }

    // Only fill empty fields, so we never overwrite what the seller typed.
    fill(input, value) {
        if (input && value && !input.value.trim()) input.value = value;
    }

    show(message, isError = false) {
        this.statusTarget.textContent = message;
        this.statusTarget.classList.toggle('lookup-error', isError);
    }
}
