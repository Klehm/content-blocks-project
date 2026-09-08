import { Controller } from '@hotwired/stimulus';

/**
 * Reveals the `align-self` row once `max-width` has a value — without one the
 * block fills its column and the cross-axis position does nothing.
 */
export default class extends Controller {
    static targets = ['alignSelfRow'];

    connect() {
        this._onInput = this._onInput.bind(this);
        this.element.addEventListener('input', this._onInput);
        this.element.addEventListener('change', this._onInput);
        this._sync();
    }

    disconnect() {
        this.element.removeEventListener('input', this._onInput);
        this.element.removeEventListener('change', this._onInput);
    }

    _onInput(event) {
        const name = event.target?.name;
        if (typeof name === 'string' && name.endsWith('[maxWidth][value]')) {
            this._sync();
        }
    }

    _sync() {
        if (!this.hasAlignSelfRowTarget) return;
        const input = this.element.querySelector('input[name$="[maxWidth][value]"]');
        const raw = (input?.value ?? '').trim();
        const num = raw === '' ? 0 : Number(raw);
        this.alignSelfRowTarget.hidden = !(Number.isFinite(num) && num > 0);
    }
}
