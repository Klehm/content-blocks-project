import { Controller } from '@hotwired/stimulus';

/**
 * Loads TinyMCE from CDN on demand and binds it to the wrapped
 * textarea. On every edit the editor writes its HTML back into the
 * textarea and dispatches a bubbling `change` event so cb-autosave
 * picks the change up via the existing LiveComponent data-model wire.
 *
 * The controller's host element carries `data-live-ignore`, so the
 * Live Component morpher leaves TinyMCE's injected DOM (toolbar,
 * iframe) untouched on re-renders.
 */

const TINYMCE_CDN_URL = 'https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js';

// Module-level promise so concurrent connects share a single network
// load — multiple rich-text blocks on the same page won't each fetch
// the script.
let tinymceLoader = null;

function loadTinyMce() {
    if (window.tinymce) return Promise.resolve(window.tinymce);
    if (tinymceLoader) return tinymceLoader;

    tinymceLoader = new Promise((resolve, reject) => {
        const script = document.createElement('script');
        script.src = TINYMCE_CDN_URL;
        script.referrerPolicy = 'origin';
        script.onload = () => resolve(window.tinymce);
        script.onerror = () => {
            tinymceLoader = null;
            reject(new Error('Failed to load TinyMCE from CDN'));
        };
        document.head.appendChild(script);
    });

    return tinymceLoader;
}

export default class extends Controller {
    static targets = ['textarea'];

    async connect() {
        if (!this.hasTextareaTarget) return;

        try {
            const tinymce = await loadTinyMce();
            // The controller may have been disconnected while we were
            // waiting on the network — bail out in that case.
            if (!this.hasTextareaTarget) return;

            const textarea = this.textareaTarget;
            const editors = await tinymce.init({
                target: textarea,
                license_key: 'gpl',
                menubar: false,
                plugins: 'lists link autolink code',
                toolbar:
                    'undo redo | blocks | bold italic underline | bullist numlist | link | removeformat | code',
                height: 320,
                branding: false,
                promotion: false,
                setup: (editor) => {
                    // Sync editor HTML → textarea + fire `change` so
                    // cb-autosave's LiveComponent model binding picks
                    // it up. `input` debounces; `change`/`blur` flush.
                    const sync = (eventName) => () => {
                        editor.save();
                        textarea.dispatchEvent(new Event(eventName, { bubbles: true }));
                    };
                    editor.on('input keyup', sync('input'));
                    editor.on('change undo redo ExecCommand', sync('change'));
                    editor.on('blur', sync('change'));
                },
            });

            this._editor = Array.isArray(editors) ? editors[0] : editors;
        } catch (e) {
            // Leave the plain textarea visible as fallback.
            console.error('[cb-tinymce]', e);
        }
    }

    disconnect() {
        if (this._editor) {
            try {
                this._editor.remove();
            } catch (_) {
                // ignore — editor may already be torn down by morph
            }
            this._editor = null;
        }
    }
}
