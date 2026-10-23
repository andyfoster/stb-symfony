import { Controller } from '@hotwired/stimulus';

const CODE = /^[A-Z]{4}[0-9]{3}$/;

/*
 * Turns the comma-separated "papers" field into tags: type a code (or part
 * of a paper's name), pick a suggestion or press Enter, and it becomes a tag.
 * Codes that aren't in the list are fine too. The original input stays the
 * one that's submitted, so the form works the same without JavaScript.
 */
export default class extends Controller {
    static targets = ['input'];
    static values = { url: String };

    connect() {
        this.tags = this.inputTarget.value.split(/[,;]+/).map((code) => code.trim()).filter(Boolean);
        this.titles = {};
        this.inputTarget.hidden = true;

        this.box = document.createElement('div');
        this.box.className = 'tag-input form-control d-flex flex-wrap align-items-center gap-2 position-relative';
        this.box.innerHTML = `
            <ul class="tag-list list-unstyled d-flex flex-wrap gap-1 m-0"></ul>
            <input type="text" class="tag-entry form-control border-0 shadow-none p-0" autocomplete="off" role="combobox" aria-expanded="false"
                aria-autocomplete="list" aria-label="Add a paper" placeholder="${this.inputTarget.placeholder || ''}">
            <ul class="tag-suggestions list-group position-absolute w-100 shadow z-3" role="listbox" hidden></ul>`;
        this.inputTarget.after(this.box);

        this.list = this.box.querySelector('.tag-list');
        this.entry = this.box.querySelector('.tag-entry');
        this.suggestions = this.box.querySelector('.tag-suggestions');
        this.suggestions.id = `${this.inputTarget.id}_suggestions`;
        this.entry.setAttribute('aria-controls', this.suggestions.id);
        this.entry.id = `${this.inputTarget.id}_entry`;
        this.element.querySelector(`label[for="${this.inputTarget.id}"]`)?.setAttribute('for', this.entry.id);

        this.entry.addEventListener('input', () => this.typed());
        this.entry.addEventListener('keydown', (event) => this.keydown(event));
        this.entry.addEventListener('blur', () => setTimeout(() => this.commitTyped(), 150));
        this.box.addEventListener('click', (event) => {
            if (event.target === this.box) this.entry.focus();
        });

        this.render();
    }

    disconnect() {
        clearTimeout(this.timer);
        this.box?.remove();
        this.inputTarget.hidden = false;
    }

    keydown(event) {
        const options = [...this.suggestions.querySelectorAll('[role=option]')];
        const active = options.indexOf(this.suggestions.querySelector('[aria-selected=true]'));

        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            if (!options.length) return;
            event.preventDefault();
            const next = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach((option, i) => {
                option.setAttribute('aria-selected', String(i === next));
                option.classList.toggle('active', i === next);
            });
            this.entry.setAttribute('aria-activedescendant', options[next].id);
        } else if (event.key === 'Enter' || event.key === ',' || event.key === ';') {
            if (!this.entry.value.trim() && event.key === 'Enter') return;
            event.preventDefault();
            this.add(active >= 0 ? options[active].dataset.code : this.entry.value);
        } else if (event.key === 'Backspace' && !this.entry.value && this.tags.length) {
            this.remove(this.tags.length - 1);
        } else if (event.key === 'Escape') {
            this.hideSuggestions();
        }
    }

    // Pasted or typed separators ("CHEM191, CHEM192") turn into tags straight away.
    typed() {
        const parts = this.entry.value.split(/[,;&]+/);
        if (parts.length > 1) {
            const rest = parts.pop();
            parts.forEach((part) => this.add(part));
            this.entry.value = rest;
        }
        this.suggest();
    }

    commitTyped() {
        if (this.entry.value.trim()) this.add(this.entry.value);
        this.hideSuggestions();
    }

    add(raw) {
        const code = raw.toUpperCase().replace(/[\s\-_.]+/g, '');
        this.entry.value = '';
        this.hideSuggestions();
        if (!code || this.tags.includes(code)) return;
        this.tags.push(code);
        this.render();
    }

    remove(index) {
        this.tags.splice(index, 1);
        this.render();
        this.entry.focus();
    }

    render() {
        this.list.replaceChildren(...this.tags.map((code, index) => {
            const item = document.createElement('li');
            item.className = CODE.test(code) ? 'tag badge text-bg-primary d-inline-flex align-items-center' : 'tag tag-invalid badge text-bg-danger d-inline-flex align-items-center';
            item.title = this.titles[code] ?? (CODE.test(code) ? '' : 'Paper codes look like CHEM191');
            item.textContent = code;
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'tag-remove';
            button.setAttribute('aria-label', `Remove ${code}`);
            button.textContent = '×';
            button.addEventListener('click', () => this.remove(index));
            item.append(button);
            return item;
        }));
        this.inputTarget.value = this.tags.join(', ');
        this.entry.placeholder = this.tags.length ? 'Add another' : (this.inputTarget.placeholder || '');
    }

    suggest() {
        clearTimeout(this.timer);
        const term = this.entry.value.trim();
        if (term.length < 2) {
            this.hideSuggestions();
            return;
        }

        this.timer = setTimeout(async () => {
            try {
                const response = await fetch(`${this.urlValue}?${new URLSearchParams({ q: term })}`, { headers: { Accept: 'application/json' } });
                const papers = (await response.json()).filter((paper) => !this.tags.includes(paper.code));
                this.showSuggestions(papers);
            } catch {
                this.hideSuggestions();
            }
        }, 150);
    }

    showSuggestions(papers) {
        this.suggestions.replaceChildren(...papers.map((paper, i) => {
            this.titles[paper.code] = paper.title ?? '';
            const option = document.createElement('li');
            option.id = `${this.suggestions.id}_${i}`;
            option.className = 'list-group-item list-group-item-action d-flex gap-2 small';
            option.setAttribute('role', 'option');
            option.setAttribute('aria-selected', 'false');
            option.dataset.code = paper.code;
            option.innerHTML = '<strong></strong> <span class="text-truncate"></span>';
            option.querySelector('strong').textContent = paper.code;
            option.querySelector('span').textContent = paper.title ?? '';
            option.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this.add(paper.code);
            });
            return option;
        }));
        this.suggestions.hidden = !papers.length;
        this.entry.setAttribute('aria-expanded', String(papers.length > 0));
    }

    hideSuggestions() {
        this.suggestions.hidden = true;
        this.entry.setAttribute('aria-expanded', 'false');
        this.entry.removeAttribute('aria-activedescendant');
    }
}
