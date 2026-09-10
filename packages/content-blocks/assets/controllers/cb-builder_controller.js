import { Controller } from '@hotwired/stimulus';

/**
 * Bridges the parent admin window with the iframe preview and the sidebar, and
 * owns every AJAX call. Five `cb:*` events are public API; the rest are not.
 *
 * @see docs/internals/frontend.md#the-cb-event-contract
 */
export default class extends Controller {
    static targets = [
        'iframe',
        'sidebar',
        'sidebarContent',
        'sidebarResize',
        'sidebarToggle',
        'progress',
        'savedFlash',
        'saveError',
        'undoBar',
        'undoLabel',
        'undoButton',
        'replacePicker',
        'replacePickerSearch',
        'replacePickerList',
        'replacePickerStatus',
        'templatePicker',
        'templatePickerSearch',
        'templatePickerList',
        'templatePickerStatus',
        'importExportPicker',
        'importFile',
        'importExportStatus',
        'actionsMenu',
        'actionsToggle',
        'actionsList',
    ];

    static values = {
        areaId: Number,
        iframeUrl: String,
    };

    /** Debounce window (ms) on the replace-picker search input. */
    static REPLACE_PICKER_DEBOUNCE_MS = 250;
    /** Confirm prompt shown before applying a destructive replace. */
    static REPLACE_PICKER_CONFIRM_FALLBACK =
        'Are you sure you want to overwrite the current content with the selected one?';
    /** Confirm prompt shown before discarding all unpublished draft changes. */
    static DISCARD_CONFIRM_FALLBACK =
        'Are you sure you want to discard all unpublished changes? This cannot be undone.';
    /** Prompt asking for a name when saving a section to the library. */
    static TEMPLATE_NAME_FALLBACK = 'Name this section template:';
    /** Confirm prompt shown before deleting a library template. */
    static TEMPLATE_DELETE_CONFIRM_FALLBACK =
        'Delete this section template? This cannot be undone.';

    /**
     * localStorage, because "copy here, paste over there" means leaving this
     * page — and therefore the payload is user-writable, and untrusted.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    static CLIPBOARD_KEY = 'cb-builder.clipboard';

    static SIDEBAR_WIDTH_KEY = 'cb-builder.sidebarWidth';
    static SIDEBAR_COLLAPSED_KEY = 'cb-builder.sidebarCollapsed';
    static SIDEBAR_MIN_WIDTH = 280;
    static SIDEBAR_MAX_WIDTH = 800;
    /**
     * Autosave fires many saved-events per second while typing; the iframe
     * refreshes only after a pause.
     */
    static SAVE_RELOAD_DEBOUNCE_MS = 500;
    static MOBILE_BREAKPOINT = '(max-width: 768px)';
    /**
     * The editor's only one-click recovery from a delete short of discarding
     * the whole draft.
     *
     * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
     */
    static UNDO_TIMEOUT_MS = 6000;
    /**
     * Below its target width a viewport button is hidden: emulating an
     * iPad-width preview on a phone screen only clips the iframe.
     */
    static VIEWPORT_MIN_WIDTHS = { desktop: 0, tablet: 768, mobile: 375 };

    connect() {
        this._onMessage = this._onMessage.bind(this);
        this._onBlockSaved = this._onBlockSaved.bind(this);
        this._onSectionSaved = this._onSectionSaved.bind(this);
        this._onResizeMove = this._onResizeMove.bind(this);
        this._onResizeEnd = this._onResizeEnd.bind(this);
        this._onWindowResize = this._onWindowResize.bind(this);
        this._onLiveConnect = this._onLiveConnect.bind(this);
        this._onSaveError = this._onSaveError.bind(this);
        this._onAreaChanged = this._onAreaChanged.bind(this);
        this._onDocumentPointerDown = this._onDocumentPointerDown.bind(this);
        this._onDocumentKeydown = this._onDocumentKeydown.bind(this);
        this._onTreeSelect = this._onTreeSelect.bind(this);
        this._onTreeDuplicate = this._onTreeDuplicate.bind(this);
        this._onTreeDelete = this._onTreeDelete.bind(this);
        this._onTreeSectionMove = this._onTreeSectionMove.bind(this);
        this._onTreeBlockMove = this._onTreeBlockMove.bind(this);
        this._onTreeState = this._onTreeState.bind(this);

        window.addEventListener('message', this._onMessage);
        window.addEventListener('resize', this._onWindowResize);
        // Both close on outside-click and Escape, and both live outside
        // this controller's own handlers — hence document-level listeners.
        document.addEventListener('pointerdown', this._onDocumentPointerDown);
        document.addEventListener('keydown', this._onDocumentKeydown);
        // BlockComponent.save() and the section-settings form both
        // dispatchBrowserEvent on save; the events bubble up to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);
        // live:connect bubbles from every Live Component in the sidebar;
        // cb:save:error from the section form and the hooks below.
        this.element.addEventListener('live:connect', this._onLiveConnect);
        this.element.addEventListener('cb:save:error', this._onSaveError);
        // Inbound: a shell fragment (or the host) changed the area through
        // its own endpoints and asks the builder to catch up.
        this.element.addEventListener('cb:area:changed', this._onAreaChanged);
        // The tree panel signals; this controller acts, so every mutation
        // still goes through one queue. See docs/internals/frontend.md
        this.element.addEventListener('cb:tree:select', this._onTreeSelect);
        this.element.addEventListener('cb:tree:duplicate', this._onTreeDuplicate);
        this.element.addEventListener('cb:tree:delete', this._onTreeDelete);
        this.element.addEventListener('cb:tree:section:move', this._onTreeSectionMove);
        this.element.addEventListener('cb:tree:block:move', this._onTreeBlockMove);
        this.element.addEventListener('cb:tree:state', this._onTreeState);

        this._restoreSidebarWidth();
        this._restoreSidebarCollapsed();
        this._refreshViewportButtons();
        // Remember the initial empty-state HTML so we can restore it
        // when the user clicks outside any focused element.
        if (this.hasSidebarContentTarget) {
            this._sidebarEmptyHtml = this.sidebarContentTarget.innerHTML;
        }
        // The library is the empty sidebar's whole content when nothing is
        // selected, so it loads with the builder rather than on demand.
        this._showTemplates();
        // Mobile boots unfocused; collapse the sheet to a strip rather
        // than a half-screen pane over the preview.
        this._syncEmptySidebar();
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        window.removeEventListener('resize', this._onWindowResize);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        this.element.removeEventListener('live:connect', this._onLiveConnect);
        this.element.removeEventListener('cb:save:error', this._onSaveError);
        this.element.removeEventListener('cb:area:changed', this._onAreaChanged);
        this.element.removeEventListener('cb:tree:select', this._onTreeSelect);
        this.element.removeEventListener('cb:tree:duplicate', this._onTreeDuplicate);
        this.element.removeEventListener('cb:tree:delete', this._onTreeDelete);
        this.element.removeEventListener('cb:tree:section:move', this._onTreeSectionMove);
        this.element.removeEventListener('cb:tree:block:move', this._onTreeBlockMove);
        this.element.removeEventListener('cb:tree:state', this._onTreeState);
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
        document.removeEventListener('pointerdown', this._onDocumentPointerDown);
        document.removeEventListener('keydown', this._onDocumentKeydown);
        clearTimeout(this._reloadTimer);
        clearTimeout(this._undoTimer);
    }

    // ---------- Topbar Actions menu ----------

    /** Action: the "Actions" button in the topbar. */
    toggleActions(event) {
        if (event) event.preventDefault();
        if (!this.hasActionsListTarget) return;
        if (this.actionsListTarget.hidden) {
            this._setActionsOpen(true);
        } else {
            this._setActionsOpen(false);
        }
    }

