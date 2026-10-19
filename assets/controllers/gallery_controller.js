import { Controller } from '@hotwired/stimulus';

/*
 * Listing photos: clicking one opens it full size in a lightbox (a native
 * <dialog>), with previous/next buttons, arrow keys and Escape. Without
 * JavaScript the links still open the large photo.
 */
export default class extends Controller {
    static targets = ['item', 'dialog', 'image', 'count'];

    open(event) {
        event.preventDefault();
        this.show(event.params.index);
        this.dialogTarget.showModal();
    }

    close() {
        this.dialogTarget.close();
    }

    previous() {
        this.show(this.index - 1);
    }

    next() {
        this.show(this.index + 1);
    }

    key(event) {
        if (event.key === 'ArrowLeft') this.previous();
        if (event.key === 'ArrowRight') this.next();
    }

    // A click on the dark area around the photo closes it.
    backdrop(event) {
        if (event.target === this.dialogTarget) this.close();
    }

    show(index) {
        const count = this.itemTargets.length;
        this.index = (index + count) % count;
        const item = this.itemTargets[this.index];
        this.imageTarget.src = item.href;
        this.imageTarget.alt = item.querySelector('img')?.alt ?? '';
        if (this.hasCountTarget) this.countTarget.textContent = `${this.index + 1} of ${count}`;
    }
}
