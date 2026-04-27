import { Controller } from '@hotwired/stimulus';

/**
 * File upload controller for ContentBlocks.
 * Uploads a file via AJAX to /_content-blocks/upload, then writes the
 * returned URL into the linked data-model field on the LiveComponent.
 *
 * Usage in a block edit template:
 *   <div data-controller="cb-file-upload"
 *        data-cb-file-upload-target-model-value="formData.src"
 *        data-cb-file-upload-preview-value="{{ formData.src }}">
 *       <input type="file" data-action="change->cb-file-upload#upload" accept="image/*">
 *       <img data-cb-file-upload-target="preview" src="{{ formData.src }}">
 *       <input type="hidden" data-cb-file-upload-target="hiddenInput">
 *   </div>
 */
export default class extends Controller {
    static values = {
        targetModel: String,
        preview: String,
    };

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

            // Update preview
            if (this.hasPreviewTarget) {
                this.previewTarget.src = data.url;
                this.previewTarget.style.display = '';
            }

            // Write URL into the form's hidden input — dispatch 'change' to
            // trigger LiveComponent's on(change)|* data-model binding
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
