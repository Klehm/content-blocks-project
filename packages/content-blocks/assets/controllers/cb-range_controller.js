import { Controller } from '@hotwired/stimulus';

/**
 * Two-way sync between the slider and the number input, which is the submitted
 * and only model-bound field — so a slider move is re-dispatched onto it.
 *
 * @see docs/internals/frontend.md#why-the-range-field-debounces-locally
 */
export default class extends Controller {
    static targets = ['slider', 'number'];

    static values = {
        /** Idle window (ms) before a typed value commits. */
        commitDelay: { type: Number, default: 400 },
    };

    connect() {
        // The slider may have rendered without a value attribute when the
        // field is empty; reflect the number's initial value onto it.
        this._toSlider();
    }

    disconnect() {
        clearTimeout(this._commitTimer);
    }

    // Slider dragged -> mirror its value onto the number input (live).
    fromSlider() {
        this._mirrorToNumber('input');
    }

    // Slider released (commit) -> mirror and flush so Live/autosave persist it.
    commitSlider() {
        // The release is the commit; drop any pending typed-value commit so it
        // can't fire a redundant `change` after the slider already saved.
        clearTimeout(this._commitTimer);
        this._mirrorToNumber('change');
    }

    // Only genuine keystrokes are debounced; the slider's own mirrored
    // event must stay live, hence `_mirroring`.
    fromNumber(event) {
        this._toSlider();
        if (this._mirroring) return;
        // Keep the raw keystroke from reaching autosave; we commit on pause.
        event?.stopPropagation();
        clearTimeout(this._commitTimer);
        this._commitTimer = setTimeout(() => this._commit(), this.commitDelayValue);
    }

    // Emit the `change` that autosave and clampNumber wait for.
    _commit() {
        if (!this.hasNumberTarget) return;
        this.numberTarget.dispatchEvent(new Event('change', { bubbles: true }));
    }

    // On commit (blur/Enter), clamp the typed value to the slider's bounds so
    // the submitted value can never fall outside [min, max].
    clampNumber() {
        // This `change` is itself the commit — drop any pending debounced one.
        clearTimeout(this._commitTimer);
        if (!this.hasNumberTarget) return;
        const raw = this.numberTarget.value;
        if (raw === '') return;
        let v = Number(raw);
        if (Number.isNaN(v)) return;
        const min = this._bound('min');
        const max = this._bound('max');
        if (min !== null && v < min) v = min;
        if (max !== null && v > max) v = max;
        this.numberTarget.value = String(v);
        this._toSlider();
    }

    // Copy the slider value into the number input, then re-emit the given
    // native event on the number so the framework treats it as a real edit.
    _mirrorToNumber(eventType) {
        if (!this.hasNumberTarget || !this.hasSliderTarget) return;
        this.numberTarget.value = this.sliderTarget.value;
        // So fromNumber can tell a slider mirror from a keystroke.
        this._mirroring = true;
        try {
            this.numberTarget.dispatchEvent(new Event(eventType, { bubbles: true }));
        } finally {
            this._mirroring = false;
        }
    }

    _toSlider() {
        if (!this.hasNumberTarget || !this.hasSliderTarget) return;
        const raw = this.numberTarget.value;
        if (raw !== '') this.sliderTarget.value = raw;
    }

    _bound(name) {
        if (!this.hasSliderTarget) return null;
        const raw = this.sliderTarget.getAttribute(name);
        if (raw === null || raw === '') return null;
        const n = Number(raw);
        return Number.isNaN(n) ? null : n;
    }
}
