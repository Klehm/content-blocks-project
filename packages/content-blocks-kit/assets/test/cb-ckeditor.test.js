import { describe, it, expect, afterEach, vi } from 'vitest';
import Controller, {
    buildCkEditorConfig,
    buildColorGrid,
    createEditor,
    createUploadAdapter,
    pickPlugins,
    uploadAdapterPlugin,
    usesAttachToSignature,
} from '../controllers/cb-ckeditor_controller.js';

/**
 * Unit tests for the CKEditor 5 adapter, driven like the TinyMCE one: the
 * controller instantiated directly with its values defined, against a CKEDITOR
 * stub. What is worth pinning here is the editor-specific surface — the
 * factory signature that changed in v48, the plugin resolution that must
 * tolerate a trimmed build, and the upload adapter's response shape.
 */

/** Named plugin constructors, as the CDN bundle exposes them. */
function stubCkEditor({ createImpl } = {}) {
    const editor = {
        listeners: {},
        getData: vi.fn(() => '<p>edited</p>'),
        destroy: vi.fn(async () => {}),
        plugins: { get: vi.fn(() => ({})) },
        model: { document: { on: (name, cb) => { editor.listeners[`model:${name}`] = cb; } } },
        editing: { view: { document: { on: (name, cb) => { editor.listeners[`view:${name}`] = cb; } } } },
    };

    const ckeditor = {
        ClassicEditor: {
            create: vi.fn(createImpl ?? (async (...args) => {
                ckeditor.createArgs = args;
                return editor;
            })),
        },
    };

    // A representative slice of the open-source plugin set.
    for (const name of [
        'Essentials', 'Paragraph', 'Heading', 'Bold', 'Italic', 'Underline', 'Link',
        'AutoLink', 'List', 'Alignment', 'FontColor', 'FontBackgroundColor',
        'RemoveFormat', 'SourceEditing', 'PasteFromOffice', 'Image', 'ImageToolbar',
        'ImageCaption', 'ImageStyle', 'ImageResize', 'ImageUpload',
    ]) {
        ckeditor[name] = function Plugin() {};
        Object.defineProperty(ckeditor[name], 'name', { value: name });
    }

    window.CKEDITOR = ckeditor;

    return { ckeditor, editor };
}

function mount({ scriptUrl = '', styleUrl = '', uploadUrl = '', config = '', palette = '' } = {}) {
    document.body.innerHTML = `
        <dialog open data-cb-csrf-token="tok-123">
            <div id="wrapper"><textarea id="content">&lt;p&gt;hi&lt;/p&gt;</textarea></div>
        </dialog>`;

    const element = document.getElementById('wrapper');
    const textarea = document.getElementById('content');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasTextareaTarget', { value: true });
    Object.defineProperty(controller, 'textareaTarget', { value: textarea });
    Object.defineProperty(controller, 'scriptUrlValue', { value: scriptUrl });
    Object.defineProperty(controller, 'styleUrlValue', { value: styleUrl });
    Object.defineProperty(controller, 'uploadUrlValue', { value: uploadUrl });
    Object.defineProperty(controller, 'configValue', { value: config });
    Object.defineProperty(controller, 'paletteValue', { value: palette });

    return { controller, textarea };
}

afterEach(() => {
    delete window.CKEDITOR;
    delete window.CKEDITOR_VERSION;
    document.head.innerHTML = '';
    vi.unstubAllGlobals();
});

describe('usesAttachToSignature', () => {
    it('uses the modern factory from 48 on', () => {
        expect(usesAttachToSignature('48.3.1')).toBe(true);
        expect(usesAttachToSignature('49.0.0')).toBe(true);
    });

    it('falls back to the deprecated one for an older self-hosted build', () => {
        expect(usesAttachToSignature('47.9.9')).toBe(false);
        expect(usesAttachToSignature('41.0.0')).toBe(false);
    });

    it('assumes modern when the build exposes no version — the kit pins 48+', () => {
        expect(usesAttachToSignature(undefined)).toBe(true);
        expect(usesAttachToSignature('')).toBe(true);
    });
});

describe('createEditor', () => {
    it('passes the element through `attachTo` on 48+', async () => {
        const { ckeditor } = stubCkEditor();
        const element = document.createElement('textarea');

        await createEditor(ckeditor.ClassicEditor, element, { licenseKey: 'GPL' }, '48.3.1');

        expect(ckeditor.createArgs).toHaveLength(1);
        expect(ckeditor.createArgs[0]).toMatchObject({ attachTo: element, licenseKey: 'GPL' });
    });

    it('passes it positionally on older builds', async () => {
        const { ckeditor } = stubCkEditor();
        const element = document.createElement('textarea');

        await createEditor(ckeditor.ClassicEditor, element, { licenseKey: 'GPL' }, '47.0.0');

        expect(ckeditor.createArgs).toEqual([element, { licenseKey: 'GPL' }]);
    });
});

