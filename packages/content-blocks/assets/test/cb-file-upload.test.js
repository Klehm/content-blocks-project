import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-file-upload_controller.js';

/**
 * Vitest unit tests for cb-file-upload — the image field's picker/drop zone.
 * Stimulus is not booted: the class is instantiated directly with a stubbed
 * `element` and targets, like the other controller tests in this suite.
 */

function setup({ accept = 'image/*', value = '' } = {}) {
    document.body.innerHTML = `
        <div data-cb-csrf-token="tok-123">
            <div id="root" class="cb-image-upload${value === '' ? ' cb-image-upload--empty' : ''}">
                <div id="wrapper" class="cb-image-upload__preview">
                    <img id="preview"${value === '' ? ' hidden' : ` src="${value}"`}>
                    <p class="cb-image-upload__hint">Drop an image here</p>
                </div>
                <label><input type="file" id="file" accept="${accept}"></label>
                <button type="button" id="remove"${value === '' ? ' hidden' : ''}>Remove</button>
                <button type="button" id="toggle" aria-expanded="false">🔗</button>
                <input type="text" id="path" value="${value}" hidden>
                <span id="status" class="cb-upload-status"></span>
                <input type="hidden" id="hidden" value="${value}">
            </div>
        </div>`;

    const element = document.getElementById('root');
    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    for (const [name, node] of Object.entries({
        file: document.getElementById('file'),
        status: document.getElementById('status'),
        preview: document.getElementById('preview'),
        hiddenInput: document.getElementById('hidden'),
        path: document.getElementById('path'),
        remove: document.getElementById('remove'),
    })) {
        Object.defineProperty(controller, `has${name[0].toUpperCase()}${name.slice(1)}Target`, { value: true });
        Object.defineProperty(controller, `${name}Target`, { value: node });
    }
    controller.connect();

    return { controller, element };
}

// jsdom has no DataTransfer, and a drag that carries files is entirely
// described by `types` + `files` as far as this controller is concerned.
function dragEvent(files = [], types = ['Files']) {
    return {
        preventDefault: vi.fn(),
        dataTransfer: { types, files, dropEffect: '' },
    };
}

function imageFile(name = 'photo.png', type = 'image/png') {
    return new File(['x'], name, { type });
}

function mockUpload(url = '/uploads/photo.png') {
    global.fetch = vi.fn(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ url }) }));
}

