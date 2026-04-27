import { Controller } from '@hotwired/stimulus';

/**
 * Opens the builder shell in a fullscreen <dialog>.
 *
 * The iframe inside the shell gets its src set lazily on first open, so
 * rendering the launcher button (and its hidden dialog) costs nothing
 * network-wise until the user actually clicks through.
 */
export default class extends Controller {
    static targets = ['dialog'];

    open() {
        if (!this.hasDialogTarget) return;

        const iframe = this.dialogTarget.querySelector('[data-cb-builder-target="iframe"]');
        const shell = this.dialogTarget.querySelector('[data-controller~="cb-builder"]');

        if (iframe && shell && !iframe.getAttribute('src')) {
            iframe.src = shell.dataset.cbBuilderIframeUrlValue;
        }

        this.dialogTarget.showModal();
    }

    /**
     * Close handler. Wired both to the explicit close button and called
     * automatically when the user presses Escape (browser default for
     * <dialog>).
     */
    close(event) {
        if (event) event.preventDefault();
        if (this.hasDialogTarget && this.dialogTarget.open) {
            this.dialogTarget.close();
        }
    }
}
