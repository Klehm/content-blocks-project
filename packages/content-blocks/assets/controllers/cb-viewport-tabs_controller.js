import { Controller } from '@hotwired/stimulus';

/**
 * Viewport switcher above a Responsive* widget. The form always submits all
 * three viewports whichever tab is showing, and the choice is not persisted.
 *
 * @see docs/internals/forms.md#the-responsive-styling-sub-types
 */
export default class extends Controller {
    static targets = ['tab'];
    static values = { active: { type: String, default: 'desktop' } };

    connect() {
        this._show(this.activeValue);
    }

    select(event) {
        event.preventDefault();
        const vp = event.currentTarget.dataset.viewport;
        if (!vp || vp === this.activeValue) return;
        this.activeValue = vp;
        this._show(vp);
    }

    _show(vp) {
        // Tabs aria-pressed state
        this.tabTargets.forEach(t => {
            t.setAttribute('aria-pressed', t.dataset.viewport === vp ? 'true' : 'false');
            t.classList.toggle('cb-viewport-tabs__btn--active', t.dataset.viewport === vp);
        });

        // The panes live in the row body, so search from the closest row
        // holding both the tabs and the widget.
        const row = this.element.closest('.cb-form-row');
        if (!row) return;
        row.querySelectorAll('[data-viewport]').forEach(node => {
            // Skip our own tab buttons (they also have data-viewport).
            if (this.tabTargets.includes(node)) return;
            node.hidden = node.dataset.viewport !== vp;
        });
    }
}
