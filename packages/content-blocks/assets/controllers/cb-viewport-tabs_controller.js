import { Controller } from '@hotwired/stimulus';

/**
 * Viewport switcher above a Responsive* form widget. Clicking a tab shows
 * the matching `[data-viewport]` child in the controller's `next-sibling`
 * widget area and hides the others — the form always submits all three
 * viewports' values regardless of which tab is visible.
 *
 * The active viewport is a UI-only state, not persisted.
 *
 * Targets:
 *   - tab: each viewport button (D / T / M); reads `data-viewport`
 */
export default class extends Controller {
    static targets = ['tab'];
    static values = { active: { type: String, default: 'd' } };

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

        // The widget area sits in the row body — search for viewport panes
        // within the closest form row that contains both the tabs and the
        // widget.
        const row = this.element.closest('.cb-form-row');
        if (!row) return;
        row.querySelectorAll('[data-viewport]').forEach(node => {
            // Skip our own tab buttons (they also have data-viewport).
            if (this.tabTargets.includes(node)) return;
            node.hidden = node.dataset.viewport !== vp;
        });
    }
}
