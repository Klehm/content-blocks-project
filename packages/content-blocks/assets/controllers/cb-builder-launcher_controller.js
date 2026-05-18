import { Controller } from '@hotwired/stimulus';

/**
 * Opens the builder shell in a fullscreen <dialog>.
 *
 * The iframe inside the shell gets its src set lazily on first open, so
 * rendering the launcher button (and its hidden dialog) costs nothing
 * network-wise until the user actually clicks through.
 *
 * No close-guard prompt: edits in the sidebar are autosaved (debounce +
 * blur), so there is no "unsaved changes" state to warn about.
 */
export default class extends Controller {
    static targets = ['dialog'];

    connect() {
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
        }
    }

    disconnect() {
        if (this._dialog) {
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
        this._dialog.close();
    }
}
