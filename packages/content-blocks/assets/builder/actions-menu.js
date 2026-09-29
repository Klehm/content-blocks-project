/**
 * The topbar Actions menu, and the host actions it lists.
 *
 * @see docs/internals/frontend.md#the-builder-controller-is-split-by-feature
 * @see docs/internals/builder-extensions.md#what-the-package-renders
 */
export default class ActionsMenu {
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
}
