/**
 * Undo and redo against the server-side journal, flushing the open form
 * first and keeping it open when the server says it survived.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/history.md
 */
export default class History {
    /** How long an undo waits for the edit it just committed to land. */
    static SAVE_FLUSH_TIMEOUT_MS = 2000;

    /** Any of these ends the wait: saved, failed, or refused by a listener. */
    static SAVE_SETTLED_EVENTS = [
        'cb:block:saved', 'cb:section:saved', 'cb:save:error', 'cb:block:refused',
    ];

    /** @param {{canUndo: boolean, canRedo: boolean}} state */
    _applyHistoryState(state) {
        const buttons = {
            '.cb-shell__history-btn--undo': state.canUndo,
            '.cb-shell__history-btn--redo': state.canRedo,
        };
        for (const [selector, enabled] of Object.entries(buttons)) {
            const button = this.element.querySelector(selector);
            if (button) button.disabled = !enabled;
        }
    }

    /**
     * Ctrl/Cmd-Z. The stack lives on the server, so this asks rather than
     * replays: the client holds no shadow copy of the draft to get wrong.
     *
     * @see docs/internals/history.md
     */
    undoLastAction() {
        return this._runHistory('undo');
    }

    /** Ctrl/Cmd-Shift-Z, and Ctrl-Y for the Windows habit. */
    redoLastAction() {
        return this._runHistory('redo');
    }

    async _runHistory(direction) {
        // The chord now fires from inside a focused field, so the edit under
        // it may not be journalled yet — undoing here would skip a step.
        await this._flushSidebarEdits();

        const open = this._openSidebarRef();
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/${direction}`,
            open ? { open } : {},
        );
        // Request failed outright — the save-error banner already says so.
        if (result === null) return;

        // A refusal still carries the counts: "nothing to undo" is exactly
        // what the button needs to go grey.
        const history = { canUndo: Boolean(result.canUndo), canRedo: Boolean(result.canRedo) };
        if (result.status !== 'ok') {
            this._applyHistoryState(history);
            this._notify(this._historyMessage(direction, result.status));

            return;
        }

        await this._settleSidebar(result.sidebar, open);
        this._hideUndo();
        this._applyDraftState(result.hasUnpublishedChanges, history);
        this.reload();
    }

    /** What the sidebar has open, in the shape the endpoint reads. */
    _openSidebarRef() {
        const { blockId, sectionId } = this._selectionIds();
        if (blockId) return { type: 'block', id: blockId };
        if (sectionId) return { type: 'section', id: sectionId };

        return null;
    }

    /**
     * The server said whether the open form outlived the step. Only `reload`
     * repaints it, so an undo elsewhere on the page costs no caret.
     *
     * @see docs/internals/history.md#the-sidebar-survives-an-undo-when-it-can
     */
    async _settleSidebar(verdict, open) {
        if (verdict === 'keep' && open) return;
        if (verdict !== 'reload' || !open) {
            this._resetSidebarToEmptyState();

            return;
        }

        const field = this._focusedFieldName();
        await (open.type === 'block'
            ? this._mountSidebar(open.id)
            : this._mountSectionSettings(open.id));
        this._restoreFieldFocus(field);
    }

    /**
     * Remounting the form drops the caret, so the field is found again by
     * name — the one thing that survives a re-render.
     */
    _focusedFieldName() {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement) || !this.hasSidebarContentTarget) return null;

        return this.sidebarContentTarget.contains(active) ? active.getAttribute('name') : null;
    }

    _restoreFieldFocus(name) {
        if (!name || !this.hasSidebarContentTarget) return;
        const selector = `[name="${window.CSS?.escape ? window.CSS.escape(name) : name}"]`;
        const field = this.sidebarContentTarget.querySelector(selector);
        field?.focus?.({ preventScroll: true });
    }

    /**
     * Clicks the open form's save and waits for it to land, so the journal
     * has the edit before the undo asks for the step before it.
     */
    async _flushSidebarEdits() {
        if (!this.hasSidebarContentTarget) return;
        const host = this.sidebarContentTarget.querySelector('[data-controller~="cb-autosave"]');
        if (!host) return;

        const autosave = this.application?.getControllerForElementAndIdentifier?.(host, 'cb-autosave');
        if (!autosave?.flush || !autosave.flush()) return;

        await this._nextSaveSettled();
    }

    /**
     * Resolves on the save's own event, or on the timeout — a save that never
     * answers must not cost the editor their Ctrl-Z.
     */
    _nextSaveSettled() {
        return new Promise((resolve) => {
            const done = () => {
                clearTimeout(timer);
                for (const name of this.constructor.SAVE_SETTLED_EVENTS) {
                    this.element.removeEventListener(name, done);
                }
                resolve();
            };
            const timer = setTimeout(done, this.constructor.SAVE_FLUSH_TIMEOUT_MS);
            for (const name of this.constructor.SAVE_SETTLED_EVENTS) {
                this.element.addEventListener(name, done);
            }
        });
    }

    _historyMessage(direction, status) {
        const messages = {
            'undo:nothing': ['cb.builder.history.nothing_to_undo', 'Nothing to undo'],
            'redo:nothing': ['cb.builder.history.nothing_to_redo', 'Nothing to redo'],
            'undo:stale': ['cb.builder.history.stale', 'This step no longer matches the page — nothing was changed'],
            'redo:stale': ['cb.builder.history.stale', 'This step no longer matches the page — nothing was changed'],
            'undo:unavailable': ['cb.builder.history.unavailable', 'Undo is unavailable in this session'],
            'redo:unavailable': ['cb.builder.history.unavailable', 'Undo is unavailable in this session'],
        };
        const [key, fallback] = messages[`${direction}:${status}`] ?? messages['undo:unavailable'];

        return this._t(key, fallback);
    }
}
