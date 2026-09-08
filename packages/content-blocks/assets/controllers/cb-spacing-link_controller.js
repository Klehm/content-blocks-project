import { Controller } from '@hotwired/stimulus';

/**
 * Syncs the four sides while linked. Engaged from the values' **uniformity**
 * on connect, not the persisted flag, so a mixed set starts unlinked.
 *
 * @see docs/internals/forms.md#the-responsive-styling-sub-types
 */
export default class extends Controller {
    static targets = ['input', 'toggle'];
    static values = { linked: Boolean };

    connect() {
        this._onInput = this._onInput.bind(this);
        this.inputTargets.forEach(i => i.addEventListener('input', this._onInput));
        // Engage the link only when the sides are uniform — never trust a stale
        // persisted flag against non-uniform values.
        this.linkedValue = this._isUniform();
        this._syncToggleUi();
        this._syncHiddenCheckbox();
    }

    disconnect() {
        this.inputTargets.forEach(i => i.removeEventListener('input', this._onInput));
    }

    toggle(event) {
        event.preventDefault();
        this.linkedValue = !this.linkedValue;
        this._syncToggleUi();
        this._syncHiddenCheckbox();
        // When activating, propagate the first input's value to all sides
        // so the user sees the link "take effect" immediately.
        if (this.linkedValue && this.inputTargets.length) {
            this._broadcast(this.inputTargets[0].value);
        }
    }

    _onInput(event) {
        if (!this.linkedValue) return;
        this._broadcast(event.target.value);
    }

    _broadcast(value) {
        this.inputTargets.forEach(i => {
            if (i.value !== value) {
                i.value = value;
                i.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
    }

    _syncToggleUi() {
        if (!this.hasToggleTarget) return;
        this.toggleTarget.setAttribute('aria-pressed', this.linkedValue ? 'true' : 'false');
        this.element.classList.toggle('cb-box-spacing--linked', this.linkedValue);
    }

    _syncHiddenCheckbox() {
        const cb = this.element.querySelector('.cb-box-spacing__linked-state input[type=checkbox]');
        if (cb) cb.checked = this.linkedValue;
    }

    /** All four empty, or all strictly equal. */
    _isUniform() {
        const vals = this.inputTargets.map(i => i.value.trim());
        return vals.every(v => v === vals[0]);
    }
}
