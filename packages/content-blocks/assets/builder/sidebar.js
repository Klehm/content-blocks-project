/**
 * The sidebar is the selection: it mounts the form of what was clicked,
 * collapses on mobile, and goes back to its empty state.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#focus-and-the-sidebar
 */
export default class Sidebar {
    static MOBILE_BREAKPOINT = '(max-width: 768px)';

    static SIDEBAR_COLLAPSED_KEY = 'cb-builder.sidebarCollapsed';

    _isMobile() {
        return window.matchMedia(this.constructor.MOBILE_BREAKPOINT).matches;
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

    _isSidebarFocusedOnBlock(blockId) {
        if (!this.hasSidebarTarget) return false;
        return this.sidebarTarget.getAttribute('data-cb-sidebar-block-id') === String(blockId);
    }

    _isSidebarFocusedOnSection(sectionId) {
        if (!this.hasSidebarTarget) return false;
        return this.sidebarTarget.getAttribute('data-cb-sidebar-section-id') === String(sectionId);
    }

    /**
     * Fetches the rendered BlockComponent for the given block id and
     * injects it into the sidebar. Stimulus + Live Component auto-connect.
     */
    async _mountSidebar(blockId) {
        await this._mountSidebarFrom(`${this._apiBase}/block/${blockId}/edit`, {
            'data-cb-sidebar-block-id': String(blockId),
        });
    }

    /** Section settings: same fetch/inject flow, different endpoint. */
    async _mountSectionSettings(sectionId) {
        await this._mountSidebarFrom(`${this._apiBase}/section/${sectionId}/settings`, {
            'data-cb-sidebar-section-id': String(sectionId),
        });
    }

    /**
     * Only the latest request lands, and clearing the sidebar cancels any in
     * flight: a late response would replace what the editor chose since.
     */
    async _mountSidebarFrom(url, dataAttrs = {}) {
        if (!this.hasSidebarTarget || !this.hasSidebarContentTarget) return;
        const mount = {};
        this._latestMount = mount;

        // The user just asked to edit something.
        this._setSidebarCollapsed(false);

        this._beginLoading();
        try {
            const response = await fetch(url, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
            });
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
                return;
            }
            if (!response.ok) {
                console.error('[cb-builder] failed to load', url, response.status);
                return;
            }

            const html = await response.text();
            if (this._latestMount !== mount) return;
            this.sidebarContentTarget.innerHTML = html;
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
        this._latestMount = null;
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

    _restoreSidebarCollapsed() {
        try {
            const stored = window.localStorage.getItem(this.constructor.SIDEBAR_COLLAPSED_KEY);
            this._setSidebarCollapsed(stored === '1');
        } catch (_) {
            // ignore — non-blocking persistence
        }
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
}
