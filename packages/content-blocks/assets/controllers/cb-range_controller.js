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
 */
export default class extends Controller {
    static targets = ['slider', 'number'];

    connect() {
        // The slider may have rendered without a value attribute when the
        // field is empty; reflect the number's initial value onto it.
        this._toSlider();
    }

    // Slider dragged -> mirror its value onto the number input (live).
    fromSlider() {
        this._mirrorToNumber('input');
    }

    // Slider released (commit) -> mirror and flush so Live/autosave persist it.
    commitSlider() {
        this._mirrorToNumber('change');
    }

    // Number typed -> move the slider thumb (it snaps to its own step grid).
    fromNumber() {
        this._toSlider();
    }

    // On commit (blur/Enter), clamp the typed value to the slider's bounds so
    // the submitted value can never fall outside [min, max].
    clampNumber() {
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
        this.numberTarget.dispatchEvent(new Event(eventType, { bubbles: true }));
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
