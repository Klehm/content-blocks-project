import { Controller } from '@hotwired/stimulus';

/**
 * Tabbed field groups in the block edit sidebar.
 *
 * Purely a DOM concern: every field stays rendered in the DOM — inactive
 * panels are only hidden — so the cb-autosave controller still serializes
 * the whole form (hidden tabs included) and server-side validation is
 * unaffected. Mirrors cb-viewport-tabs.
 *
 * The active tab survives Live Component re-renders for free: Live's
 * external-mutation tracker records the `hidden`/class toggles made here
 * and re-applies them after each morph (same mechanism cb-viewport-tabs
 * relies on), so no render-lifecycle hook is needed.
 *
 * Targets:
 *   - tab:   each tab button; reads `data-cb-tab` (the panel index)
 *   - panel: each tab panel;  reads `data-cb-tab` (its own index)
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];
    static values = { active: { type: String, default: '0' } };

    connect() {
        this._show(this.activeValue);
    }

    select(event) {
        event.preventDefault();
        const index = event.currentTarget.dataset.cbTab;
        if (index === undefined || index === this.activeValue) return;
        this.activeValue = index;
        this._show(index);
    }

    _show(index) {
        this.tabTargets.forEach(tab => {
            const isActive = tab.dataset.cbTab === index;
            tab.classList.toggle('cb-block__tab--active', isActive);
            tab.setAttribute('aria-selected', isActive ? 'true' : 'false');
        });
        this.panelTargets.forEach(panel => {
            panel.hidden = panel.dataset.cbTab !== index;
        });
    }
}
