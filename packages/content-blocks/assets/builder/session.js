/**
 * Notices a lost session, says so instead of following the login redirect,
 * and resumes once it is back.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#an-expired-session-is-said-not-followed
 */
export default class Session {
    /** Minimum gap (ms) between two session checks once it is known lost. */
    static SESSION_RECHECK_MS = 3000;

    /**
     * A 401, the package's own marker, or a followed redirect: none of the
     * builder's endpoints redirects, so a redirect is the host's login.
     *
     * @see docs/internals/frontend.md#an-expired-session-is-said-not-followed
     */
    static isSessionLoss(response) {
        if (!response) return false;
        return response.status === 401
            || response.headers?.get?.('X-Content-Blocks-Session') === 'expired'
            || response.redirected === true;
    }

    /** Any interaction after a long idle, or any while the session is lost. */
    _onActivity() {
        const now = Date.now();
        const idle = now - this._lastActivityAt;
        this._lastActivityAt = now;
        if (this._sessionExpired) {
            if (now - this._lastSessionCheckAt >= this.constructor.SESSION_RECHECK_MS) {
                this._checkSession();
            }
            return;
        }
        if (idle >= this.sessionCheckAfterValue) this._checkSession();
    }

    _onVisibilityChange() {
        if (document.visibilityState === 'visible') this._onActivity();
    }

    /**
     * Asks before the editor types into a dead session, and brings back the
     * CSRF token a renewed one issued.
     *
     * @see docs/internals/frontend.md#an-expired-session-is-said-not-followed
     */
    async _checkSession() {
        if (this._sessionCheck) return this._sessionCheck;
        const dialog = this.element.closest('dialog');
        if (dialog && !dialog.open) return undefined;

        this._lastSessionCheckAt = Date.now();
        this._sessionCheck = (async () => {
            let response;
            try {
                response = await fetch(`${this._apiBase}/area/${this.areaIdValue}/state`, {
                    credentials: 'same-origin',
                    headers: { Accept: 'application/json' },
                });
            } catch (_) {
                return; // Offline says nothing about the session.
            }
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
                return;
            }
            if (!response.ok) return;
            const payload = await response.json().catch(() => null);
            if (typeof payload?.csrfToken === 'string' && payload.csrfToken !== '') {
                this.element.dataset.cbCsrfToken = payload.csrfToken;
            }
            if (this._sessionExpired) await this._onSessionRestored();
        })();
        try {
            await this._sessionCheck;
        } finally {
            this._sessionCheck = null;
        }
        return undefined;
    }

    /**
     * The link reopens this page rather than the login form: the firewall
     * brings the editor back here once they are in.
     */
    _onSessionExpired() {
        this._sessionExpired = true;
        this._clearSaveError();
        if (this.hasSessionLoginTarget) {
            this.sessionLoginTarget.href = window.location.href;
        }
        if (this.hasSessionExpiredTarget) this.sessionExpiredTarget.hidden = false;
    }

    /** The edit the dead session swallowed is sent again, then the preview. */
    async _onSessionRestored() {
        this._sessionExpired = false;
        if (this.hasSessionExpiredTarget) this.sessionExpiredTarget.hidden = true;
        await this._flushSidebarEdits();
        this.reload();
    }
}
