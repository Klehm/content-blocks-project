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

        this._setStatus('Saving as model…');

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
            this._setStatus('Could not save as model.');
            return;
        }

        this._renderSuccess(payload);
    }

    _renderSuccess(payload) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.hidden = false;
        this.statusTarget.innerHTML = '';

        const label = document.createElement('span');
        label.textContent = 'Model created: ';

        const link = document.createElement('a');
        link.href = payload.builderUrl;
        link.textContent = `${payload.title} (#${payload.id})`;
        link.setAttribute('data-cb-model-link', '');

        this.statusTarget.appendChild(label);
        this.statusTarget.appendChild(link);
    }

    _setStatus(text) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.hidden = false;
        this.statusTarget.textContent = text;
    }
}
