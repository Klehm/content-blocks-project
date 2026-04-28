import { Controller } from '@hotwired/stimulus';

/**
 * Hijacks the section settings form so its submit doesn't navigate the
 * admin page away. Posts the FormData via fetch; on success, fires
 * `cb:section:saved` so the parent cb-builder controller can close the
 * sidebar and reload the iframe. On a validation 422, swaps the
 * sidebar HTML with the re-rendered form so the user sees the errors.
 */
export default class extends Controller {
    static targets = ['form'];
    static values = { sectionId: Number };

    connect() {
        this._onSubmit = this._onSubmit.bind(this);
        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this._onSubmit);
        }
    }

    disconnect() {
        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this._onSubmit);
        }
    }

    async _onSubmit(event) {
        event.preventDefault();
        if (!this.hasFormTarget) return;

        const csrfToken = this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
        const formData = new FormData(this.formTarget);

        const response = await fetch(this.formTarget.action, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
            headers: { 'X-CSRF-Token': csrfToken },
        });

        if (response.ok) {
            this.element.dispatchEvent(new CustomEvent('cb:section:saved', {
                bubbles: true,
                detail: { sectionId: this.sectionIdValue },
            }));
            return;
        }

        if (response.status === 422) {
            // Validation errors — swap the sidebar HTML with the new form.
            const html = await response.text();
            this.element.outerHTML = html;
            return;
        }

        console.error('[cb-section-settings-form] save failed', response.status);
    }
}
