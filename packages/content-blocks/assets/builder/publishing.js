/**
 * Publish, Discard, and the draft state every mutation reports: the
 * topbar buttons, the launcher badge, the tree.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/history.md#the-buttons-and-their-state
 */
export default class Publishing {
    /** Confirm prompt shown before reverting the draft to what is live. */
    static DISCARD_CONFIRM_FALLBACK =
        'Revert to the published version? Every unpublished change will be lost, and this cannot be undone.';

    async publish(event) {
        if (event) event.preventDefault();
        const result = await this._jsonRequest(
            'POST', `${this._apiBase}/area/${this.areaIdValue}/publish`, undefined, { tolerate: [409] },
        );
        if (result === null || this._saidRefused(result)) return;
        // Publish physically removed soft-deleted rows — a pending undo
        // offer can no longer be honoured.
        this._hideUndo();
        this._applyDraftState(result.hasUnpublishedChanges);
        this.reload();
    }

    async discard(event) {
        if (event) event.preventDefault();
        // Discard throws away every unpublished edit at once, and is
        // irreversible — unlike a delete, which has its own Undo.
        const confirmText = this._t('cb.builder.discard_confirm', this.constructor.DISCARD_CONFIRM_FALLBACK);
        if (!window.confirm(confirmText)) return;
        const result = await this._jsonRequest(
            'POST', `${this._apiBase}/area/${this.areaIdValue}/discard`, undefined, { tolerate: [409] },
        );
        if (result === null || this._saidRefused(result)) return;
        // Discard already reverted every draft deletion (or removed
        // never-published rows) — the undo offer is moot either way.
        this._hideUndo();
        this._applyDraftState(result.hasUnpublishedChanges);
        this.reload();
    }

    /**
     * Inbound `cb:area:changed`: something outside wrote to the area, so
     * re-sync the buttons and reload. Supersedes a pending debounced reload.
     *
     * @see docs/internals/frontend.md#the-cb-event-contract
     */
    _onAreaChanged(event) {
        const detail = event?.detail;
        const hasUnpublishedChanges = detail && detail.hasUnpublishedChanges !== undefined
            ? Boolean(detail.hasUnpublishedChanges)
            : true;
        this._applyDraftState(hasUnpublishedChanges);
        clearTimeout(this._reloadTimer);
        this.reload();
    }

    /**
     * `history` is passed only by undo and redo; every other caller is a
     * mutation, which always means "undoable, and no future left".
     *
     * @see docs/internals/history.md#the-buttons-and-their-state
     */
    _applyDraftState(hasUnpublishedChanges, history = null) {
        this._applyHistoryState(history ?? {
            canUndo: Boolean(hasUnpublishedChanges),
            canRedo: false,
        });
        // Hidden rather than disabled, so it is only ever seen when it is
        // actionable.
        const discardBtn = this.element.querySelector('.cb-shell__discard');
        if (discardBtn) {
            discardBtn.hidden = !hasUnpublishedChanges;
        }
        // The primary action: always visible so it is known to exist,
        // disabled when there is nothing to publish.
        const publishBtn = this.element.querySelector('.cb-shell__publish');
        if (publishBtn) {
            publishBtn.disabled = !hasUnpublishedChanges;
        }

        // Launcher badge lives outside the shell (before the <dialog>). We
        // look it up at document scope.
        const badge = document.querySelector('.cb-launcher__badge');
        if (hasUnpublishedChanges && !badge) {
            // No way to recreate it without the translation string — leave
            // its absence to next page render.
        } else if (!hasUnpublishedChanges && badge) {
            badge.remove();
        }

        // Every mutation passes here, so the tree is told once. Last, so a
        // listener that throws cannot cost the buttons their sync.
        this._invalidateTree();
    }
}
