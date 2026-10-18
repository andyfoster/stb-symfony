import { Controller } from '@hotwired/stimulus';

/*
 * Turns the multiple file input into drop slots with previews. Files dropped
 * on a slot are added to the input with DataTransfer, so the form still posts
 * a normal multipart upload (and works without JavaScript).
 */
export default class extends Controller {
    static targets = ['input', 'slot'];
    static values = { max: Number, existing: Number };

    connect() {
        this.files = new DataTransfer();
        this.urls = [];
        this.inputTarget.classList.add('visually-hidden');
    }

    disconnect() {
        this.urls.forEach((url) => URL.revokeObjectURL(url));
    }

    // Files picked with the file dialog (replaces what the input holds).
    preview() {
        this.files = new DataTransfer();
        this.add(this.inputTarget.files);
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
        this.add(event.dataTransfer.files);
    }

    add(fileList) {
        const room = this.maxValue - this.keptCount();
        for (const file of fileList) {
            if (!file.type.startsWith('image/') || this.files.files.length >= room) continue;
            if (![...this.files.files].some((f) => f.name === file.name && f.size === file.size)) {
                this.files.items.add(file);
            }
        }
        this.inputTarget.files = this.files.files;
        this.render();
    }

    keptCount() {
        const removed = this.element.querySelectorAll('input[type=checkbox]:checked').length;
        this.element.querySelectorAll('input[type=checkbox]').forEach((box) => {
            box.closest('.photo-slot')?.classList.toggle('photo-slot-removed', box.checked);
        });
        return this.existingValue - removed;
    }

    render() {
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
                slot.innerHTML = `<img src="${url}" alt="">`;
            } else {
                slot.innerHTML = '<span>Drop photo here</span>';
            }
        });

        this.dispatch('changed');
    }
}
