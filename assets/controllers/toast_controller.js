import { Controller } from '@hotwired/stimulus';

/*
 * A flash message shown as a toast: it slides in at the bottom left and goes
 * away by itself (sooner for good news than for errors). Hovering or focusing
 * it keeps it up; the × closes it straight away.
 */
export default class extends Controller {
    static values = { delay: { type: Number, default: 5000 } };

    connect() {
        this.start();
        this.element.addEventListener('mouseenter', this.pause);
        this.element.addEventListener('focusin', this.pause);
        this.element.addEventListener('mouseleave', this.start);
        this.element.addEventListener('focusout', this.start);
    }

    disconnect() {
        clearTimeout(this.timer);
    }

    pause = () => clearTimeout(this.timer);

    start = () => {
        clearTimeout(this.timer);
        this.timer = setTimeout(() => this.close(), this.delayValue);
    };

    close() {
        clearTimeout(this.timer);
        this.element.classList.add('toast-leaving');
        const remove = () => this.element.remove();
        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
            remove();
        } else {
            this.element.addEventListener('animationend', remove, { once: true });
        }
    }
}
