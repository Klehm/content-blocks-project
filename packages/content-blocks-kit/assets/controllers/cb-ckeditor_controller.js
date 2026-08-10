import { Controller } from '@hotwired/stimulus';
import {
    adoptDetachedUi,
    loadStylesheet,
    mergeConfig,
    parseJsonValue,
    readCsrfToken,
    resolveEditorGlobal,
    uploadFile,
} from '../lib/rich_text.js';

/**
 * CKEditor 5 adapter for the `rich_text` block.
 *
 * Same contract as the TinyMCE adapter — the textarea stays in the DOM and
 * keeps holding the HTML, the wrapper is `data-live-ignore`, the UI CKEditor
 * appends to <body> is re-parented into the builder's modal dialog — with the
 * two differences CKEditor brings:
 *
 *  - it needs a stylesheet next to its script (`style-url`);
 *  - it has no built-in endpoint upload: images go through a custom upload
 *    adapter (see `createUploadAdapter`) pointed at the builder's endpoint.
 *
 * Values are written by the PHP adapter (see CkEditor): `script-url`,
 * `style-url`, `upload-url`, `config`, `palette`.
 */

/**
 * Which factory signature to call.
 *
 * CKEditor 48 replaced `create(element, config)` with
 * `create({ attachTo: element, ...config })` and deprecated the old form. The
 * kit pins a 48+ CDN build, so an unknown version means "the modern one";
 * only a host self-hosting an older build takes the legacy branch.
 * Exported for unit tests.
 */
export function usesAttachToSignature(version) {
    const major = Number.parseInt(String(version ?? '').split('.')[0], 10);

    return Number.isNaN(major) || major >= 48;
}

export function createEditor(ClassicEditor, element, config, version) {
    return usesAttachToSignature(version)
        ? ClassicEditor.create({ attachTo: element, ...config })
        : ClassicEditor.create(element, config);
}

/**
 * Resolve plugin constructors by name off the CKEDITOR global, skipping any
 * the loaded build does not ship. A host bundling a trimmed build loses the
 * corresponding button rather than a boot error. Exported for unit tests.
 */
export function pickPlugins(ckeditor, names) {
    return names.map((name) => ckeditor[name]).filter(Boolean);
}

/**
 * Palette → CKEditor color-grid entries. Both use `{ color, label }`, so this
 * only drops entries without a color.
 */
export function buildColorGrid(palette) {
    if (!Array.isArray(palette)) return [];

    return palette
        .filter((entry) => entry?.color)
        .map((entry) => ({ color: entry.color, label: entry.label || entry.color }));
}

const PLUGIN_NAMES = [
    'Essentials', 'Paragraph', 'Heading', 'Bold', 'Italic', 'Underline',
    'Link', 'AutoLink', 'List', 'Alignment', 'FontColor', 'FontBackgroundColor',
    'RemoveFormat', 'SourceEditing', 'PasteFromOffice',
];

const IMAGE_PLUGIN_NAMES = [
    'Image', 'ImageToolbar', 'ImageCaption', 'ImageStyle', 'ImageResize', 'ImageUpload',
];

/**
 * The init config before the host's overrides. Exported for unit tests.
 */
export function buildCkEditorConfig(ckeditor, { palette, uploads }) {
    const colors = buildColorGrid(palette);
    const toolbar = [
        'undo', 'redo', '|', 'heading', '|', 'bold', 'italic', 'underline',
        '|', 'fontColor', 'fontBackgroundColor', '|', 'alignment',
        'bulletedList', 'numberedList', '|', 'link',
    ];

    if (uploads) toolbar.push('uploadImage');
    toolbar.push('|', 'removeFormat', 'sourceEditing');

    const config = {
        licenseKey: 'GPL',
        plugins: pickPlugins(ckeditor, uploads ? [...PLUGIN_NAMES, ...IMAGE_PLUGIN_NAMES] : PLUGIN_NAMES),
        toolbar,
    };

    // Seed both color dropdowns from the host palette, keeping CKEditor's own
    // grid available underneath via `colorPicker`.
    if (colors.length) {
        config.fontColor = { colors, columns: 5 };
        config.fontBackgroundColor = { colors, columns: 5 };
    }

    if (uploads) {
        config.image = {
            toolbar: ['imageTextAlternative', '|', 'imageStyle:inline', 'imageStyle:block', 'imageStyle:side'],
        };
    }

    return config;
}

/**
 * CKEditor's upload contract: a class with `upload()` resolving to
 * `{ default: url }` and `abort()`. Ours delegates to the same endpoint (and
 * the same CSRF + MIME + size validation) every other kit upload uses, and
 * maps the endpoint's `{ url }` onto the shape CKEditor wants.
 *
 * Exported as a factory so a test can drive it without an editor instance.
 */
export function createUploadAdapter({ uploadUrl, csrfToken }) {
    return (loader) => ({
        async upload() {
            const file = await loader.file;

            return { default: await uploadFile(file, { uploadUrl, csrfToken }) };
        },
        abort() {
            // The upload runs on fetch without an abort signal; CKEditor
            // tolerates a no-op and simply drops the placeholder.
        },
    });
}

/**
 * The adapter above, packaged as a CKEditor plugin so it is installed during
 * `create()` rather than bolted on afterwards — that is the documented seam,
 * and it means the first upload cannot race an editor that has not been told
 * where to send files yet.
 */
export function uploadAdapterPlugin(target) {
    return function ContentBlocksUploadAdapter(editor) {
        editor.plugins.get('FileRepository').createUploadAdapter = createUploadAdapter(target);
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
        this._detachedUi = adoptDetachedUi(textarea, '.ck-body-wrapper');

        try {
            loadStylesheet(this.styleUrlValue);
            const ckeditor = await resolveEditorGlobal('CKEDITOR', this.scriptUrlValue);
            if (!this.hasTextareaTarget) return; // disconnected while loading

            const uploads = Boolean(this.uploadUrlValue);
            const config = mergeConfig(
                buildCkEditorConfig(ckeditor, {
                    palette: parseJsonValue(this.paletteValue, null),
                    uploads,
                }),
                parseJsonValue(this.configValue, {}),
            );

            // Appended after the merge, so a host replacing `plugins` to trim
            // the build does not silently lose its image uploads — that is
            // what `options.uploads: false` is for.
            if (uploads) {
                config.plugins = [
                    ...(config.plugins ?? []),
                    uploadAdapterPlugin({
                        uploadUrl: this.uploadUrlValue,
                        csrfToken: readCsrfToken(textarea),
                    }),
                ];
            }

            const editor = await createEditor(
                ckeditor.ClassicEditor,
                textarea,
                config,
                window.CKEDITOR_VERSION,
            );

            // Write back on every change, before the event bubbles, for the
            // same reason TinyMCE does: cb-autosave must never read a stale
            // textarea. `input` debounces, `blur` flushes.
            const sync = (eventName) => {
                textarea.value = editor.getData();
                textarea.dispatchEvent(new Event(eventName, { bubbles: true }));
            };
            editor.model.document.on('change:data', () => sync('input'));
            editor.editing.view.document.on('blur', () => sync('change'));

            this._detachedUi.sweep();
            this._editor = editor;
        } catch (e) {
            // Leave the plain textarea visible as fallback.
            console.error('[cb-ckeditor]', e);
        }
    }

    async disconnect() {
        this._detachedUi?.stop();
        this._detachedUi = null;

        if (this._editor) {
            const editor = this._editor;
            this._editor = null;
            try {
                await editor.destroy();
            } catch (_) {
                // editor may already be torn down by the morph
            }
        }
    }
}
