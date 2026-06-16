import { Controller } from '@hotwired/stimulus';

/**
 * Two-way sync between the slider and the editable number input rendered by
 * the cb_form_theme `range_widget` block.
 *
 * The number input is the submitted field, so the editor can type a value
 * finer than the slider's step grid (a range <input> can't hold a value off
 * its step). The slider is a visual aid that mirrors the number input and,
 * when manipulated, writes its (step-snapped) value back into it.
 *
 * Crucially, the number input is the *only* model-bound field: the block edit
 * form is a Live Component whose fields sync into their LiveProp on `change`
 * (and autosave only flushes the focused element). Setting `number.value`
 * programmatically fires no event, so a slider move would never reach the
 * server and the morph would revert it. We therefore re-dispatch the slider's
 * own `input`/`change` onto the number input — as if the user had typed there
 * — so Live's binding and cb-autosave both observe the new value.
 *
 * Manual typing is debounced here, locally. Each keystroke's `input` event
 * would otherwise bubble straight to cb-autosave, whose own (shorter) debounce
 * then flushes a `change` on the still-focused number input — clamping the
 * partial value, snapping the slider, and triggering a Live morph between two
 * keystrokes. That mid-typing commit is the "jump" that makes the field hard
 * to fill. So while the editor types, we stop the raw `input` from reaching
 * autosave and instead emit a single `change` once they pause for
 * `commitDelay` ms — collapsing a burst of keystrokes into one debounced save.
 * The slider drag path is untouched (it keeps its immediate commit-on-release).
 */
export default class extends Controller {
    static targets = ['slider', 'number'];

    static values = {
        /** Idle window (ms) after the last keystroke before a typed value commits. */
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

    // Number typed -> move the slider thumb and debounce the commit.
    //
    // `_mirrorToNumber` re-dispatches `input` here when the slider moves; that
    // path must stay live (it bubbles to autosave as before), so we only debounce
    // genuine keystrokes — never the slider's mirrored event (`_mirroring`).
    fromNumber(event) {
        this._toSlider();
        if (this._mirroring) return;
        // Keep the raw keystroke from reaching autosave; we commit on pause.
        event?.stopPropagation();
        clearTimeout(this._commitTimer);
        this._commitTimer = setTimeout(() => this._commit(), this.commitDelayValue);
    }

    // Debounce elapsed -> emit the `change` autosave (and clampNumber) wait for.
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
        // Flag the synthetic dispatch so the `input` action handler (fromNumber)
        // can tell a slider mirror from a real keystroke and skip the debounce.
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