    /** Closes the menu; safe to call when there is no menu at all. */
    closeActions() {
        if (!this.hasActionsListTarget || this.actionsListTarget.hidden) return;
        this._setActionsOpen(false);
    }

    _setActionsOpen(open) {
        this.actionsListTarget.hidden = !open;
        if (this.hasActionsToggleTarget) {
            this.actionsToggleTarget.setAttribute('aria-expanded', open ? 'true' : 'false');
            this.actionsToggleTarget.classList.toggle('cb-shell__actions-toggle--open', open);
            // Without this, focus falls to <body> and the next Tab
            // restarts from the top of the shell.
            if (!open) this.actionsToggleTarget.focus({ preventScroll: true });
        }
    }

    /**
     * The menu closes; a picker only on its own backdrop, so a click inside
     * one — or on its list's scrollbar — never dismisses it.
     */
    _onDocumentPointerDown(event) {
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
        if ((event.ctrlKey || event.metaKey) && !event.altKey && !event.shiftKey) {
            const key = event.key?.toLowerCase();
            if ((key === 'c' || key === 'v') && !this._isTextEditing()) {
                event.preventDefault();
                if (key === 'c') this.copySelection(); else this.pasteClipboard();

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
        if (this.hasImportExportPickerTarget && !this.importExportPickerTarget.hidden) {
            this.closeImportExport();
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

    _onWindowResize() {
        this._refreshViewportButtons();
        // Crossing the mobile breakpoint mid-session — re-collapse the
        // sidebar if we just entered mobile with no focused entity.
        this._syncEmptySidebar();
    }

    /**
     * Hides buttons wider than the shell, falling back to desktop if the
     * active one just went — or the iframe stays stuck at a clipped size.
     */
    _refreshViewportButtons() {
        const shellWidth = this.element.clientWidth || window.innerWidth;
        const buttons = this.element.querySelectorAll('.cb-shell__viewport-btn');
        let activeStillVisible = false;
        buttons.forEach((btn) => {
            const viewport = btn.dataset.cbBuilderViewportParam;
            const minWidth = this.constructor.VIEWPORT_MIN_WIDTHS[viewport] ?? 0;
            const fits = minWidth <= shellWidth;
            btn.hidden = !fits;
            if (fits && btn.classList.contains('cb-shell__viewport-btn--active')) {
                activeStillVisible = true;
            }
        });
        if (!activeStillVisible) {
            this._applyViewport('desktop');
        }
    }

    _applyViewport(viewport) {
        const buttons = this.element.querySelectorAll('.cb-shell__viewport-btn');
        buttons.forEach((btn) => {
            btn.classList.toggle(
                'cb-shell__viewport-btn--active',
                btn.dataset.cbBuilderViewportParam === viewport,
            );
        });
        if (this.hasIframeTarget) {
            const widths = { desktop: '100%', tablet: '768px', mobile: '375px' };
            this.iframeTarget.style.maxWidth = widths[viewport] ?? '100%';
            this.iframeTarget.style.margin = viewport === 'desktop' ? '0' : '0 auto';
        }
    }

    /**
     * Preserves scrollY across the reload, and keeps the progress bar up
     * throughout so feedback is continuous.
     */
    reload() {
        if (!this.hasIframeTarget) return;

        let scrollY = 0;
        try {
            scrollY = this.iframeTarget.contentWindow?.scrollY ?? 0;
        } catch (_) {
            // Cross-origin would throw; ignore and restore to 0.
        }

        // A just-inserted section is the one thing the editor wants to see,
        // and restoring the old scroll would hide it.
        const scrollToSectionId = this._pendingScrollSectionId ?? null;
        this._pendingScrollSectionId = null;

        this._beginLoading();
        const onLoad = () => {
            this.iframeTarget.removeEventListener('load', onLoad);
            if (scrollToSectionId !== null) {
                this._postToPreview({ type: 'cb:section:scroll-into-view', sectionId: scrollToSectionId });
            } else {
                try {
                    this.iframeTarget.contentWindow?.scrollTo(0, scrollY);
                } catch (_) {
                    // Same as above.
                }
            }
            this._restorePinnedFocus();
            // One frame, so the overlay has re-pinned focus and the rect
            // is queryable before we measure.
            requestAnimationFrame(() => this._ensureFocusedVisible());
            this._endLoading();
        };
        this.iframeTarget.addEventListener('load', onLoad);

        try {
            this.iframeTarget.contentWindow?.location.reload();
        } catch (_) {
            // Fallback when the iframe document isn't accessible.
            this.iframeTarget.src = this.iframeUrlValue;
        }
    }

    /**
     * Re-pins focus after a reload, or an autosave would wipe the outline and
     * toolbar. The entity comes from the sidebar's mount markers.
     *
     * @see docs/internals/frontend.md#focus-and-the-sidebar
     */
    _restorePinnedFocus() {
        if (!this.hasSidebarTarget || !this.hasIframeTarget) return;
        const blockId = this.sidebarTarget.getAttribute('data-cb-sidebar-block-id');
        const sectionId = this.sidebarTarget.getAttribute('data-cb-sidebar-section-id');

        let message = null;
        if (blockId) {
            message = { type: 'cb:focus:block', blockId: parseInt(blockId, 10) };
        } else if (sectionId) {
            message = { type: 'cb:focus:section', sectionId: parseInt(sectionId, 10) };
        }
        if (!message) return;

        try {
            this.iframeTarget.contentWindow?.postMessage(message, window.location.origin);
        } catch (_) {
            // Cross-origin / detached frame — silently ignore.
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

    async publish(event) {
        if (event) event.preventDefault();
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/publish`);
        if (result === null) return;
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
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/discard`);
        if (result === null) return;
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

    // ---------- Tree panel ----------

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

    _applyDraftState(hasUnpublishedChanges) {
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

        // Last, and every mutation path passes through here: this is the one
        // place the tree has to be told the area moved under it. A listener
        // that throws must not cost the buttons above their sync.
        this._invalidateTree();
    }

    async addSection(event) {
        if (event) event.preventDefault();
        const layout = event?.params?.layout ?? 'full';
        await this._addSection(layout);
    }

    /**
     * Asks the next reload to scroll here instead of restoring the previous
     * position. A no-op for a missing id, so callers need no guard.
     */
    _scrollPreviewTo(sectionId) {
        const id = parseInt(sectionId, 10);
        if (Number.isFinite(id)) this._pendingScrollSectionId = id;
    }

    /** Posts a message to the preview overlay, swallowing a dead iframe. */
    _postToPreview(message) {
        if (!this.hasIframeTarget) return;
        try {
            this.iframeTarget.contentWindow?.postMessage(message, window.location.origin);
        } catch (_) {
            // Iframe gone or cross-origin — nothing to steer.
        }
    }

    async _addSection(layout) {
        const allowed = ['full', 'two_cols', 'three_cols'];
        const finalLayout = allowed.includes(layout) ? layout : 'full';
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/sections`, { layout: finalLayout });
        // It lands at the end of the area, off screen on any long page —
        // without this the editor gets no feedback at all.
        this._scrollPreviewTo(result?.id);
        this._afterStructuralOp();
        // Configure it immediately. The reload above runs in parallel; the
        // sidebar fetches its HTML separately.
        if (result?.id) {
            this._mountSectionSettings(result.id);
        }
    }

    async _addBlock(columnId, blockType) {
        if (!columnId || !blockType) return;
        const result = await this._jsonRequest('POST', `/_content-blocks/column/${columnId}/blocks`, { type: blockType });
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
     * Inserts a rendered block at the end of its column, ahead of the
     * "+ Block" button. Falls back to a reload if the iframe is unreachable.
     */
    _insertBlockInPreview(columnId, html) {
        if (!this.hasIframeTarget) {
            this.reload();
            return;
        }
        try {
            this.iframeTarget.contentWindow?.postMessage(
                { type: 'cb:block:insert', columnId: parseInt(columnId, 10), html },
                window.location.origin,
            );
        } catch (_) {
            this.reload();
        }
    }

    async _deleteBlock(blockId) {
        if (!blockId) return;
        const result = await this._jsonRequest('DELETE', `/_content-blocks/block/${blockId}`);
        // Delete failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        if (this._isSidebarFocusedOnBlock(blockId)) {
            this._resetSidebarToEmptyState();
        }
        // A delete is a pure removal: nothing new to render, so drop the block
        // from the preview in place instead of reloading the whole iframe.
        this._applyDraftState(true);
        this._removeBlockFromPreview(blockId);
        this._offerUndo('block', blockId);
    }

    /**
     * Asks the preview overlay to remove a block element in place. Falls back
     * to a full reload if the iframe can't be reached.
     */
    _removeBlockFromPreview(blockId) {
        if (!this.hasIframeTarget) {
            this.reload();
            return;
        }
        try {
            this.iframeTarget.contentWindow?.postMessage(
                { type: 'cb:block:remove', blockId },
                window.location.origin,
            );
        } catch (_) {
            this.reload();
        }
    }

    async _moveBlock(blockId, toColumnId, position) {
        if (!blockId || !toColumnId) return;
        const finalPosition = position ?? 0;
        const result = await this._jsonRequest('POST', `/_content-blocks/block/${blockId}/move`, {
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
        const result = await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/move`, { direction });
        if (result === null) return;
        this._applyDraftState(true);
        // Already at the edge — the server couldn't move it, so neither do we.
        if (result.moved === false) return;
        this._reorderInPreview({ type: 'cb:section:move:apply', sectionId, direction });
    }

    async _reorderSection(sectionId, position) {
        if (!sectionId || !Number.isInteger(position) || position < 0) return;
        const result = await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/move`, { position });
        if (result === null) return;
        this._applyDraftState(true);
        if (result.moved === false) return;
        this._reorderInPreview({ type: 'cb:section:reorder:apply', sectionId, position });
    }

    /**
     * Moves the **live** node, keeping its DOM and JS state — a re-render
     * would discard both. Falls back to a reload.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    _reorderInPreview(message) {
        if (!this.hasIframeTarget) {
            this.reload();
            return;
        }
        try {
            this.iframeTarget.contentWindow?.postMessage(message, window.location.origin);
        } catch (_) {
            this.reload();
        }
    }

    async _duplicateSection(sectionId) {
        if (!sectionId) return;
        const result = await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/duplicate`);
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
        const result = await this._jsonRequest('POST', `/_content-blocks/block/${blockId}/duplicate`);
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

    /**
     * Drops a rendered duplicate right after its source node, falling back to
     * a full reload if the iframe is unreachable.
     */
    _duplicateInPreview(message) {
        if (!this.hasIframeTarget) {
            this.reload();
            return;
        }
        try {
            this.iframeTarget.contentWindow?.postMessage(message, window.location.origin);
        } catch (_) {
            this.reload();
        }
    }

    async _deleteSection(sectionId) {
        if (!sectionId) return;
        const result = await this._jsonRequest('DELETE', `/_content-blocks/section/${sectionId}`);
        // Delete failed (CSRF/access/network) — leave the preview untouched.
        if (result === null) return;
        // The direct case. A focused block *inside* this section is caught
        // after reload, by the overlay's `cb:focus:not-found` reply.
        if (this._isSidebarFocusedOnSection(sectionId)) {
            this._resetSidebarToEmptyState();
        }
        this._afterStructuralOp();
        this._offerUndo('section', sectionId);
    }

    _isSidebarFocusedOnBlock(blockId) {
        if (!this.hasSidebarTarget) return false;
        return this.sidebarTarget.getAttribute('data-cb-sidebar-block-id') === String(blockId);
    }

    _isSidebarFocusedOnSection(sectionId) {
        if (!this.hasSidebarTarget) return false;
        return this.sidebarTarget.getAttribute('data-cb-sidebar-section-id') === String(sectionId);
    }

    /**
     * Every structural op leaves at least one unpublished change, so the draft
     * state is flipped on proactively rather than round-tripped for.
     */
    _afterStructuralOp() {
        this._applyDraftState(true);
        this.reload();
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

    // ---------- Save-failure feedback ----------

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

    _onSaveError() {
        this._showSaveError();
    }

    /**
     * Persistent, unlike the "Saved" flash: the editor must know their latest
     * edits are not stored.
     *
     * @see docs/internals/frontend.md#feedback-what-stays-and-what-flashes
     */
    _showSaveError() {
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = false;
    }

    _clearSaveError() {
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = true;
    }

    // ---------- Clipboard (copy / paste) ----------

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

        const entry = await this._jsonRequest('GET', `/_content-blocks/${scope}/${blockId || sectionId}/copy`);
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
            `/_content-blocks/area/${this.areaIdValue}/paste`,
            {
                payload: entry,
                ...(blockId ? { targetBlockId: blockId } : {}),
                ...(sectionId ? { targetSectionId: sectionId } : {}),
            },
            // A reason the editor can act on, not a failed save.
            { tolerate: [422] },
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
        };
        const [key, fallback] = messages[error] ?? messages.unreadable_clipboard;
        // An unreadable or stale entry will never paste anywhere; keeping
        // it only lets the editor hit the same wall again.
        if (error !== 'no_target') this._clearClipboard();
        this._notify(this._t(key, fallback));
    }

    /** The open sidebar's entity. Both null when nothing is selected. */
    _selectionIds() {
        if (!this.hasSidebarTarget) return { blockId: null, sectionId: null };
        const blockId = this.sidebarTarget.getAttribute('data-cb-sidebar-block-id');
        const sectionId = this.sidebarTarget.getAttribute('data-cb-sidebar-section-id');

        return {
            blockId: blockId ? parseInt(blockId, 10) : null,
            sectionId: sectionId ? parseInt(sectionId, 10) : null,
        };
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

    // ---------- Undo delete (snackbar) ----------

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
        this.undoBarTarget.hidden = false;
        clearTimeout(this._undoTimer);
        this._undoTimer = setTimeout(() => this._hideUndo(), this.constructor.UNDO_TIMEOUT_MS);
    }

    /**
     * The same snackbar with nothing to click. It shares the slot with the undo
     * offer, and clears it rather than leaving an invisible one armed.
     */
    _notify(message) {
        if (!this.hasUndoBarTarget) return;
        this._pendingUndo = null;
        if (this.hasUndoLabelTarget) this.undoLabelTarget.textContent = message;
        if (this.hasUndoButtonTarget) this.undoButtonTarget.hidden = true;
        this.undoBarTarget.hidden = false;
        clearTimeout(this._undoTimer);
        this._undoTimer = setTimeout(() => this._hideUndo(), this.constructor.UNDO_TIMEOUT_MS);
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
            `/_content-blocks/${pending.kind}/${pending.id}/restore`,
        );
        if (result === null) return;
        // It comes back with its full subtree, and undo is rare — a full
        // reload is the simplest correct refresh.
        this._applyDraftState(true);
        this.reload();
    }

    setViewport(event) {
        if (event) event.preventDefault();
        const viewport = event?.params?.viewport ?? 'desktop';
        this._applyViewport(viewport);
    }

    /**
     * Emits one generic event carrying the button's key, rather than per-key
     * event types, which keeps add/removeEventListener simple for the host.
     *
     * @see docs/internals/builder-extensions.md#what-the-package-renders
     */
    runAction(event) {
        if (event) event.preventDefault();
        const key = event?.params?.actionKey;
        if (!key) return;
        this.closeActions();
        this.element.dispatchEvent(new CustomEvent('cb:builder:action', {
            bubbles: true,
            detail: { key, areaId: this.areaIdValue, button: event.currentTarget },
        }));
    }

    _onMessage(event) {
        // Origin check: only trust same-origin posts.
        if (event.origin !== window.location.origin) return;

        const data = event.data;
        if (!data || typeof data !== 'object' || typeof data.type !== 'string') return;
        if (!data.type.startsWith('cb:')) return;

        switch (data.type) {
            case 'cb:ready':
                break;
            case 'cb:block:edit':
                this._mountSidebar(data.blockId);
                break;
            case 'cb:block:delete-requested':
                this._deleteBlock(data.blockId);
                break;
            case 'cb:block:add-requested':
                this._addBlock(data.columnId, data.blockType);
                break;
            case 'cb:block:reorder':
                this._moveBlock(data.blockId, data.toColumnId, data.position);
                break;
            case 'cb:section:add-requested':
                this._addSection(data.layout);
                break;
            case 'cb:section:move-requested':
                this._moveSection(data.sectionId, data.direction);
                break;
            case 'cb:section:reorder':
                this._reorderSection(data.sectionId, data.position);
                break;
            case 'cb:section:duplicate-requested':
                this._duplicateSection(data.sectionId);
                break;
            case 'cb:section:save-template-requested':
                this._saveSectionAsTemplate(data.sectionId);
                break;
            case 'cb:template:insert-requested':
                this.openTemplatePicker();
                break;
            // Relayed by the overlay: what gets copied is whatever the
            // sidebar has open, which only this side knows.
            case 'cb:clipboard:copy-requested':
                this.copySelection();
                break;
            case 'cb:clipboard:paste-requested':
                this.pasteClipboard();
                break;
            case 'cb:section:delete-requested':
                this._deleteSection(data.sectionId);
                break;
            case 'cb:block:duplicate-requested':
                this._duplicateBlock(data.blockId);
                break;
            case 'cb:section:settings':
                this._mountSectionSettings(data.sectionId);
                break;
            case 'cb:preview:outside-click':
                this._onPreviewOutsideClick();
                break;
            case 'cb:focus:not-found':
                // The focused element no longer exists — a section delete
                // that cascaded to a child block. Clear the stale form.
                this._resetSidebarToEmptyState();
                break;
            case 'cb:reorder:desync':
                // The overlay couldn't find a node it was asked to relocate —
                // its DOM drifted from the server's model. Reload to resync.
                this.reload();
                break;
            default:
                // Unknown cb:* message — silently ignore (forward-compat).
                break;
        }
    }

    /**
     * Fetches the rendered BlockComponent for the given block id and
     * injects it into the sidebar. Stimulus + Live Component auto-connect.
     */
    async _mountSidebar(blockId) {
        await this._mountSidebarFrom(`/_content-blocks/block/${blockId}/edit`, {
            'data-cb-sidebar-block-id': String(blockId),
        });
    }

    /** Section settings: same fetch/inject flow, different endpoint. */
    async _mountSectionSettings(sectionId) {
        await this._mountSidebarFrom(`/_content-blocks/section/${sectionId}/settings`, {
            'data-cb-sidebar-section-id': String(sectionId),
        });
    }

    async _mountSidebarFrom(url, dataAttrs = {}) {
        if (!this.hasSidebarTarget || !this.hasSidebarContentTarget) return;

        // The user just asked to edit something.
        this._setSidebarCollapsed(false);

        this._beginLoading();
        try {
            const response = await fetch(url, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                console.error('[cb-builder] failed to load', url, response.status);
                return;
            }

            this.sidebarContentTarget.innerHTML = await response.text();
            this._clearSidebarDataAttrs();
            for (const [k, v] of Object.entries(dataAttrs)) {
                this.sidebarTarget.setAttribute(k, v);
            }
            this._broadcastSelection();
            // So the focused element is not covered by the mobile sheet.
            this._ensureFocusedVisible();
        } catch (e) {
            console.error('[cb-builder] mount error', e);
        } finally {
            this._endLoading();
        }
    }

    _clearSidebarDataAttrs() {
        for (const key of ['cb-sidebar-block-id', 'cb-sidebar-section-id']) {
            this.sidebarTarget.removeAttribute('data-' + key);
        }
    }

    /**
     * Back to the hint and the "Add section" buttons, on an outside click or
     * after an op that removed the focused element.
     */
    _resetSidebarToEmptyState() {
        if (!this.hasSidebarContentTarget) return;
        if (typeof this._sidebarEmptyHtml !== 'string') return;
        this.sidebarContentTarget.innerHTML = this._sidebarEmptyHtml;
        this._clearSidebarDataAttrs();
        this._broadcastSelection();
        // The snapshot's library list is empty and only this controller
        // knows what was in it, so repaint from cache.
        this._showTemplates();
        // Mobile: nothing focused → collapse the sheet to its 32px
        // strip so the preview reclaims the screen.
        this._syncEmptySidebar();
    }

    /**
     * Read as "clear the focused form": the sidebar stays on screen and
     * reverts to its empty state.
     */
    _onPreviewOutsideClick() {
        this._resetSidebarToEmptyState();
    }

    /**
     * Here rather than on the launcher, which re-parents the <dialog> to
     * document.body — moving this button out of its Stimulus scope.
     */
    close(event) {
        if (event) event.preventDefault();
        this.element.closest('dialog')?.close();
    }

    /** Action: toggle the sidebar between expanded and collapsed widths. */
    toggleSidebar(event) {
        if (event) event.preventDefault();
        const wasCollapsed = this.element.classList.contains('cb-shell--sidebar-collapsed');
        this._setSidebarCollapsed(!wasCollapsed);
    }

    _setSidebarCollapsed(collapsed, { persist = true } = {}) {
        this.element.classList.toggle('cb-shell--sidebar-collapsed', collapsed);
        if (this.hasSidebarToggleTarget) {
            this.sidebarToggleTarget.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
        if (persist) {
            try {
                window.localStorage.setItem(
                    this.constructor.SIDEBAR_COLLAPSED_KEY,
                    collapsed ? '1' : '0',
                );
            } catch (_) {
                // ignore — non-blocking persistence
            }
        }
        // Mobile bottom-sheet just slid up over the iframe — make sure
        // the focused element didn't end up hidden underneath.
        if (!collapsed) this._ensureFocusedVisible();
    }

    /**
     * Mobile-only: an empty-state hint should not steal half the screen.
     * `persist: false` leaves the user's own preference untouched.
     */
    _syncEmptySidebar() {
        if (!this._isMobile()) return;
        if (!this.hasSidebarTarget) return;
        const hasFocus =
            this.sidebarTarget.hasAttribute('data-cb-sidebar-block-id') ||
            this.sidebarTarget.hasAttribute('data-cb-sidebar-section-id');
        if (!hasFocus) {
            this._setSidebarCollapsed(true, { persist: false });
        }
    }

    /**
     * Mobile-only: scrolls the iframe just enough to lift the focused element
     * above the bottom sheet, and only when it is actually hidden.
     */
    _ensureFocusedVisible() {
        if (!this._isMobile()) return;
        if (!this.hasIframeTarget || !this.hasSidebarTarget) return;
        if (this.element.classList.contains('cb-shell--sidebar-collapsed')) return;

        const blockId = this.sidebarTarget.getAttribute('data-cb-sidebar-block-id');
        const sectionId = this.sidebarTarget.getAttribute('data-cb-sidebar-section-id');
        if (!blockId && !sectionId) return;

        let doc;
        try { doc = this.iframeTarget.contentDocument; } catch (_) { return; }
        if (!doc) return;

        const selector = blockId
            ? `[data-cb-block-id="${blockId}"]`
            : `[data-cb-section-id="${sectionId}"]`;
        const el = doc.querySelector(selector);
        if (!el) return;

        const iframeRect = this.iframeTarget.getBoundingClientRect();
        // The layout height, so this measures correctly even while the
        // sheet is still sliding up.
        const sidebarHeight = this.sidebarTarget.offsetHeight;
        const visibleBottom = iframeRect.height - sidebarHeight;
        if (visibleBottom <= 0) return;

        const elRect = el.getBoundingClientRect();
        const overflow = elRect.bottom - visibleBottom;
        if (overflow <= 0) return;

        try {
            this.iframeTarget.contentWindow?.scrollBy({
                top: overflow + 16,
                behavior: 'smooth',
            });
        } catch (_) {
            // Cross-origin / detached frame — silently ignore.
        }
    }

    /**
     * Hot-swaps the focused block where possible, the server having the final
     * say. Both paths share one debounce, so a burst of saves is one refresh.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    _onBlockSaved(event) {
        this._applyDraftState(true);
        this._flashSaved();
        const blockId = this.hasSidebarTarget
            ? this.sidebarTarget.getAttribute('data-cb-sidebar-block-id')
            : null;
        if (blockId) {
            this._scheduleBlockRefresh(parseInt(blockId, 10));
        } else {
            this._scheduleReload();
        }
    }

    _onSectionSaved(event) {
        this._applyDraftState(true);
        this._flashSaved();
        // Settings never change structure, so patching the wrapper in
        // place is always safe. Falls back if the id is unknown.
        const sectionId = this.hasSidebarTarget
            ? this.sidebarTarget.getAttribute('data-cb-sidebar-section-id')
            : null;
        if (sectionId) {
            this._scheduleSectionRefresh(parseInt(sectionId, 10));
        } else {
            this._scheduleReload();
        }
    }

    _scheduleReload() {
        clearTimeout(this._reloadTimer);
        this._reloadTimer = setTimeout(
            () => this.reload(),
            this.constructor.SAVE_RELOAD_DEBOUNCE_MS,
        );
    }

    _scheduleBlockRefresh(blockId) {
        clearTimeout(this._reloadTimer);
        this._reloadTimer = setTimeout(
            () => this._refreshBlock(blockId),
            this.constructor.SAVE_RELOAD_DEBOUNCE_MS,
        );
    }

    /**
     * Any failure — network, missing block, a type that opts out — falls back
     * to a full reload, so the preview is never left stale.
     */
    async _refreshBlock(blockId) {
        if (!blockId || !this.hasIframeTarget) {
            this.reload();
            return;
        }

        this._beginLoading();
        let payload = null;
        try {
            const response = await fetch(`/_content-blocks/block/${blockId}/render`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.ok) {
                payload = await response.json().catch(() => null);
            }
        } catch (_) {
            // Network/detached frame — fall through to the full reload below.
        } finally {
            this._endLoading();
        }

        if (!payload || payload.hotReload !== true || typeof payload.html !== 'string') {
            this.reload();
            return;
        }

        try {
            this.iframeTarget.contentWindow?.postMessage(
                { type: 'cb:block:replace', blockId, html: payload.html },
                window.location.origin,
            );
        } catch (_) {
            // Couldn't reach the iframe — last-resort full reload.
            this.reload();
        }
    }

    _scheduleSectionRefresh(sectionId) {
        clearTimeout(this._reloadTimer);
        this._reloadTimer = setTimeout(
            () => this._refreshSection(sectionId),
            this.constructor.SAVE_RELOAD_DEBOUNCE_MS,
        );
    }

    /**
     * Patches the wrapper and column widths in place, falling back to a full
     * reload on any failure.
     */
    async _refreshSection(sectionId) {
        if (!sectionId || !this.hasIframeTarget) {
            this.reload();
            return;
        }

        this._beginLoading();
        let payload = null;
        try {
            const response = await fetch(`/_content-blocks/section/${sectionId}/render`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (response.ok) {
                payload = await response.json().catch(() => null);
            }
        } catch (_) {
            // Network/detached frame — fall through to the full reload below.
        } finally {
            this._endLoading();
        }

        if (!payload || payload.hotReload !== true || typeof payload.html !== 'string') {
            this.reload();
            return;
        }

        try {
            this.iframeTarget.contentWindow?.postMessage(
                { type: 'cb:section:patch', sectionId, html: payload.html },
                window.location.origin,
            );
        } catch (_) {
            this.reload();
        }
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

    // ---------- Replace-content picker ----------

    /**
     * First open loads the candidate list; later opens re-use the cache, so
     * hopping in and out does not flash the network.
     */
    async openReplacePicker(event) {
        if (event) event.preventDefault();
        if (!this.hasReplacePickerTarget) return;
        this.closeActions();
        this.replacePickerTarget.hidden = false;
        this._setBackdrop(true);

        if (this.hasReplacePickerSearchTarget) {
            // Don't clobber the user's last query when reopening — preserve
            // the filter so iterative searches feel continuous.
            this.replacePickerSearchTarget.focus({ preventScroll: true });
        }

        // First open OR a stale list (after a successful replace we reset
        // the cache so the next open shows fresh candidates).
        if (!this._replacePickerLoaded) {
            await this._loadReplaceCandidates('');
            this._replacePickerLoaded = true;
        }
    }

    /** Action: × button on the picker header. */
    closeReplacePicker(event) {
        if (event) event.preventDefault();
        if (!this.hasReplacePickerTarget) return;
        this.replacePickerTarget.hidden = true;
        this._setBackdrop(false);
    }

    /** Action: input event on the picker's search field (debounced). */
    onReplacePickerSearch(event) {
        const value = event?.target?.value ?? '';
        clearTimeout(this._replacePickerSearchTimer);
        this._replacePickerSearchTimer = setTimeout(() => {
            this._loadReplaceCandidates(value);
        }, this.constructor.REPLACE_PICKER_DEBOUNCE_MS);
    }

    async _loadReplaceCandidates(filter) {
        if (!this.hasReplacePickerListTarget) return;
        this._setReplacePickerStatus(this._t('cb.builder.replace.loading', 'Loading…'));
        this.replacePickerListTarget.innerHTML = '';

        const params = new URLSearchParams();
        if (filter) params.set('q', filter);
        const qs = params.toString();
        const url = `/_content-blocks/area/${this.areaIdValue}/replace-candidates${qs ? `?${qs}` : ''}`;

        let payload;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            payload = await response.json();
        } catch (e) {
            console.error('[cb-builder] replace candidates failed', e);
            this._setReplacePickerStatus(this._t('cb.builder.replace.error', 'Failed to load.'));
            return;
        }

        this._renderReplaceCandidates(payload, filter);
    }

    _renderReplaceCandidates(payload, filter) {
        const items = Array.isArray(payload?.items) ? payload.items : [];
        const list = this.replacePickerListTarget;
        list.innerHTML = '';

        if (items.length === 0) {
            this._setReplacePickerStatus(filter
                ? this._t('cb.builder.replace.empty_filtered', 'No results for this search')
                : this._t('cb.builder.replace.empty', 'No content available'),
            );
            return;
        }
        this._setReplacePickerStatus('');

        for (const item of items) {
            const li = document.createElement('li');
            li.className = 'cb-replace-picker__item';
            li.setAttribute('role', 'option');
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cb-replace-picker__item-btn';
            btn.dataset.cbReplaceSourceId = String(item.id ?? '');
            btn.textContent = item.label ?? `#${item.id}`;
            btn.addEventListener('click', () => this._confirmAndReplace(item));
            li.appendChild(btn);
            list.appendChild(li);
        }
    }

    async _confirmAndReplace(item) {
        const confirmText = this._t(
            'cb.builder.replace.confirm',
            this.constructor.REPLACE_PICKER_CONFIRM_FALLBACK,
        );
        if (!window.confirm(confirmText)) return;

        const result = await this._jsonRequest(
            'POST',
            `/_content-blocks/area/${this.areaIdValue}/replace-with/${item.id}`,
        );
        if (result === null) return;
        // The target's updatedAt just changed, so a sticky cache would
        // lie on the next open.
        this._replacePickerLoaded = false;
        this.closeReplacePicker();
        this._applyDraftState(result.hasUnpublishedChanges ?? true);
        this.reload();
    }

    _setReplacePickerStatus(text) {
        if (!this.hasReplacePickerStatusTarget) return;
        this.replacePickerStatusTarget.textContent = text;
    }

    // ---------- Section-template library ----------

    /**
     * Saves a section into the global library. The name prompt mirrors the
     * confirm-based UX used elsewhere, so there is no extra dialog markup.
     */
    async _saveSectionAsTemplate(sectionId) {
        const id = parseInt(sectionId, 10);
        if (!Number.isFinite(id)) return;

        const raw = window.prompt(this._t('cb.builder.template.name_prompt', this.constructor.TEMPLATE_NAME_FALLBACK));
        if (raw === null) return; // cancelled
        const name = raw.trim();
        if (!name) return;

        const result = await this._jsonRequest(
            'POST',
            `/_content-blocks/section/${id}/save-as-template`,
            { name },
        );
        if (result === null) return;
        // The library changed — drop the cache so the next paint re-fetches.
        this._templateItems = null;
        this._flashSaved();
        // The library is the one place that answers "did it save, and
        // under what name?", and where the next move starts.
        await this.openTemplatePicker();
    }

    /**
     * Also invoked from the iframe tray. First open loads the list; later ones
     * re-use the cache unless a save or delete invalidated it.
     */
    async openTemplatePicker(event) {
        if (event) event.preventDefault();
        this.closeActions();
        // The library lives in the empty sidebar, so opening it means
        // clearing the selection.
        this._resetSidebarToEmptyState();
        // Right when the user clicked away, wrong when they asked for the
        // library. Asking wins.
        if (this._isMobile()) {
            this._setSidebarCollapsed(false, { persist: false });
        }
        if (!this.hasTemplatePickerTarget) return;
        if (this.hasTemplatePickerSearchTarget) {
            this.templatePickerSearchTarget.focus({ preventScroll: true });
        }
        await this._showTemplates();
    }

    /**
     * Every return to the empty state rebuilds the sidebar DOM, so the list is
     * repainted each time — from cache, since clicking away is navigation.
     */
    async _showTemplates() {
        if (!this.hasTemplatePickerListTarget) return;
        if (this._templateItems === null || this._templateItems === undefined) {
            await this._loadTemplates(this._templatePickerFilter ?? '', 0, false);
            return;
        }
        if (this.hasTemplatePickerSearchTarget && this._templatePickerFilter) {
            this.templatePickerSearchTarget.value = this._templatePickerFilter;
        }
        this._paintTemplates();
    }

    /** Debounced input on the template picker's search field. */
    onTemplatePickerSearch(event) {
        const value = event?.target?.value ?? '';
        clearTimeout(this._templatePickerSearchTimer);
        this._templatePickerSearchTimer = setTimeout(() => {
            this._loadTemplates(value, 0, false);
        }, this.constructor.REPLACE_PICKER_DEBOUNCE_MS);
    }

    async _loadTemplates(filter, page = 0, append = false) {
        if (!this.hasTemplatePickerListTarget) return;
        this._templatePickerFilter = filter;
        if (!append) {
            this._templateItems = [];
            this.templatePickerListTarget.innerHTML = '';
            this._setTemplatePickerStatus(this._t('cb.builder.template.loading', 'Loading…'));
        }

        const params = new URLSearchParams();
        if (filter) params.set('q', filter);
        if (page > 0) params.set('page', String(page));
        const qs = params.toString();
        const url = `/_content-blocks/area/${this.areaIdValue}/section-templates${qs ? `?${qs}` : ''}`;

        let payload;
        try {
            const response = await fetch(url, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            payload = await response.json();
        } catch (e) {
            console.error('[cb-builder] section templates failed', e);
            this._setTemplatePickerStatus(this._t('cb.builder.template.error', 'Failed to load.'));
            return;
        }

        this._renderTemplates(payload, filter, append);
    }

    /**
     * Folds a page into the accumulated list, then repaints. The DOM is
     * disposable; `_templateItems` is what survives a sidebar rebuild.
     */
    _renderTemplates(payload, filter, append) {
        const items = Array.isArray(payload?.items) ? payload.items : [];
        this._templateItems = append ? [...(this._templateItems ?? []), ...items] : items;
        this._templateHasMore = payload?.hasMore === true;
        this._templatePage = payload?.page ?? 0;
        this._paintTemplates();
    }

    /** Renders `_templateItems`. Safe to call repeatedly. */
    _paintTemplates() {
        if (!this.hasTemplatePickerListTarget) return;
        const list = this.templatePickerListTarget;
        const filter = this._templatePickerFilter ?? '';
        const items = this._templateItems ?? [];
        list.innerHTML = '';

        if (items.length === 0) {
            this._setTemplatePickerStatus(filter
                ? this._t('cb.builder.template.empty_filtered', 'No templates match this search')
                : this._t('cb.builder.template.empty', 'No saved templates yet'),
            );
            return;
        }
        this._setTemplatePickerStatus('');

        for (const item of items) {
            list.appendChild(this._buildTemplateRow(item, filter));
        }

        if (this._templateHasMore) {
            const more = document.createElement('button');
            more.type = 'button';
            more.className = 'cb-template-picker__more';
            more.textContent = this._t('cb.builder.template.load_more', 'Load more');
            const nextPage = (this._templatePage ?? 0) + 1;
            more.addEventListener('click', () => this._loadTemplates(filter, nextPage, true));
            list.appendChild(more);
        }
    }

    /**
     * Draws a thumbnail from the server's poster spec, in the DOM rather than
     * rasterized. Null when there is nothing to draw.
     *
     * @see docs/internals/section-templates.md#why-the-poster-is-a-spec
     */
    _buildTemplatePoster(poster) {
        const columns = Array.isArray(poster?.columns) ? poster.columns : [];
        if (columns.length === 0) return null;

        const root = document.createElement('div');
        root.className = 'cb-template-poster';
        // Decorative: the card's name is its accessible label.
        root.setAttribute('aria-hidden', 'true');

        // Resolved server-side, preset included. `dark` comes with it, or
        // the copy disappears into its own ground.
        if (typeof poster.background === 'string' && poster.background !== '') {
            root.style.background = poster.background;
            root.classList.add('cb-template-poster--tinted');
            if (poster.dark) root.classList.add('cb-template-poster--dark');
        }

        for (const column of columns) {
            const col = document.createElement('div');
            col.className = 'cb-template-poster__col';
            // Real preset widths, so a sidebar column still reads as one.
            // Basis 0 plus grow keeps the ratio whatever the tiles measure.
            col.style.flexGrow = String(Number(column?.width) || 12);

            for (const tile of (Array.isArray(column?.tiles) ? column.tiles : [])) {
                col.appendChild(this._buildPosterTile(tile));
            }

            const more = Number(column?.more) || 0;
            if (more > 0) {
                const chip = document.createElement('span');
                chip.className = 'cb-template-poster__more';
                chip.textContent = `+${more}`;
                col.appendChild(chip);
            }

            root.appendChild(col);
        }

        return root;
    }

    /** One block, as one tile. Unknown kinds degrade to the generic chip. */
    _buildPosterTile(tile) {
        const kind = typeof tile?.kind === 'string' ? tile.kind : 'generic';
        const el = document.createElement('span');
        el.className = `cb-template-poster__tile cb-template-poster__tile--${kind}`;
        if (tile?.missing) el.classList.add('cb-template-poster__tile--missing');
        // From the core `styling` sub-form, so a section of coloured cards
        // still reads as coloured cards at thumbnail size.
        if (typeof tile?.background === 'string' && tile.background !== '') {
            el.style.background = tile.background;
            // A tile can be dark inside a light section (a red card on cream),
            // so it answers the contrast question for itself.
            el.classList.add(tile.backgroundDark
                ? 'cb-template-poster__tile--on-dark'
                : 'cb-template-poster__tile--on-light');
        }

        if (kind === 'image' && typeof tile.image === 'string' && tile.image !== '') {
            const img = document.createElement('img');
            // Ten cards' worth of thumbnails would otherwise all fetch at once
            // for a list the editor may never scroll through.
            img.loading = 'lazy';
            img.alt = '';
            // A template stores a path, not the file. The labelled tile
            // beats a broken-image glyph, which reads as the wrong fault.
            img.addEventListener('error', () => {
                img.remove();
                el.classList.remove('cb-template-poster__tile--image');
                el.classList.add('cb-template-poster__tile--generic');
                el.textContent = tile.label ?? '';
            }, { once: true });
            img.src = tile.image;
            el.appendChild(img);
            return el;
        }

        if (kind === 'rule') return el;

        // A block with no hint, or one this build lacks, names itself: an
        // empty tile would say "this block is empty", which is different.
        const named = kind === 'generic' || kind === 'image';
        const text = typeof tile?.text === 'string' && tile.text !== ''
            ? tile.text
            : (named ? (tile?.label ?? '') : '');
        el.textContent = text;
        if (text === '') el.classList.add('cb-template-poster__tile--blank');

        return el;
    }

    _buildTemplateRow(item, filter) {
        const li = document.createElement('li');
        li.className = 'cb-template-picker__item';
        li.setAttribute('role', 'option');

        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cb-template-picker__item-btn';

        // The poster is decorative — the name already labels the card — and
        // a payload with nothing drawable falls back to a plain named row.
        const poster = this._buildTemplatePoster(item.poster);
        if (poster) btn.appendChild(poster);

        const name = document.createElement('span');
        name.className = 'cb-template-picker__item-name';
        name.textContent = item.name ?? `#${item.id}`;
        btn.appendChild(name);

        const skipped = Array.isArray(item.skippedTypes) ? item.skippedTypes : [];

        if (item.insertable === false) {
            // Three distinct reasons, not interchangeable to whoever must
            // act on them. See section-templates.md
            btn.disabled = true;
            if (item.unreadableFormat) {
                btn.title = this._t(
                    'cb.builder.template.unreadable_format',
                    'Unavailable — saved by an incompatible version of the section library',
                );
            } else if (item.staleVersion) {
                btn.title = this._t(
                    'cb.builder.template.stale_version',
                    'Unavailable — saved under an older version of your content schema',
                );
            } else {
                btn.title = this._t(
                    'cb.builder.template.incompatible',
                    'Unavailable — none of its block types exist here: %types%',
                ).replace('%types%', skipped.join(', '));
            }
            li.classList.add('cb-template-picker__item--disabled');
        } else {
            // Partially usable templates stay clickable — the editor is warned
            // before the click rather than blocked from a useful insert.
            if (skipped.length > 0) {
                btn.title = this._t(
                    'cb.builder.template.partial',
                    '%count% block(s) will be skipped — missing type(s): %types%',
                ).replace('%count%', String(skipped.length)).replace('%types%', skipped.join(', '));
                li.classList.add('cb-template-picker__item--partial');
            }
            btn.addEventListener('click', () => this._confirmInsert(item));
        }
        li.appendChild(btn);

        if (item.canManage) {
            const del = document.createElement('button');
            del.type = 'button';
            del.className = 'cb-template-picker__delete';
            del.textContent = '🗑';
            del.title = this._t('cb.builder.template.delete', 'Delete template');
            del.setAttribute('aria-label', del.title);
            del.addEventListener('click', (e) => {
                e.stopPropagation();
                this._deleteTemplate(item, filter);
            });
            li.appendChild(del);
        }

        return li;
    }

    async _confirmInsert(item) {
        const result = await this._jsonRequest(
            'POST',
            `/_content-blocks/area/${this.areaIdValue}/insert-template/${item.id}`,
        );
        if (result === null) return;

        // The insert succeeded, possibly minus a few blocks.
        const warning = this._restoreWarning(result, {
            skipped: ['cb.builder.template.skipped_blocks', 'Inserted — %count% block(s) skipped, missing type(s): %types%'],
            unknown: ['cb.builder.template.warnings', 'Inserted, but some stored fields no longer exist on: %types%'],
        });

        this._scrollPreviewTo(result.sectionId);
        this._afterStructuralOp();

        if (warning !== null) {
            // They share a panel, so mounting the form would blank the only
            // element carrying the warning.
            this._setTemplatePickerStatus(warning);
        } else if (result.sectionId) {
            this._mountSectionSettings(result.sectionId);
        }
    }

    async _deleteTemplate(item, filter) {
        const confirmText = this._t(
            'cb.builder.template.delete_confirm',
            this.constructor.TEMPLATE_DELETE_CONFIRM_FALLBACK,
        );
        if (!window.confirm(confirmText)) return;

        const result = await this._jsonRequest('DELETE', `/_content-blocks/section-templates/${item.id}`);
        if (result === null) return;
        // From the top, so counts and pagination stay sane.
        await this._loadTemplates(filter ?? this._templatePickerFilter ?? '', 0, false);
    }

    _setTemplatePickerStatus(text) {
        if (!this.hasTemplatePickerStatusTarget) return;
        this.templatePickerStatusTarget.textContent = text;
    }

    /**
     * Reads precomputed strings off `data-i18n-*` attributes, falling back to
     * English — dependency-free, and still overridable by a host.
     */
    _t(key, fallback) {
        const attr = 'data-i18n-' + key.replace(/[._]/g, '-');
        const sources = [];
        if (this.hasReplacePickerTarget) sources.push(this.replacePickerTarget);
        if (this.hasTemplatePickerTarget) sources.push(this.templatePickerTarget);
        if (this.hasImportExportPickerTarget) sources.push(this.importExportPickerTarget);
        // Shell root: always present, carries topbar strings the pickers don't.
        sources.push(this.element);
        for (const el of sources) {
            const value = el.getAttribute(attr);
            if (value && value.length > 0) return value;
        }
        return fallback;
    }

    // ---------- Import / Export picker ----------

    /**
     * Pure show/hide: the panel is only a download button and a file picker,
     * so there is nothing to fetch.
     */
    openImportExport(event) {
        if (event) event.preventDefault();
        if (!this.hasImportExportPickerTarget) return;
        this.closeActions();
        this.importExportPickerTarget.hidden = false;
        this._setBackdrop(true);
        this._setImportExportStatus('');
    }

    /** Action: × button on the picker header. */
    closeImportExport(event) {
        if (event) event.preventDefault();
        if (!this.hasImportExportPickerTarget) return;
        this.importExportPickerTarget.hidden = true;
        this._setBackdrop(false);
    }

    /**
     * A programmatic `<a download>` click, so the browser's save dialog uses
     * the server's Content-Disposition filename.
     */
    runExport(event) {
        if (event) event.preventDefault();
        const link = document.createElement('a');
        link.href = `/_content-blocks/area/${this.areaIdValue}/export`;
        link.rel = 'noopener';
        // Empty, so the server's Content-Disposition filename wins.
        link.download = '';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    /**
     * Mirrors the replace-with flow: confirm, post as multipart, reload.
     *
     * @see docs/internals/transfer.md#import-is-a-replace-and-does-not-flush
     */
    async runImport(event) {
        if (event) event.preventDefault();
        if (!this.hasImportFileTarget) return;
        const file = this.importFileTarget.files && this.importFileTarget.files[0];
        if (!file) {
            this._setImportExportStatus(
                this._t('cb.builder.import_export.no_file', 'Pick a JSON file first.'),
            );
            return;
        }

        const confirmText = this._t(
            'cb.builder.import_export.confirm',
            'Are you sure you want to overwrite the current content with the imported one?',
        );
        if (!window.confirm(confirmText)) return;

        this._setImportExportStatus(
            this._t('cb.builder.import_export.importing', 'Importing…'),
        );

        const formData = new FormData();
        formData.append('file', file);

        const csrfToken = this.element.dataset.cbCsrfToken || '';
        this._beginLoading();
        let payload = null;
        let ok = false;
        try {
            const response = await fetch(
                `/_content-blocks/area/${this.areaIdValue}/import`,
                {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'X-CSRF-Token': csrfToken,
                        Accept: 'application/json',
                    },
                    body: formData,
                },
            );
            payload = await response.json().catch(() => null);
            ok = response.ok;
            if (!ok) {
                const msg = payload && payload.error
                    ? payload.error
                    : this._t('cb.builder.import_export.error', 'Import failed.');
                this._setImportExportStatus(msg);
                return;
            }
        } catch (e) {
            console.error('[cb-builder] import failed', e);
            this._setImportExportStatus(
                this._t('cb.builder.import_export.error', 'Import failed.'),
            );
            return;
        } finally {
            this._endLoading();
        }

        // Non-blocking: the import succeeded. Keep the panel open, since
        // closing it would blank the only element carrying the message.
        const warning = this._restoreWarning(payload, {
            skipped: ['cb.builder.import_export.skipped_blocks', 'Imported — %count% block(s) skipped, missing type(s): %types%'],
            unknown: ['cb.builder.import_export.unknown_fields', 'Imported, but some stored fields are unknown on: %types%'],
        });
        this.importFileTarget.value = '';
        if (warning !== null) {
            this._setImportExportStatus(warning);
        } else {
            this.closeImportExport();
        }
        this._replacePickerLoaded = false;
        this._applyDraftState(
            payload && payload.hasUnpublishedChanges !== undefined
                ? payload.hasUnpublishedChanges
                : true,
        );
        this.reload();
    }

    /**
     * One helper for both restore flows, which report the same two facts.
     * Skipped blocks come first: not arriving is worse news than a stray key.
     *
     * @see docs/internals/section-templates.md#skipped-blocks-versus-kept-keys
     */
    _restoreWarning(payload, messages) {
        const skipped = Array.isArray(payload?.skippedBlockTypes) ? payload.skippedBlockTypes : [];
        if (skipped.length > 0) {
            return this._t(...messages.skipped)
                .replace('%count%', String(payload.skippedBlockCount ?? skipped.length))
                .replace('%types%', skipped.join(', '));
        }

        // `unknownFields` for what a template kept, `droppedFields` for what
        // a paste threw away. Same shape, different verb.
        const raw = payload?.[messages.fieldsKey ?? 'unknownFields'];
        const fields = Array.isArray(raw) ? raw : [];
        if (fields.length > 0) {
            const types = [...new Set(fields.map((f) => f.blockType))].join(', ');

            return this._t(...messages.unknown).replace('%types%', types);
        }

        return null;
    }

    _setImportExportStatus(text) {
        if (!this.hasImportExportStatusTarget) return;
        this.importExportStatusTarget.textContent = text;
    }

    // ---------- Sidebar resize ----------

    _isMobile() {
        return window.matchMedia(this.constructor.MOBILE_BREAKPOINT).matches;
    }

    _restoreSidebarWidth() {
        // Mobile stacks the panels — the saved desktop width is irrelevant
        // there. We only restore the width on desktop layouts.
        if (this._isMobile()) return;
        try {
            const stored = window.localStorage.getItem(this.constructor.SIDEBAR_WIDTH_KEY);
            if (!stored) return;
            const parsed = parseInt(stored, 10);
            if (Number.isNaN(parsed)) return;
            const clamped = Math.max(
                this.constructor.SIDEBAR_MIN_WIDTH,
                Math.min(this.constructor.SIDEBAR_MAX_WIDTH, parsed),
            );
            this.element.style.setProperty('--cb-sidebar-width', clamped + 'px');
        } catch (_) {
            // localStorage may throw in privacy modes — silently fall back.
        }
    }

    _restoreSidebarCollapsed() {
        try {
            const stored = window.localStorage.getItem(this.constructor.SIDEBAR_COLLAPSED_KEY);
            this._setSidebarCollapsed(stored === '1');
        } catch (_) {
            // ignore — non-blocking persistence
        }
    }

    /** Action: mousedown / touchstart on the resize handle. */
    startSidebarResize(event) {
        if (!this.hasSidebarTarget || !this.hasIframeTarget) return;
        if (this._isMobile()) return; // No resize affordance on mobile.
        event.preventDefault();

        const point = this._eventPoint(event);
        this._resizeStartX = point.x;
        const rect = this.sidebarTarget.getBoundingClientRect();
        this._resizeStartWidth = rect.width;
        document.body.style.cursor = 'col-resize';

        // Disable iframe pointer events during the drag so mousemove on
        // top of it still fires on the parent document.
        this.iframeTarget.style.pointerEvents = 'none';
        document.addEventListener('mousemove', this._onResizeMove);
        document.addEventListener('mouseup', this._onResizeEnd);
        document.addEventListener('touchmove', this._onResizeMove, { passive: false });
        document.addEventListener('touchend', this._onResizeEnd);
    }

    _onResizeMove(event) {
        if (this._resizeStartX === undefined) return;
        const point = this._eventPoint(event);
        // Sidebar is left-anchored; dragging the right edge to the right
        // grows the sidebar.
        const delta = point.x - this._resizeStartX;
        const next = Math.max(
            this.constructor.SIDEBAR_MIN_WIDTH,
            Math.min(this.constructor.SIDEBAR_MAX_WIDTH, this._resizeStartWidth + delta),
        );
        this.element.style.setProperty('--cb-sidebar-width', next + 'px');
    }

    _onResizeEnd() {
        if (this._resizeStartX === undefined) return;
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
        document.removeEventListener('touchmove', this._onResizeMove);
        document.removeEventListener('touchend', this._onResizeEnd);

        if (this.hasIframeTarget) this.iframeTarget.style.pointerEvents = '';
        document.body.style.cursor = '';

        try {
            const w = Math.round(this.sidebarTarget.getBoundingClientRect().width);
            window.localStorage.setItem(this.constructor.SIDEBAR_WIDTH_KEY, String(w));
        } catch (_) {
            // ignore — non-blocking persistence
        }

        this._resizeStartX = undefined;
        this._resizeStartWidth = undefined;
    }

    _eventPoint(event) {
        const t = event.touches?.[0] ?? event.changedTouches?.[0];
        if (t) return { x: t.clientX, y: t.clientY };
        return { x: event.clientX, y: event.clientY };
    }
}
