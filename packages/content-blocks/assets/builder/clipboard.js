/**
 * Copy and paste of the selected section or block, through localStorage
 * so a copy survives leaving the page.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#keyboard-and-clipboard
 */
export default class Clipboard {
    /**
     * localStorage, because "copy here, paste over there" means leaving this
     * page — and therefore the payload is user-writable, and untrusted.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    static CLIPBOARD_KEY = 'cb-builder.clipboard';

    /**
     * Copies whatever the sidebar has open, block winning over section, and
     * says so in the snackbar.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    async copySelection() {
        const { blockId, sectionId } = this._selectionIds();
        const scope = blockId ? 'block' : (sectionId ? 'section' : null);
        if (!scope) {
            this._notify(this._t('cb.builder.clipboard.nothing_selected', 'Select a section or a block to copy it'));

            return;
        }

        const entry = await this._jsonRequest('GET', `${this._apiBase}/${scope}/${blockId || sectionId}/copy`);
        if (entry === null) return;

        try {
            window.localStorage.setItem(this.constructor.CLIPBOARD_KEY, JSON.stringify(entry));
        } catch (e) {
            // The copy did not happen, and saying so beats a paste that
            // mystifyingly does nothing.
            console.error('[cb-builder] clipboard write failed', e);
            this._notify(this._t('cb.builder.clipboard.unreadable', 'This copy cannot be read and was discarded'));

            return;
        }

        this._notify(this._t(
            scope === 'block' ? 'cb.builder.clipboard.block_copied' : 'cb.builder.clipboard.section_copied',
            scope === 'block' ? 'Block copied' : 'Section copied',
        ));
    }

    /**
     * Hands the entry back with the current selection as target; placement is
     * decided server-side, into the draft.
     *
     * @see docs/internals/clipboard.md#replay-and-placement
     */
    async pasteClipboard() {
        const entry = this._readClipboard();
        if (!entry) {
            this._notify(this._t('cb.builder.clipboard.empty', 'Nothing copied yet'));

            return;
        }

        const { blockId, sectionId } = this._selectionIds();
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/paste`,
            {
                payload: entry,
                ...(blockId ? { targetBlockId: blockId } : {}),
                ...(sectionId ? { targetSectionId: sectionId } : {}),
            },
            // A reason the editor can act on, not a failed save.
            { tolerate: [413, 422] },
        );
        if (result === null) return;

        if (result.error) {
            this._notifyPasteRefusal(result.error);

            return;
        }

        const warning = this._restoreWarning(result, {
            skipped: ['cb.builder.clipboard.skipped_blocks', 'Pasted — %count% block(s) skipped, missing type(s): %types%'],
            unknown: ['cb.builder.clipboard.dropped_fields', 'Pasted, but some fields were reset on: %types%'],
            fieldsKey: 'droppedFields',
        });
        if (warning !== null) this._notify(warning);

        if (result.sectionId) this._scrollPreviewTo(result.sectionId);
        this._afterStructuralOp();
    }

    _notifyPasteRefusal(error) {
        const messages = {
            no_target: ['cb.builder.clipboard.no_target', 'Select a section or a block first — a copied block needs somewhere to go'],
            incompatible_content_version: ['cb.builder.clipboard.stale_version', 'This copy was made under another version of your content schema — copy it again'],
            incompatible_clipboard: ['cb.builder.clipboard.unreadable', 'This copy cannot be read and was discarded'],
            unreadable_clipboard: ['cb.builder.clipboard.unreadable', 'This copy cannot be read and was discarded'],
            too_large: ['cb.builder.clipboard.too_large', 'This copy holds more than a page can and was discarded'],
        };
        const [key, fallback] = messages[error] ?? messages.unreadable_clipboard;
        // An unreadable or stale entry will never paste anywhere; keeping
        // it only lets the editor hit the same wall again.
        if (error !== 'no_target') this._clearClipboard();
        this._notify(this._t(key, fallback));
    }

    _readClipboard() {
        let raw = null;
        try {
            raw = window.localStorage.getItem(this.constructor.CLIPBOARD_KEY);
        } catch (e) {
            console.error('[cb-builder] clipboard read failed', e);
        }
        if (!raw) return null;
        try {
            const entry = JSON.parse(raw);

            return entry && typeof entry === 'object' ? entry : null;
        } catch {
            // Corrupt beyond parsing — drop it now so the next paste says
            // "nothing copied" instead of failing on the server every time.
            this._clearClipboard();

            return null;
        }
    }

    _clearClipboard() {
        try {
            window.localStorage.removeItem(this.constructor.CLIPBOARD_KEY);
        } catch (e) {
            console.error('[cb-builder] clipboard clear failed', e);
        }
    }
}
