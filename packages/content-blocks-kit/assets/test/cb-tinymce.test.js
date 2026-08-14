import { describe, it, expect, afterEach, vi } from 'vitest';
import Controller, { buildColorMap, buildTinyMceConfig } from '../controllers/cb-tinymce_controller.js';

/**
 * Unit tests for the TinyMCE adapter.
 *
 * The controller is driven the way the rest of the suite drives Stimulus
 * controllers — instantiated directly, with `element` and the value
 * properties defined, then `connect()` called — so no Stimulus runtime and no
 * real TinyMCE are needed. `window.tinymce` is a stub recording the init
 * config, which is exactly the surface this adapter is responsible for.
 */

function mount({ scriptUrl = '', uploadUrl = '', config = '', palette = '' } = {}) {
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
    Object.defineProperty(controller, 'uploadUrlValue', { value: uploadUrl });
    Object.defineProperty(controller, 'configValue', { value: config });
    Object.defineProperty(controller, 'paletteValue', { value: palette });

    return { controller, textarea };
}

/** A TinyMCE stand-in that records the config it was initialized with. */
function stubTinyMce() {
    const editor = {
        handlers: {},
        save: vi.fn(),
        remove: vi.fn(),
        on(events, handler) {
            events.split(' ').forEach((name) => { this.handlers[name] = handler; });
        },
    };
    const tinymce = {
        init: vi.fn(async (config) => {
            tinymce.config = config;
            config.setup?.(editor);
            return [editor];
        }),
    };
    window.tinymce = tinymce;

    return { tinymce, editor };
}

afterEach(() => {
    delete window.tinymce;
    vi.unstubAllGlobals();
});

describe('buildColorMap', () => {
    it('prepends palette colors (hex without #) then the web palette', () => {
        const map = buildColorMap([
            { label: 'Primary', color: '#eb0540' },
            { label: 'Dark', color: '#252525' },
        ]);

        // Flat [hex, label, hex, label, …] format; palette first.
        expect(map.slice(0, 4)).toEqual(['eb0540', 'Primary', '252525', 'Dark']);
        // Web palette still present afterwards.
        expect(map).toContain('Black');
        expect(map).toContain('White');
    });

    it('falls back to the web palette when no palette is given', () => {
        const map = buildColorMap(null);
        expect(map).toContain('Black');
        // No leading theme swatches.
        expect(map[0]).toBe('BFEDD2');
    });

    it('skips entries with an empty color and labels missing labels by hex', () => {
        const map = buildColorMap([
            { label: 'Nope', color: '' },
            { color: '#00ff00' },
        ]);

        expect(map).not.toContain('Nope');
        // Missing label → labeled by its hex.
        const idx = map.indexOf('00ff00');
        expect(idx).toBe(0);
        expect(map[idx + 1]).toBe('00ff00');
    });
});

describe('buildTinyMceConfig', () => {
    it('adds the image plugin and button only when uploads are on', () => {
        const withUploads = buildTinyMceConfig({ palette: null, uploads: true });
        expect(withUploads.plugins).toContain('image');
        expect(withUploads.toolbar).toContain('image');
        expect(withUploads.automatic_uploads).toBe(true);

        const without = buildTinyMceConfig({ palette: null, uploads: false });
        expect(without.plugins).not.toContain('image');
        expect(without.toolbar).not.toContain('image');
        expect(without.automatic_uploads).toBe(false);
    });

    it('runs under the GPL license key', () => {
        expect(buildTinyMceConfig({ palette: null, uploads: false }).license_key).toBe('gpl');
    });
});

