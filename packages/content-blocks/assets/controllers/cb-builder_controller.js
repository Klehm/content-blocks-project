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
    static targets = ['iframe', 'sidebar', 'sidebarContent', 'sidebarResize'];

    static values = {
        areaId: Number,
        iframeUrl: String,
    };

    static SIDEBAR_WIDTH_KEY = 'cb-builder.sidebarWidth';
    static SIDEBAR_MIN_WIDTH = 280;
    static SIDEBAR_MAX_WIDTH = 800;

    connect() {
        this._onMessage = this._onMessage.bind(this);
        this._onBlockSaved = this._onBlockSaved.bind(this);
        this._onSectionSaved = this._onSectionSaved.bind(this);
        this._onResizeMove = this._onResizeMove.bind(this);
        this._onResizeEnd = this._onResizeEnd.bind(this);

        window.addEventListener('message', this._onMessage);
        // BlockComponent.save() and the section-settings form both
        // dispatchBrowserEvent on save; the events bubble up to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);

        this._restoreSidebarWidth();
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);
    }

    /**
     * Reloads the iframe, preserving scrollY across the reload.
     */
    reload() {
        if (!this.hasIframeTarget) return;

        let scrollY = 0;
        try {
            scrollY = this.iframeTarget.contentWindow?.scrollY ?? 0;
        } catch (_) {
            // Cross-origin would throw; ignore and restore to 0.
        }

        const onLoad = () => {
            this.iframeTarget.removeEventListener('load', onLoad);
            try {
                this.iframeTarget.contentWindow?.scrollTo(0, scrollY);
            } catch (_) {
                // Same as above.
            }
        };
        this.iframeTarget.addEventListener('load', onLoad);

        try {
            this.iframeTarget.contentWindow?.location.reload();
        } catch (_) {
            // Fallback when the iframe document isn't accessible.
            this.iframeTarget.src = this.iframeUrlValue;
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
     * Refreshes the topbar (Discard enabled/disabled) and the launcher badge
     * outside the dialog so the parent admin page reflects the latest draft
     * state without a full reload.
     */
    _applyDraftState(hasUnpublishedChanges) {
        // Discard button on the topbar.
        const discardBtn = this.element.querySelector('.cb-shell__discard');
        if (discardBtn) {
            discardBtn.disabled = !hasUnpublishedChanges;
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
        await this._jsonRequest('POST', `/_content-blocks/area/${this.areaIdValue}/sections`, { layout });
        this._afterStructuralOp();
    }

    async _addBlock(columnId, blockType) {
        if (!columnId || !blockType) return;
        await this._jsonRequest('POST', `/_content-blocks/column/${columnId}/blocks`, { type: blockType });
        this._afterStructuralOp();
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

        const response = await fetch(url, init);
        if (!response.ok) {
            console.error('[cb-builder] request failed', method, url, response.status);
            return null;
        }

        return response.json().catch(() => null);
    }

    setViewport(event) {
        if (event) event.preventDefault();
        const viewport = event?.params?.viewport ?? 'desktop';

        const buttons = this.element.querySelectorAll('.cb-shell__viewport-btn');
        buttons.forEach((btn) => btn.classList.remove('cb-shell__viewport-btn--active'));
        if (event?.currentTarget instanceof Element) {
            event.currentTarget.classList.add('cb-shell__viewport-btn--active');
        }

        if (this.hasIframeTarget) {
            const widths = { desktop: '100%', tablet: '768px', mobile: '375px' };
            this.iframeTarget.style.maxWidth = widths[viewport] ?? '100%';
            this.iframeTarget.style.margin = viewport === 'desktop' ? '0' : '0 auto';
        }

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
            case 'cb:section:move-requested':
                this._moveSection(data.sectionId, data.direction);
                break;
            case 'cb:section:delete-requested':
                this._deleteSection(data.sectionId);
                break;
            case 'cb:section:settings':
                this._mountSectionSettings(data.sectionId);
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
            this._clearSidebarDataAttrs();
            for (const [k, v] of Object.entries(dataAttrs)) {
                this.sidebarTarget.setAttribute(k, v);
            }

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
        }
    }

    _clearSidebarDataAttrs() {
        for (const key of ['cb-sidebar-block-id', 'cb-sidebar-section-id']) {
            this.sidebarTarget.removeAttribute('data-' + key);
        }
    }

    /** Action: explicit close via the × button in the sidebar header. */
    closeSidebar(event) {
        if (event) event.preventDefault();
        if (!this.hasSidebarTarget) return;
        this.sidebarTarget.hidden = true;
        if (this.hasSidebarContentTarget) this.sidebarContentTarget.innerHTML = '';
        this._clearSidebarDataAttrs();
    }

    /**
     * Save kept the sidebar open (so the user can keep tweaking / saving
     * iteratively). Reloads the iframe with the freshly persisted draft.
     */
    _onBlockSaved(event) {
        console.log('[cb-builder] block:saved', event.detail);
        this._applyDraftState(true);
        this.reload();
    }

    _onSectionSaved(event) {
        console.log('[cb-builder] section:saved', event.detail);
        this._applyDraftState(true);
        this.reload();
    }

    // ---------- Sidebar resize ----------

    _restoreSidebarWidth() {
        if (!this.hasSidebarTarget) return;
        try {
            const stored = window.localStorage.getItem(this.constructor.SIDEBAR_WIDTH_KEY);
            if (!stored) return;
            const parsed = parseInt(stored, 10);
            if (Number.isNaN(parsed)) return;
            const clamped = Math.max(
                this.constructor.SIDEBAR_MIN_WIDTH,
                Math.min(this.constructor.SIDEBAR_MAX_WIDTH, parsed),
            );
            this.sidebarTarget.style.width = clamped + 'px';
        } catch (_) {
            // localStorage may throw in privacy modes — silently fall back.
        }
    }

    /** Action: mousedown on the resize handle. */
    startSidebarResize(event) {
        if (!this.hasSidebarTarget || !this.hasIframeTarget) return;
        event.preventDefault();

        this._resizeStartX = event.clientX;
        this._resizeStartWidth = this.sidebarTarget.getBoundingClientRect().width;
        // Disable iframe pointer events during the drag so mousemove on it
        // still fires on the parent document; Bootstrap modal-style trick.
        this.iframeTarget.style.pointerEvents = 'none';
        document.body.style.cursor = 'col-resize';
        document.addEventListener('mousemove', this._onResizeMove);
        document.addEventListener('mouseup', this._onResizeEnd);
    }

    _onResizeMove(event) {
        if (this._resizeStartX === undefined) return;
        // Drag toward the left grows the sidebar (the handle sits on its
        // left edge).
        const delta = this._resizeStartX - event.clientX;
        const next = Math.max(
            this.constructor.SIDEBAR_MIN_WIDTH,
            Math.min(this.constructor.SIDEBAR_MAX_WIDTH, this._resizeStartWidth + delta),
        );
        this.sidebarTarget.style.width = next + 'px';
    }

    _onResizeEnd() {
        if (this._resizeStartX === undefined) return;
        document.removeEventListener('mousemove', this._onResizeMove);
        document.removeEventListener('mouseup', this._onResizeEnd);

        if (this.hasIframeTarget) this.iframeTarget.style.pointerEvents = '';
        document.body.style.cursor = '';

        const w = Math.round(this.sidebarTarget.getBoundingClientRect().width);
        try {
            window.localStorage.setItem(this.constructor.SIDEBAR_WIDTH_KEY, String(w));
        } catch (_) {
            // ignore — non-blocking persistence
        }

        this._resizeStartX = undefined;
        this._resizeStartWidth = undefined;
    }
}
