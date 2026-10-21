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
    remove_confirm_many: 'Remove %count% files?', upload_partial: '%failed% of %count% failed:', selected_many: '%count% selected', move_to: 'Move to',
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
                <div data-cb-media-notice hidden><span data-cb-media-notice-text></span><button type="button" data-cb-media-notice-close></button></div>
                ${manage ? '<button type="button" data-cb-media-new-folder></button>' : ''}
                <div data-cb-media-main>
                    <ul data-cb-media-folders></ul>
                    <ul data-cb-media-grid></ul>
                    <p data-cb-media-status></p>
                    <div data-cb-media-sentinel></div>
                    <button type="button" data-cb-media-more hidden></button>
                </div>
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

        const notice = dialog.querySelector('[data-cb-media-notice]');
        expect(notice.hidden).toBe(false);
        expect(notice.textContent).toBe('File type "x" is not allowed');
        expect(notice.classList.contains('cb-media__notice--error')).toBe(true);
        // Above the grid, not in the zone that scrolls.
        expect(dialog.querySelector('[data-cb-media-main]').contains(notice)).toBe(false);
    });

    it('says which part of a drop failed, and keeps the rest', async () => {
        await picker.open(request());
        let n = 0;
        fetch.mockImplementation((url) => {
            if (url === '/cb/upload') {
                n += 1;
                return n === 2
                    ? respond({ error: 'The file "b.png" is too large' }, { ok: false, status: 400 })
                    : respond({ url: `/u/${n}.png` });
            }
            if (url === '/m/place') return respond(item(10 + n));

            return respond(page({ items: [item(11), item(13)] }));
        });

        await picker.uploadMany([new File(['a'], 'a.png'), new File(['b'], 'b.png'), new File(['c'], 'c.png')]);

        expect(dialog.querySelector('[data-cb-media-notice-text]').textContent)
            .toBe('1 of 3 failed: The file "b.png" is too large');
        expect(picker.selection).toEqual(new Set([11, 13]));
    });

    it('a notice stays until closed, and a clean upload clears it', async () => {
        await picker.open(request());
        picker._notice('Nope', true);
        const notice = dialog.querySelector('[data-cb-media-notice]');

        dialog.querySelector('[data-cb-media-notice-close]').click();
        expect(notice.hidden).toBe(true);

        picker._notice('Nope', true);
        fetch.mockImplementation((url) => (url === '/cb/upload' ? respond({ url: '/u/9.png' })
            : url === '/m/place' ? respond(item(9)) : respond(page({ items: [item(9)] }))));
        await picker.upload(new File(['a'], 'a.png'));
        expect(notice.hidden).toBe(true);
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
        expect(posted('/m/file/remove')).toEqual({ ids: [1] });
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

        await vi.waitFor(() => expect(posted('/m/file/move')).toEqual({ ids: [1], folder: 7 }));
        await vi.waitFor(() => expect(picker.selected).toBeNull());
    });

    it('says why a change was refused', async () => {
        route({ '/m/file/update': () => respond({ error: 'invalid_title' }, { ok: false, status: 400 }) });
        await picker.open(request());
        picker.select(picker.items[0]);

        await picker.saveField('title', '');

        expect(dialog.querySelector('[data-cb-media-notice-text]').textContent).toBe('invalid_title');
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

describe('MediaPicker — a file browser', () => {
    let dialog;
    let fetch;
    let confirm;
    let picker;

    function route(answers) {
        fetch.mockImplementation((url) => {
            const path = String(url).split('?')[0];
            const answer = answers[path] ?? page({ items: [item(1), item(2), item(3), item(4)], folders: [{ id: 7, name: 'Logos' }] });

            return typeof answer === 'function' ? answer(url) : respond(answer);
        });
    }

    function posted(path) {
        const call = fetch.mock.calls.find(([url]) => url === path);

        return call ? JSON.parse(call[1].body) : null;
    }

    // jsdom has no DataTransfer: a drag is its types, files and data.
    function drag(type, target, { types = [], files = [], data = {} } = {}) {
        const event = new Event(type, { bubbles: true, cancelable: true });
        const store = { ...data };
        Object.defineProperty(event, 'dataTransfer', {
            value: {
                types, files, dropEffect: '', effectAllowed: '',
                setData: (k, v) => { store[k] = v; },
                getData: (k) => store[k] ?? '',
            },
        });
        target.dispatchEvent(event);

        return { event, store };
    }

    const button = (id) => dialog.querySelector(`[data-id="${id}"]`);
    const click = (target, init = {}) => target.dispatchEvent(new MouseEvent('click', { bubbles: true, ...init }));
    const pressed = () => [...dialog.querySelectorAll('[aria-pressed="true"]')].map((b) => Number(b.dataset.id));

    beforeEach(async () => {
        dialog = mount();
        fetch = vi.fn();
        confirm = vi.fn(() => true);
        route({ '/m/file/usage': { pages: [], others: 0 }, '/m/folders': { folders: [] } });
        picker = new MediaPicker(dialog, { fetch, confirm, prompt: vi.fn() });
        await picker.open(request());
    });

    it('lets go of a file clicked again, or of everything on a click in the empty', () => {
        click(button(2));
        expect(pressed()).toEqual([2]);
        click(button(2));
        expect(pressed()).toEqual([]);

        click(button(3));
        click(dialog.querySelector('[data-cb-media-main]'));
        expect(pressed()).toEqual([]);
        expect(dialog.querySelector('[data-cb-media-detail]').hidden).toBe(true);
    });

    it('adds with Ctrl or Cmd, takes a range with Shift', () => {
        click(button(1));
        click(button(3), { ctrlKey: true });
        expect(pressed()).toEqual([1, 3]);
        click(button(1), { metaKey: true });
        expect(pressed()).toEqual([3]);

        // The range runs from the last file clicked, as in a file browser.
        click(button(2));
        click(button(4), { shiftKey: true });
        expect(pressed()).toEqual([2, 3, 4]);
    });

    it('several files: no insert, a count, and the group actions only', () => {
        click(button(1));
        click(button(2), { ctrlKey: true });

        expect(dialog.querySelector('[data-cb-media-insert]').disabled).toBe(true);
        expect(dialog.querySelector('[data-cb-media-selection]').textContent).toBe('2 selected');
        expect(dialog.querySelector('[data-cb-media-detail]').classList.contains('cb-media__detail--many')).toBe(true);
    });

    it('selects all with Ctrl+A, but not while typing', () => {
        dialog.querySelector('[data-cb-media-grid]').dispatchEvent(new KeyboardEvent('keydown', { key: 'a', ctrlKey: true, bubbles: true, cancelable: true }));
        expect(pressed()).toEqual([1, 2, 3, 4]);

        picker.select(null);
        dialog.querySelector('[data-cb-media-search]').dispatchEvent(new KeyboardEvent('keydown', { key: 'a', ctrlKey: true, bubbles: true }));
        expect(pressed()).toEqual([]);
    });

    it('Escape lets go of the selection first, then closes', () => {
        click(button(1));
        const escape = () => dialog.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

        escape();
        expect(pressed()).toEqual([]);
        expect(dialog.close).not.toHaveBeenCalled();

        escape();
        expect(dialog.close).toHaveBeenCalled();
    });

    it('uploads every file dropped on the zone into this folder, and selects them', async () => {
        await picker.openFolder(7);
        let n = 10;
        route({
            '/cb/upload': () => respond({ url: `/u/${n}.png` }),
            '/m/place': () => respond(item(n++)),
            '/m/list': page({ items: [item(10), item(11)] }),
        });
        const main = dialog.querySelector('[data-cb-media-main]');
        const files = [new File(['a'], 'a.png'), new File(['b'], 'b.png')];

        drag('dragenter', main, { types: ['Files'] });
        expect(main.classList.contains('cb-media__main--drop')).toBe(true);
        drag('drop', main, { types: ['Files'], files });

        expect(main.classList.contains('cb-media__main--drop')).toBe(false);
        await vi.waitFor(() => expect(pressed()).toEqual([10, 11]));
        const places = fetch.mock.calls.filter(([url]) => url === '/m/place').map(([, init]) => JSON.parse(init.body));
        expect(places.map((p) => p.folder)).toEqual([7, 7]);
    });

    it('a drag that carries no file does not upload', () => {
        drag('drop', dialog.querySelector('[data-cb-media-main]'), { types: ['text/plain'] });

        expect(fetch.mock.calls.some(([url]) => url === '/cb/upload')).toBe(false);
    });

    it('drags the selection onto a folder, in one request', async () => {
        route({ '/m/file/move': { moved: 2 } });
        click(button(1));
        click(button(2), { ctrlKey: true });

        const { store } = drag('dragstart', button(2));
        const folder = dialog.querySelector('[data-folder="7"]');
        const over = drag('dragover', folder, { types: [MediaPicker.DRAG_TYPE] });
        expect(over.event.defaultPrevented).toBe(true);
        drag('drop', folder, { types: [MediaPicker.DRAG_TYPE], data: store });

        await vi.waitFor(() => expect(posted('/m/file/move')).toEqual({ ids: [1, 2], folder: 7 }));
    });

    it('a drag shows a compact ghost, dims what leaves, outlines where it can go', () => {
        click(button(1));
        click(button(2), { ctrlKey: true });
        const ghosts = [];
        const start = new Event('dragstart', { bubbles: true, cancelable: true });
        Object.defineProperty(start, 'dataTransfer', {
            value: { setData() {}, setDragImage: (node) => ghosts.push(node.textContent) },
        });

        button(2).dispatchEvent(start);

        expect(ghosts).toEqual(['2 selected']);
        expect(dialog.classList.contains('cb-media--dragging')).toBe(true);
        expect(button(1).classList.contains('cb-media__item--dragged')).toBe(true);
        expect(button(3).classList.contains('cb-media__item--dragged')).toBe(false);

        button(2).dispatchEvent(new Event('dragend', { bubbles: true }));
        expect(dialog.classList.contains('cb-media--dragging')).toBe(false);
        expect(dialog.querySelector('.cb-media__item--dragged')).toBeNull();
    });

    it('the whole folder row lights up, its buttons included, until left', () => {
        const row = dialog.querySelector('[data-folder="7"]').closest('.cb-media__folder-row');
        const rename = row.querySelector('[data-folder-act="rename"]');

        drag('dragover', rename, { types: [MediaPicker.DRAG_TYPE] });
        expect(row.classList.contains('cb-media__drop-target')).toBe(true);

        // Moving onto a child of the row is not leaving it.
        const inside = new Event('dragleave', { bubbles: true });
        Object.defineProperty(inside, 'relatedTarget', { value: row.querySelector('[data-folder]') });
        Object.defineProperty(inside, 'dataTransfer', { value: { types: [MediaPicker.DRAG_TYPE] } });
        rename.dispatchEvent(inside);
        expect(row.classList.contains('cb-media__drop-target')).toBe(true);

        const away = new Event('dragleave', { bubbles: true });
        Object.defineProperty(away, 'relatedTarget', { value: dialog.querySelector('[data-cb-media-grid]') });
        rename.dispatchEvent(away);
        expect(row.classList.contains('cb-media__drop-target')).toBe(false);
    });

    it('a drop on a folder\'s rename button still moves into that folder', async () => {
        route({ '/m/file/move': { moved: 1 } });
        const rename = dialog.querySelector('[data-folder-act="rename"][data-folder-id="7"]');

        drag('drop', rename, { types: [MediaPicker.DRAG_TYPE], data: { [MediaPicker.DRAG_TYPE]: '[3]' } });

        await vi.waitFor(() => expect(posted('/m/file/move')).toEqual({ ids: [3], folder: 7 }));
        expect(dialog.querySelector('.cb-media__drop-target')).toBeNull();
    });

    it('dragging a file outside the selection drags it alone', () => {
        click(button(1));

        const { store } = drag('dragstart', button(3));

        expect(JSON.parse(store[MediaPicker.DRAG_TYPE])).toEqual([3]);
        expect(pressed()).toEqual([3]);
    });

    it('a folder in the trail takes a drop too, so a file goes back up', async () => {
        await picker.openFolder(7);
        route({ '/m/list': page({ items: [item(1)], breadcrumb: [{ id: 7, name: 'Logos' }] }), '/m/file/move': { moved: 1 } });
        await picker.load();
        const root = dialog.querySelector('[data-cb-media-trail] [data-folder=""]');

        drag('drop', root, { types: [MediaPicker.DRAG_TYPE], data: { [MediaPicker.DRAG_TYPE]: '[1]' } });

        await vi.waitFor(() => expect(posted('/m/file/move')).toEqual({ ids: [1], folder: null }));
    });

    it('removes several files after one confirmation', async () => {
        route({ '/m/file/remove': { removed: 3 } });
        picker.selectAll();
        click(button(4), { ctrlKey: true });

        await picker.remove();

        expect(confirm).toHaveBeenCalledWith('Remove 3 files?');
        expect(posted('/m/file/remove')).toEqual({ ids: [1, 2, 3] });
    });

    it('an editor who may not manage cannot drag files', () => {
        dialog = mount({ manage: false });
        picker = new MediaPicker(dialog, { fetch, confirm, prompt: vi.fn() });

        return picker.open(request()).then(() => {
            expect(button(1).draggable).toBe(false);
        });
    });
});

describe('MediaPicker — loading on scroll', () => {
    let dialog;
    let fetch;
    let observer;

    // jsdom has no IntersectionObserver: this one is driven by the test.
    class FakeObserver {
        constructor(callback, options) {
            this.callback = callback;
            this.options = options;
            observer = this;
        }

        observe(target) {
            this.target = target;
            this.observed = (this.observed ?? 0) + 1;
            // A real observer reports the target's state once observed.
            if (this.visible !== undefined) queueMicrotask(() => this.show(this.visible));
        }

        unobserve() {}

        show(visible = true) {
            this.visible = visible;
            this.callback([{ target: this.target, isIntersecting: visible }]);
        }
    }

    // Page n of a folder holding `total` files, 2 per page.
    function pages(total) {
        return (url) => {
            const n = Number(new URL(url, 'http://x').searchParams.get('page'));
            const ids = [2 * n - 1, 2 * n].filter((id) => id <= total);

            return respond(page({ items: ids.map((id) => item(id)), hasMore: 2 * n < total }));
        };
    }

    const requested = () => fetch.mock.calls
        .filter(([url]) => String(url).startsWith('/m/list'))
        .map(([url]) => Number(new URL(url, 'http://x').searchParams.get('page')));

    beforeEach(() => {
        dialog = mount();
        fetch = vi.fn(pages(6));
    });

    function picker() {
        return new MediaPicker(dialog, { fetch, IntersectionObserver: FakeObserver });
    }

    it('watches the end of the grid inside the zone that scrolls', () => {
        picker();

        expect(observer.target).toBe(dialog.querySelector('[data-cb-media-sentinel]'));
        expect(observer.options.root).toBe(dialog.querySelector('[data-cb-media-main]'));
    });

    it('loads the next page when the end comes into view', async () => {
        const p = picker();
        await p.open(request());
        expect(requested()).toEqual([1]);

        observer.show();
        await vi.waitFor(() => expect(p.items).toHaveLength(6));

        expect(requested()).toEqual([1, 2, 3]);
        expect(dialog.querySelector('[data-cb-media-more]').hidden).toBe(true);
    });

    it('asks once for a page however often the end shows', async () => {
        let release;
        const p = picker();
        await p.open(request());
        fetch.mockImplementationOnce((url) => new Promise((resolve) => { release = () => resolve(pages(6)(url)); }));

        observer.show(false);
        p.hasMore = true;
        observer.show();
        observer.show();
        observer.show();
        expect(requested()).toEqual([1, 2]);

        observer.show(false);
        release();
        await vi.waitFor(() => expect(p.loadingMore).toBe(false));
        expect(requested()).toEqual([1, 2]);
    });

    it('keeps loading while a short first page leaves the end in view', async () => {
        const p = picker();
        observer.visible = true;

        await p.open(request());

        await vi.waitFor(() => expect(requested()).toEqual([1, 2, 3]));
        expect(p.hasMore).toBe(false);
    });

    // The grid it last saw is gone: a stale "in view" must not load a page.
    it('asks the observer again once the grid is replaced', async () => {
        const p = picker();
        await p.open(request());
        observer.visible = false;
        p.sentinelVisible = true;
        const before = observer.observed;

        await p.openFolder(7);
        await Promise.resolve();

        expect(observer.observed).toBe(before + 1);
        expect(requested()).toEqual([1, 1]);
    });

    // A next page must never cancel the folder the editor just opened.
    it('a next page in flight does not supersede a new listing', async () => {
        const p = picker();
        await p.open(request());
        let releasePage;
        fetch.mockImplementationOnce((url) => new Promise((resolve) => { releasePage = () => resolve(pages(6)(url)); }));
        p.more();

        fetch.mockImplementationOnce(() => respond(page({ items: [item(9)], breadcrumb: [{ id: 7, name: 'Logos' }] })));
        await p.openFolder(7);
        releasePage();
        await vi.waitFor(() => expect(p.loadingMore).toBe(false));

        expect(p.items.map((i) => i.id)).toEqual([9]);
    });

    it('no next page is asked while a listing loads', async () => {
        const p = picker();
        let releaseList;
        fetch.mockImplementationOnce((url) => new Promise((resolve) => { releaseList = () => resolve(pages(6)(url)); }));
        const opening = p.open(request());
        p.hasMore = true;

        await p.more();
        expect(requested()).toEqual([1]);

        releaseList();
        await opening;
    });

    it('a failed page is asked again, not skipped', async () => {
        const p = picker();
        await p.open(request());
        fetch.mockImplementationOnce(() => respond({}, { ok: false, status: 500 }));

        await p.more();
        expect(p.state.page).toBe(1);

        await p.more();
        expect(requested()).toEqual([1, 2, 2]);
    });

    it('adds the new tiles without repainting the ones shown', async () => {
        const p = picker();
        await p.open(request());
        const first = dialog.querySelector('[data-id="1"]');

        await p.more();

        expect(dialog.querySelector('[data-id="1"]')).toBe(first);
        expect(dialog.querySelectorAll('[data-id]')).toHaveLength(4);
    });

    it('works without an observer, through the button', async () => {
        const p = new MediaPicker(dialog, { fetch, IntersectionObserver: undefined });
        await p.open(request());

        dialog.querySelector('[data-cb-media-more]').click();

        await vi.waitFor(() => expect(requested()).toEqual([1, 2]));
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
