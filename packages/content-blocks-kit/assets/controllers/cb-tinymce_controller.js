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
 * TinyMCE adapter. The wrapper is `data-live-ignore` so the morpher leaves its
 * injected DOM alone, and its popups are re-parented into the modal `<dialog>`.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
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
        // `automatic_uploads` routes a pasted picture through the handler
        // rather than leaving a base64 data URI in the stored HTML.
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

            // The only way to pass what JSON cannot carry. Listeners mutate
            // `detail.config` in place. See kit.md#assets-and-the-asset-prefix
            this.dispatch('configure', {
                prefix: 'cb-rich-text',
                detail: { config, editor: 'tinymce', element: this.element },
            });

            const editors = await tinymce.init({
                ...config,
                target: textarea,
                ...(this.uploadUrlValue ? this._uploadHandlers(textarea) : {}),
                setup: (editor) => {
                    // Bubbled so cb-autosave's Live binding sees it:
                    // `input` debounces, `change`/`blur` flush.
                    const sync = (eventName) => () => {
                        editor.save();
                        textarea.dispatchEvent(new Event(eventName, { bubbles: true }));
                    };
                    editor.on('input keyup', sync('input'));
                    editor.on('change undo redo ExecCommand blur', sync('change'));

                    // After ours, not instead of it: losing the sync is a
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
