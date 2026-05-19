import { Controller } from '@hotwired/stimulus';

/**
 * Toggles visibility of conditional rows in the block styling sub-form.
 *
 * Currently: the `align-self` row is only meaningful when `max-width`
 * has a value — without it the block fills the column and the cross-axis
 * position has no visible effect. We hide the row by default and reveal
 * it as soon as the user types a max-width.
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
