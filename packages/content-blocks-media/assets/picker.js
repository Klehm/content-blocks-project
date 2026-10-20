/**
 * The media picker: answers the upload widget's `cb:media:browse` with a
 * dialog, and hands the chosen file back through `pick()`.
 *
 * @see docs/internals/media.md#the-picker-is-self-contained
 */
export class MediaPicker {
    static SEARCH_DELAY = 250;

    constructor(dialog, {
        fetch = globalThis.fetch?.bind(globalThis),
        confirm = (message) => globalThis.confirm(message),
        prompt = (message, value) => globalThis.prompt(message, value),
    } = {}) {
        this.dialog = dialog;
        this.fetch = fetch;
        this.confirm = confirm;
        this.prompt = prompt;
        this.kinds = JSON.parse(dialog.dataset.kinds || '[]');
        this.urls = JSON.parse(dialog.dataset.urls || '{}');
        this.messages = JSON.parse(dialog.dataset.i18n || '{}');
        this.canManage = dialog.dataset.canManage === '1';
        this.request = null;
        this.state = { kind: 'image', folder: null, query: '', page: 1 };
        this.items = [];
        this.folders = [];
        this.selected = null;
        this.usage = null;
        // Answers to a superseded request are dropped, not painted over.
        this.generation = 0;
        this.usageGeneration = 0;

        this.el = (name) => dialog.querySelector(`[data-cb-media-${name}]`);
        this._bind();
    }

    supports(kind) {
        return this.kinds.includes(kind);
    }

    open(request) {
        this.request = request;
        this.state = { kind: request.kind || 'image', folder: null, query: '', page: 1 };
        this.el('title').textContent = this._t(this.state.kind);
        this.el('search').value = '';
        this.el('file').setAttribute('accept', request.accept || '');
        this.select(null);

        if (!this.dialog.open) {
            if (typeof this.dialog.showModal === 'function') this.dialog.showModal();
            else this.dialog.setAttribute('open', '');
        }
        this.el('search').focus();

        return this.load();
    }

    close() {
        this.request = null;
        if (typeof this.dialog.close === 'function') this.dialog.close();
        else this.dialog.removeAttribute('open');
    }

    async load({ append = false } = {}) {
        const generation = ++this.generation;
        if (!append) {
            this.state.page = 1;
            this._status(this._t('loading'));
        }

        const query = new URLSearchParams({
            area: this.dialog.dataset.area,
            kind: this.state.kind,
            folder: this.state.folder ?? '',
            q: this.state.query,
            page: String(this.state.page),
        });

        let data;
        try {
            data = await this._json(`${this.urls.list}?${query}`, {}, 'failed');
        } catch {
            if (generation === this.generation) this._status(this._t('failed'), true);
            return;
        }
        if (generation !== this.generation) return;

        this.items = append ? [...this.items, ...data.items] : data.items;
        if (!append) {
            this.folders = data.folders ?? [];
            this._paintTrail(data.breadcrumb ?? []);
            this._paintFolders();
        }
        this._paintGrid();
        this.el('more').hidden = !data.hasMore;

        const nothing = this.items.length === 0 && this.folders.length === 0;
        this._status(nothing ? this._t(this.state.query === '' ? 'empty' : 'no_match') : '');
    }

    openFolder(id) {
        this.state.folder = id;
        this.state.query = '';
        this.el('search').value = '';

        return this.load();
    }

    search(value) {
        this.state.query = value.trim();

        return this.load();
    }

    more() {
        this.state.page += 1;

        return this.load({ append: true });
    }

    select(item) {
        this.selected = item;
        for (const button of this.el('grid').querySelectorAll('[data-id]')) {
            button.setAttribute('aria-pressed', String(Number(button.dataset.id) === item?.id));
        }
        this._paintSelection();
        this._paintDetail();
        if (item) this._loadUsage(item);
    }

    insert() {
        if (!this.selected || !this.request) return;
        const { path, alt } = this.selected;
        const request = this.request;
        this.close();
        request.pick({ path, alt });
    }

    /** Through the core's upload endpoint, then filed under this folder. */
    async upload(file) {
        if (!file) return;
        this._status(this._t('uploading'));

        try {
            const body = new FormData();
            body.append('file', file);
            body.append('area', this.dialog.dataset.area);
            const uploaded = await this._json(this.urls.upload, {
                method: 'POST',
                headers: { 'X-CSRF-Token': this.dialog.dataset.csrfToken },
                body,
            }, 'upload_failed');
            const item = await this._post('place', {
                area: this.dialog.dataset.area,
                path: uploaded.url,
                folder: this.state.folder,
            }, 'upload_failed');

            this.state.query = '';
            this.el('search').value = '';
            await this.load();
            this.select(this.items.find((candidate) => candidate.id === item.id) ?? item);
        } catch (error) {
            this._status(error.message, true);
        }
    }

