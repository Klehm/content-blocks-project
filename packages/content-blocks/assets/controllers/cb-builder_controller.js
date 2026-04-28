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
        this._onSectionSaved = this._onSectionSaved.bind(this);
        this._onSectionCancel = this._onSectionCancel.bind(this);

        window.addEventListener('message', this._onMessage);
        // Browser CustomEvents emitted from sidebar forms (BlockComponent's
        // dispatchBrowserEvent or the section-settings form controller)
        // bubble up the DOM to here.
        this.element.addEventListener('cb:block:saved', this._onBlockSaved);
        this.element.addEventListener('cb:block:cancel', this._onBlockCancel);
        this.element.addEventListener('cb:section:saved', this._onSectionSaved);
        this.element.addEventListener('cb:section:cancel', this._onSectionCancel);
    }

    disconnect() {
        window.removeEventListener('message', this._onMessage);
        this.element.removeEventListener('cb:block:saved', this._onBlockSaved);
        this.element.removeEventListener('cb:block:cancel', this._onBlockCancel);
        this.element.removeEventListener('cb:section:saved', this._onSectionSaved);
        this.element.removeEventListener('cb:section:cancel', this._onSectionCancel);
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

            // Move focus to the first form field so the user can type
            // straight away. Defer to after the next paint so Stimulus + Live
            // Component have wired their controllers up. preventScroll is
            // critical: while the sidebar is mid-slide-in (translateX(100%)
            // → 0), focusing an input would otherwise tell the browser to
            // scroll horizontally to bring the still-off-screen input into
            // view, which makes the iframe visually drift left-then-right.
            requestAnimationFrame(() => {
                const target = this.sidebarTarget.querySelector(
                    'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), [contenteditable="true"]',
                );
                if (target) target.focus({ preventScroll: true });
            });

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
        this._applyDraftState(true);
        this.reload();
    }

    _onBlockCancel(event) {
        console.log('[cb-builder] block:cancel', event.detail);
        this._unmountSidebar();
    }

    /**
     * Section settings: same fetch/inject + save/cancel flow as blocks,
     * just pointing at a different endpoint.
     */
    async _mountSectionSettings(sectionId) {
        if (!this.hasSidebarTarget) return;
        try {
            const response = await fetch(`/_content-blocks/section/${sectionId}/settings`, {
                headers: { 'Accept': 'text/html' },
                credentials: 'same-origin',
            });
            if (!response.ok) {
                console.error('[cb-builder] failed to load section settings', sectionId, response.status);
                return;
            }
            const html = await response.text();
            this.sidebarTarget.innerHTML = html;
            this.sidebarTarget.hidden = false;
            this.sidebarTarget.dataset.cbSidebarSectionId = String(sectionId);

            requestAnimationFrame(() => {
                const target = this.sidebarTarget.querySelector(
                    'input:not([type="hidden"]):not([disabled]), textarea:not([disabled]), select:not([disabled])',
                );
                if (target) target.focus({ preventScroll: true });
            });
        } catch (e) {
            console.error('[cb-builder] mount section settings error', e);
        }
    }

    _onSectionSaved(event) {
        console.log('[cb-builder] section:saved', event.detail);
        this._unmountSidebar();
        this._applyDraftState(true);
        this.reload();
    }

    _onSectionCancel(event) {
        console.log('[cb-builder] section:cancel', event.detail);
        this._unmountSidebar();
    }
}
