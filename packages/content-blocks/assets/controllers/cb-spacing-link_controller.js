import { Controller } from '@hotwired/stimulus';

/**
 * Box-spacing link toggle: when the link is active, editing any of the four
 * side inputs (top/right/bottom/left) syncs the other three.
 *
 * The link is only engaged when the four sides are "uniform" — all empty or
 * all strictly equal. It's derived from the current values on connect (not a
 * persisted flag), so loading a non-uniform set (e.g. 45 / 0 / 45 / 0) starts
 * unlinked and editing one side never overwrites the others. A uniform set
 * (e.g. 45 / 45 / 45 / 45, or all empty) starts linked. The button still lets
 * the user link (equalise the sides) or unlink on demand.
 *
 * The state is mirrored to a hidden `linked` checkbox so the server-side
 * BoxSpacingType keeps a value, but the authoritative source on load is the
 * values' uniformity.
 *
 * Targets:
 *   - input: the four IntegerType inputs (T/R/B/L)
 *   - toggle: the link button
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

    /** True when the four sides are uniform: all empty or all strictly equal. */
    _isUniform() {
        const vals = this.inputTargets.map(i => i.value.trim());
        return vals.every(v => v === vals[0]);
    }
}
