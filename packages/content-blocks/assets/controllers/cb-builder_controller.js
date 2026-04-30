import { Controller } from '@hotwired/stimulus';

/**
 * Bridges the parent admin window with the iframe preview and the sidebar.
 *
 * Listens to `postMessage` events from the iframe (block edit/delete/add,
 * section move/delete, drag&drop reorder) and dispatches them as JS events
 * on its element so the rest of the admin can react. In phase 1 the actions
 * just log; phase 2 (sidebar mount) and phase 3 (AJAX endpoints) wire them.
 *
 * Reload preserves the iframe's scroll position so the user isn't kicked
 * back to the top after each block save.
 */
export default class extends Controller {
    static targets = ['iframe', 'sidebar', 'sidebarContent', 'sidebarResize', 'progress', 'savedFlash'];

    static values = {
        areaId: Number,
        iframeUrl: String,
    };

    static SIDEBAR_WIDTH_KEY = 'cb-builder.sidebarWidth';
    static SIDEBAR_MIN_WIDTH = 280;
    static SIDEBAR_MAX_WIDTH = 800;
    static SIDEBAR_HEIGHT_KEY = 'cb-builder.sidebarHeight';
    static SIDEBAR_MIN_HEIGHT = 200;
    static SIDEBAR_MAX_HEIGHT_VH = 80;
    static MOBILE_BREAKPOINT = '(max-width: 768px)';
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

        window.addEventListener('message', this._onMessage);
        window.addEventListener('resize', this._onWindowResize);
        // BlockComponent.save() and the section-settings form both
        // dispatchBrowserEvent on save; the events bubble up to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);

