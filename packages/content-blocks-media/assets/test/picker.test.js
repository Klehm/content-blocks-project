import { describe, it, expect, beforeEach, vi } from 'vitest';
import { MediaPicker, install } from '../picker.js';

const URLS = {
    list: '/m/list', place: '/m/place', upload: '/cb/upload', folders: '/m/folders',
    folderCreate: '/m/folder/create', folderRename: '/m/folder/rename', folderDelete: '/m/folder/delete',
    fileUpdate: '/m/file/update', fileMove: '/m/file/move', fileRemove: '/m/file/remove', fileUsage: '/m/file/usage',
};
const I18N = {
    image: 'Pick an image', video: 'Pick a video', empty: 'Empty', no_match: 'No match', failed: 'Failed',
    root: 'Library', upload_failed: 'Upload failed', save_failed: 'Save failed', folder_name: 'Name',
    rename_folder: 'Rename', delete_folder: 'Delete', delete_folder_confirm: 'Delete %name%?',
    remove_confirm: 'Remove %name%?', remove_confirm_used: 'Remove %name%, used on %count%?',
    unused: 'Unused', draft: 'draft', others: 'and %count% more',
};

// Mirrors templates/picker.html.twig: only the hooks the script reads.
function mount({ kinds = ['image', 'video'], manage = true } = {}) {
    document.body.innerHTML = `
        <dialog data-cb-media data-area="42" data-csrf-token="tok"
                data-can-manage="${manage ? '1' : '0'}"
                data-kinds='${JSON.stringify(kinds)}'
                data-urls='${JSON.stringify(URLS)}'
                data-i18n='${JSON.stringify(I18N)}'>
            <form method="dialog">
                <h2 data-cb-media-title></h2>
                <input type="search" data-cb-media-search>
                <nav data-cb-media-trail></nav>
                ${manage ? '<button type="button" data-cb-media-new-folder></button>' : ''}
                <ul data-cb-media-folders></ul>
                <ul data-cb-media-grid></ul>
                <p data-cb-media-status></p>
                <button type="button" data-cb-media-more hidden></button>
                <aside data-cb-media-detail hidden>
                    <div data-cb-media-preview></div>
                    <p data-cb-media-facts></p>
                    <input data-cb-media-field="title" ${manage ? '' : 'readonly'}>
                    <textarea data-cb-media-field="alt" ${manage ? '' : 'readonly'}></textarea>
                    ${manage ? '<select data-cb-media-move></select>' : ''}
                    <ul data-cb-media-usage></ul>
                    ${manage ? '<button type="button" data-cb-media-remove></button>' : ''}
                </aside>
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

describe('MediaPicker — managing the library', () => {
    let dialog;
    let fetch;
    let confirm;
    let prompt;
    let picker;

    // Routes each request by URL, like the controllers would answer.
    function route(answers) {
        fetch.mockImplementation((url) => {
            const path = String(url).split('?')[0];
            const answer = answers[path] ?? page({ items: [item(1), item(2)] });

            return typeof answer === 'function' ? answer(url) : respond(answer);
        });
    }

    function posted(path) {
        const call = fetch.mock.calls.find(([url]) => url === path);

        return call ? JSON.parse(call[1].body) : null;
    }

    beforeEach(() => {
        dialog = mount();
        fetch = vi.fn();
        confirm = vi.fn(() => true);
        prompt = vi.fn(() => 'Logos');
        route({ '/m/file/usage': { pages: [], others: 0 }, '/m/folders': { folders: [] } });
        picker = new MediaPicker(dialog, { fetch, confirm, prompt });
    });

    it('opens the detail of the selected file with its title and alt', async () => {
        await picker.open(request());
        picker.select(picker.items[0]);

        expect(dialog.querySelector('[data-cb-media-detail]').hidden).toBe(false);
        expect(dialog.querySelector('[data-cb-media-field="title"]').value).toBe('file 1');
        expect(dialog.querySelector('[data-cb-media-facts]').textContent).toContain('120 × 80');
    });

    it('saves an edited alt and keeps the saved version', async () => {
        route({ '/m/file/update': item(1, { alt: 'Our logo' }), '/m/file/usage': { pages: [], others: 0 } });
        await picker.open(request());
        picker.select(picker.items[0]);
        const alt = dialog.querySelector('[data-cb-media-field="alt"]');

        alt.value = 'Our logo';
        alt.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(posted('/m/file/update')).toEqual({ id: 1, alt: 'Our logo' }));
        await vi.waitFor(() => expect(picker.items[0].alt).toBe('Our logo'));
    });

    it('inserts the alt as typed, even before it is saved', async () => {
        const req = request();
        await picker.open(req);
        picker.select(picker.items[0]);
        const alt = dialog.querySelector('[data-cb-media-field="alt"]');

        alt.value = 'Typed';
        alt.dispatchEvent(new Event('input'));
        picker.insert();

        expect(req.pick).toHaveBeenCalledWith({ path: '/u/1.png', alt: 'Typed' });
    });

    it('names the pages using the file, counts the others, marks drafts', async () => {
        route({
            '/m/file/usage': {
                pages: [
                    { area: 3, label: 'Home', url: '/home', draft: true, published: true },
                    { area: 4, label: 'Soon', url: null, draft: true, published: false },
                ],
                others: 2,
            },
        });
        await picker.open(request());
        picker.select(picker.items[0]);

        const usage = dialog.querySelector('[data-cb-media-usage]');
        await vi.waitFor(() => expect(usage.children).toHaveLength(3));
        expect(usage.querySelector('a').getAttribute('href')).toBe('/home');
        expect(usage.children[1].textContent).toBe('Soon (draft)');
        expect(usage.children[2].textContent).toBe('and 2 more');
    });

    it('warns with the number of pages before removing a used file', async () => {
        route({ '/m/file/usage': { pages: [{ area: 3, label: 'Home', url: null, draft: true, published: true }], others: 1 } });
        await picker.open(request());
        picker.select(picker.items[0]);
        await vi.waitFor(() => expect(picker.usage).not.toBeNull());
        confirm.mockReturnValueOnce(false);

        await picker.remove();

        expect(confirm).toHaveBeenCalledWith('Remove file 1, used on 2?');
        expect(posted('/m/file/remove')).toBeNull();

        await picker.remove();
        expect(posted('/m/file/remove')).toEqual({ id: 1 });
        expect(picker.selected).toBeNull();
    });

    it('creates a folder inside the one the editor is in', async () => {
        route({ '/m/folder/create': { id: 9, name: 'Logos' } });
        await picker.open(request());
        await picker.openFolder(7);

        await picker.createFolder();

        expect(prompt).toHaveBeenCalledWith('Name', '');
        expect(posted('/m/folder/create')).toEqual({ name: 'Logos', parent: 7 });
    });

    it('asks before deleting a folder, then reloads', async () => {
        route({ '/m/list': page({ folders: [{ id: 7, name: 'Logos' }] }), '/m/folder/delete': { deleted: true } });
        await picker.open(request());

        dialog.querySelector('[data-folder-act="delete"][data-folder-id="7"]').click();

        expect(confirm).toHaveBeenCalledWith('Delete Logos?');
        await vi.waitFor(() => expect(posted('/m/folder/delete')).toEqual({ id: 7 }));
    });

    it('moves a file to another folder, which takes it out of this one', async () => {
        route({
            '/m/folders': { folders: [{ id: 7, name: 'Logos', depth: 0 }] },
            '/m/file/move': item(1, { folder: 7 }),
            '/m/file/usage': { pages: [], others: 0 },
        });
        await picker.open(request());
        picker.select(picker.items[0]);
        const select = dialog.querySelector('[data-cb-media-move]');
        await vi.waitFor(() => expect(select.options).toHaveLength(2));

        select.value = '7';
        select.dispatchEvent(new Event('change'));

        await vi.waitFor(() => expect(posted('/m/file/move')).toEqual({ id: 1, folder: 7 }));
        await vi.waitFor(() => expect(picker.selected).toBeNull());
    });

    it('says why a change was refused', async () => {
        route({ '/m/file/update': () => respond({ error: 'invalid_title' }, { ok: false, status: 400 }) });
        await picker.open(request());
        picker.select(picker.items[0]);

        await picker.saveField('title', '');

        expect(dialog.querySelector('[data-cb-media-status]').textContent).toBe('invalid_title');
    });

    it('offers no management to an editor who may not manage', async () => {
        dialog = mount({ manage: false });
        route({ '/m/list': page({ items: [item(1)], folders: [{ id: 7, name: 'Logos' }] }) });
        picker = new MediaPicker(dialog, { fetch, confirm, prompt });
        await picker.open(request());
        picker.select(picker.items[0]);

        expect(dialog.querySelector('[data-folder-act]')).toBeNull();
        await picker.saveField('alt', 'x');
        expect(posted('/m/file/update')).toBeNull();
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
