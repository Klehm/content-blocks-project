import { Controller } from '@hotwired/stimulus';
import {
    adoptDetachedUi,
    mergeConfig,
    parseJsonValue,
    readCsrfToken,
    resolveEditorGlobal,
    uploadFile,
} from '../lib/rich_text.js';

/**
 * TinyMCE adapter for the `rich_text` block.
 *
 * Mounts TinyMCE on the block's textarea with the fixes a Live-Component
 * builder needs:
 *
 *  - the wrapper carries `data-live-ignore` (set by the form theme), so the
 *    morpher leaves TinyMCE's injected DOM — toolbar, iframe — untouched on
 *    re-renders;
 *  - on every edit `editor.save()` writes the HTML back to the textarea
 *    BEFORE the `input`/`change` event bubbles, so cb-autosave (and the Live
 *    save it triggers) always reads the latest value — no "one edit behind"
 *    race;
 *  - TinyMCE's auxiliary container (toolbar popups + WindowManager modals) is
 *    re-parented into the builder's `<dialog>`, which is opened with
 *    `showModal()` and therefore renders above anything left on `<body>`;
 *  - the color picker's swatches are seeded from the ContentBlocks palette,
 *    then the standard web palette, with a free picker still available.
 *
 * Values (all written by the PHP adapter, see TinyMceEditor):
 *  - `script-url`: where to load TinyMCE from; empty means the host bundled it
 *    and `window.tinymce` is expected to exist already.
 *  - `upload-url`: the builder's upload endpoint; empty disables image upload.
 *  - `config`: JSON merged over the init config below — the host's word wins.
 *  - `palette`: JSON `[{label, color}]` seeding the color swatches.
 *
 * Events:
 *  - `cb-rich-text:configure` fires on the wrapper (bubbling) once the config
 *    is merged and before TinyMCE is initialized. `event.detail.config` is the
 *    live object: mutate it to add what JSON cannot express — `setup` and the
 *    custom buttons it registers, or URLs only a bundler knows.
 */

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

/**
 * The init config before the host's overrides. Exported so a test can assert
 * what uploads add — and what they leave alone.
 */
export function buildTinyMceConfig({ palette, uploads }) {
    const plugins = ['advlist', 'lists', 'link', 'autolink', 'code'];
    const toolbar = [
        'undo redo', 'blocks', 'bold italic underline', 'forecolor backcolor',
        'alignleft aligncenter alignright', 'bullist numlist', 'link',
    ];

    if (uploads) {
        // `image` brings the dialog and the toolbar button; `automatic_uploads`
        // is what routes a pasted or dropped picture through the handler
        // instead of leaving a base64 data URI in the stored HTML.
        plugins.push('image');
        toolbar.push('image');
    }

    toolbar.push('removeformat code');

    return {
        license_key: 'gpl',
        menubar: false,
        branding: false,
        promotion: false,
        // Keep the status bar solely for the drag-to-resize grip.
        statusbar: true,
        elementpath: false,
        resize: true,
        toolbar_mode: 'sliding',
        plugins: plugins.join(' '),
        toolbar: toolbar.join(' | '),
        color_map: buildColorMap(palette),
        color_cols: 5,
        custom_colors: true,
        height: 320,
        automatic_uploads: uploads,
        file_picker_types: 'image',
    };
}

export default class extends Controller {
    static targets = ['textarea'];
    static values = {
        scriptUrl: String,
        styleUrl: String,
        uploadUrl: String,
        config: String,
        palette: String,
    };

    async connect() {
        if (!this.hasTextareaTarget) return;

        const textarea = this.textareaTarget;
        this._detachedUi = adoptDetachedUi(textarea, '.tox-tinymce-aux');

        try {
            const tinymce = await resolveEditorGlobal('tinymce', this.scriptUrlValue);
            if (!this.hasTextareaTarget) return; // disconnected while loading

            const config = mergeConfig(
                buildTinyMceConfig({
                    palette: parseJsonValue(this.paletteValue, null),
                    uploads: Boolean(this.uploadUrlValue),
                }),
                parseJsonValue(this.configValue, {}),
            );

            // Last word on the config, and the only way to pass anything JSON
            // cannot carry — `setup`, a custom button's `onAction`, a list of
            // stylesheet URLs a bundler only knows at build time. Listeners
            // mutate `detail.config` in place.
            this.dispatch('configure', {
                prefix: 'cb-rich-text',
                detail: { config, editor: 'tinymce', element: this.element },
            });

            const editors = await tinymce.init({
                ...config,
                target: textarea,
                ...(this.uploadUrlValue ? this._uploadHandlers(textarea) : {}),
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

                    // A host's `config.setup` runs after ours rather than
                    // replacing it: losing the autosave sync would be a
                    // silent data-loss bug, not a styling preference.
                    if (typeof config.setup === 'function') config.setup(editor);
                },
            });

            this._detachedUi.sweep();
            this._editor = Array.isArray(editors) ? editors[0] : editors;
        } catch (e) {
            // Leave the plain textarea visible as fallback.
            console.error('[cb-tinymce]', e);
        }
    }

    /**
     * Both routes an image can take into the editor, pointed at the builder's
     * upload endpoint: the dialog's "browse" button, and paste / drag-drop.
     */
    _uploadHandlers(textarea) {
        const target = { uploadUrl: this.uploadUrlValue, csrfToken: readCsrfToken(textarea) };

        return {
            images_upload_handler: (blobInfo) => uploadFile(blobInfo.blob(), {
                ...target,
                filename: blobInfo.filename(),
            }),
            file_picker_callback: (callback, _value, meta) => {
                if (meta.filetype !== 'image') return;

                const input = document.createElement('input');
                input.type = 'file';
                input.accept = 'image/*';
                input.addEventListener('change', async () => {
                    const file = input.files?.[0];
                    if (!file) return;
                    try {
                        callback(await uploadFile(file, target), { alt: file.name });
                    } catch (e) {
                        console.error('[cb-tinymce]', e);
                        this._editor?.notificationManager?.open({ text: e.message, type: 'error' });
                    }
                });
                input.click();
            },
        };
    }

    disconnect() {
        this._detachedUi?.stop();
        this._detachedUi = null;

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
