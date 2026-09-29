/**
 * Document-level keys and clicks: the Ctrl/Cmd chords, Escape, outside
 * clicks, and the modals they close.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#keyboard-and-clipboard
 */
export default class Keyboard {
    /**
     * The Ctrl/Cmd chords, as the method each one calls. One table, so the
     * shell and the relayed-from-the-iframe path can never drift apart.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    static shortcutIntent(key, shiftKey) {
        if (key === 'z') return shiftKey ? 'redoLastAction' : 'undoLastAction';
        if (shiftKey) return null;

        return { c: 'copySelection', v: 'pasteClipboard', y: 'redoLastAction' }[key] ?? null;
    }

    /** The two that yield only to a real undo stack, not to any field. */
    static HISTORY_INTENTS = new Set(['undoLastAction', 'redoLastAction']);

    /** `<input>` types that take no typing, so they undo nothing natively. */
    static UNDOLESS_INPUT_TYPES = new Set([
        'color', 'range', 'checkbox', 'radio', 'file',
        'button', 'submit', 'reset', 'image', 'hidden',
    ]);

    /**
     * The menu closes; a picker only on its own backdrop, so a click inside
     * one — or on its list's scrollbar — never dismisses it.
     */
    _onDocumentPointerDown(event) {
        this._onActivity();
        const target = event.target;
        if (this.hasActionsMenuTarget && !this.actionsMenuTarget.contains(target)) {
            this.closeActions();
        }
        if (target instanceof Element && target.classList?.contains('cb-modal-backdrop')) {
            this._closeTopModal();
        }
    }

    /**
     * Escape closes the topmost open thing. See `_isTextEditing` for what
     * keeps Ctrl-C/V out of a genuine text copy.
     */
    _onDocumentKeydown(event) {
        this._onActivity();
        if ((event.ctrlKey || event.metaKey) && !event.altKey) {
            const key = event.key?.toLowerCase();
            const intent = this.constructor.shortcutIntent(key, event.shiftKey);
            if (intent && !this._shortcutBlocked(intent)) {
                event.preventDefault();
                this[intent]();

                return;
            }
        }

        if (event.key !== 'Escape') return;
        if (this._closeTopModal()) {
            event.preventDefault();
            return;
        }
        if (this.hasActionsListTarget && !this.actionsListTarget.hidden) {
            this.closeActions();
            event.preventDefault();
        }
    }

    /**
     * Closes whichever modal is open, returning true when one was. The section
     * library is not one: it lives in the sidebar, not over it.
     */
    _closeTopModal() {
        if (this.hasReplacePickerTarget && !this.replacePickerTarget.hidden) {
            this.closeReplacePicker();
            return true;
        }
        // The Escape handler cancels the dialog's own close, so close it here.
        if (this.hasTransferDialogTarget && this.transferDialogTarget.open) {
            this._transfer?.close();
            return true;
        }
        // Last: the tree is a panel the editor works alongside, so it yields
        // to any real modal.
        const tree = this.element.querySelector('.cb-tree');
        if (tree && !tree.hidden) {
            this._signalTree('cb:tree:close');
            return true;
        }

        return false;
    }

    /**
     * One backdrop for all three pickers, so the dimming can never stack or be
     * left behind by a picker that forgot to clean up.
     */
    _setBackdrop(visible) {
        const backdrop = this.element.querySelector('.cb-modal-backdrop');
        if (backdrop) backdrop.hidden = !visible;
        this.element.classList.toggle('cb-shell--modal-open', visible);
    }

    /**
     * Wraps Tab / Shift-Tab around the focusable elements of `container`.
     */
    static trapTab(container, event) {
        const focusable = [...container.querySelectorAll(
            'a[href], button:not([disabled]), input:not([disabled]), '
            + 'select:not([disabled]), textarea:not([disabled]), '
            + '[tabindex]:not([tabindex="-1"])',
        )].filter((el) => !el.hidden && !el.closest('[hidden]'));
        if (focusable.length === 0) {
            event.preventDefault();
            return;
        }
        const first = focusable[0];
        const last = focusable[focusable.length - 1];
        const active = container.ownerDocument.activeElement;
        if (event.shiftKey && (active === first || !container.contains(active))) {
            event.preventDefault();
            last.focus();
        } else if (!event.shiftKey && (active === last || !container.contains(active))) {
            event.preventDefault();
            first.focus();
        }
    }

    /**
     * Whether this chord belongs to the field under the caret rather than to
     * the builder. Undo yields far less than copy: see `_hasNativeUndo`.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    _shortcutBlocked(intent) {
        return this.constructor.HISTORY_INTENTS.has(intent)
            ? this._hasNativeUndo()
            : this._isTextEditing();
    }

    /**
     * A `<select>` or a colour swatch has no undo stack of its own, so there
     * is nothing there for Ctrl-Z to step on.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    _hasNativeUndo() {
        const active = document.activeElement;
        if (!(active instanceof HTMLElement)) return false;
        if (active.isContentEditable || active.closest('[contenteditable="true"]')) return true;
        if (active.tagName === 'TEXTAREA') return true;
        if (active.tagName !== 'INPUT') return false;

        // Listed the other way round: an unknown (or future) input type is
        // assumed to take typing, so the chord stays the browser's.
        return !this.constructor.UNDOLESS_INPUT_TYPES.has((active.type || 'text').toLowerCase());
    }

    /**
     * Whether Ctrl-C/V belongs to the editor's text. Stealing a real selection
     * would be worse than not having the shortcut.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    _isTextEditing() {
        const active = document.activeElement;
        if (active && (active.isContentEditable
            || ['INPUT', 'TEXTAREA', 'SELECT'].includes(active.tagName))) {
            return true;
        }
        const selection = window.getSelection?.();

        return !!selection && !selection.isCollapsed;
    }
}
