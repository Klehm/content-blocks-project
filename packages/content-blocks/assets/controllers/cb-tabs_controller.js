import { Controller } from '@hotwired/stimulus';

/**
 * Tabbed field groups. Purely DOM — every field stays rendered, and Live's
 * external-mutation tracker re-applies the toggles across a morph.
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
