import { Controller } from '@hotwired/stimulus';

/**
 * Sidebar tab switcher (General / Styling).
 *
 * Lives on the section-settings sidebar fragment, which cb-builder
 * injects via innerHTML — that strips any inline <script> tags, so the
 * tab toggle logic has to ride on a Stimulus controller to boot
 * automatically when Stimulus rescans the new DOM.
 *
 * Targets:
 *   - tab: each tab button (carries `data-cb-tab="..."`)
 *   - panel: each panel (carries `data-cb-tab-panel="..."`)
 */
export default class extends Controller {
    static targets = ['tab', 'panel'];

    connect() {
        // No-op: initial active state is already set by the server. Click
        // bindings are wired declaratively via data-action.
    }

    select(event) {
        event.preventDefault();
        const key = event.currentTarget.dataset.cbTab;
        if (!key) return;
        this.tabTargets.forEach(t => {
            const active = t.dataset.cbTab === key;
            t.classList.toggle('active', active);
            t.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        this.panelTargets.forEach(p => {
            p.hidden = p.dataset.cbTabPanel !== key;
        });
    }
}
