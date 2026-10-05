/**
 * Adds, moves, duplicates and deletes sections, columns and blocks, then
 * brings the preview in line.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
 */
export default class Structure {
    async addSection(event) {
        if (event) event.preventDefault();
        const layout = event?.params?.layout ?? 'full';
        await this._addSection(layout);
    }

    async _addSection(layout) {
        // The layouts are host config: the server is the one to refuse.
        const finalLayout = typeof layout === 'string' && layout !== '' ? layout : 'full';
        const result = await this._jsonRequest('POST', `${this._apiBase}/area/${this.areaIdValue}/sections`, { layout: finalLayout });
        // Create failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        if (result.hotReload && result.id && typeof result.html === 'string') {
            this._applyDraftState(true);
            this._insertSectionInPreview(result.id, result.html);
        } else {
            // It lands at the end of the area, off screen on any long page —
            // without this the editor gets no feedback at all.
            this._scrollPreviewTo(result.id);
            this._afterStructuralOp();
        }
        // Configure it immediately. The insert above runs in parallel; the
        // sidebar fetches its HTML separately.
        if (result.id) {
            this._mountSectionSettings(result.id);
        }
    }

    async _addBlock(columnId, blockType) {
        if (!blockType) return;
        if (!columnId) {
            await this._addBlockToArea(blockType);
            return;
        }
        const result = await this._jsonRequest('POST', `${this._apiBase}/column/${columnId}/blocks`, { type: blockType });
        // Create failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        this._applyDraftState(true);
        // A hot-reloadable block ships its markup; one that opts out needs
        // a full reload. See frontend.md#hot-reload-and-when-it-is-refused
        if (result.hotReload && typeof result.html === 'string') {
            this._insertBlockInPreview(columnId, result.html);
        } else {
            this.reload();
        }
        // Fill it in immediately. The insert above runs in parallel; the
        // sidebar mount fetches from a separate endpoint.
        if (result.id) {
            this._mountSidebar(result.id);
        }
    }

    /**
     * The first block of an area whose sections are hidden: the server adds
     * the section it needs, so the preview is reloaded rather than patched.
     */
    async _addBlockToArea(blockType) {
        const result = await this._jsonRequest(
            'POST', `${this._apiBase}/area/${this.areaIdValue}/blocks`, { type: blockType }, { tolerate: [422] },
        );
        if (result === null) return;
        if (result.error) {
            this._notify(this._t('cb.builder.add_block_no_target', 'There is no section to add this block to'));
            return;
        }
        this._afterStructuralOp();
        if (result.id) {
            this._mountSidebar(result.id);
        }
    }

    async _deleteBlock(blockId) {
        if (!blockId) return;
        const result = await this._jsonRequest(
            'DELETE', `${this._apiBase}/block/${blockId}`, undefined, { tolerate: [409] },
        );
        // Delete failed or refused — leave the preview untouched.
        if (result === null || this._saidRefused(result)) return;
        if (this._isSidebarFocusedOnBlock(blockId)) {
            this._resetSidebarToEmptyState();
        }
        // A delete is a pure removal: nothing new to render, so drop the block
        // from the preview in place instead of reloading the whole iframe.
        this._applyDraftState(true);
        this._removeBlockFromPreview(blockId);
        this._offerUndo('block', blockId);
    }

    async _moveBlock(blockId, toColumnId, position) {
        if (!blockId || !toColumnId) return;
        const finalPosition = position ?? 0;
        const result = await this._jsonRequest('POST', `${this._apiBase}/block/${blockId}/move`, {
            toColumnId,
            position: finalPosition,
        });
        // Move failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        this._applyDraftState(true);
        // Server reports the move was a no-op (e.g. the block vanished) —
        // there's nothing to reposition.
        if (result.moved === false) return;
        // A reorder only changes sibling order: relocate the live block node
        // in place (preserving its DOM + JS state) instead of reloading.
        this._reorderInPreview({ type: 'cb:block:reorder:apply', blockId, toColumnId, position: finalPosition });
    }

    async _moveSection(sectionId, direction) {
        if (!sectionId || !['up', 'down'].includes(direction)) return;
        const result = await this._jsonRequest('POST', `${this._apiBase}/section/${sectionId}/move`, { direction });
        if (result === null) return;
        this._applyDraftState(true);
        // Already at the edge — the server couldn't move it, so neither do we.
        if (result.moved === false) return;
        this._reorderInPreview({ type: 'cb:section:move:apply', sectionId, direction });
    }

    async _reorderSection(sectionId, position) {
        if (!sectionId || !Number.isInteger(position) || position < 0) return;
        const result = await this._jsonRequest('POST', `${this._apiBase}/section/${sectionId}/move`, { position });
        if (result === null) return;
        this._applyDraftState(true);
        if (result.moved === false) return;
        this._reorderInPreview({ type: 'cb:section:reorder:apply', sectionId, position });
    }

    async _duplicateSection(sectionId) {
        if (!sectionId) return;
        const result = await this._jsonRequest('POST', `${this._apiBase}/section/${sectionId}/duplicate`);
        // Duplicate failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        this._applyDraftState(true);
        // Ships markup only when every block hot-reloads; otherwise a full
        // reload so their scripts run.
        if (result.hotReload && typeof result.html === 'string') {
            this._duplicateInPreview({ type: 'cb:section:duplicate:apply', sourceId: sectionId, html: result.html });
        } else {
            this.reload();
        }
    }

    async _duplicateBlock(blockId) {
        if (!blockId) return;
        const result = await this._jsonRequest('POST', `${this._apiBase}/block/${blockId}/duplicate`);
        // Duplicate failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        this._applyDraftState(true);
        // Same policy as _addBlock, landing right after the source.
        if (result.hotReload && typeof result.html === 'string') {
            this._duplicateInPreview({ type: 'cb:block:duplicate:apply', sourceId: blockId, html: result.html });
        } else {
            this.reload();
        }
    }

    async _deleteSection(sectionId) {
        if (!sectionId) return;
        const result = await this._jsonRequest('DELETE', `${this._apiBase}/section/${sectionId}`);
        // Delete failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        // The direct case. A focused block *inside* this section is caught by
        // the overlay's `cb:focus:not-found` reply to the re-pin below.
        if (this._isSidebarFocusedOnSection(sectionId)) {
            this._resetSidebarToEmptyState();
        }
        this._applyDraftState(true);
        this._removeSectionFromPreview(sectionId);
        this._offerUndo('section', sectionId);
    }

    _onColumnAddRequested(event) {
        const { sectionId, messages } = event.detail ?? {};
        return this._columnOp(sectionId, `section/${sectionId}/columns`, messages);
    }

    _onColumnDeleteRequested(event) {
        const { sectionId, columnId, messages } = event.detail ?? {};
        if (!Number.isFinite(columnId)) return undefined;
        return this._columnOp(sectionId, `column/${columnId}/delete`, messages);
    }

    /**
     * The column count moves the whole row and the sidebar's column list, so
     * both are rebuilt rather than patched.
     */
    async _columnOp(sectionId, path, messages = {}) {
        if (!Number.isFinite(sectionId)) return;
        const result = await this._jsonRequest('POST', `${this._apiBase}/${path}`, {}, { tolerate: [400] });
        if (result === null) return;
        if (result.error) {
            this._notify(messages?.[result.error] || result.error);
            return;
        }
        this._afterStructuralOp();
        if (this._isSidebarFocusedOnSection(sectionId)) {
            await this._mountSectionSettings(sectionId);
        }
    }

    /**
     * Every structural op leaves at least one unpublished change, so the draft
     * state is flipped on proactively rather than round-tripped for.
     */
    _afterStructuralOp() {
        this._applyDraftState(true);
        this.reload();
    }
}
