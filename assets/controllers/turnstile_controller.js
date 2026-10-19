import { Controller } from '@hotwired/stimulus';

const SCRIPT_URL = 'https://challenges.cloudflare.com/turnstile/v0/api.js?render=explicit';
let scriptPromise;

function loadTurnstile() {
    scriptPromise ??= new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = SCRIPT_URL;
        script.async = true;
        script.onload = () => resolve(window.turnstile);
        script.onerror = reject;
        document.head.appendChild(script);
    });
    return scriptPromise;
}

/*
 * Cloudflare Turnstile, rendered explicitly so it works with Turbo page
 * changes. The widget posts its token under our form field's name.
 */
export default class extends Controller {
    static values = { siteKey: String, fieldName: String };

    connect() {
        // Inside a closed popup, wait until it opens: the widget needs to be visible to render.
        const dialog = this.element.closest('dialog');
        if (dialog && !dialog.open) {
            this.observer = new MutationObserver(() => {
                if (dialog.open) {
                    this.observer.disconnect();
                    this.render();
                }
            });
            this.observer.observe(dialog, { attributes: true, attributeFilter: ['open'] });
        } else {
            this.render();
        }
    }

    async render() {
        if (this.widgetId !== undefined) return;
        const turnstile = await loadTurnstile();
        if (!this.element.isConnected) return;
        this.widgetId = turnstile.render(this.element, {
            sitekey: this.siteKeyValue,
            'response-field-name': this.fieldNameValue,
            theme: 'light',
        });
    }

    disconnect() {
        this.observer?.disconnect();
        if (this.widgetId !== undefined) window.turnstile?.remove(this.widgetId);
    }
}