describe('pickPlugins', () => {
    it('drops names a trimmed build does not ship instead of throwing', () => {
        const { ckeditor } = stubCkEditor();

        const picked = pickPlugins(ckeditor, ['Bold', 'NotShipped', 'Italic']);

        expect(picked).toHaveLength(2);
        expect(picked.map((p) => p.name)).toEqual(['Bold', 'Italic']);
    });
});

describe('buildColorGrid', () => {
    it('maps the palette onto CKEditor color entries', () => {
        expect(buildColorGrid([{ label: 'Primary', color: '#eb0540' }]))
            .toEqual([{ color: '#eb0540', label: 'Primary' }]);
    });

    it('skips entries without a color and labels the unlabeled by hex', () => {
        expect(buildColorGrid([{ label: 'Nope', color: '' }, { color: '#00ff00' }]))
            .toEqual([{ color: '#00ff00', label: '#00ff00' }]);
    });

    it('tolerates a missing palette', () => {
        expect(buildColorGrid(null)).toEqual([]);
    });
});

describe('buildCkEditorConfig', () => {
    it('adds the image plugins and the upload button only when uploads are on', () => {
        const { ckeditor } = stubCkEditor();

        const withUploads = buildCkEditorConfig(ckeditor, { palette: null, uploads: true });
        expect(withUploads.toolbar).toContain('uploadImage');
        expect(withUploads.plugins.map((p) => p.name)).toContain('ImageUpload');
        expect(withUploads.image).toBeDefined();

        const without = buildCkEditorConfig(ckeditor, { palette: null, uploads: false });
        expect(without.toolbar).not.toContain('uploadImage');
        expect(without.plugins.map((p) => p.name)).not.toContain('ImageUpload');
        expect(without.image).toBeUndefined();
    });

    it('runs under the GPL license key', () => {
        const { ckeditor } = stubCkEditor();

        expect(buildCkEditorConfig(ckeditor, { palette: null, uploads: false }).licenseKey).toBe('GPL');
    });

    it('seeds both color dropdowns from the host palette', () => {
        const { ckeditor } = stubCkEditor();

        const config = buildCkEditorConfig(ckeditor, {
            palette: [{ label: 'Primary', color: '#eb0540' }],
            uploads: false,
        });

        expect(config.fontColor.colors).toEqual([{ color: '#eb0540', label: 'Primary' }]);
        expect(config.fontBackgroundColor.colors).toEqual(config.fontColor.colors);
    });

    it('leaves the color dropdowns on their own defaults when the palette is empty', () => {
        const { ckeditor } = stubCkEditor();

        const config = buildCkEditorConfig(ckeditor, { palette: [], uploads: false });

        expect(config.fontColor).toBeUndefined();
    });
});

describe('createUploadAdapter', () => {
    it('resolves the shape CKEditor expects from the endpoint\'s own payload', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ url: '/uploads/blocks/dropped.png' }),
        }));

        const adapter = createUploadAdapter({ uploadUrl: '/_content-blocks/upload', csrfToken: 'tok' })({
            file: Promise.resolve(new File(['x'], 'dropped.png', { type: 'image/png' })),
        });

        await expect(adapter.upload()).resolves.toEqual({ default: '/uploads/blocks/dropped.png' });
        expect(fetch.mock.calls[0][1].headers['X-CSRF-Token']).toBe('tok');
        expect(() => adapter.abort()).not.toThrow();
    });

    it('rejects with the endpoint\'s message so CKEditor can show it', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 400,
            json: async () => ({ error: 'File too large (max 10 MB)' }),
        }));

        const adapter = createUploadAdapter({ uploadUrl: '/upload', csrfToken: '' })({
            file: Promise.resolve(new File(['x'], 'big.png')),
        });

        await expect(adapter.upload()).rejects.toThrow('File too large (max 10 MB)');
    });
});

describe('uploadAdapterPlugin', () => {
    it('installs the adapter on the editor\'s FileRepository at init time', () => {
        const fileRepository = {};
        const editor = { plugins: { get: vi.fn(() => fileRepository) } };

        uploadAdapterPlugin({ uploadUrl: '/upload', csrfToken: '' })(editor);

        expect(editor.plugins.get).toHaveBeenCalledWith('FileRepository');
        expect(typeof fileRepository.createUploadAdapter).toBe('function');
    });
});