describe('cb-tinymce connect', () => {
    it('mounts on the textarea using the host-bundled global when no script URL is set', async () => {
        const { tinymce } = stubTinyMce();
        const { controller, textarea } = mount();

        await controller.connect();

        expect(tinymce.init).toHaveBeenCalledOnce();
        expect(tinymce.config.target).toBe(textarea);
        // No upload URL → no upload handlers wired at all.
        expect(tinymce.config.images_upload_handler).toBeUndefined();
        expect(tinymce.config.file_picker_callback).toBeUndefined();
    });

    it('lets the host config win over the coded defaults', async () => {
        const { tinymce } = stubTinyMce();
        const { controller } = mount({
            config: JSON.stringify({ height: 500, toolbar: 'bold italic', menubar: true }),
        });

        await controller.connect();

        expect(tinymce.config.height).toBe(500);
        expect(tinymce.config.toolbar).toBe('bold italic');
        expect(tinymce.config.menubar).toBe(true);
    });

    it('keeps the autosave sync when the host also declares a setup callback', async () => {
        const { tinymce, editor } = stubTinyMce();
        const hostSetup = vi.fn();
        const { controller, textarea } = mount();
        document.addEventListener('cb-rich-text:configure', (e) => {
            e.detail.config.setup = hostSetup;
        }, { once: true });

        await controller.connect();

        expect(hostSetup).toHaveBeenCalledWith(editor);

        // …and our own sync is still bound: an edit writes back and bubbles.
        const bubbled = vi.fn();
        textarea.addEventListener('input', bubbled);
        editor.handlers.input();

        expect(editor.save).toHaveBeenCalled();
        expect(bubbled).toHaveBeenCalled();
    });

    it('offers the merged config to cb-rich-text:configure before init', async () => {
        const { tinymce } = stubTinyMce();
        const { controller } = mount({ config: JSON.stringify({ height: 500 }) });
        const seen = [];
        document.addEventListener('cb-rich-text:configure', (e) => {
            seen.push({
                editor: e.detail.editor,
                height: e.detail.config.height,
                license: e.detail.config.license_key,
                initCalled: tinymce.init.mock.calls.length,
            });
        }, { once: true });

        await controller.connect();

        // The listener sees the coded config already merged with the host's
        // JSON — and it sees it while there is still time to change it.
        expect(seen).toEqual([{ editor: 'tinymce', height: 500, license: 'gpl', initCalled: 0 }]);
    });

    it('lets a listener add what JSON cannot carry', async () => {
        const { tinymce } = stubTinyMce();
        const { controller } = mount();
        const onAction = vi.fn();
        document.addEventListener('cb-rich-text:configure', (e) => {
            // The efs case: a stylesheet list only the bundler knows, and a
            // custom button whose handler is a function.
            e.detail.config.content_css = ['/build/app.4b6.css', '/build/front.f0a.css'];
            e.detail.config.buttons = { twocolumns: { onAction } };
        }, { once: true });

        await controller.connect();

        expect(tinymce.config.content_css).toEqual(['/build/app.4b6.css', '/build/front.f0a.css']);
        expect(tinymce.config.buttons.twocolumns.onAction).toBe(onAction);
    });

    it('routes a pasted image through the builder upload endpoint', async () => {
        const { tinymce } = stubTinyMce();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ url: '/uploads/blocks/pasted.png' }),
        }));

        const { controller } = mount({ uploadUrl: '/_content-blocks/upload' });
        await controller.connect();

        const blob = new Blob(['x'], { type: 'image/png' });
        const url = await tinymce.config.images_upload_handler({
            blob: () => blob,
            filename: () => 'pasted.png',
        });

        expect(url).toBe('/uploads/blocks/pasted.png');
        const [endpoint, init] = fetch.mock.calls[0];
        expect(endpoint).toBe('/_content-blocks/upload');
        // The CSRF token is read off the builder shell, not passed in.
        expect(init.headers['X-CSRF-Token']).toBe('tok-123');
    });

    it('leaves the plain textarea in place when the editor cannot load', async () => {
        const error = vi.spyOn(console, 'error').mockImplementation(() => {});
        // No global, no script URL → resolveEditorGlobal throws.
        const { controller, textarea } = mount();

        await controller.connect();

        expect(error).toHaveBeenCalled();
        expect(textarea.isConnected).toBe(true);
        error.mockRestore();
    });
});
