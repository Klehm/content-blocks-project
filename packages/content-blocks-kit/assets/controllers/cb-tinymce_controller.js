import { Controller } from '@hotwired/stimulus';

/**
 * TinyMCE bridge for the RichText block's edit form.
 *
 * Loads TinyMCE from CDN on demand (the kit stays self-contained — the host
 * doesn't have to bundle it) and binds it to the wrapped textarea, with the
 * fixes a Live-Component builder needs:
 *
 *  - the wrapper carries `data-live-ignore`, so the morpher leaves TinyMCE's
 *    injected DOM (toolbar, iframe) untouched on re-renders;
 *  - on every edit `editor.save()` writes the HTML back to the textarea BEFORE
 *    the `input`/`change` event bubbles, so cb-autosave (and the Live save it
 *    triggers) always reads the latest value — no "one edit behind" race;
 *  - TinyMCE's auxiliary container (toolbar popups + WindowManager modals) is
 *    re-parented into the builder's native `<dialog>`. The dialog is opened
 *    with `showModal()` (top layer), so anything appended to `<body>` renders
 *    behind it and is uninteractable;
 *  - the color picker's swatches are seeded from the ContentBlocks palette when
 *    the form provides it (`data-cb-tinymce-palette-value`), then the standard
 *    web palette, with a free picker still available (`custom_colors`).
 *
 * Values:
 *  - `content-css`: comma-separated stylesheet URLs injected into the editor
 *    body so the editing surface mirrors the published page.
 *  - `palette`: JSON array of `{label, color}` (see `cb_color_palette()`),
 *    prepended to the color swatches.
 */

const TINYMCE_CDN_URL = 'https://cdn.jsdelivr.net/npm/tinymce@7/tinymce.min.js';

// Module-level promise so concurrent connects share a single network load —
// multiple rich-text blocks on a page won't each fetch the script.
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

// Standard web-color swatches appended after the theme palette. Kept in the
// TinyMCE `color_map` flat format: [hex, label, hex, label, …].
const WEB_COLOR_MAP = [
    'BFEDD2', 'Light Green', 'FBEEB8', 'Light Yellow', 'F8CAC6', 'Light Red',
    'ECCAFA', 'Light Purple', 'C2E0F4', 'Light Blue', '2DC26B', 'Green',
    'F1C40F', 'Yellow', 'E03E2D', 'Red', 'B96AD9', 'Purple', '3598DB', 'Blue',
    'E67E23', 'Orange', '95A5A6', 'Gray', '34495E', 'Navy', '000000', 'Black',
    'FFFFFF', 'White',
];

/**
 * Build a TinyMCE `color_map` from a ContentBlocks palette. Exported for unit
 * tests. Palette colors come first (labeled), then the standard web palette.
 */
export function buildColorMap(palette) {
    const themeSwatches = [];
    if (Array.isArray(palette)) {
        for (const entry of palette) {
            const hex = (entry?.color ?? '').replace(/^#/, '');
            if (hex) {
                themeSwatches.push(hex, entry.label || hex);
            }
        }
    }
    return [...themeSwatches, ...WEB_COLOR_MAP];
}

export default class extends Controller {
    static targets = ['textarea'];
    static values = { contentCss: String, palette: String };

    async connect() {
        if (!this.hasTextareaTarget) return;

        const textarea = this.textareaTarget;
        const dialog = textarea.closest('dialog');

        // Re-parent TinyMCE's aux container into the dialog as soon as it
        // appears, so popups/modals render in the same top layer as the
        // builder dialog rather than behind it.
        if (dialog) {
            this._auxObserver = new MutationObserver((mutations) => {
                for (const m of mutations) {
                    for (const node of m.addedNodes) {
                        if (node.nodeType === 1 && node.classList?.contains('tox-tinymce-aux')) {
                            dialog.appendChild(node);
                        }
                    }
                }
            });
            this._auxObserver.observe(document.body, { childList: true });
        }

        let palette = null;
        if (this.paletteValue) {
            try {
                palette = JSON.parse(this.paletteValue);
            } catch (_) {
                palette = null;
            }
        }

        try {
            const tinymce = await loadTinyMce();
            if (!this.hasTextareaTarget) return; // disconnected while loading

            const editors = await tinymce.init({
                target: textarea,
                license_key: 'gpl',
                menubar: false,
                branding: false,
                promotion: false,
                // Keep the status bar solely for the drag-to-resize grip.
                statusbar: true,
                elementpath: false,
                resize: true,
                toolbar_mode: 'sliding',
                plugins: 'advlist lists link autolink code',
                toolbar:
                    'undo redo | blocks | bold italic underline | forecolor backcolor | '
                    + 'alignleft aligncenter alignright | bullist numlist | link | removeformat | code',
                color_map: buildColorMap(palette),
                color_cols: 5,
                custom_colors: true,
                height: 320,
                ...(this.contentCssValue
                    ? { content_css: this.contentCssValue.split(',').filter(Boolean) }
                    : {}),
                setup: (editor) => {
                    // Sync editor HTML → textarea + bubble the event so
                    // cb-autosave's Live model binding picks it up. `input`
                    // debounces; `change`/`blur` flush.
                    const sync = (eventName) => () => {
                        editor.save();
                        textarea.dispatchEvent(new Event(eventName, { bubbles: true }));
                    };
                    editor.on('input keyup', sync('input'));
                    editor.on('change undo redo ExecCommand blur', sync('change'));
                },
            });

            // Catch-up: aux container may have been created synchronously
            // during init (already on <body> by the time we get here).
            if (dialog) {
                const aux = document.body.querySelector(':scope > .tox-tinymce-aux');
                if (aux) dialog.appendChild(aux);
            }

            this._editor = Array.isArray(editors) ? editors[0] : editors;
        } catch (e) {
            // Leave the plain textarea visible as fallback.
            console.error('[cb-tinymce]', e);
        }
    }

    disconnect() {
        if (this._auxObserver) {
            this._auxObserver.disconnect();
            this._auxObserver = null;
        }
        if (this._editor) {
            try {
                this._editor.remove();
            } catch (_) {
                // editor may already be torn down by the morph
            }
            this._editor = null;
        }
    }
}