describe('cb-ckeditor connect', () => {
    it('mounts on the textarea and adds the editor stylesheet', async () => {
        const { ckeditor } = stubCkEditor();
        window.CKEDITOR_VERSION = '48.3.1';
        const { controller, textarea } = mount({ styleUrl: 'https://cdn.example/ckeditor5.css' });

        await controller.connect();

        expect(ckeditor.ClassicEditor.create).toHaveBeenCalledOnce();
        expect(ckeditor.createArgs[0].attachTo).toBe(textarea);
        expect(document.head.querySelector('link[rel="stylesheet"]').href)
            .toBe('https://cdn.example/ckeditor5.css');
    });

    it('offers the merged config to cb-rich-text:configure before create', async () => {
        const { ckeditor } = stubCkEditor();
        const { controller } = mount({ config: JSON.stringify({ toolbar: ['bold'] }) });
        const seen = [];
        document.addEventListener('cb-rich-text:configure', (e) => {
            seen.push({
                editor: e.detail.editor,
                toolbar: e.detail.config.toolbar,
                createCalled: ckeditor.ClassicEditor.create.mock.calls.length,
            });
        }, { once: true });

        await controller.connect();

        expect(seen).toEqual([{ editor: 'ckeditor', toolbar: ['bold'], createCalled: 0 }]);
    });

    it('lets a listener add what JSON cannot carry, and still wires uploads after it', async () => {
        const { ckeditor } = stubCkEditor();
        const { controller } = mount({ uploadUrl: '/_content-blocks/upload' });
        function HostPlugin() {}
        document.addEventListener('cb-rich-text:configure', (e) => {
            // A host replacing the plugin list wholesale must not silently
            // lose its uploads — the adapter is appended after this hook.
            e.detail.config.plugins = [HostPlugin];
        }, { once: true });

        await controller.connect();

        const config = ckeditor.createArgs[0];
        expect(config.plugins[0]).toBe(HostPlugin);
        expect(config.plugins).toHaveLength(2);
    });

    it('lets the host config win over the coded defaults', async () => {
        const { ckeditor } = stubCkEditor();
        const { controller } = mount({
            config: JSON.stringify({ toolbar: ['bold'], image: { toolbar: ['imageTextAlternative'] } }),
            uploadUrl: '/_content-blocks/upload',
        });

        await controller.connect();

        expect(ckeditor.createArgs[0].toolbar).toEqual(['bold']);
        expect(ckeditor.createArgs[0].image.toolbar).toEqual(['imageTextAlternative']);
    });

    it('keeps the upload adapter even when the host replaces the plugin list', async () => {
        const { ckeditor } = stubCkEditor();
        const { controller } = mount({
            config: JSON.stringify({ plugins: [] }),
            uploadUrl: '/_content-blocks/upload',
        });

        await controller.connect();

        const plugins = ckeditor.createArgs[0].plugins;
        expect(plugins).toHaveLength(1);
        expect(plugins[0].name).toBe('ContentBlocksUploadAdapter');
    });

    it('wires no upload adapter when uploads are off', async () => {
        const { ckeditor } = stubCkEditor();
        const { controller } = mount();

        await controller.connect();

        expect(ckeditor.createArgs[0].plugins.map((p) => p.name))
            .not.toContain('ContentBlocksUploadAdapter');
    });

    it('writes the HTML back to the textarea and bubbles the event on every change', async () => {
        const { editor } = stubCkEditor();
        const { controller, textarea } = mount();
        await controller.connect();

        const bubbled = vi.fn();
        textarea.addEventListener('input', bubbled);
        editor.listeners['model:change:data']();

        expect(textarea.value).toBe('<p>edited</p>');
        expect(bubbled).toHaveBeenCalledOnce();
    });

    /**
     * Regression guard, and the reason this controller does not simply mirror
     * `change:data` onto `input`: the Live model only picks the value up on
     * `change`, so without this the save POSTs the pre-edit content and the
     * block persists empty. CKEditor reports every keystroke, hence the
     * trailing debounce — one `change` per typing pause, not per character.
     */
    it('pushes the value into the Live model on a trailing change, once per pause', async () => {
        vi.useFakeTimers();
        const { editor } = stubCkEditor();
        const { controller, textarea } = mount();
        await controller.connect();

        const changes = vi.fn();
        textarea.addEventListener('change', changes);

        editor.listeners['model:change:data']();
        editor.listeners['model:change:data']();
        editor.listeners['model:change:data']();
        expect(changes).not.toHaveBeenCalled(); // still typing

        vi.advanceTimersByTime(200);
        expect(changes).toHaveBeenCalledOnce();

        vi.useRealTimers();
    });

    it('flushes on blur with a change event, without a queued one firing behind it', async () => {
        vi.useFakeTimers();
        const { editor } = stubCkEditor();
        const { controller, textarea } = mount();
        await controller.connect();

        const changes = vi.fn();
        textarea.addEventListener('change', changes);

        editor.listeners['model:change:data']();
        editor.listeners['view:blur']();
        expect(changes).toHaveBeenCalledOnce();

        // The pending debounce was cancelled by the blur, not left to fire.
        vi.advanceTimersByTime(500);
        expect(changes).toHaveBeenCalledOnce();

        vi.useRealTimers();
    });

    it('leaves the plain textarea in place when the editor cannot load', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        const { controller, textarea } = mount(); // no global, no script URL

        await controller.connect();

        expect(error).toHaveBeenCalled();
        expect(textarea.isConnected).toBe(true);
        error.mockRestore();
    });

    it('destroys the editor on disconnect so a morph cannot leave two of them', async () => {
        const { editor } = stubCkEditor();
        const { controller } = mount();
        await controller.connect();

        await controller.disconnect();

        expect(editor.destroy).toHaveBeenCalledOnce();
    });
});
