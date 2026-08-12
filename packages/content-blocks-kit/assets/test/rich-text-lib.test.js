import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
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
 * The editor-agnostic half of the rich-text bridge: everything both adapters
 * share. Tested here once rather than twice per editor.
 */

describe('mergeConfig', () => {
    it('lets the host override a coded value', () => {
        expect(mergeConfig({ height: 320, menubar: false }, { height: 500 }))
            .toEqual({ height: 500, menubar: false });
    });

    it('merges nested objects key by key', () => {
        const merged = mergeConfig(
            { image: { toolbar: ['a'], styles: ['s'] } },
            { image: { toolbar: ['b'] } },
        );

        // The override replaces the toolbar wholesale but keeps its sibling.
        expect(merged.image).toEqual({ toolbar: ['b'], styles: ['s'] });
    });

    it('replaces arrays instead of appending — a spelled-out toolbar is a replacement', () => {
        expect(mergeConfig({ toolbar: ['bold', 'italic'] }, { toolbar: ['link'] }))
            .toEqual({ toolbar: ['link'] });
    });

    it('ignores a non-object override', () => {
        const base = { height: 320 };
        expect(mergeConfig(base, null)).toBe(base);
        expect(mergeConfig(base, 'nope')).toBe(base);
    });
});

describe('parseJsonValue', () => {
    it('parses a payload', () => {
        expect(parseJsonValue('{"a":1}', {})).toEqual({ a: 1 });
    });

    it('falls back on empty, null and malformed input', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        expect(parseJsonValue('', { d: 1 })).toEqual({ d: 1 });
        expect(parseJsonValue('null', { d: 1 })).toEqual({ d: 1 });
        expect(parseJsonValue('{oops', { d: 1 })).toEqual({ d: 1 });

        expect(warn).toHaveBeenCalledOnce();
        warn.mockRestore();
    });
});

describe('readCsrfToken', () => {
    it('reads the token off the nearest ancestor carrying it', () => {
        document.body.innerHTML = `
            <div data-cb-csrf-token="tok-123">
                <div id="wrapper"><textarea></textarea></div>
            </div>`;

        expect(readCsrfToken(document.querySelector('textarea'))).toBe('tok-123');
    });

    it('returns an empty string outside the builder shell', () => {
        document.body.innerHTML = '<textarea></textarea>';

        expect(readCsrfToken(document.querySelector('textarea'))).toBe('');
    });
});

describe('resolveEditorGlobal', () => {
    afterEach(() => {
        delete window.fakeEditor;
    });

    it('returns an already-present global without loading anything', async () => {
        window.fakeEditor = { id: 'bundled' };

        await expect(resolveEditorGlobal('fakeEditor', '')).resolves.toEqual({ id: 'bundled' });
    });

    it('explains itself when CDN loading is off and the host bundled nothing', async () => {
        await expect(resolveEditorGlobal('fakeEditor', '')).rejects.toThrow(
            /window\.fakeEditor is not defined and CDN loading is disabled/,
        );
    });

    it('loads the script when a URL is given, then resolves the global', async () => {
        // jsdom does not fetch; emulate the browser by defining the global
        // when the <script> lands in the head.
        const observer = new MutationObserver((mutations) => {
            for (const m of mutations) {
                for (const node of m.addedNodes) {
                    if (node.tagName === 'SCRIPT') {
                        window.fakeEditor = { id: 'from-cdn' };
                        node.onload();
                    }
                }
            }
        });
        observer.observe(document.head, { childList: true });

        await expect(resolveEditorGlobal('fakeEditor', 'https://cdn.example/editor.js'))
            .resolves.toEqual({ id: 'from-cdn' });

        observer.disconnect();
    });
});

describe('loadStylesheet', () => {
    beforeEach(() => {
        document.head.innerHTML = '';
    });

    it('adds the link once, whatever the number of blocks on the page', () => {
        loadStylesheet('https://cdn.example/editor.css');
        loadStylesheet('https://cdn.example/editor.css');

        expect(document.head.querySelectorAll('link[rel="stylesheet"]')).toHaveLength(1);
    });

    it('does nothing without a URL', () => {
        loadStylesheet('');

        expect(document.head.querySelectorAll('link')).toHaveLength(0);
    });
});

describe('uploadFile', () => {
    const file = new File(['x'], 'photo.png', { type: 'image/png' });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('posts the file with the CSRF header and returns the stored URL', async () => {
        const fetchMock = vi.fn().mockResolvedValue({
            ok: true,
            status: 200,
            json: async () => ({ url: '/uploads/photo.png' }),
        });
        vi.stubGlobal('fetch', fetchMock);

        await expect(uploadFile(file, { uploadUrl: '/_content-blocks/upload', csrfToken: 'tok' }))
            .resolves.toBe('/uploads/photo.png');

        const [url, init] = fetchMock.mock.calls[0];
        expect(url).toBe('/_content-blocks/upload');
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-Token']).toBe('tok');
        expect(init.body.get('file')).toBeInstanceOf(File);
    });

    it('surfaces the endpoint\'s own error message', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 400,
            json: async () => ({ error: 'File type "image/tiff" is not allowed' }),
        }));

        await expect(uploadFile(file, { uploadUrl: '/upload', csrfToken: '' }))
            .rejects.toThrow('File type "image/tiff" is not allowed');
    });

    it('falls back to the status code when the body is not JSON', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: false,
            status: 502,
            json: async () => { throw new SyntaxError('not json'); },
        }));

        await expect(uploadFile(file, { uploadUrl: '/upload', csrfToken: '' }))
            .rejects.toThrow('Upload failed (HTTP 502)');
    });

    it('refuses to post when uploads are disabled', async () => {
        await expect(uploadFile(file, { uploadUrl: '', csrfToken: '' }))
            .rejects.toThrow('Uploads are disabled for this block.');
    });
});

describe('adoptDetachedUi', () => {
    beforeEach(() => {
        document.body.innerHTML = `
            <dialog id="builder"><div id="wrapper"><textarea></textarea></div></dialog>`;
    });

    it('moves matching containers from <body> into the builder dialog', async () => {
        const handle = adoptDetachedUi(document.querySelector('textarea'), '.aux');

        const aux = document.createElement('div');
        aux.className = 'aux';
        document.body.appendChild(aux);

        // MutationObserver delivers on a microtask.
        await Promise.resolve();

        expect(aux.parentElement.id).toBe('builder');
        handle.stop();
    });

    it('sweeps containers that already existed', () => {
        const aux = document.createElement('div');
        aux.className = 'aux';
        document.body.appendChild(aux);

        adoptDetachedUi(document.querySelector('textarea'), '.aux').stop();

        expect(aux.parentElement.id).toBe('builder');
    });

    it('is inert outside a dialog — the form may be embedded anywhere', async () => {
        document.body.innerHTML = '<div><textarea></textarea></div>';
        const handle = adoptDetachedUi(document.querySelector('textarea'), '.aux');

        const aux = document.createElement('div');
        aux.className = 'aux';
        document.body.appendChild(aux);
        await Promise.resolve();

        expect(aux.parentElement).toBe(document.body);
        handle.stop();
    });
});
