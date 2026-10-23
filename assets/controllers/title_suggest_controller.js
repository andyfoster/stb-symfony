import { Controller } from '@hotwired/stimulus';

/*
 * Suggests textbook titles people have listed before as you type the title.
 * Picking one fills in the title, switches the category to Textbooks and
 * fills the author if it's still empty. Typing your own title is fine too.
 */
export default class extends Controller {
    static targets = ['input', 'category', 'author'];
    static values = { url: String, textbooks: String };

    connect() {
        this.suggestions = document.createElement('ul');
        this.suggestions.className = 'tag-suggestions title-suggestions';
        this.suggestions.id = `${this.inputTarget.id}_suggestions`;
        this.suggestions.setAttribute('role', 'listbox');
        this.suggestions.hidden = true;
        this.inputTarget.parentElement.classList.add('has-suggestions');
        this.inputTarget.after(this.suggestions);

        this.inputTarget.setAttribute('autocomplete', 'off');
        this.inputTarget.setAttribute('role', 'combobox');
        this.inputTarget.setAttribute('aria-autocomplete', 'list');
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.setAttribute('aria-controls', this.suggestions.id);

        this.onInput = () => this.suggest();
        this.onKeydown = (event) => this.keydown(event);
        this.onBlur = () => setTimeout(() => this.hide(), 150);
        this.inputTarget.addEventListener('input', this.onInput);
        this.inputTarget.addEventListener('keydown', this.onKeydown);
        this.inputTarget.addEventListener('blur', this.onBlur);
    }

    disconnect() {
        clearTimeout(this.timer);
        this.inputTarget.removeEventListener('input', this.onInput);
        this.inputTarget.removeEventListener('keydown', this.onKeydown);
        this.inputTarget.removeEventListener('blur', this.onBlur);
        this.suggestions?.remove();
    }

    keydown(event) {
        const options = [...this.suggestions.querySelectorAll('[role=option]')];
        if (this.suggestions.hidden || !options.length) return;
        const active = options.indexOf(this.suggestions.querySelector('[aria-selected=true]'));

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault();
            const next = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach((option, i) => option.setAttribute('aria-selected', String(i === next)));
            this.inputTarget.setAttribute('aria-activedescendant', options[next].id);
        } else if (event.key === 'Enter' && active >= 0) {
            event.preventDefault();
            this.pick(this.books[active]);
        } else if (event.key === 'Escape') {
            this.hide();
        }
    }

    suggest() {
        clearTimeout(this.timer);
        const term = this.inputTarget.value.trim();
        if (term.length < 2) {
            this.hide();
            return;
        }

        this.timer = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?${new URLSearchParams({ q: term })}`, { headers: { Accept: 'application/json' } });
                if (!response.ok) throw new Error(response.statusText);
                this.show(await response.json());
            } catch {
                this.hide();
            }
        }, 200);
    }

    show(books) {
        // Nothing to add when the only suggestion is what's already typed.
        const typed = this.inputTarget.value.trim().toLowerCase();
        this.books = books.length === 1 && books[0].title.toLowerCase() === typed ? [] : books;

        this.suggestions.replaceChildren(...this.books.map((book, i) => {
            const option = document.createElement('li');
            option.id = `${this.suggestions.id}_${i}`;
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.innerHTML = '<strong></strong> <span></span>';
            option.querySelector('strong').textContent = book.title;
            option.querySelector('span').textContent = book.author;
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.pick(book);
            });
            return option;
        }));
        this.suggestions.hidden = !this.books.length;
        this.inputTarget.setAttribute('aria-expanded', String(this.books.length > 0));
        this.inputTarget.removeAttribute('aria-activedescendant');
    }

    hide() {
        this.suggestions.hidden = true;
        this.inputTarget.setAttribute('aria-expanded', 'false');
        this.inputTarget.removeAttribute('aria-activedescendant');
    }

    pick(book) {
        this.inputTarget.value = book.title;
        this.inputTarget.dispatchEvent(new Event('input', { bubbles: true }));
        clearTimeout(this.timer);
        this.hide();

        if (this.hasCategoryTarget && this.categoryTarget.value !== this.textbooksValue) {
            this.categoryTarget.value = this.textbooksValue;
            this.categoryTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
        if (this.hasAuthorTarget && !this.authorTarget.value.trim()) {
            this.authorTarget.value = book.author;
        }
    }
}
