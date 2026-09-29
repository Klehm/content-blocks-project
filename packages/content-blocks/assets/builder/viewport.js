/**
 * The desktop / tablet / mobile preview, and the order a section or a
 * block takes on the narrow ones.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/rendering.md#order-per-viewport
 */
export default class Viewport {
    /**
     * Below its target width a viewport button is hidden: emulating an
     * iPad-width preview on a phone screen only clips the iframe.
     */
    static VIEWPORT_MIN_WIDTHS = { desktop: 0, tablet: 768, mobile: 375 };

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

    setViewport(event) {
        if (event) event.preventDefault();
        const viewport = event?.params?.viewport ?? 'desktop';
        this._applyViewport(viewport);
    }

    /**
     * The hint follows what the preview renders, reported by the overlay,
     * not the button: both decide what a drag means there.
     *
     * @see docs/internals/rendering.md#order-per-viewport
     */
    _showViewportOrder(viewport) {
        this._previewViewport = viewport;
        if (!this.hasViewportOrderTarget) return;
        const narrow = viewport === 'tablet' || viewport === 'mobile';
        this.viewportOrderTarget.hidden = !narrow;
        if (!narrow) return;
        const fallbacks = {
            tablet: ['Tablet order: desktop is unchanged', 'Reset the tablet order'],
            mobile: ['Mobile order: desktop is unchanged', 'Reset the mobile order'],
        }[viewport];
        if (this.hasViewportOrderLabelTarget) {
            this.viewportOrderLabelTarget.textContent = this._t(`cb.builder.viewport_order.${viewport}`, fallbacks[0]);
        }
        if (this.hasViewportOrderResetTarget) {
            const reset = this._t(`cb.builder.viewport_order.reset_${viewport}`, fallbacks[1]);
            this.viewportOrderResetTarget.title = reset;
            this.viewportOrderResetTarget.setAttribute('aria-label', reset);
        }
    }

    async _setViewportOrder({ viewport, scope, ids, columnId }) {
        if (!['tablet', 'mobile'].includes(viewport) || !Array.isArray(ids)) return;
        const body = { viewport, scope, ids };
        if (scope === 'block') body.columnId = columnId;
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/viewport-order`,
            body,
            { tolerate: [400] },
        );
        if (result === null) return;
        if (result.error) {
            // The preview drifted from the server: redraw it from the truth.
            this.reload();
            return;
        }
        this._applyDraftState(true);
        this._reorderInPreview({ type: 'cb:viewport-order:apply', scope, orders: result.orders });
    }

    /** Action: the hint's reset button. */
    async resetViewportOrder(event) {
        if (event) event.preventDefault();
        const viewport = this._previewViewport;
        if (!['tablet', 'mobile'].includes(viewport)) return;
        const result = await this._jsonRequest(
            'POST',
            `${this._apiBase}/area/${this.areaIdValue}/viewport-order/reset`,
            { viewport },
        );
        if (result === null) return;
        this._afterStructuralOp();
    }
}