    // ---- managing ----------------------------------------------------------

    async saveField(field, value) {
        const item = this.selected;
        if (!item || !this.canManage) return;

        try {
            const saved = await this._post('fileUpdate', { id: item.id, [field]: value });
            this._replace(saved);
        } catch (error) {
            this._status(error.message, true);
        }
    }

    async move(folder) {
        const item = this.selected;
        if (!item) return;

        try {
            await this._post('fileMove', { id: item.id, folder });
            await this.load();
            this.select(null);
        } catch (error) {
            this._status(error.message, true);
        }
    }

    async remove() {
        const item = this.selected;
        if (!item) return;
        const used = this.usage ? this.usage.pages.length + this.usage.others : 0;
        const message = this._t(used > 0 ? 'remove_confirm_used' : 'remove_confirm')
            .replace('%name%', item.title)
            .replace('%count%', String(used));
        if (!this.confirm(message)) return;

        try {
            await this._post('fileRemove', { id: item.id });
            this.select(null);
            await this.load();
        } catch (error) {
            this._status(error.message, true);
        }
    }

    async createFolder() {
        const name = this.prompt(this._t('folder_name'), '')?.trim();
        if (!name) return;

        try {
            await this._post('folderCreate', { name, parent: this.state.folder });
            this.folderTree = null;
            await this.load();
        } catch (error) {
            this._status(error.message, true);
        }
    }

    async renameFolder(id) {
        const folder = this.folders.find((candidate) => candidate.id === id);
        const name = this.prompt(this._t('folder_name'), folder?.name ?? '')?.trim();
        if (!name || name === folder?.name) return;

        try {
            await this._post('folderRename', { id, name });
            this.folderTree = null;
            await this.load();
        } catch (error) {
            this._status(error.message, true);
        }
    }

    async deleteFolder(id) {
        const folder = this.folders.find((candidate) => candidate.id === id);
        if (!this.confirm(this._t('delete_folder_confirm').replace('%name%', folder?.name ?? ''))) return;

        try {
            await this._post('folderDelete', { id });
            this.folderTree = null;
            await this.load();
        } catch (error) {
            this._status(error.message, true);
        }
    }

    // ---- requests ----------------------------------------------------------

    _post(urlKey, body, fallback = 'save_failed') {
        return this._json(this.urls[urlKey], {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': this.dialog.dataset.csrfToken },
            body: JSON.stringify(body),
        }, fallback);
    }

    async _json(url, init, fallback) {
        let response;
        try {
            response = await this.fetch(url, {
                credentials: 'same-origin',
                ...init,
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest', ...(init.headers ?? {}) },
            });
        } catch {
            throw new Error(this._t(fallback));
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) {
            throw new Error(data.error && fallback !== 'failed' ? data.error : this._t(fallback));
        }

        return data;
    }

    async _loadUsage(item) {
        const generation = ++this.usageGeneration;
        this.usage = null;
        const list = this.el('usage');
        list.replaceChildren();

        try {
            const query = new URLSearchParams({ area: this.dialog.dataset.area, id: String(item.id) });
            const usage = await this._json(`${this.urls.fileUsage}?${query}`, {}, 'failed');
            if (generation !== this.usageGeneration) return;
            this.usage = usage;
            this._paintUsage();
        } catch {
            // The usage list is a hint; the rest of the panel stays useful.
        }
    }

    async _folderTree() {
        this.folderTree ??= this._json(
            `${this.urls.folders}?${new URLSearchParams({ area: this.dialog.dataset.area })}`,
            {},
            'failed',
        ).then((data) => data.folders).catch(() => []);

        return this.folderTree;
    }

    _replace(saved) {
        this.items = this.items.map((item) => (item.id === saved.id ? saved : item));
        if (this.selected?.id === saved.id) this.selected = saved;
        const name = this.el('grid').querySelector(`[data-id="${saved.id}"] .cb-media__name`);
        if (name) name.textContent = saved.title;
        this._paintSelection();
    }

    // ---- events ------------------------------------------------------------

