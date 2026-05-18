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
            // Re-parent the dialog to document.body. The launcher button is
            // usually rendered inside the host app's edit form (Sylius,
            // EasyAdmin, …), which would make every <form> inside the builder
            // (block edit, section settings) a nested form. HTML forbids
            // nesting forms: the browser flattens them, so Enter/submit in an
            // inner input triggers the OUTER form, and Live Component action
            // POSTs lose the form data attached via the (collapsed) inner
            // <form>. Lifting the dialog out of the host form keeps the HTML
            // valid no matter where the launcher is rendered.
            //
            // We cache a direct reference because Stimulus targets are
            // resolved by querying within this.element — once the dialog
            // moves out, hasDialogTarget would flip to false.
            this._dialog = this.dialogTarget;
            if (this._dialog.parentElement !== document.body) {
                document.body.appendChild(this._dialog);
            }
            this._dialog.addEventListener('cancel', this._onCancel);
        }
    }

    disconnect() {
        if (this._dialog) {
            this._dialog.removeEventListener('cancel', this._onCancel);
            // Remove the orphaned dialog from <body> so it doesn't survive
            // Turbo navigations / re-renders of the host page.
            if (this._dialog.parentElement === document.body) {
                this._dialog.remove();
            }
            this._dialog = null;
        }
    }

    open() {
        if (!this._dialog) return;

        const iframe = this._dialog.querySelector('[data-cb-builder-target="iframe"]');
        const shell = this._dialog.querySelector('[data-controller~="cb-builder"]');

        if (iframe && shell && !iframe.getAttribute('src')) {
            iframe.src = shell.dataset.cbBuilderIframeUrlValue;
        }

        this._dialog.showModal();
    }

    close(event) {
        if (event) event.preventDefault();
        if (!this._dialog || !this._dialog.open) return;

        if (this._sidebarHasOpenForm()) {
            const message = this.confirmCloseMessageValue
                || 'You have unsaved changes. Close anyway?';
            if (!window.confirm(message)) return;
        }

        this._dialog.close();
    }

    /** Native <dialog> cancel event (Escape press). Guard the same way. */
    _onCancel(event) {
        if (this._sidebarHasOpenForm() && !window.confirm(this.confirmCloseMessageValue)) {
            event.preventDefault();
        }
    }

    _sidebarHasOpenForm() {
        if (!this._dialog) return false;
        const sidebar = this._dialog.querySelector('[data-cb-builder-target="sidebar"]');
        return !!(sidebar && !sidebar.hidden && sidebar.querySelector('form, .cb-block__edit-form'));
    }
}
