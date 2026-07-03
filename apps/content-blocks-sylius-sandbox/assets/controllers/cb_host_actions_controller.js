import { Controller } from '@hotwired/stimulus';

/*
 * Host-side listener for the builder's generic `cb:builder:action` event.
 *
 * The package's topbar buttons (declared via the form's `topbar_actions`
 * option / the launcher's `topbarActions`) dispatch ONE generic
 * `cb:builder:action` event carrying `detail.key`. The bundle re-parents the
 * builder <dialog> to <body>, so the event bubbles all the way up to
 * `document` — we listen there, not on this element. The host owns the
 * behaviour: here "save-as-model" round-trips to a host endpoint and surfaces
 * a link to the freshly-created model page.
 */
export default class extends Controller {
    static values = {
        saveAsModelUrl: String,
    };

    static targets = ['status'];

    connect() {
        this._onAction = this._onAction.bind(this);
        document.addEventListener('cb:builder:action', this._onAction);
    }

    disconnect() {
        document.removeEventListener('cb:builder:action', this._onAction);
    }

    async _onAction(event) {
        // One generic event for every host action — filter on the key.
        if (event.detail?.key !== 'save-as-model') return;

        this._setStatus('Enregistrement du modèle…');

        let payload = null;
        try {
            const response = await fetch(this.saveAsModelUrlValue, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            payload = await response.json();
        } catch (e) {
            console.error('[sandbox] save-as-model failed', e);
            this._setStatus('Impossible d\'enregistrer le modèle.', 'danger');
            return;
        }

        this._renderSuccess(payload);
    }

    _renderSuccess(payload) {
        if (!this.hasStatusTarget) return;
        this._applyAlert('success');
        this.statusTarget.hidden = false;
        this.statusTarget.innerHTML = '';

        const label = document.createElement('span');
        const count = payload.sectionCount ?? 0;
        label.textContent = `✓ Modèle « ${payload.name} » créé (${count} section${count > 1 ? 's' : ''}). `;

        const link = document.createElement('a');
        link.href = payload.editUrl;
        link.textContent = 'Ouvrir le modèle';
        link.className = 'alert-link';
        link.setAttribute('data-cb-model-link', '');

        this.statusTarget.appendChild(label);
        this.statusTarget.appendChild(link);
    }

    _setStatus(text, variant = 'info') {
        if (!this.hasStatusTarget) return;
        this._applyAlert(variant);
        this.statusTarget.hidden = false;
        this.statusTarget.textContent = text;
    }

    /** Swap the status paragraph to a Bootstrap alert of the given variant. */
    _applyAlert(variant) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.className = `alert alert-${variant} mt-3`;
        this.statusTarget.setAttribute('role', 'status');
    }
}
