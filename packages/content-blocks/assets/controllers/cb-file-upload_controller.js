import { Controller } from '@hotwired/stimulus';

/**
 * File upload controller for ContentBlocks.
 * Uploads a file via AJAX to /_content-blocks/upload, then writes the
 * returned URL into the hidden path input and dispatches `change` so
 * autosave / LiveComponent bindings pick the new value up.
 *
 * The file can be picked through the <input type="file"> or dropped anywhere
 * on the widget — both paths funnel through the same `_upload()`, so the
 * endpoint, the CSRF token and the server-side limits are identical either way.
 * A third path skips the upload entirely: the link toggle reveals a text field
 * where an editor pastes a path or URL for an image that already exists.
 *
 * Every path ends in `_setValue()`, the single writer of the hidden input —
 * which is what keeps the preview, the empty state and the `change` dispatch
 * consistent whichever way the value arrived.
 *
 * Rendered by the `cb_image_upload_widget` form-theme block
 * (ImageUploadType); can also be used standalone:
 *
 *   <div data-controller="cb-file-upload"
 *        data-action="dragenter->cb-file-upload#dragEnter dragover->cb-file-upload#dragOver
 *                     dragleave->cb-file-upload#dragLeave drop->cb-file-upload#drop">
 *       <input type="file" data-action="change->cb-file-upload#upload" accept="image/*">
 *       <img data-cb-file-upload-target="preview" hidden>
 *       <input type="hidden" data-cb-file-upload-target="hiddenInput">
 *   </div>
 *
 * The CSRF token is read from the nearest `[data-cb-csrf-token]` ancestor
 * (rendered by the builder shell).
 */
export default class extends Controller {
    static targets = ['preview', 'hiddenInput', 'status', 'file', 'path', 'remove'];

    connect() {
        // Depth counter for the drag highlight: dragenter/dragleave fire for
        // every child the pointer crosses, so a plain toggle flickers off the
        // moment the cursor moves from the widget onto its own preview image.
        this._dragDepth = 0;
        this._syncEmptyState();
    }

    disconnect() {
        this._setDragging(false);
    }

    _getCsrfToken() {
        return this.element.closest('[data-cb-csrf-token]')?.dataset.cbCsrfToken || '';
    }

    async upload(event) {
        const file = event.target.files[0];
        if (!file) return;

        await this._upload(file);

        // Clear the picker so re-picking the same file fires `change` again.
        event.target.value = '';
    }

    dragEnter(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        this._dragDepth += 1;
        this._setDragging(true);
    }

    // Without preventDefault on dragover the browser refuses the drop and
    // navigates to the file instead.
    dragOver(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        if (event.dataTransfer) {
            event.dataTransfer.dropEffect = 'copy';
        }
    }

    dragLeave(event) {
        if (this._dragDepth === 0) return;
        event.preventDefault();
        this._dragDepth = Math.max(0, this._dragDepth - 1);
        if (this._dragDepth === 0) {
            this._setDragging(false);
        }
    }

    async drop(event) {
        if (!this._hasFiles(event)) return;
        event.preventDefault();
        this._dragDepth = 0;
        this._setDragging(false);

        const file = event.dataTransfer?.files?.[0];
        if (!file) return;

        // The picker's `accept` is the field's own contract (ImageUploadType
        // sets it, a host can narrow it); a drop must honor the same one, or
        // the widget would accept what its file dialog would not.
        if (!this._accepts(file)) {
            this._setStatus('error', this._t('cb.upload.rejected', 'Unsupported file type'));
            return;
        }

        await this._upload(file);
    }

    /** Clears the field — the stored file is untouched, only the reference goes. */
    remove() {
        this._setValue('');
        this._setStatus('idle');
    }

    /**
     * Reveals the raw path field. It is the escape hatch for images that already
     * exist somewhere — a media library the host fills by other means, an asset
     * migrated from a previous CMS — where re-uploading a copy would be absurd.
     */
    togglePath(event) {
        if (!this.hasPathTarget) return;

        const show = this.pathTarget.hidden;
        this.pathTarget.hidden = !show;
        event?.currentTarget?.setAttribute?.('aria-expanded', String(show));
        if (show) {
            this.pathTarget.value = this._value();
            this.pathTarget.focus();
        }
    }

    /**
     * Typing in the path field. The value is written through silently: the
     * sidebar's autosave already listens for `input` on the form, so notifying
     * here would only add a second save per keystroke.
     */
    editPath() {
        if (!this.hasPathTarget) return;
        this._setValue(this._normalizePath(this.pathTarget.value), { syncPath: false, notify: false });
    }

    /**
     * Enter commits instead of submitting the surrounding form — the field is a
     * value editor, not a search box. Written out rather than declared as a
     * `keydown.enter->…:prevent` action so it holds on Stimulus 3.0/3.1, where
     * action options do not exist.
     */
    pathKeydown(event) {
        if (event.key !== 'Enter') return;
        event.preventDefault();
        this.commitPath();
    }