    _bind() {
        // The builder's shortcuts would act behind the dialog: Escape on the
        // Navigator, Ctrl-V pasting a block. Its keys stay its own.
        this.dialog.addEventListener('keydown', (event) => {
            event.stopPropagation();
            if (event.key === 'Escape') {
                event.preventDefault();
                this.close();
            }
        });
        this.dialog.addEventListener('close', () => {
            this.request = null;
        });

        let timer = null;
        this.el('search').addEventListener('input', (event) => {
            clearTimeout(timer);
            timer = setTimeout(() => this.search(event.target.value), MediaPicker.SEARCH_DELAY);
        });
        // A search field submits its form on Enter, which closes the dialog.
        this.el('search').addEventListener('keydown', (event) => {
            if (event.key !== 'Enter') return;
            event.preventDefault();
            clearTimeout(timer);
            this.search(event.target.value);
        });

        this.el('more').addEventListener('click', () => this.more());
        this.el('insert').addEventListener('click', () => this.insert());
        this.el('file').addEventListener('change', (event) => {
            this.upload(event.target.files?.[0]);
            event.target.value = '';
        });

        this.el('grid').addEventListener('click', (event) => {
            const button = event.target.closest('[data-id]');
            if (button) this.select(this._item(button));
        });
        this.el('grid').addEventListener('dblclick', (event) => {
            const button = event.target.closest('[data-id]');
            if (!button) return;
            this.select(this._item(button));
            this.insert();
        });

        this.el('folders').addEventListener('click', (event) => {
            const action = event.target.closest('[data-folder-act]');
            if (action) {
                const id = Number(action.dataset.folderId);
                if (action.dataset.folderAct === 'rename') this.renameFolder(id);
                else this.deleteFolder(id);

                return;
            }
            this._openFolderFrom(event);
        });
        this.el('trail').addEventListener('click', (event) => this._openFolderFrom(event));
        this.el('new-folder')?.addEventListener('click', () => this.createFolder());

        for (const field of this.dialog.querySelectorAll('[data-cb-media-field]')) {
            const name = field.dataset.cbMediaField;
            // Kept as typed, so Insert carries an alt not saved yet.
            field.addEventListener('input', () => {
                if (this.selected && this.canManage) this.selected = { ...this.selected, [name]: field.value };
            });
            field.addEventListener('change', () => this.saveField(name, field.value));
        }
        this.el('move')?.addEventListener('change', (event) => {
            this.move(event.target.value === '' ? null : Number(event.target.value));
        });
        this.el('remove')?.addEventListener('click', () => this.remove());
    }

    _openFolderFrom(event) {
        const button = event.target.closest('[data-folder]');
        if (button) this.openFolder(button.dataset.folder === '' ? null : Number(button.dataset.folder));
    }

    _item(button) {
        return this.items.find((item) => item.id === Number(button.dataset.id)) ?? null;
    }

    // ---- painting ----------------------------------------------------------

    _node(tag, className, text) {
        const node = this.dialog.ownerDocument.createElement(tag);
        if (className) node.className = className;
        if (text !== undefined) node.textContent = text;

        return node;
    }

    _paintTrail(breadcrumb) {
        const trail = this.el('trail');
        trail.replaceChildren();
        const newFolder = this.el('new-folder');
        if (newFolder) newFolder.hidden = this.state.query !== '';
        if (this.state.query !== '') return;

        const crumbs = [{ id: null, name: this._t('root') }, ...breadcrumb];
        crumbs.forEach((crumb, index) => {
            const last = index === crumbs.length - 1;
            const node = this._node(last ? 'span' : 'button', 'cb-media__crumb', crumb.name);
            if (last) {
                node.setAttribute('aria-current', 'location');
            } else {
                node.type = 'button';
                node.dataset.folder = crumb.id ?? '';
            }
            trail.append(node);
        });
    }

    _paintFolders() {
        const list = this.el('folders');
        list.replaceChildren(...this.folders.map((folder) => {
            const li = this._node('li', 'cb-media__folder-row');
            const open = this._node('button', 'cb-media__folder', folder.name);
            open.type = 'button';
            open.dataset.folder = String(folder.id);
            li.append(open);
            if (this.canManage) {
                for (const [act, label, glyph] of [['rename', 'rename_folder', '✎'], ['delete', 'delete_folder', '×']]) {
                    const button = this._node('button', 'cb-media__folder-act', glyph);
                    button.type = 'button';
                    button.dataset.folderAct = act;
                    button.dataset.folderId = String(folder.id);
                    button.title = `${this._t(label)} — ${folder.name}`;
                    button.setAttribute('aria-label', button.title);
                    li.append(button);
                }
            }

            return li;
        }));
        list.hidden = this.folders.length === 0;
    }

