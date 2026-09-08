import { Controller } from '@hotwired/stimulus';

/**
 * Three ways in — picker, drop, pasted path — and one writer, `_setValue()`,
 * which is what keeps the preview, the empty state and `change` consistent.
 *
 * @see docs/internals/assets.md#three-seams-and-why-each-is-its-own-interface
 */
export default class extends Controller {
    static targets = ['preview', 'hiddenInput', 'status', 'file', 'path', 'remove'];

    connect() {
        // dragenter/dragleave fire per child crossed, so a plain toggle
        // flickers off as the cursor reaches the preview image.
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

        // A drop honours the same `accept` as the picker, or the widget
        // takes what its own file dialog would not.
        if (!this._accepts(file)) {
            this._setStatus('error', this._t('cb.upload.rejected', 'Unsupported file type'));
            return;
        }

        await this._upload(file);
    }

    /** Clears the reference. The stored file is untouched. */
    remove() {
        this._setValue('');
        this._setStatus('idle');
    }

    /**
     * The escape hatch for an image that already exists somewhere, where
     * re-uploading a copy would be absurd.
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
     * Written silently: autosave already listens for `input`, so notifying
     * would add a second save per keystroke.
     */
    editPath() {
        if (!this.hasPathTarget) return;
        this._setValue(this._normalizePath(this.pathTarget.value), { syncPath: false, notify: false });
    }

    /**
     * Enter commits rather than submitting. Written out rather than as an
     * action option, which Stimulus 3.0/3.1 does not have.
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
     * A same-origin absolute URL is stored as its path, which survives a domain
     * change. Anything else is left as typed — guessing breaks a CDN setup.
     */
    _normalizePath(value) {
        const raw = (value || '').trim();
        if (raw === '') return '';

        try {
            // No base URL: a relative value stays exactly as typed, not
            // resolved against whatever page is being previewed.
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
     * The one writer: input, preview, path field and empty state move together.
     * `notify` fires `change`, which a programmatic `.value =` does not.
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
     * Mirrors the `<input accept>` syntax. No attribute, or an undetermined
     * type, means everything passes — the server is the real gate.
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
     * The same `data-i18n-*` lookup the builder shell uses, falling back to
     * English when the controller runs standalone.
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
