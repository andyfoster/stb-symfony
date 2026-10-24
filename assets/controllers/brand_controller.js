import { Controller } from '@hotwired/stimulus';

// Explicit keyframes survive Turbo moving the permanent wordmark between pages.
export default class extends Controller {
    connect() {
        this.update();
    }

    update() {
        const pale = document.body.dataset.home === 'true';
        if (this.element.classList.contains('brand-pale') === pale) return;

        const letters = [...this.element.querySelectorAll('.brand-remainder')];
        const before = letters.map((letter) => ({
            opacity: getComputedStyle(letter).opacity,
        }));
        this.animations?.forEach((animation) => animation.cancel());
        this.element.classList.toggle('brand-pale', pale);

        if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) return;

        this.animations = letters.map((letter, index) => letter.animate([
            before[index],
            {
                opacity: getComputedStyle(letter).opacity,
            },
        ], {
            duration: 600,
            easing: 'cubic-bezier(.22, 1, .36, 1)',
        }));
    }

    disconnect() {
        this.animations?.forEach((animation) => animation.cancel());
    }
}
