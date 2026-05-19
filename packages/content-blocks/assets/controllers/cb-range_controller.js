import { Controller } from '@hotwired/stimulus';

/**
 * Live numeric readout for `<input type="range">` rendered via the
 * cb_form_theme `range_widget` block.
 *
 * The widget renders an <output> element next to the slider; this
 * controller keeps its text in sync with the slider value as the user
 * drags. Browsers don't auto-bind <output> to <input type="range">.
 */
export default class extends Controller {
    static targets = ['input', 'output'];

    connect() {
        this._sync();
    }

    update() {
        this._sync();
    }

    _sync() {
        if (!this.hasInputTarget || !this.hasOutputTarget) return;
        this.outputTarget.textContent = this.inputTarget.value;
    }
}
