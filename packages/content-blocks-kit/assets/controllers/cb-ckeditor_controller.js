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
 * CKEditor 5 adapter. Same contract as the TinyMCE one, plus a stylesheet and
 * a custom upload adapter, since CKEditor has no built-in endpoint upload.
 *
 * @see docs/internals/kit.md#rich-text-one-payload-several-editors
 */

/**
 * Shorter than cb-autosave's own 250 ms debounce, so the model is fresh by the
 * time autosave decides to save.
 */
const CHANGE_FLUSH_MS = 150;

/**
 * Trailing debounce with a `cancel()`, so blur can flush immediately without
 * a queued call firing a second time behind it. Exported for unit tests.
 */
export function debounce(fn, wait) {
    let timer = null;
    const debounced = (...args) => {
        clearTimeout(timer);
        timer = setTimeout(() => fn(...args), wait);
    };
    debounced.cancel = () => clearTimeout(timer);

    return debounced;
}

/**
 * CKEditor 48 replaced `create(element, config)`. An unknown version means the
 * modern signature; only an older self-hosted build takes the legacy branch.
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
 * Skips any plugin the loaded build does not ship, so a trimmed build loses
 * the button rather than failing to boot.
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
 * Delegates to the same endpoint — and the same CSRF, MIME and size checks —
 * as every other kit upload, mapping its `{ url }` onto CKEditor's shape.
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
 * Packaged as a plugin so it installs during `create()`, which is the
 * documented seam and stops the first upload racing the editor.
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

            // The only way to pass what JSON cannot carry. Listeners mutate
            // `detail.config` in place. See kit.md#assets-and-the-asset-prefix
            this.dispatch('configure', {
                prefix: 'cb-rich-text',
                detail: { config, editor: 'ckeditor', element: this.element },
            });

            // After the merge, so trimming `plugins` does not silently lose
            // uploads — `options.uploads: false` is for that.
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

            // `input` keeps autosave in step, `change` pushes into the Live
            // model — without it a save POSTs the pre-edit value.
            const sync = (eventName) => {
                textarea.value = editor.getData();
                textarea.dispatchEvent(new Event(eventName, { bubbles: true }));
            };
            const flush = debounce(() => sync('change'), CHANGE_FLUSH_MS);

            editor.model.document.on('change:data', () => {
                sync('input');
                flush();
            });
            editor.editing.view.document.on('blur', () => {
                flush.cancel();
                sync('change');
            });
            this._flush = flush;

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
        // A queued flush would fire against a torn-down editor.
        this._flush?.cancel();
        this._flush = null;

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
