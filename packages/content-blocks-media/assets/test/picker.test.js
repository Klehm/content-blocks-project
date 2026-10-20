import { describe, it, expect, beforeEach, vi } from 'vitest';
import { MediaPicker, install } from '../picker.js';

// Mirrors templates/picker.html.twig: only the hooks the script reads.
function mount({ kinds = ['image', 'video'] } = {}) {
    document.body.innerHTML = `
        <dialog data-cb-media data-area="42"
                data-kinds='${JSON.stringify(kinds)}'
                data-list-url="/m/list" data-place-url="/m/place"
                data-upload-url="/cb/upload" data-csrf-token="tok"
                data-i18n='{"image":"Pick an image","video":"Pick a video","empty":"Empty","no_match":"No match","failed":"Failed","root":"Library","upload_failed":"Upload failed"}'>
            <form method="dialog">
                <h2 data-cb-media-title></h2>
                <input type="search" data-cb-media-search>
                <nav data-cb-media-trail></nav>
                <ul data-cb-media-folders></ul>
                <ul data-cb-media-grid></ul>
                <p data-cb-media-status></p>
                <button type="button" data-cb-media-more hidden></button>
                <input type="file" data-cb-media-file>
                <p data-cb-media-selection></p>
                <button type="button" data-cb-media-insert disabled></button>
            </form>
        </dialog>`;
    const dialog = document.querySelector('dialog');
    // jsdom has no modal dialog.
    dialog.showModal = vi.fn(() => dialog.setAttribute('open', ''));
    dialog.close = vi.fn(() => {
        dialog.removeAttribute('open');
        dialog.dispatchEvent(new Event('close'));
    });

    return dialog;
}

function item(id, extra = {}) {
    return { id, path: `/u/${id}.png`, title: `file ${id}`, alt: '', kind: 'image', width: 120, height: 80, thumb: { src: `/t/${id}.webp`, srcset: null }, ...extra };
}

function page({ items = [], folders = [], breadcrumb = [], hasMore = false } = {}) {
    return { items, folders, breadcrumb, hasMore, page: 1, folder: null };
}

function respond(data, { ok = true, status = 200 } = {}) {
    return Promise.resolve({ ok, status, redirected: false, json: () => Promise.resolve(data) });
}

function request(extra = {}) {
    return { kind: 'image', accept: 'image/*', value: '', pick: vi.fn(), ...extra };
}

