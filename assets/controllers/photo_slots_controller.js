import { Controller } from '@hotwired/stimulus';

/*
 * Turns the multiple file input into drop slots with previews. Each pick or
 * drop adds photos to the next free slots (a file input on its own would
 * replace the earlier ones), and each new photo can be removed again. The
 * chosen files are put back on the input with DataTransfer, so the form still
 * posts a normal multipart upload, and works without JavaScript.
 */
export default class extends Controller {
    static targets = ['input', 'slot'];
    static values = { max: Number, existing: Number };

    connect() {
        this.files = new DataTransfer();
        this.urls = [];
        this.inputTarget.classList.add('visually-hidden');
        this.inputTarget.addEventListener('change', this.picked);
    }

    disconnect() {
        this.inputTarget.removeEventListener('change', this.picked);
        this.urls.forEach((url) => URL.revokeObjectURL(url));
    }

    // The file dialog only knows about this pick, so add it to what's chosen already.
    picked = () => {
        const picked = [...this.inputTarget.files];
        this.add(picked);
    };

    // Ticking "Remove" on an existing photo frees a slot.
    preview() {
        this.add([]);
    }

    dragOver(event) {
        event.preventDefault();
        event.currentTarget.classList.add('photo-slot-over');
    }

    dragLeave(event) {
        event.currentTarget.classList.remove('photo-slot-over');
    }

    drop(event) {
        event.preventDefault();
        event.currentTarget.classList.remove('photo-slot-over');
        this.add([...event.dataTransfer.files]);
    }

    remove(event) {
        event.preventDefault();
        event.stopPropagation();
        const keep = [...this.files.files].filter((_, i) => i !== Number(event.params.index));
        this.files = new DataTransfer();
        keep.forEach((file) => this.files.items.add(file));
        this.add([]);
    }

    add(newFiles) {
        const room = Math.min(this.slotTargets.length, this.maxValue - this.keptCount());
        const chosen = [...this.files.files];
        for (const file of newFiles) {
            if (!file.type.startsWith('image/')) continue;
            if (chosen.some((f) => f.name === file.name && f.size === file.size)) continue;
            chosen.push(file);
        }

        this.files = new DataTransfer();
        chosen.slice(0, Math.max(room, 0)).forEach((file) => this.files.items.add(file));
        this.inputTarget.files = this.files.files;
        this.render(chosen.length > room);
    }

    keptCount() {
        let kept = this.existingValue;
        this.element.querySelectorAll('input[type=checkbox]').forEach((box) => {
            box.closest('.photo-slot')?.classList.toggle('photo-slot-removed', box.checked);
            if (box.checked) kept -= 1;
        });
        return kept;
    }

    render(tooMany) {
        this.urls.forEach((url) => URL.revokeObjectURL(url));
        this.urls = [];
        const files = [...this.files.files];

        this.slotTargets.forEach((slot, i) => {
            const file = files[i];
            slot.classList.toggle('photo-slot-filled', Boolean(file));
            slot.classList.toggle('photo-slot-empty', !file);
            if (file) {
                const url = URL.createObjectURL(file);
                this.urls.push(url);
                slot.innerHTML = `<img src="${url}" alt="">
                    <button type="button" class="photo-remove photo-remove-new" data-action="photo-slots#remove"
                        data-photo-slots-index-param="${i}" aria-label="Remove this photo">Remove</button>`;
            } else {
                slot.innerHTML = '<span>Add photo</span>';
            }
        });

        this.element.querySelector('.photo-limit')?.remove();
        if (tooMany) {
            this.slotTargets.at(-1)?.closest('.photo-slots')?.insertAdjacentHTML('afterend',
                `<p class="form-help photo-limit">You can add up to ${this.maxValue} photos, so the rest were left out.</p>`);
        }

        this.dispatch('changed');
    }
}
