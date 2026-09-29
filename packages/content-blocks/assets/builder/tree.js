/**
 * The half of the navigator panel that acts: the panel signals, and every
 * mutation it asks for goes through the same queue as the preview.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md
 */
export default class Tree {
    /**
     * Action: the topbar button. The panel sits beside `<main>` so it can
     * float over the preview, which puts it outside this button's scope.
     */
    toggleTree(event) {
        if (event) event.preventDefault();
        this.closeActions();
        this._signalTree('cb:tree:toggle');
    }

    /** Keeps the topbar button in step with a panel it cannot see. */
    _onTreeState(event) {
        const open = event.detail?.open === true;
        const toggle = this.element.querySelector('.cb-shell__tree-toggle');
        if (!toggle) return;
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.classList.toggle('cb-shell__tree-toggle--open', open);
        // Or focus falls to <body> and the next Tab restarts from the top
        // of the shell.
        if (!open) toggle.focus({ preventScroll: true });
    }

    _signalTree(type) {
        this.element.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: { areaId: this.areaIdValue },
        }));
    }

    /**
     * Selecting a row does what clicking the element does — opens its sidebar
     * — and brings the preview to it, since the row may be a page away.
     */
    _onTreeSelect(event) {
        const { kind, id } = event.detail ?? {};
        if (!Number.isFinite(id)) return;
        if (kind === 'block') {
            this._mountSidebar(id);
            this._postToPreview({ type: 'cb:focus:block', blockId: id });
            this._postToPreview({ type: 'cb:block:scroll-into-view', blockId: id });
        } else if (kind === 'section') {
            this._mountSectionSettings(id);
            this._postToPreview({ type: 'cb:focus:section', sectionId: id });
            this._postToPreview({ type: 'cb:section:scroll-into-view', sectionId: id });
        }
    }

    _onTreeDuplicate(event) {
        const { kind, id } = event.detail ?? {};
        if (!Number.isFinite(id)) return;
        if (kind === 'block') this._duplicateBlock(id);
        else if (kind === 'section') this._duplicateSection(id);
    }

    _onTreeDelete(event) {
        const { kind, id } = event.detail ?? {};
        if (!Number.isFinite(id)) return;
        if (kind === 'block') this._deleteBlock(id);
        else if (kind === 'section') this._deleteSection(id);
    }

    _onTreeSectionMove(event) {
        const { sectionId, position } = event.detail ?? {};
        this._reorderSection(sectionId, position);
    }

    _onTreeBlockMove(event) {
        const { blockId, toColumnId, position } = event.detail ?? {};
        this._moveBlock(blockId, toColumnId, position);
    }

    /** Tells the tree its outline is stale. A closed panel just notes it. */
    _invalidateTree() {
        this.element.dispatchEvent(new CustomEvent('cb:tree:invalidate', {
            bubbles: true,
            detail: { areaId: this.areaIdValue },
        }));
    }

    /**
     * The sidebar *is* the selection, so the tree's highlight follows its
     * mount markers rather than tracking a second state.
     */
    _broadcastSelection() {
        const { blockId, sectionId } = this._selectionIds();
        this.element.dispatchEvent(new CustomEvent('cb:tree:selection', {
            bubbles: true,
            detail: { areaId: this.areaIdValue, blockId, sectionId },
        }));
    }
}
