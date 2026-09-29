/**
 * Steers the preview iframe: reloads that keep the scroll and the focus,
 * and the in-place patches that spare a reload.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
 */
export default class Preview {
    /**
     * Autosave fires many saved-events per second while typing; the iframe
     * refreshes only after a pause.
     */
    static SAVE_RELOAD_DEBOUNCE_MS = 500;

    /**
     * Preserves scrollY across the reload, and keeps the progress bar up
     * throughout so feedback is continuous.
     */
    reload() {
        if (!this.hasIframeTarget) return;
        // Without a session the preview URL lands on the host's login.
        if (this._sessionExpired) return;

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

    /**
     * Appends a rendered (empty) section, which the overlay then focuses and
     * scrolls to. Falls back to a reload if the iframe is unreachable.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    _insertSectionInPreview(sectionId, html) {
        const id = parseInt(sectionId, 10);
        try {
            if (!this.hasIframeTarget || !this.iframeTarget.contentWindow) {
                throw new Error('preview unreachable');
            }
            this.iframeTarget.contentWindow.postMessage(
                { type: 'cb:section:insert', sectionId: id, html },
                window.location.origin,
            );
        } catch (_) {
            this._scrollPreviewTo(id);
            this.reload();
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

    /**
     * Flags the section deleted in place — the markup a reload would produce —
     * then re-pins focus so a block open inside it clears the sidebar.
     */
    _removeSectionFromPreview(sectionId) {
        try {
            if (!this.hasIframeTarget || !this.iframeTarget.contentWindow) {
                throw new Error('preview unreachable');
            }
            this.iframeTarget.contentWindow.postMessage(
                { type: 'cb:section:remove', sectionId: parseInt(sectionId, 10) },
                window.location.origin,
            );
        } catch (_) {
            this.reload();
            return;
        }
        this._restorePinnedFocus();
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
            const response = await fetch(`${this._apiBase}/block/${blockId}/render`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
                return;
            }
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
            const response = await fetch(`${this._apiBase}/section/${sectionId}/render`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (this.constructor.isSessionLoss(response)) {
                this._onSessionExpired();
                return;
            }
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
}
