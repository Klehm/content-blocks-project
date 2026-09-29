/**
 * The "Saved" flash and the persistent save-error banner, fed by the
 * Live Component hooks as well as by the AJAX calls.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
 */
export default class SaveStatus {
    /**
     * Hooks a Live Component's two failure paths — a non-component response,
     * and a network failure its own promise never handles.
     *
     * @see docs/internals/frontend.md#live-component-failures-need-two-hooks
     */
    _onLiveConnect(event) {
        const component = event.detail?.component;
        if (!component || typeof component.on !== 'function') return;
        component.on('response:error', (backendResponse, controls) => {
            controls.displayError = false;
            const response = backendResponse?.response;
            if (this.constructor.isSessionLoss(response)) {
                // Marked first, so the bubbling save error stays quiet.
                this._onSessionExpired();
                this._signalSaveError(component.element);
                return;
            }
            this._signalSaveError(component.element);
        });
        component.on('loading.state:started', (el, request) => {
            request?.promise?.catch(() => {
                // Live never resets this on rejection, so every later
                // action would queue behind a dead request forever.
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }
                this._signalSaveError(component.element);
            });
        });
    }

    /**
     * Dispatched on the autosave wrapper, which both resets its dirty baseline
     * and bubbles up to raise the banner.
     *
     * @see docs/internals/frontend.md#live-component-failures-need-two-hooks
     */
    _signalSaveError(fromElement) {
        const autosaveEl = fromElement?.querySelector?.('[data-controller~="cb-autosave"]');
        if (autosaveEl) {
            autosaveEl.dispatchEvent(new CustomEvent('cb:save:error', { bubbles: true }));
        } else {
            this._showSaveError();
        }
    }

    _onSaveError(event) {
        if (event?.detail?.sessionExpired) {
            this._onSessionExpired();
            return;
        }
        this._showSaveError();
    }

    /**
     * Persistent, unlike the "Saved" flash: the editor must know their latest
     * edits are not stored.
     *
     * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
     */
    _showSaveError() {
        // The session banner already says why, and what to do about it.
        if (this._sessionExpired) return;
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = false;
    }

    _clearSaveError() {
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = true;
    }

    _flashSaved() {
        // A successful save supersedes any earlier failure.
        this._clearSaveError();
        if (!this.hasSavedFlashTarget) return;
        const el = this.savedFlashTarget;
        el.hidden = false;
        // Force a reflow so the class is applied as a transition trigger,
        // not the same paint as the unhide.
        void el.offsetWidth;
        el.classList.add('is-visible');
        clearTimeout(this._savedFlashTimer);
        this._savedFlashTimer = setTimeout(() => {
            el.classList.remove('is-visible');
            // Wait for the fade-out before re-hiding so screen readers and
            // CSS transitions both have time to complete.
            setTimeout(() => { el.hidden = true; }, 250);
        }, 1500);
    }
}
