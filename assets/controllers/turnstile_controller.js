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

    async connect() {
        const turnstile = await loadTurnstile();
        if (!this.element.isConnected) return;
        this.widgetId = turnstile.render(this.element, {
            sitekey: this.siteKeyValue,
            'response-field-name': this.fieldNameValue,
            theme: 'light',
        });
    }

    disconnect() {
        if (this.widgetId !== undefined) window.turnstile?.remove(this.widgetId);
    }
}
