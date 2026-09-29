/**
 * Every AJAX mutation, serialized through one queue, and the progress bar
 * counting what is in flight.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#mutations-are-serialized
 */
export default class Requests {
    static DEFAULT_API_BASE = '/_content-blocks';

    /**
     * Where the host mounted the endpoints (`cb_api_base()`). Falls back to
     * the default mount for a shell template that predates the attribute.
     *
     * @see docs/internals/frontend.md#endpoint-urls-come-from-the-router
     */
    get _apiBase() {
        return this.element.dataset.cbApiBase ?? this.constructor.DEFAULT_API_BASE;
    }

    /**
     * Shared AJAX helper. Calls are **serialized** — at most one mutation is
     * ever in flight — because the endpoints share a read-modify-write.
     *
     * @see docs/internals/frontend.md#mutations-are-serialized
     */
    _jsonRequest(method, url, body, options) {
        const exec = () => this._performJsonRequest(method, url, body, options);
        // On both fulfil and reject, so a prior failure still releases the
        // slot. _performJsonRequest never rejects.
        const result = this._mutationQueue
            ? this._mutationQueue.then(exec, exec)
            : exec();
        // Tail only, so one failure cannot wedge every later mutation
        // behind a permanently-rejected promise.
        this._mutationQueue = result.catch(() => {});

        return result;
    }

    /**
     * Performs a single JSON request. Pulls the CSRF token from the shell
     * wrapper element (`data-cb-csrf-token`) and forwards it as `X-CSRF-Token`.
     */
    async _performJsonRequest(method, url, body, options = {}) {
        const csrfToken = this.element.dataset.cbCsrfToken || '';
        const init = {
            method,
            credentials: 'same-origin',
            headers: {
                'X-CSRF-Token': csrfToken,
                'Accept': 'application/json',
            },
        };
        if (body !== undefined) {
            init.headers['Content-Type'] = 'application/json';
            init.body = JSON.stringify(body);
        }

        this._beginLoading();
        try {
            let response;
            try {
                response = await fetch(url, init);
            } catch (e) {
                // Without this the rejection reaches callers that never
                // handle it, and the editor gets zero feedback.
                console.error('[cb-builder] request failed', method, url, e);
                this._showSaveError();
                return null;
            }
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
                return null;
            }
            if (!response.ok) {
                // A refusal carrying a reason opts out of the generic
                // banner; the caller reads the body and says it.
                if (options.tolerate?.includes(response.status)) {
                    return await response.json().catch(() => null);
                }
                console.error('[cb-builder] request failed', method, url, response.status);
                this._showSaveError();
                return null;
            }

            this._clearSaveError();
            return await response.json().catch(() => null);
        } finally {
            this._endLoading();
        }
    }

    /**
     * Reference-counted, so overlapping operations stack and the bar only goes
     * away once the last finishes.
     */
    _beginLoading() {
        this._loadingDepth = (this._loadingDepth ?? 0) + 1;
        this.element.classList.add('cb-shell--loading');
    }

    _endLoading() {
        this._loadingDepth = Math.max(0, (this._loadingDepth ?? 0) - 1);
        if (this._loadingDepth === 0) {
            this.element.classList.remove('cb-shell--loading');
        }
    }
}
