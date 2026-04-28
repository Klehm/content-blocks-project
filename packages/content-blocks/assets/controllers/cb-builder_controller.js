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
    static targets = ['iframe', 'sidebar'];

    static values = {
        areaId: Number,
        iframeUrl: String,
    };

    connect() {
        this._onMessage = this._onMessage.bind(this);
        this._onBlockSaved = this._onBlockSaved.bind(this);
        this._onBlockCancel = this._onBlockCancel.bind(this);

        window.addEventListener('message', this._onMessage);
        // Browser CustomEvents emitted by BlockComponent's dispatchBrowserEvent
        // bubble up the DOM from the sidebar to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:block:cancel', this._onBlockCancel);
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:block:cancel', this._onBlockCancel);
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

    publish(event) {
        if (event) event.preventDefault();
        console.log('[cb-builder] publish requested', { areaId: this.areaIdValue });
    }

    discard(event) {
        if (event) event.preventDefault();
        console.log('[cb-builder] discard requested', { areaId: this.areaIdValue });
    }

    addSection(event) {
        if (event) event.preventDefault();
        const layout = event?.params?.layout ?? 'full';
        console.log('[cb-builder] addSection', { areaId: this.areaIdValue, layout });
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
                console.log('[cb-builder] block:delete-requested', data);
                break;
            case 'cb:block:add-requested':
                console.log('[cb-builder] block:add-requested', data);
                break;
            case 'cb:block:reorder':
                console.log('[cb-builder] block:reorder', data);
                break;
            case 'cb:section:move-requested':
                console.log('[cb-builder] section:move-requested', data);
                break;
            case 'cb:section:delete-requested':
                console.log('[cb-builder] section:delete-requested', data);
                break;
            default:
                console.log('[cb-builder] unknown message type', data.type, data);
        }
    }

    /**
     * Fetches the rendered BlockComponent for the given block id and
     * injects it into the sidebar. Stimulus controllers and the Live
     * Component framework auto-connect to the new DOM nodes.
     */
    async _mountSidebar(blockId) {
        if (!this.hasSidebarTarget) return;

        try {
            const response = await fetch(`/_content-blocks/block/${blockId}/edit`, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                console.error('[cb-builder] failed to load block', blockId, response.status);
                return;
            }

            const html = await response.text();
            this.sidebarTarget.innerHTML = html;
            this.sidebarTarget.hidden = false;
            this.sidebarTarget.dataset.cbSidebarBlockId = String(blockId);

            console.log('[cb-builder] sidebar mounted for block', blockId);
        } catch (e) {
            console.error('[cb-builder] mount error', e);
        }
    }

    _unmountSidebar() {
        if (!this.hasSidebarTarget) return;
        this.sidebarTarget.innerHTML = '';
        this.sidebarTarget.hidden = true;
        delete this.sidebarTarget.dataset.cbSidebarBlockId;
    }

    _onBlockSaved(event) {
        console.log('[cb-builder] block:saved', event.detail);
        this._unmountSidebar();
        this.reload();
    }

    _onBlockCancel(event) {
        console.log('[cb-builder] block:cancel', event.detail);
        this._unmountSidebar();
    }
}