    /** Commit — blur or Enter. This is where the model bindings are told. */
    commitPath() {
        if (!this.hasPathTarget) return;

        const cleaned = this._normalizePath(this.pathTarget.value);
        // Only rewrite the field when normalization actually changed something,
        // so a caret mid-edit is never yanked to the end.
        if (cleaned !== this.pathTarget.value) {
            this.pathTarget.value = cleaned;
        }
        this._setValue(cleaned, { syncPath: false });
    }

    /**
     * An absolute URL on this very origin is the same image as its path, and the
     * path is what survives a domain change — so a pasted
     * `https://this-host/uploads/a.jpg` is stored as `/uploads/a.jpg`. Anything
     * else (another origin, an already-relative path) is left exactly as typed:
     * guessing at a foreign URL's shape is how a widget breaks a CDN setup.
     */
    _normalizePath(value) {
        const raw = (value || '').trim();
        if (raw === '') return '';

        try {
            // No base URL on purpose: a relative value must stay exactly as
            // typed, not be resolved against the page the builder happens to be
            // previewing.
            const url = new URL(raw);
            if (/^https?:$/.test(url.protocol) && url.host === window.location.host) {
                return url.pathname + url.search;
            }
        } catch {
            // Not an absolute URL — a plain path, which is what we want anyway.
        }

        return raw;
    }

    async _upload(file) {
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
                this._setStatus('error', data.error || this._t('cb.upload.failed', 'Upload failed'));
                return;
            }

            this._setValue(data.url);
            this._setStatus('success');
        } catch (e) {
            console.error('Upload failed:', e);
            this._setStatus('error', this._t('cb.upload.network_error', 'Network error'));
        }
    }

    _value() {
        return this.hasHiddenInputTarget ? this.hiddenInputTarget.value : '';
    }

    /**
     * The one writer of the field's value: hidden input, preview, path field and
     * empty state move together, whether the value came from an upload, a drop,
     * a paste or the remove button.
     *
     * `notify` dispatches `change` on the hidden input, which is what autosave
     * and the Live model bindings listen for — a programmatic `.value =` fires
     * nothing on its own.
     */
    _setValue(value, { syncPath = true, notify = true } = {}) {
        if (this.hasHiddenInputTarget) {
            this.hiddenInputTarget.value = value;
        }

        if (this.hasPreviewTarget) {
            // An empty `src` resolves to the document URL and would have the
            // browser re-fetch the page as an image — drop the attribute.
            if (value === '') {
                this.previewTarget.removeAttribute('src');
            } else {
                this.previewTarget.src = value;
            }
            // Only the image is hidden — its frame is the drop zone and the
            // empty-state placeholder, so it stays on screen either way.
            this.previewTarget.hidden = value === '';
        }

        if (syncPath && this.hasPathTarget) {
            this.pathTarget.value = value;
        }

        this._syncEmptyState();

        if (notify && this.hasHiddenInputTarget) {
            this.hiddenInputTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }

    _syncEmptyState() {
        const empty = this._value() === '';
        this.element.classList.toggle('cb-image-upload--empty', empty);
        if (this.hasRemoveTarget) {
            this.removeTarget.hidden = empty;
        }
    }

    // True when the drag actually carries files — dragging text or an element
    // from elsewhere in the builder must not light the widget up.
    _hasFiles(event) {
        const types = event.dataTransfer?.types;
        if (!types) return false;

        return Array.from(types).includes('Files');
    }

    /**
     * Mirrors the <input accept> syntax: a comma-separated list of extensions
     * (`.png`), exact MIME types (`image/png`) and wildcards (`image/*`). No
     * accept attribute (or a dropped file whose type the browser could not
     * determine) means everything passes — the server is the real gate.
     */
    _accepts(file) {
        const accept = (this.hasFileTarget ? this.fileTarget.getAttribute('accept') : '') || '';
        if (accept.trim() === '') return true;

        const type = (file.type || '').toLowerCase();
        const name = (file.name || '').toLowerCase();

        return accept.split(',').some((raw) => {
            const rule = raw.trim().toLowerCase();
            if (rule === '') return false;
            if (rule.startsWith('.')) return name.endsWith(rule);
            if (rule.endsWith('/*')) return type.startsWith(rule.slice(0, -1));

            return type === rule;
        });
    }

    _setDragging(on) {
        if (!on) this._dragDepth = 0;
        this.element.classList.toggle('cb-image-upload--dragging', on);
    }

    /**
     * Same tiny lookup the builder shell uses: the host's translations are not
     * available client-side, so the widget renders them as `data-i18n-*`
     * attributes and we read them back, falling through to the English default
     * when the controller is used standalone.
     */
    _t(key, fallback) {
        const value = this.element.getAttribute('data-i18n-' + key.replace(/[._]/g, '-'));

        return value && value.length > 0 ? value : fallback;
    }

    _setStatus(state, message = '') {
        if (!this.hasStatusTarget) return;

        this.statusTarget.textContent = message
            || (state === 'uploading' ? this._t('cb.upload.uploading', 'Uploading...') : '');
        this.statusTarget.className = `cb-upload-status cb-upload-status--${state}`;
    }
}
