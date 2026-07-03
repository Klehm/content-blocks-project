import { Controller } from '@hotwired/stimulus';

/**
 * File upload controller for ContentBlocks.
 * Uploads a file via AJAX to /_content-blocks/upload, then writes the
 * returned URL into the hidden path input and dispatches `change` so
 * autosave / LiveComponent bindings pick the new value up.
 *
 * Rendered by the `cb_image_upload_widget` form-theme block
 * (ImageUploadType); can also be used standalone:
 *
 *   <div data-controller="cb-file-upload">
 *       <input type="file" data-action="change->cb-file-upload#upload" accept="image/*">
 *       <img data-cb-file-upload-target="preview" hidden>
 *       <input type="hidden" data-cb-file-upload-target="hiddenInput">
 *   </div>
 *
 * The CSRF token is read from the nearest `[data-cb-csrf-token]` ancestor
 * (rendered by the builder shell).
 */
export default class extends Controller {
    static targets = ['preview', 'hiddenInput', 'status'];

    _getCsrfToken() {
        return this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
    }

    async upload(event) {
        const file = event.target.files[0];
        if (!file) return;

        this._setStatus('uploading');

        const formData = new FormData();
        formData.append('file', file);

        try {
            const response = await fetch('/_content-blocks/upload', {
                method: 'POST',
                headers: {
                    'X-CSRF-Token': this._getCsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: formData,
            });

            const data = await response.json();

            if (!response.ok) {
                this._setStatus('error', data.error || 'Upload failed');
                return;
            }

            // Update the preview — both the img and its wrapper start
            // hidden when the field has no value yet.
            if (this.hasPreviewTarget) {
                this.previewTarget.src = data.url;
                this.previewTarget.hidden = false;
                this.previewTarget.closest('.cb-image-upload__preview')?.removeAttribute('hidden');
            }

            // Write URL into the form's hidden input — dispatch 'change' to
            // trigger autosave / LiveComponent data-model bindings.
            if (this.hasHiddenInputTarget) {
                this.hiddenInputTarget.value = data.url;
                this.hiddenInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
            }

            this._setStatus('success');
        } catch (e) {
            console.error('Upload failed:', e);
            this._setStatus('error', 'Network error');
        }
    }

    _setStatus(state, message = '') {
        if (!this.hasStatusTarget) return;

        this.statusTarget.textContent = message || (state === 'uploading' ? 'Uploading...' : '');
        this.statusTarget.className = `cb-upload-status cb-upload-status--${state}`;
    }
}
