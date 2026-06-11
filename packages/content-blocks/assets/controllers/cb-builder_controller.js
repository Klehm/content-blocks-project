import { Controller } from '@hotwired/stimulus';

/**
 * Bridges the parent admin window with the iframe preview and the sidebar.
 *
 * Listens to `postMessage` events from the iframe (block edit/delete/add,
 * section move/delete, drag&drop reorder) and dispatches them as JS events
 * on its element so the rest of the admin can react.
 *
 * The sidebar is permanent: it always occupies its grid column and only its
 * content swaps based on which entity is focused. A collapsed state shrinks
 * the column to a fly-out toggle handle so the iframe can claim the full
 * row width. There is no "open / close" lifecycle anymore — block & section
 * forms save themselves via autosave (debounce on input, immediate on blur).
 *
 * Reload preserves the iframe's scroll position so the user isn't kicked
 * back to the top after each save.
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
        'replacePicker',
        'replacePickerSearch',
        'replacePickerList',
        'replacePickerStatus',
        'importExportPicker',
        'importFile',
        'importExportStatus',
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

    static SIDEBAR_WIDTH_KEY = 'cb-builder.sidebarWidth';
    static SIDEBAR_COLLAPSED_KEY = 'cb-builder.sidebarCollapsed';
    static SIDEBAR_MIN_WIDTH = 280;
    static SIDEBAR_MAX_WIDTH = 800;
    /**
     * Coalesce window for the iframe reload after a save. Autosave can fire
     * many `cb:*:saved` events per second while the user types; rather than
     * reloading the preview on each one, we debounce so the iframe only
     * refreshes after the user pauses for a moment.
     */
    static SAVE_RELOAD_DEBOUNCE_MS = 500;
    static MOBILE_BREAKPOINT = '(max-width: 768px)';
    /**
     * How long the "deleted — Undo" snackbar stays actionable. Deletes are
     * immediate (no confirm dialog), so this window is the editor's only
     * one-click recovery short of discarding the whole draft.
     */
    static UNDO_TIMEOUT_MS = 6000;
    /**
     * Minimum shell width (in px) for each emulated viewport. The "desktop"
     * viewport always fits because it tracks the shell's actual width. A
     * tablet/mobile button is hidden when the shell is narrower than its
     * target — emulating an iPad-width preview on a phone-sized screen
     * would just clip the iframe, so the button isn't useful.
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

        window.addEventListener('message', this._onMessage);
        window.addEventListener('resize', this._onWindowResize);
        // BlockComponent.save() and the section-settings form both
        // dispatchBrowserEvent on save; the events bubble up to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);
        // Save-failure feedback: live:connect bubbles up from every Live
        // Component mounted in the sidebar (block edit forms) — we hook each
        // component's error paths there. cb:save:error bubbles up from the
        // section-settings form (its own fetch) and from the live error
        // hooks below; both end in the persistent topbar error banner.
        this.element.addEventListener('live:connect', this._onLiveConnect);
        this.element.addEventListener('cb:save:error', this._onSaveError);

        this._restoreSidebarWidth();
        this._restoreSidebarCollapsed();
        this._refreshViewportButtons();
        // Remember the initial empty-state HTML so we can restore it
        // when the user clicks outside any focused element.
        if (this.hasSidebarContentTarget) {
            this._sidebarEmptyHtml = this.sidebarContentTarget.innerHTML;
        }
        // Mobile boots with no focused entity — collapse the bottom
        // sheet so it reads as a strip at the bottom rather than a
        // half-screen pane covering the preview.
        this._syncEmptySidebar();
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        window.removeEventListener('resize', this._onWindowResize);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        this.element.removeEventListener('live:connect', this._onLiveConnect);
        this.element.removeEventListener('cb:save:error', this._onSaveError);
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
        clearTimeout(this._reloadTimer);
        clearTimeout(this._undoTimer);
    }

    _onWindowResize() {
        this._refreshViewportButtons();
        // Crossing the mobile breakpoint mid-session — re-collapse the
        // sidebar if we just entered mobile with no focused entity.
        this._syncEmptySidebar();
    }

    /**
     * Hide viewport buttons whose target width exceeds the shell width,
     * and if the currently-active viewport just got hidden, fall back to
     * desktop so the iframe doesn't stay stuck at a clipped size.
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
     * Reloads the iframe, preserving scrollY across the reload. While the
     * iframe is mid-load the shell carries a "is-loading" class so the
     * progress bar stays visible — the user gets continuous feedback from
     * "AJAX submitted" all the way through to "preview repainted".
     */
    reload() {
        if (!this.hasIframeTarget) return;

        let scrollY = 0;
        try {
            scrollY = this.iframeTarget.contentWindow?.scrollY ?? 0;
        } catch (_) {
            // Cross-origin would throw; ignore and restore to 0.
        }

        this._beginLoading();
        const onLoad = () => {
            this.iframeTarget.removeEventListener('load', onLoad);
            try {
                this.iframeTarget.contentWindow?.scrollTo(0, scrollY);
            } catch (_) {
                // Same as above.
            }
            this._restorePinnedFocus();
            // Wait one frame so the iframe overlay has re-pinned the
            // focused element and its rect is queryable before we
            // measure for the mobile bottom-sheet auto-scroll.
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
     * After the iframe finishes (re)loading, tell the preview overlay to
     * re-pin focus on the element currently being edited. Without this,
     * an autosave-triggered reload would wipe the blue outline + toolbar
     * because the iframe DOM is rebuilt from scratch.
     *
     * The currently-edited entity is read from the sidebar's data-* mount
     * markers (set by `_mountSidebarFrom`). When the sidebar shows the
     * empty state, nothing is pinned and the call is a no-op.
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
     * Reference-counted loading flag — multiple overlapping operations stack
     * (e.g. a save followed by an iframe reload) and the bar only goes away
     * once the last one finishes.
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
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/discard`);
        if (result === null) return;
        // Discard already reverted every draft deletion (or removed
        // never-published rows) — the undo offer is moot either way.
        this._hideUndo();
        this._applyDraftState(result.hasUnpublishedChanges);
        this.reload();
    }

    /**
     * Refreshes topbar action states (Discard hidden/visible, Publish
     * enabled/disabled) and the launcher badge outside the dialog so the
     * parent admin page reflects the latest draft state without a full
     * reload.
     */
    _applyDraftState(hasUnpublishedChanges) {
        // Discard is irrelevant when nothing is pending — hide it entirely
        // rather than rendering a disabled button. The user only sees it
        // when it's actually actionable.
        const discardBtn = this.element.querySelector('.cb-shell__discard');
        if (discardBtn) {
            discardBtn.hidden = !hasUnpublishedChanges;
        }
        // Publish is the primary action — keep it visible at all times so
        // the user knows it exists, but disable it when there's nothing to
        // publish.
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
    }

    async addSection(event) {
        if (event) event.preventDefault();
        const layout = event?.params?.layout ?? 'full';
        await this._addSection(layout);
    }

    async _addSection(layout) {
        const allowed = ['full', 'two_cols', 'three_cols'];
        const finalLayout = allowed.includes(layout) ? layout : 'full';
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/sections`, { layout: finalLayout });
        this._afterStructuralOp();
        // Open the settings sidebar on the freshly-created section so the user
        // can configure it immediately — mirrors _addBlock. The iframe reload
        // above runs in parallel; the sidebar fetches its HTML separately.
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
        // A static / CSS-only block ships its rendered markup: drop it into the
        // preview in place. A JS-dependent block opts out (no html) and needs a
        // full reload so its scripts run.
        if (result.hotReload && typeof result.html === 'string') {
            this._insertBlockInPreview(columnId, result.html);
        } else {
            this.reload();
        }
        // Open the edit sidebar on the freshly-created block so the user can
        // fill it in immediately. The insert/reload above happens in parallel —
        // the sidebar mount fetches its HTML from a separate endpoint.
        if (result.id) {
            this._mountSidebar(result.id);
        }
    }

    /**
     * Asks the preview overlay to insert a freshly-rendered block at the end of
     * its column (ahead of the permanent "+ Block" button). Falls back to a
     * full reload if the iframe can't be reached.
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
     * Asks the preview overlay to relocate an existing section/block node in
     * place after a server-confirmed reorder. Moving the live node keeps the
     * block's DOM + JS state intact (a re-render or full reload would discard
     * it). Falls back to a full reload if the iframe can't be reached.
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
        // A section whose blocks all hot-reload ships its rendered markup: drop
        // the copy into the preview in place, right after the source. A section
        // carrying a JS-dependent block opts out (no html) and needs a full
        // reload so its scripts run.
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
        // Same policy as _addBlock: a static / CSS-only copy ships its markup
        // and lands in place (right after the source); a JS-dependent block
        // opts out (no html) and falls back to a full reload.
        if (result.hotReload && typeof result.html === 'string') {
            this._duplicateInPreview({ type: 'cb:block:duplicate:apply', sourceId: blockId, html: result.html });
        } else {
            this.reload();
        }
    }

    /**
     * Asks the preview overlay to drop a freshly-rendered duplicate into place,
     * anchored right after its source node. Falls back to a full reload if the
     * iframe can't be reached.
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
        // Direct case: the focused element is the section itself. The
        // cascading case (a focused block lived inside this section) is
        // caught after reload via the iframe's `cb:focus:not-found` reply.
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
     * Common tail for any structural mutation: every such op leaves the area
     * with at least one unpublished change, so flip the discard button on
     * proactively (instead of doing a roundtrip just to discover the area is
     * dirty), then reload the iframe to reflect the new draft state.
     */
    _afterStructuralOp() {
        this._applyDraftState(true);
        this.reload();
    }

    /**
     * Shared AJAX helper. Pulls the CSRF token from the shell wrapper element
     * (`data-cb-csrf-token`) and forwards it as `X-CSRF-Token`.
     */
    async _jsonRequest(method, url, body) {
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
                // Network failure (offline, DNS, aborted) — without this catch
                // the rejection would propagate to callers that never handle
                // it, and the editor would get zero feedback.
                console.error('[cb-builder] request failed', method, url, e);
                this._showSaveError();
                return null;
            }
            if (!response.ok) {
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
     * A Live Component connected somewhere under the shell (block edit form
     * in the sidebar). Hook its two failure paths:
     *  - `response:error`  — the server answered with a non-component
     *    response (500, expired session…). We suppress Live's default
     *    raw-HTML error modal in favour of the topbar banner.
     *  - network failure   — Live's own request promise has no rejection
     *    handler at all (the save dies silently), so we attach one through
     *    the `loading.state:started` hook, which receives the request.
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
                // Live never resets `backendRequest` when its request
                // rejects, so every subsequent action would queue behind the
                // dead request forever — the component is wedged and the
                // editor's retry would silently do nothing. Clear it so the
                // next interaction can actually re-save.
                if (component.backendRequest === request) {
                    component.backendRequest = null;
                }
                this._signalSaveError(component.element);
            });
        });
    }

    /**
     * Route a save failure detected on a Live form. Dispatching cb:save:error
     * on the form's autosave wrapper kills two birds: cb-autosave resets its
     * dirty-detection baseline (so the next interaction re-attempts the save
     * instead of considering the failed state "already saved"), and the event
     * bubbles back up to our own cb:save:error listener which shows the
     * banner. Falls back to showing the banner directly when the form has no
     * autosave wrapper.
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
     * Persistent (non-flashing) error banner in the topbar: unlike the
     * transient "Saved" flash, it stays visible until a subsequent save
     * succeeds — the editor must know their latest edits are not stored.
     */
    _showSaveError() {
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = false;
    }

    _clearSaveError() {
        if (!this.hasSaveErrorTarget) return;
        this.saveErrorTarget.hidden = true;
    }

    // ---------- Undo delete (snackbar) ----------

    /**
     * Deletes are immediate (no confirm dialog) and the only other recovery
     * is discarding the WHOLE draft — far too coarse for one mis-click. So
     * after every delete we offer a one-click undo for a few seconds. The
     * offer is single-slot (a newer delete replaces it), which matches the
     * usual snackbar pattern.
     */
    _offerUndo(kind, id) {
        if (!this.hasUndoBarTarget) return;
        this._pendingUndo = { kind, id };
        if (this.hasUndoLabelTarget) {
            const key = kind === 'section' ? 'cbBuilderUndoSectionDeleted' : 'cbBuilderUndoBlockDeleted';
            this.undoLabelTarget.textContent = this.undoBarTarget.dataset[key] || '';
        }
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
        // The restored element comes back with its full subtree — simplest
        // correct refresh is a full reload (undo is rare; no need for the
        // hot-reload path here).
        this._applyDraftState(true);
        this.reload();
    }

    setViewport(event) {
        if (event) event.preventDefault();
        const viewport = event?.params?.viewport ?? 'desktop';
        this._applyViewport(viewport);
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
                // The iframe couldn't pin focus after reload — the focused
                // element no longer exists (e.g. a section delete cascaded
                // to a focused child block). Clear the stale form.
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

        // Expand the sidebar if it was collapsed — the user just asked to
        // edit something, so we surface the form even without a manual
        // expand click.
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
            // Sidebar now points at the new entity — scroll the iframe
            // so the focused element isn't covered by the bottom sheet
            // on mobile.
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
     * Resets the sidebar content back to its empty state (the hint +
     * three "Add section" buttons). Called when the user clicks empty
     * preview space or after structural ops that remove the focused
     * element. No animation — just an instant content swap.
     */
    _resetSidebarToEmptyState() {
        if (!this.hasSidebarContentTarget) return;
        if (typeof this._sidebarEmptyHtml !== 'string') return;
        this.sidebarContentTarget.innerHTML = this._sidebarEmptyHtml;
        this._clearSidebarDataAttrs();
        // Mobile: nothing focused → collapse the sheet to its 32px
        // strip so the preview reclaims the screen.
        this._syncEmptySidebar();
    }

    /**
     * The iframe forwards a `cb:preview:outside-click` whenever the user
     * clicks anywhere in the preview that isn't an overlay toolbar/popover.
     * In the new permanent-sidebar model we read it as "clear the focused
     * form" — the sidebar stays on screen but reverts to the empty state.
     */
    _onPreviewOutsideClick() {
        this._resetSidebarToEmptyState();
    }

    /**
     * Action: close the builder dialog. Handled here rather than on the
     * launcher controller because the launcher re-parents the <dialog> to
     * document.body on connect — that moves this close button out of the
     * launcher's element, so its Stimulus action no longer resolves. The
     * cb-builder controller lives inside the shell (inside the dialog), so
     * it stays in scope and can close the enclosing <dialog> directly.
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
     * Mobile-only: collapse the bottom sheet down to its 32px strip
     * whenever nothing is focused — the empty-state hint shouldn't steal
     * the bottom half of the screen when the user hasn't asked to edit
     * anything yet. `persist: false` keeps the user's explicit
     * expand/collapse preference in localStorage untouched, so once they
     * focus an element again the sidebar restores their last choice.
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
     * Mobile-only safety net: when the bottom-sheet sidebar overlays the
     * iframe, the element being edited can end up hidden behind the
     * sheet. Scroll the iframe just enough so the focused element's
     * bottom edge sits above the sheet — but only if it's actually
     * hidden. No-op on desktop (sidebar is on the side, no vertical
     * overlap), when collapsed, or when nothing is focused.
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
        // offsetHeight is the layout (post-CSS, pre-transform) height, so
        // we can measure correctly even mid-transition while the sheet
        // is still sliding up.
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
     * Autosave callback: a block was just persisted by the form. Bump the
     * dirty indicators, flash "Saved", and refresh the preview.
     *
     * Hybrid reload: if we know which block is focused we try to hot-swap
     * just that block's markup in the iframe (no flash, no re-running the
     * host page's JS). The server has the final say — a JS-dependent block
     * type answers "no hot reload" and we fall back to a full iframe reload.
     * Both paths are coalesced through the same debounce timer so a burst of
     * keystroke-saves only triggers one refresh.
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
        // Section settings only change the section wrapper's style + its column
        // widths (never structure), so hot-reload just that section's
        // attributes in place instead of reloading the whole iframe. Falls
        // back to a full reload if the section id is unknown.
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
     * Fetches the freshly-rendered markup for a single block and asks the
     * preview overlay to swap it in place. Any failure — network error,
     * missing block, or a block type that opts out of hot reload — falls
     * back to a full iframe reload so the preview is never left stale.
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
     * Fetches the freshly-rendered markup for a single section and asks the
     * preview overlay to patch its wrapper (class/style) + column widths in
     * place. Any failure falls back to a full iframe reload so the preview is
     * never left stale.
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
     * Action: opens the "insert content from an existing area" picker.
     * First open loads the default (unfiltered) candidate list; subsequent
     * opens re-use the cached list so the user can hop in and out without
     * the network flashing.
     */
    async openReplacePicker(event) {
        if (event) event.preventDefault();
        if (!this.hasReplacePickerTarget) return;
        this.replacePickerTarget.hidden = false;
        const trigger = this.element.querySelector('.cb-shell__replace');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');

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
        const trigger = this.element.querySelector('.cb-shell__replace');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
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
        // Close + invalidate the picker cache so the next open re-fetches
        // (the target area's updatedAt just changed, and the user may want
        // to replace again from the same source — sticky cache would lie).
        this._replacePickerLoaded = false;
        this.closeReplacePicker();
        this._applyDraftState(result.hasUnpublishedChanges ?? true);
        this.reload();
    }

    _setReplacePickerStatus(text) {
        if (!this.hasReplacePickerStatusTarget) return;
        this.replacePickerStatusTarget.textContent = text;
    }

    /**
     * Tiny translation lookup. The host's translation strings are not
     * available client-side; we read precomputed values from data-*
     * attributes on any picker root that carries them, otherwise fall back
     * to the English default. This keeps the bundle dependency-free while
     * still letting hosts override the wording.
     */
    _t(key, fallback) {
        const attr = 'data-i18n-' + key.replace(/[._]/g, '-');
        const sources = [];
        if (this.hasReplacePickerTarget) sources.push(this.replacePickerTarget);
        if (this.hasImportExportPickerTarget) sources.push(this.importExportPickerTarget);
        for (const el of sources) {
            const value = el.getAttribute(attr);
            if (value && value.length > 0) return value;
        }
        return fallback;
    }

    // ---------- Import / Export picker ----------

    /**
     * Action: opens the Import / Export overlay. Pure show/hide — no
     * server roundtrip needed; the panel only contains a download button
     * and a file picker.
     */
    openImportExport(event) {
        if (event) event.preventDefault();
        if (!this.hasImportExportPickerTarget) return;
        this.importExportPickerTarget.hidden = false;
        const trigger = this.element.querySelector('.cb-shell__import-export');
        if (trigger) trigger.setAttribute('aria-expanded', 'true');
        this._setImportExportStatus('');
    }

    /** Action: × button on the picker header. */
    closeImportExport(event) {
        if (event) event.preventDefault();
        if (!this.hasImportExportPickerTarget) return;
        this.importExportPickerTarget.hidden = true;
        const trigger = this.element.querySelector('.cb-shell__import-export');
        if (trigger) trigger.setAttribute('aria-expanded', 'false');
    }

    /**
     * Action: download the area as a JSON file. Uses a programmatic
     * <a download> click so the browser handles the save dialog with the
     * filename the server provides via Content-Disposition.
     */
    runExport(event) {
        if (event) event.preventDefault();
        const link = document.createElement('a');
        link.href = `/_content-blocks/area/${this.areaIdValue}/export`;
        link.rel = 'noopener';
        // download="" lets the server-provided Content-Disposition filename win.
        link.download = '';
        document.body.appendChild(link);
        link.click();
        link.remove();
    }

    /**
     * Action: upload the picked JSON file and replace the current draft.
     * Mirrors the replace-with flow: confirms, posts the file as multipart,
     * then reloads the iframe so the new draft is visible.
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

        // Reset the picker so the next open starts clean, then refresh the
        // preview to surface the freshly-imported draft.
        this.importFileTarget.value = '';
        this.closeImportExport();
        this._replacePickerLoaded = false;
        this._applyDraftState(
            payload && payload.hasUnpublishedChanges !== undefined
                ? payload.hasUnpublishedChanges
                : true,
        );
        this.reload();
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