describe('MediaPicker', () => {
    let dialog;
    let fetch;
    let picker;

    beforeEach(() => {
        dialog = mount();
        fetch = vi.fn(() => respond(page({ items: [item(1), item(2)] })));
        picker = new MediaPicker(dialog, { fetch });
    });

    it('opens on the kind asked, with its accept, and lists the root', async () => {
        await picker.open(request({ kind: 'video', accept: 'video/mp4' }));

        expect(dialog.showModal).toHaveBeenCalled();
        expect(dialog.querySelector('[data-cb-media-title]').textContent).toBe('Pick a video');
        expect(dialog.querySelector('[data-cb-media-file]').getAttribute('accept')).toBe('video/mp4');
        const url = new URL(fetch.mock.calls[0][0], 'http://x');
        expect(url.pathname).toBe('/m/list');
        expect(Object.fromEntries(url.searchParams)).toEqual({ area: '42', kind: 'video', folder: '', q: '', page: '1' });
    });

    it('paints a thumbnail per file, through the resolved URL', async () => {
        await picker.open(request());

        const images = dialog.querySelectorAll('[data-cb-media-grid] img');
        expect([...images].map((img) => img.getAttribute('src'))).toEqual(['/t/1.webp', '/t/2.webp']);
    });

    it('says the library is empty, or that a search found nothing', async () => {
        fetch.mockImplementation(() => respond(page()));
        const status = dialog.querySelector('[data-cb-media-status]');

        await picker.open(request());
        expect(status.textContent).toBe('Empty');

        await picker.search('nope');
        expect(status.textContent).toBe('No match');
    });

    it('drops an answer a newer request superseded', async () => {
        let release;
        fetch.mockImplementationOnce(() => new Promise((resolve) => { release = resolve; }));
        const first = picker.open(request());
        fetch.mockImplementationOnce(() => respond(page({ items: [item(9)] })));
        await picker.search('nine');

        release({ ok: true, json: () => Promise.resolve(page({ items: [item(1)] })) });
        await first;

        expect(picker.items.map((i) => i.id)).toEqual([9]);
    });

    it('inserts the selected file through pick() and closes', async () => {
        const req = request();
        await picker.open(req);

        dialog.querySelector('[data-id="2"]').click();
        expect(dialog.querySelector('[data-id="2"]').getAttribute('aria-pressed')).toBe('true');
        expect(dialog.querySelector('[data-cb-media-selection]').textContent).toBe('file 2 — 120 × 80');

        dialog.querySelector('[data-cb-media-insert]').click();

        expect(req.pick).toHaveBeenCalledWith({ path: '/u/2.png', alt: '' });
        expect(dialog.close).toHaveBeenCalled();
    });

    it('inserts on double click', async () => {
        const req = request();
        await picker.open(req);

        dialog.querySelector('[data-id="1"]').dispatchEvent(new MouseEvent('dblclick', { bubbles: true }));

        expect(req.pick).toHaveBeenCalledWith({ path: '/u/1.png', alt: '' });
    });

    it('cannot insert without a selection', async () => {
        const req = request();
        await picker.open(req);

        expect(dialog.querySelector('[data-cb-media-insert]').disabled).toBe(true);
        picker.insert();
        expect(req.pick).not.toHaveBeenCalled();
    });

    it('enters a folder and walks back up through the trail', async () => {
        fetch.mockImplementationOnce(() => respond(page({ folders: [{ id: 7, name: 'Logos' }] })));
        await picker.open(request());

        fetch.mockImplementationOnce(() => respond(page({ breadcrumb: [{ id: 7, name: 'Logos' }] })));
        dialog.querySelector('[data-folder="7"]').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(new URL(fetch.mock.calls[1][0], 'http://x').searchParams.get('folder')).toBe('7');

        await vi.waitFor(() => expect(dialog.querySelector('[data-cb-media-trail] [data-folder=""]')).not.toBeNull());
        expect(dialog.querySelector('[data-cb-media-trail] [aria-current]').textContent).toBe('Logos');

        dialog.querySelector('[data-cb-media-trail] [data-folder=""]').click();
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(3));
        expect(new URL(fetch.mock.calls[2][0], 'http://x').searchParams.get('folder')).toBe('');
    });

    it('appends the next page', async () => {
        fetch.mockImplementationOnce(() => respond(page({ items: [item(1)], hasMore: true })));
        await picker.open(request());
        expect(dialog.querySelector('[data-cb-media-more]').hidden).toBe(false);

        fetch.mockImplementationOnce(() => respond(page({ items: [item(2)] })));
        await picker.more();

        expect(new URL(fetch.mock.calls[1][0], 'http://x').searchParams.get('page')).toBe('2');
        expect(dialog.querySelectorAll('[data-id]')).toHaveLength(2);
        expect(dialog.querySelector('[data-cb-media-more]').hidden).toBe(true);
    });

    it('uploads through the core endpoint, files it here, and selects it', async () => {
        await picker.open(request());
        await picker.openFolder(7);
        fetch.mockReset();
        fetch
            .mockImplementationOnce(() => respond({ url: '/u/new.png' }))
            .mockImplementationOnce(() => respond(item(5, { path: '/u/new.png' })))
            .mockImplementationOnce(() => respond(page({ items: [item(5, { path: '/u/new.png' })] })));

        await picker.upload(new File(['x'], 'new.png', { type: 'image/png' }));

        const [uploadUrl, upload] = fetch.mock.calls[0];
        expect(uploadUrl).toBe('/cb/upload');
        expect(upload.headers['X-CSRF-Token']).toBe('tok');
        expect(upload.body.get('area')).toBe('42');
        const [placeUrl, place] = fetch.mock.calls[1];
        expect(placeUrl).toBe('/m/place');
        expect(JSON.parse(place.body)).toEqual({ area: '42', path: '/u/new.png', folder: 7 });
        expect(picker.selected.id).toBe(5);
    });

    it('says why an upload failed', async () => {
        await picker.open(request());
        fetch.mockImplementationOnce(() => respond({ error: 'File type "x" is not allowed' }, { ok: false, status: 400 }));

        await picker.upload(new File(['x'], 'a.exe'));

        const status = dialog.querySelector('[data-cb-media-status]');
        expect(status.textContent).toBe('File type "x" is not allowed');
        expect(status.classList.contains('cb-media__status--error')).toBe(true);
    });

    it('keeps its keys from the builder, and closes on Escape', async () => {
        await picker.open(request());
        const reachedDocument = vi.fn();
        document.addEventListener('keydown', reachedDocument);

        dialog.querySelector('[data-cb-media-search]')
            .dispatchEvent(new KeyboardEvent('keydown', { key: 'v', ctrlKey: true, bubbles: true }));
        dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

        expect(reachedDocument).not.toHaveBeenCalled();
        expect(dialog.close).toHaveBeenCalled();
        document.removeEventListener('keydown', reachedDocument);
    });

    it('searches at once on Enter rather than submitting the dialog', async () => {
        await picker.open(request());
        const search = dialog.querySelector('[data-cb-media-search]');
        search.value = 'logo';
        const enter = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });

        search.dispatchEvent(enter);

        expect(enter.defaultPrevented).toBe(true);
        await vi.waitFor(() => expect(fetch).toHaveBeenCalledTimes(2));
        expect(new URL(fetch.mock.calls[1][0], 'http://x').searchParams.get('q')).toBe('logo');
    });
});

describe('install', () => {
    function browse(detail) {
        const event = new CustomEvent('cb:media:browse', { bubbles: true, cancelable: true, detail });
        document.body.dispatchEvent(event);

        return event;
    }

    it('claims the kinds the library offers, and only those', () => {
        const dialog = mount({ kinds: ['image'] });
        globalThis.fetch = vi.fn(() => respond(page()));
        install(document);

        expect(browse(request({ kind: 'image' })).defaultPrevented).toBe(true);
        expect(dialog.hasAttribute('open')).toBe(true);
        dialog.close();
        expect(browse(request({ kind: 'video' })).defaultPrevented).toBe(false);
    });

    it('leaves an event alone when no picker is on the page', () => {
        document.body.innerHTML = '';
        install(document);

        expect(browse(request()).defaultPrevented).toBe(false);
    });
});
