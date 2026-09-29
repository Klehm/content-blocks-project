/**
 * The one-slot snackbar: the undo offer after a delete, and every message
 * the builder, a host or a refusing listener has to say.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
 */
export default class Snackbar {
    /**
     * The editor's only one-click recovery from a delete short of discarding
     * the whole draft.
     *
     * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
     */
    static UNDO_TIMEOUT_MS = 6000;

    static NOTIFY_LINK_TIMEOUT_MS = 10000;

    /**
     * Deletes are immediate, and discarding the whole draft is far too coarse
     * for one mis-click. Single-slot: a newer delete replaces the offer.
     */
    _offerUndo(kind, id) {
        if (!this.hasUndoBarTarget) return;
        this._pendingUndo = { kind, id };
        if (this.hasUndoLabelTarget) {
            const key = kind === 'section' ? 'cbBuilderUndoSectionDeleted' : 'cbBuilderUndoBlockDeleted';
            this.undoLabelTarget.textContent = this.undoBarTarget.dataset[key] || '';
        }
        if (this.hasUndoButtonTarget) this.undoButtonTarget.hidden = false;
        if (this.hasUndoLinkTarget) this.undoLinkTarget.hidden = true;
        this.undoBarTarget.hidden = false;
        clearTimeout(this._undoTimer);
        this._undoTimer = setTimeout(() => this._hideUndo(), this.constructor.UNDO_TIMEOUT_MS);
    }

    /**
     * The same snackbar, at most a link to follow. It shares the slot with the
     * undo offer, and clears it rather than leaving an invisible one armed.
     */
    _notify(message, link = null) {
        if (!this.hasUndoBarTarget) return;
        this._pendingUndo = null;
        if (this.hasUndoLabelTarget) this.undoLabelTarget.textContent = message;
        if (this.hasUndoButtonTarget) this.undoButtonTarget.hidden = true;
        if (this.hasUndoLinkTarget) {
            this.undoLinkTarget.hidden = link === null;
            this.undoLinkTarget.textContent = link?.label ?? '';
            if (link) this.undoLinkTarget.href = link.href;
            else this.undoLinkTarget.removeAttribute('href');
        }
        this.undoBarTarget.hidden = false;
        clearTimeout(this._undoTimer);
        const delay = link && this.hasUndoLinkTarget
            ? this.constructor.NOTIFY_LINK_TIMEOUT_MS
            : this.constructor.UNDO_TIMEOUT_MS;
        this._undoTimer = setTimeout(() => this._hideUndo(), delay);
    }

    _hideUndo() {
        clearTimeout(this._undoTimer);
        this._pendingUndo = null;
        if (this.hasUndoBarTarget) this.undoBarTarget.hidden = true;
    }

    /** Action: the snackbar's "Undo" button. */
    async undoDelete(event) {
        if (event) event.preventDefault();
        const pending = this._pendingUndo;
        // Hide first: whatever the outcome, the offer is consumed (a failed
        // restore surfaces the save-error banner via _jsonRequest).
        this._hideUndo();
        if (!pending) return;
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/${pending.kind}/${pending.id}/restore`,
        );
        if (result === null) return;
        // It comes back with its full subtree, and undo is rare — a full
        // reload is the simplest correct refresh.
        this._applyDraftState(true);
        this.reload();
    }

    /**
     * Inbound `cb:notify`: a host or fragment reports in the snackbar, since
     * the page under the builder's modal is out of sight.
     *
     * @see docs/internals/frontend.md#the-cb-event-contract
     */
    _onNotify(event) {
        const detail = event?.detail ?? {};
        if (typeof detail.message !== 'string' || detail.message.trim() === '') return;
        this._notify(detail.message, this._safeLink(detail.link));
    }

    /** Only http(s) targets: a `javascript:` href would run in the admin. */
    _safeLink(link) {
        if (!link || typeof link.href !== 'string' || typeof link.label !== 'string') return null;
        try {
            const url = new URL(link.href, window.location.href);
            if (url.protocol !== 'http:' && url.protocol !== 'https:') return null;
            return { href: url.href, label: link.label };
        } catch (e) {
            return null;
        }
    }

    /**
     * A server-side listener refused the action: say its reason in the
     * snackbar. True when it did, so the caller stops there.
     *
     * @see docs/guide/events.md#refusing-an-action
     */
    _saidRefused(result) {
        if (result?.error !== 'refused') return false;
        this._notify(result.message || this._t('cb.builder.refused', 'This action was refused'));

        return true;
    }
}
