import { Controller } from '@hotwired/stimulus';

/**
 * Opens the builder shell in a fullscreen <dialog>.
 *
 * The iframe inside the shell gets its src set lazily on first open, so
 * rendering the launcher button (and its hidden dialog) costs nothing
 * network-wise until the user actually clicks through.
 *
 * Closes are guarded by a confirm dialog when the sidebar is open: any
 * pending block edit not yet saved would be lost on close, so we let the
 * user back out. The browser fires <dialog>'s native `cancel` event when
 * Escape is pressed; we hook into it for the same guard.
 */
export default class extends Controller {
    static targets = ['dialog'];
    static values = {
        confirmCloseMessage: { type: String, default: 'You have unsaved changes. Close anyway?' },
    };

    connect() {
        this._onCancel = this._onCancel.bind(this);
        if (this.hasDialogTarget) {
            this.dialogTarget.addEventListener('cancel', this._onCancel);
        }
    }

    disconnect() {
        if (this.hasDialogTarget) {
            this.dialogTarget.removeEventListener('cancel', this._onCancel);
        }
    }

    open() {
        if (!this.hasDialogTarget) return;

        const iframe = this.dialogTarget.querySelector('[data-cb-builder-target="iframe"]');
        const shell = this.dialogTarget.querySelector('[data-controller~="cb-builder"]');

        if (iframe && shell && !iframe.getAttribute('src')) {
            iframe.src = shell.dataset.cbBuilderIframeUrlValue;
        }

        this.dialogTarget.showModal();
    }

    close(event) {
        if (event) event.preventDefault();
        if (!this.hasDialogTarget || !this.dialogTarget.open) return;

        if (this._sidebarHasOpenForm()) {
            const message = this.confirmCloseMessageValue
                || 'You have unsaved changes. Close anyway?';
            if (!window.confirm(message)) return;
        }

        this.dialogTarget.close();
    }

    /** Native <dialog> cancel event (Escape press). Guard the same way. */
    _onCancel(event) {
        if (this._sidebarHasOpenForm() && !window.confirm(this.confirmCloseMessageValue)) {
            event.preventDefault();
        }
    }

    _sidebarHasOpenForm() {
        if (!this.hasDialogTarget) return false;
        const sidebar = this.dialogTarget.querySelector('[data-cb-builder-target="sidebar"]');
        return !!(sidebar && !sidebar.hidden && sidebar.querySelector('form, .cb-block__edit-form'));
    }
}
