import { Controller } from '@hotwired/stimulus';

/**
 * Opens the builder shell in a fullscreen `<dialog>`, setting the iframe src
 * lazily. No close guard: the sidebar autosaves, so nothing is unsaved.
 */
export default class extends Controller {
    static targets = ['dialog'];

    connect() {
        if (this.hasDialogTarget) {
            // HTML forbids nested forms; the browser flattens them and
            // Enter would hit the host's own.
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
}