    _paintGrid() {
        this.el('grid').replaceChildren(...this.items.map((item) => {
            const li = this._node('li');
            const button = this._node('button', 'cb-media__item');
            button.type = 'button';
            button.dataset.id = String(item.id);
            button.setAttribute('aria-pressed', String(this.selected?.id === item.id));
            button.title = item.title;

            const frame = this._node('span', 'cb-media__thumb');
            frame.append(this._visual(item, true));
            button.append(frame, this._node('span', 'cb-media__name', item.title));
            li.append(button);

            return li;
        }));
    }

    _visual(item, thumbnail) {
        if (item.kind === 'video') {
            const video = this._node('video');
            video.src = item.path;
            video.muted = true;
            video.preload = 'metadata';
            if (!thumbnail) video.controls = true;

            return video;
        }
        const img = this._node('img');
        img.src = thumbnail ? (item.thumb?.src ?? item.path) : item.path;
        if (thumbnail && item.thumb?.srcset) {
            img.srcset = item.thumb.srcset;
            img.sizes = '160px';
        }
        img.alt = '';
        if (thumbnail) img.loading = 'lazy';

        return img;
    }

    _paintSelection() {
        const item = this.selected;
        this.el('insert').disabled = item === null;
        if (item === null) {
            this.el('selection').textContent = '';
            return;
        }
        const size = item.width && item.height ? ` — ${item.width} × ${item.height}` : '';
        this.el('selection').textContent = `${item.title}${size}`;
    }

    async _paintDetail() {
        const item = this.selected;
        const detail = this.el('detail');
        detail.hidden = item === null;
        this.dialog.classList.toggle('cb-media--detail', item !== null);
        if (item === null) return;

        this.el('preview').replaceChildren(this._visual(item, false));
        const facts = [
            item.width && item.height ? `${item.width} × ${item.height}` : null,
            item.size ? `${Math.max(1, Math.round(item.size / 1024))} KB` : null,
            item.createdAt ? item.createdAt.slice(0, 10) : null,
        ].filter(Boolean);
        this.el('facts').textContent = facts.join(' · ');
        for (const field of this.dialog.querySelectorAll('[data-cb-media-field]')) {
            field.value = item[field.dataset.cbMediaField] ?? '';
        }

        const select = this.el('move');
        if (!select) return;
        const folders = await this._folderTree();
        if (this.selected?.id !== item.id) return;
        const options = [{ id: null, name: this._t('root'), depth: 0 }, ...folders.map((f) => ({ ...f, depth: f.depth + 1 }))];
        select.replaceChildren(...options.map((folder) => {
            const option = this._node('option', '', `${'  '.repeat(folder.depth)}${folder.name}`);
            option.value = folder.id === null ? '' : String(folder.id);
            option.selected = (item.folder ?? null) === folder.id;

            return option;
        }));
    }

    _paintUsage() {
        const list = this.el('usage');
        const { pages, others } = this.usage;
        if (pages.length === 0 && others === 0) {
            list.replaceChildren(this._node('li', 'cb-media__muted', this._t('unused')));
            return;
        }
        const rows = pages.map((page) => {
            const li = this._node('li');
            const label = page.url ? this._node('a', '', page.label) : this._node('span', '', page.label);
            if (page.url) {
                label.href = page.url;
                label.target = '_blank';
                label.rel = 'noopener';
            }
            li.append(label);
            if (!page.published) li.append(this._node('span', 'cb-media__muted', ` (${this._t('draft')})`));

            return li;
        });
        if (others > 0) rows.push(this._node('li', 'cb-media__muted', this._t('others').replace('%count%', String(others))));
        list.replaceChildren(...rows);
    }

    _status(message, error = false) {
        const status = this.el('status');
        status.textContent = message;
        status.classList.toggle('cb-media__status--error', error);
    }

    _t(key) {
        return this.messages[key] ?? key;
    }
}

/** One listener per document, whatever the number of fragments rendered. */
export function install(doc) {
    if (doc.cbMediaInstalled) return;
    doc.cbMediaInstalled = true;

    doc.addEventListener('cb:media:browse', (event) => {
        const dialog = doc.querySelector('dialog[data-cb-media]');
        if (!dialog || typeof event.detail?.pick !== 'function') return;

        dialog.cbMediaPicker ??= new MediaPicker(dialog);
        if (!dialog.cbMediaPicker.supports(event.detail.kind)) return;

        event.preventDefault();
        dialog.cbMediaPicker.open(event.detail);
    });
}

if (typeof document !== 'undefined') install(document);
