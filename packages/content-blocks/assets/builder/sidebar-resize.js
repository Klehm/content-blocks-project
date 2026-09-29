/**
 * Dragging the sidebar edge, and the width it keeps across visits.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 */
export default class SidebarResize {
    static SIDEBAR_WIDTH_KEY = 'cb-builder.sidebarWidth';

    static SIDEBAR_MIN_WIDTH = 280;

    static SIDEBAR_MAX_WIDTH = 800;

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