        this._restoreSidebarWidth();
        this._refreshViewportButtons();
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        window.removeEventListener('resize', this._onWindowResize);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
    }

    _onWindowResize() {
        this._refreshViewportButtons();
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
        this._applyDraftState(result.hasUnpublishedChanges);
        this.reload();
    }

    async discard(event) {
        if (event) event.preventDefault();
        const result = await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/discard`);
        if (result === null) return;
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
        await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/sections`, { layout: finalLayout });
        this._afterStructuralOp();
    }

    async _addBlock(columnId, blockType) {
        if (!columnId || !blockType) return;
        const result = await this._jsonRequest('POST', `/_content-blocks/column/${columnId}/blocks`, { type: blockType });
        this._afterStructuralOp();
        // Open the edit sidebar on the freshly-created block so the user
        // can fill it in immediately. The iframe reload triggered above
        // happens in parallel — the sidebar mount fetches its HTML from a
        // separate endpoint so it doesn't need to wait.
        if (result?.id) {
            this._mountSidebar(result.id);
        }
    }

    async _deleteBlock(blockId) {
        if (!blockId) return;
        await this._jsonRequest('DELETE', `/_content-blocks/block/${blockId}`);
        this._afterStructuralOp();
    }

    async _moveBlock(blockId, toColumnId, position) {
        if (!blockId || !toColumnId) return;
        await this._jsonRequest('POST', `/_content-blocks/block/${blockId}/move`, {
            toColumnId,
            position: position ?? 0,
        });
        this._afterStructuralOp();
    }

    async _moveSection(sectionId, direction) {
        if (!sectionId || !['up', 'down'].includes(direction)) return;
        await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/move`, { direction });
        this._afterStructuralOp();
    }

    async _reorderSection(sectionId, position) {
        if (!sectionId || !Number.isInteger(position) || position < 0) return;
        await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/move`, { position });
        this._afterStructuralOp();
    }

    async _duplicateSection(sectionId) {
        if (!sectionId) return;
        await this._jsonRequest('POST', `/_content-blocks/section/${sectionId}/duplicate`);
        this._afterStructuralOp();
    }

    async _duplicateBlock(blockId) {
        if (!blockId) return;
        await this._jsonRequest('POST', `/_content-blocks/block/${blockId}/duplicate`);
        this._afterStructuralOp();
    }

    async _deleteSection(sectionId) {
        if (!sectionId) return;
        await this._jsonRequest('DELETE', `/_content-blocks/section/${sectionId}`);
        this._afterStructuralOp();
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
            const response = await fetch(url, init);
            if (!response.ok) {
                console.error('[cb-builder] request failed', method, url, response.status);
                return null;
            }

            return await response.json().catch(() => null);
        } finally {
            this._endLoading();
        }
    }

    setViewport(event) {
        if (event) event.preventDefault();
        const viewport = event?.params?.viewport ?? 'desktop';
        this._applyViewport(viewport);
        console.log('[cb-builder] setViewport', { viewport });
    }

    _onMessage(event) {
        // Origin check: only trust same-origin posts.
        if (event.origin !== window.location.origin) return;

        const data = event.data;
        if (!data || typeof data !== 'object' || typeof data.type !== 'string') return;
        if (!data.type.startsWith('cb:')) return;

        switch (data.type) {
            case 'cb:ready':
                console.log('[cb-builder] iframe ready');
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
            default:
                console.log('[cb-builder] unknown message type', data.type, data);
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
            this.sidebarTarget.hidden = false;
            this.element.classList.add('cb-shell--sidebar-open');
            this._clearSidebarDataAttrs();
            for (const [k, v] of Object.entries(dataAttrs)) {
                this.sidebarTarget.setAttribute(k, v);
            }
            this._refreshSaveButtonState();

            // Move focus to the first form field once Stimulus + Live
            // Component finish wiring. preventScroll is critical: while the
            // sidebar is mid-slide-in, focusing an off-screen input would
            // otherwise scroll the iframe horizontally.
            requestAnimationFrame(() => {
                const target = this.sidebarContentTarget.querySelector(
                    'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled]), [contenteditable="true"]',
                );
                if (target) target.focus({ preventScroll: true });
            });
        } catch (e) {
            console.error('[cb-builder] mount error', e);
        } finally {
            this._endLoading();
        }
    }

    _refreshSaveButtonState() {
        const saveBtn = this.element.querySelector('.cb-shell__sidebar-save');
        if (!saveBtn) return;
        const inFormSave = this.hasSidebarContentTarget
            ? this.sidebarContentTarget.querySelector('[data-cb-sidebar-save]')
            : null;
        saveBtn.disabled = !inFormSave;
    }

    _clearSidebarDataAttrs() {
        for (const key of ['cb-sidebar-block-id', 'cb-sidebar-section-id']) {
            this.sidebarTarget.removeAttribute('data-' + key);
        }
    }

    /**
     * The iframe forwards a `cb:preview:outside-click` whenever the user
     * clicks anywhere in the preview that isn't an overlay toolbar/popover.
     * We treat it as "click outside the sidebar" and close it without
     * saving — same effect as the × button.
     */
    _onPreviewOutsideClick() {
        if (this.hasSidebarTarget && !this.sidebarTarget.hidden) {
            this.closeSidebar();
        }
    }

    /** Action: explicit close via the × button in the sidebar header. */
    closeSidebar(event) {
        if (event) event.preventDefault();
        if (!this.hasSidebarTarget) return;
        this.sidebarTarget.hidden = true;
        this.element.classList.remove('cb-shell--sidebar-open');
        if (this.hasSidebarContentTarget) this.sidebarContentTarget.innerHTML = '';
        this._clearSidebarDataAttrs();
        this._refreshSaveButtonState();
    }

    /**
     * Action: header Save button. Delegates to whichever real submit /
     * Live Action button the mounted form exposes via the
     * [data-cb-sidebar-save] hook. Keeps the framework-specific wiring
     * (Live Component LiveAction, Symfony form submit) inside the form
     * template instead of leaking into the shell.
     *
     * Live Component model bindings are wired with `on(change)`. The
     * input still focused when the user clicks the header Save has not
     * flushed its value to the model yet — and `.click()` on the in-form
     * save button doesn't move focus, so blur+change never fire and
     * Live POSTs the stale prop. Forcing a blur on the focused sidebar
     * field fires the synthetic change event, lets Live update the
     * model, and the subsequent click goes out with fresh data.
     */
    saveSidebar(event) {
        if (event) event.preventDefault();
        if (!this.hasSidebarContentTarget) return;
        const sidebar = this.sidebarContentTarget;
        const active = document.activeElement;
        if (active instanceof HTMLElement && sidebar.contains(active)) {
            active.blur();
        }
        const target = sidebar.querySelector('[data-cb-sidebar-save]');
        if (target) target.click();
    }

    /**
     * Save kept the sidebar open (so the user can keep tweaking / saving
     * iteratively). Reloads the iframe with the freshly persisted draft and
     * flashes a "✓ Saved" pill near the Save button so the user has explicit
     * feedback that the click actually persisted something.
     */
    _onBlockSaved(event) {
        console.log('[cb-builder] block:saved', event.detail);
        this._applyDraftState(true);
        this._flashSaved();
        this.reload();
    }

    _onSectionSaved(event) {
        console.log('[cb-builder] section:saved', event.detail);
        this._applyDraftState(true);
        this._flashSaved();
        this.reload();
    }

    _flashSaved() {
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

    // ---------- Sidebar resize ----------

    _isMobile() {
        return window.matchMedia(this.constructor.MOBILE_BREAKPOINT).matches;
    }

    _restoreSidebarWidth() {
        if (!this.hasSidebarTarget) return;
        try {
            if (this._isMobile()) {
                const stored = window.localStorage.getItem(this.constructor.SIDEBAR_HEIGHT_KEY);
                if (!stored) return;
                const parsed = parseInt(stored, 10);
                if (Number.isNaN(parsed)) return;
                const max = (this.constructor.SIDEBAR_MAX_HEIGHT_VH / 100) * window.innerHeight;
                const clamped = Math.max(
                    this.constructor.SIDEBAR_MIN_HEIGHT,
                    Math.min(max, parsed),
                );
                this.element.style.setProperty('--cb-sidebar-height', clamped + 'px');
            } else {
                const stored = window.localStorage.getItem(this.constructor.SIDEBAR_WIDTH_KEY);
                if (!stored) return;
                const parsed = parseInt(stored, 10);
                if (Number.isNaN(parsed)) return;
                const clamped = Math.max(
                    this.constructor.SIDEBAR_MIN_WIDTH,
                    Math.min(this.constructor.SIDEBAR_MAX_WIDTH, parsed),
                );
                this.sidebarTarget.style.width = clamped + 'px';
            }
        } catch (_) {
            // localStorage may throw in privacy modes — silently fall back.
        }
    }

    /** Action: mousedown / touchstart on the resize handle. */
    startSidebarResize(event) {
        if (!this.hasSidebarTarget || !this.hasIframeTarget) return;
        event.preventDefault();

        const point = this._eventPoint(event);
        this._resizeMobile = this._isMobile();

        if (this._resizeMobile) {
            this._resizeStartY = point.y;
            this._resizeStartHeight = this.sidebarTarget.getBoundingClientRect().height;
            document.body.style.cursor = 'row-resize';
        } else {
            this._resizeStartX = point.x;
            this._resizeStartWidth = this.sidebarTarget.getBoundingClientRect().width;
            document.body.style.cursor = 'col-resize';
        }

        // Disable iframe pointer events during the drag so mousemove on
        // top of it still fires on the parent document.
        this.iframeTarget.style.pointerEvents = 'none';
        document.addEventListener('mousemove', this._onResizeMove);
        document.addEventListener('mouseup', this._onResizeEnd);
        document.addEventListener('touchmove', this._onResizeMove, { passive: false });
        document.addEventListener('touchend', this._onResizeEnd);
    }

    _onResizeMove(event) {
        const point = this._eventPoint(event);

        if (this._resizeMobile) {
            if (this._resizeStartY === undefined) return;
            // Drag upward grows the sidebar (handle is on its top edge).
            event.preventDefault?.();
            const delta = this._resizeStartY - point.y;
            const max = (this.constructor.SIDEBAR_MAX_HEIGHT_VH / 100) * window.innerHeight;
            const next = Math.max(
                this.constructor.SIDEBAR_MIN_HEIGHT,
                Math.min(max, this._resizeStartHeight + delta),
            );
            this.element.style.setProperty('--cb-sidebar-height', next + 'px');
        } else {
            if (this._resizeStartX === undefined) return;
            // Drag toward the left grows the sidebar.
            const delta = this._resizeStartX - point.x;
            const next = Math.max(
                this.constructor.SIDEBAR_MIN_WIDTH,
                Math.min(this.constructor.SIDEBAR_MAX_WIDTH, this._resizeStartWidth + delta),
            );
            this.sidebarTarget.style.width = next + 'px';
        }
    }

    _onResizeEnd() {
        if (this._resizeStartX === undefined && this._resizeStartY === undefined) return;
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
        document.removeEventListener('touchmove', this._onResizeMove);
        document.removeEventListener('touchend', this._onResizeEnd);

        if (this.hasIframeTarget) this.iframeTarget.style.pointerEvents = '';
        document.body.style.cursor = '';

        try {
            if (this._resizeMobile) {
                const h = Math.round(this.sidebarTarget.getBoundingClientRect().height);
                window.localStorage.setItem(this.constructor.SIDEBAR_HEIGHT_KEY, String(h));
            } else {
                const w = Math.round(this.sidebarTarget.getBoundingClientRect().width);
                window.localStorage.setItem(this.constructor.SIDEBAR_WIDTH_KEY, String(w));
            }
        } catch (_) {
            // ignore — non-blocking persistence
        }

        this._resizeStartX = undefined;
        this._resizeStartY = undefined;
        this._resizeStartWidth = undefined;
        this._resizeStartHeight = undefined;
        this._resizeMobile = false;
    }

    _eventPoint(event) {
        const t = event.touches?.[0] ?? event.changedTouches?.[0];
        if (t) return { x: t.clientX, y: t.clientY };
        return { x: event.clientX, y: event.clientY };
    }
}
