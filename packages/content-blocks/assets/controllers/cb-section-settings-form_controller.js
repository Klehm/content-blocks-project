import { Controller } from '@hotwired/stimulus';

/**
 * Hijacks the section settings form so its submit doesn't navigate the
 * admin page away. Posts the FormData via fetch; on success, fires
 * `cb:section:saved` so the parent cb-builder controller can close the
 * sidebar and reload the iframe. On a validation 422, swaps the
 * sidebar HTML with the re-rendered form so the user sees the errors.
 */
export default class extends Controller {
    static targets = ['form', 'maxWidthRow'];
    static values = { sectionId: Number };

    connect() {
        this._onSubmit = this._onSubmit.bind(this);
        this._onChange = this._onChange.bind(this);
        if (this.hasFormTarget) {
            this.formTarget.addEventListener('submit', this._onSubmit);
            this.formTarget.addEventListener('change', this._onChange);
            // Server-rendered visibility is correct on first paint; this
            // call only matters if the form was re-rendered by a 422
            // (validation) swap and the user had toggled widthMode
            // before saving.
            this._syncMaxWidthVisibility();
        }
    }

    disconnect() {
        if (this.hasFormTarget) {
            this.formTarget.removeEventListener('submit', this._onSubmit);
            this.formTarget.removeEventListener('change', this._onChange);
        }
    }

    /**
     * Watches the widthMode radio group; the maxWidth row is only
     * meaningful when the section is "centered" (BuiltInSectionDecorator
     * ignores maxWidth in "full" mode).
     */
    _onChange(event) {
        const name = event.target?.name;
        if (typeof name === 'string' && name.endsWith('[widthMode]')) {
            this._syncMaxWidthVisibility();
        }
    }

    _syncMaxWidthVisibility() {
        if (!this.hasMaxWidthRowTarget) return;
        const checked = this.formTarget.querySelector('input[name$="[widthMode]"]:checked');
        this.maxWidthRowTarget.hidden = checked?.value !== 'centered';
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