describe('cb-file-upload', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        global.fetch = undefined;
    });

    it('uploads a dropped file to the builder endpoint with the CSRF token', async () => {
        mockUpload('/uploads/dropped.png');
        const { controller, element } = setup();

        await controller.drop(dragEvent([imageFile()]));

        expect(global.fetch).toHaveBeenCalledTimes(1);
        const [url, options] = global.fetch.mock.calls[0];
        expect(url).toBe('/_content-blocks/upload');
        expect(options.headers['X-CSRF-Token']).toBe('tok-123');
        expect(options.body.get('file')).toBeInstanceOf(File);
        // Same post-upload wiring as the picker: preview shown, hidden input set.
        expect(document.getElementById('hidden').value).toBe('/uploads/dropped.png');
        expect(document.getElementById('preview').hidden).toBe(false);
        // The frame itself never hides — it is the drop zone and the empty-state
        // placeholder; only the <img> inside it comes and goes.
        expect(element.querySelector('.cb-image-upload__preview').hasAttribute('hidden')).toBe(false);
    });

    it('dispatches change on the hidden input so autosave persists the drop', async () => {
        mockUpload();
        const { controller } = setup();
        const seen = [];
        document.getElementById('hidden').addEventListener('change', () => seen.push('change'));

        await controller.drop(dragEvent([imageFile()]));

        expect(seen).toEqual(['change']);
    });

    it('lights the widget up while a file drag is over it, and only for files', () => {
        const { controller, element } = setup();

        controller.dragEnter(dragEvent([], ['Files']));
        expect(element.classList.contains('cb-image-upload--dragging')).toBe(true);

        controller.dragLeave(dragEvent([], ['Files']));
        expect(element.classList.contains('cb-image-upload--dragging')).toBe(false);

        // A collection row being dragged across the sidebar carries no files.
        controller.dragEnter(dragEvent([], ['text/plain']));
        expect(element.classList.contains('cb-image-upload--dragging')).toBe(false);
    });

    /**
     * dragenter/dragleave fire again for every child the pointer crosses. A
     * plain toggle would drop the highlight the moment the cursor moved from
     * the widget onto its own preview image, so the controller counts depth.
     */
    it('keeps the highlight while the pointer crosses child elements', () => {
        const { controller, element } = setup();

        controller.dragEnter(dragEvent());   // onto the widget
        controller.dragEnter(dragEvent());   // onto the preview inside it
        controller.dragLeave(dragEvent());   // off the preview, still inside
        expect(element.classList.contains('cb-image-upload--dragging')).toBe(true);

        controller.dragLeave(dragEvent());   // off the widget
        expect(element.classList.contains('cb-image-upload--dragging')).toBe(false);
    });

    it('cancels the browser default so the file is not opened as a page', () => {
        const { controller } = setup();
        const over = dragEvent();

        controller.dragOver(over);

        expect(over.preventDefault).toHaveBeenCalled();
        expect(over.dataTransfer.dropEffect).toBe('copy');
    });

    it('rejects a dropped file the picker itself would not accept', async () => {
        mockUpload();
        const { controller } = setup({ accept: 'image/*' });

        await controller.drop(dragEvent([new File(['x'], 'notes.pdf', { type: 'application/pdf' })]));

        expect(global.fetch).not.toHaveBeenCalled();
        expect(document.getElementById('status').textContent).toBe('Unsupported file type');
        expect(document.getElementById('status').className).toContain('cb-upload-status--error');
    });

    it('honors an accept list of extensions and exact types', async () => {
        mockUpload();
        const { controller } = setup({ accept: '.webp,image/png' });

        await controller.drop(dragEvent([imageFile('a.png', 'image/png')]));
        await controller.drop(dragEvent([imageFile('b.webp', 'image/webp')]));
        await controller.drop(dragEvent([imageFile('c.gif', 'image/gif')]));

        expect(global.fetch).toHaveBeenCalledTimes(2);
    });

    it('reads its status wording from the widget data-i18n attributes', async () => {
        mockUpload();
        const { controller, element } = setup();
        element.setAttribute('data-i18n-cb-upload-rejected', 'Format refusé');

        await controller.drop(dragEvent([new File(['x'], 'notes.pdf', { type: 'application/pdf' })]));

        expect(document.getElementById('status').textContent).toBe('Format refusé');
    });

    it('reports a failed upload instead of writing a value', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, json: () => Promise.resolve({ error: 'File too large' }) }));
        const { controller } = setup();

        await controller.drop(dragEvent([imageFile()]));

        expect(document.getElementById('hidden').value).toBe('');
        expect(document.getElementById('status').textContent).toBe('File too large');
    });

    describe('remove', () => {
        it('clears the value, the preview and the empty state', () => {
            const { controller, element } = setup({ value: '/uploads/a.png' });
            const changes = [];
            document.getElementById('hidden').addEventListener('change', () => changes.push(1));

            controller.remove();

            expect(document.getElementById('hidden').value).toBe('');
            expect(document.getElementById('preview').hidden).toBe(true);
            // Not `src=""`: an empty src resolves to the document URL and the
            // browser would fetch the page as an image.
            expect(document.getElementById('preview').hasAttribute('src')).toBe(false);
            expect(document.getElementById('wrapper').hidden).toBe(false);
            expect(element.classList.contains('cb-image-upload--empty')).toBe(true);
            expect(document.getElementById('remove').hidden).toBe(true);
            expect(changes).toHaveLength(1);
        });
    });

    describe('paste a path', () => {
        it('reveals the field seeded with the current value, and focuses it', () => {
            const { controller } = setup({ value: '/uploads/a.png' });
            const toggle = document.getElementById('toggle');
            const path = document.getElementById('path');

            controller.togglePath({ currentTarget: toggle });

            expect(path.hidden).toBe(false);
            expect(path.value).toBe('/uploads/a.png');
            expect(toggle.getAttribute('aria-expanded')).toBe('true');
            expect(document.activeElement).toBe(path);

            controller.togglePath({ currentTarget: toggle });
            expect(path.hidden).toBe(true);
            expect(toggle.getAttribute('aria-expanded')).toBe('false');
        });

        it('writes a typed path through to the field, and fills the preview', () => {
            const { controller, element } = setup();
            const path = document.getElementById('path');

            path.value = '/files/visits/2026/house.png';
            controller.editPath();

            expect(document.getElementById('hidden').value).toBe('/files/visits/2026/house.png');
            expect(document.getElementById('preview').getAttribute('src')).toBe('/files/visits/2026/house.png');
            expect(element.classList.contains('cb-image-upload--empty')).toBe(false);
        });

        /**
         * Typing must not notify: the sidebar's autosave already saves on the
         * form's own `input` event, so a dispatch here doubles every keystroke.
         * The commit (blur / Enter) is what tells the model bindings.
         */
        it('stays silent while typing and notifies on commit', () => {
            const { controller } = setup();
            const changes = [];
            document.getElementById('hidden').addEventListener('change', () => changes.push(1));

            document.getElementById('path').value = '/uploads/b.png';
            controller.editPath();
            expect(changes).toHaveLength(0);

            controller.commitPath();
            expect(changes).toHaveLength(1);
        });

        /**
         * An absolute URL on this origin is the same image as its path, and the
         * path is what survives a domain change.
         */
        it('rewrites a same-origin URL to its path on commit', () => {
            const { controller } = setup();
            const path = document.getElementById('path');

            path.value = `${window.location.origin}/uploads/photo.png?v=2`;
            controller.commitPath();

            expect(path.value).toBe('/uploads/photo.png?v=2');
            expect(document.getElementById('hidden').value).toBe('/uploads/photo.png?v=2');
        });

        it('leaves a foreign URL and a relative path exactly as typed', () => {
            const { controller } = setup();
            const path = document.getElementById('path');

            for (const typed of ['https://cdn.example.test/a.png', 'files/a.png', '/files/a.png']) {
                path.value = typed;
                controller.commitPath();
                expect(path.value).toBe(typed);
                expect(document.getElementById('hidden').value).toBe(typed);
            }
        });

        it('commits on Enter instead of submitting the form around it', () => {
            const { controller } = setup();
            const changes = [];
            document.getElementById('hidden').addEventListener('change', () => changes.push(1));
            const event = { key: 'Enter', preventDefault: vi.fn() };

            document.getElementById('path').value = '/uploads/c.png';
            controller.pathKeydown(event);

            expect(event.preventDefault).toHaveBeenCalled();
            expect(document.getElementById('hidden').value).toBe('/uploads/c.png');
            expect(changes).toHaveLength(1);

            // Any other key is left to the browser.
            const typing = { key: 'a', preventDefault: vi.fn() };
            controller.pathKeydown(typing);
            expect(typing.preventDefault).not.toHaveBeenCalled();
        });

        it('is kept in sync when the value changes some other way', async () => {
            mockUpload('/uploads/uploaded.png');
            const { controller } = setup();

            await controller.drop(dragEvent([imageFile()]));

            expect(document.getElementById('path').value).toBe('/uploads/uploaded.png');
        });
    });

    /**
     * The picker fires `change` only when the selection changes, so re-picking
     * the file you just uploaded is silent unless the input is reset — which
     * matters now that a failed upload leaves the field empty but the picker
     * still holding that file's name.
     */
    it('clears the picker after an upload so the same file can be re-picked', async () => {
        mockUpload();
        const { controller } = setup();
        const target = { files: [imageFile()], value: 'C:\\fakepath\\photo.png' };

        await controller.upload({ target });

        // The picker path goes through the same upload as a drop.
        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(document.getElementById('hidden').value).toBe('/uploads/photo.png');
        expect(target.value).toBe('');
    });
});
