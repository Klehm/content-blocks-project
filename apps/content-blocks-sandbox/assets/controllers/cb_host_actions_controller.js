import { Controller } from '@hotwired/stimulus';

/*
 * Host-side listener for the builder's generic `cb:builder:action` event.
 *
 * The package's topbar buttons (declared via the form's `topbar_actions`
 * option / the launcher's `topbarActions`) dispatch ONE generic
 * `cb:builder:action` event carrying `detail.key`. The bundle re-parents the
 * builder <dialog> to <body>, so the event bubbles all the way up to
 * `document` — we listen there, not on this element. The host owns the
 * behaviour: here "save-as-model" round-trips to a host endpoint and reports
 * back through the inbound `cb:notify` event, and "translate" opens the i18n
 * package's workbench.
 */
export default class extends Controller {
    static values = {
        saveAsModelUrl: String,
        workbenchUrl: String,
        messages: Object,
    };

    connect() {
        this._onAction = this._onAction.bind(this);
        document.addEventListener('cb:builder:action', this._onAction);
    }

    disconnect() {
        document.removeEventListener('cb:builder:action', this._onAction);
    }

    async _onAction(event) {
        // One generic event for every host action — filter on the key.
        const key = event.detail?.key;

        if (key === 'translate') {
            this._openWorkbench();
            return;
        }

        if (key !== 'save-as-model') return;

        // The page under the builder's modal is out of sight: the answer goes
        // to the builder's snackbar, dispatched back at the action's target.
        const origin = event.target;
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
            this._notify(origin, { message: this._message('failed') });
            return;
        }

        this._notify(origin, {
            message: this._message('created').replace('%title%', payload.title),
            link: { label: this._message('open'), href: payload.builderUrl },
        });
    }

    /**
     * The workbench is a full page of its own, and the builder holds unsaved
     * draft state in a <dialog> — so it opens in a new tab rather than
     * navigating away from work in progress.
     */
    _openWorkbench() {
        if (!this.hasWorkbenchUrlValue || this.workbenchUrlValue === '') return;

        window.open(this.workbenchUrlValue, '_blank', 'noopener');
    }

    _message(key) {
        return this.messagesValue[key] ?? key;
    }

    _notify(origin, detail) {
        origin.dispatchEvent(new CustomEvent('cb:notify', { bubbles: true, detail }));
    }
}
