/**
 * The media picker: answers the upload widget's `cb:media:browse` with a
 * dialog, and hands the chosen file back through `pick()`.
 *
 * @see docs/internals/media.md#the-picker-is-self-contained
 */
export class MediaPicker {
    static SEARCH_DELAY = 250;

    constructor(dialog, { fetch = globalThis.fetch?.bind(globalThis) } = {}) {
        this.dialog = dialog;
        this.fetch = fetch;
        this.kinds = JSON.parse(dialog.dataset.kinds || '[]');
        this.messages = JSON.parse(dialog.dataset.i18n || '{}');
        this.request = null;
        this.state = { kind: 'image', folder: null, query: '', page: 1 };
        this.items = [];
        this.selected = null;
        // Answers to a superseded request are dropped, not painted over.
        this.generation = 0;

        this.el = (name) => dialog.querySelector(`[data-cb-media-${name}]`);
        this._bind();
    }

    supports(kind) {
        return this.kinds.includes(kind);
    }

    open(request) {
        this.request = request;
        this.state = { kind: request.kind || 'image', folder: null, query: '', page: 1 };
        this.selected = null;
        this.el('title').textContent = this._t(this.state.kind);
        this.el('search').value = '';
        this.el('file').setAttribute('accept', request.accept || '');
        this._paintSelection();

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
            const response = await this.fetch(`${this.dialog.dataset.listUrl}?${query}`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            });
            if (!response.ok || response.redirected) throw new Error(String(response.status));
            data = await response.json();
        } catch {
            if (generation === this.generation) this._status(this._t('failed'), true);
            return;
        }
        if (generation !== this.generation) return;

        this.items = append ? [...this.items, ...data.items] : data.items;
        if (!append) {
            this._paintTrail(data.breadcrumb ?? []);
            this._paintFolders(data.folders ?? []);
        }
        this._paintGrid();
        this.el('more').hidden = !data.hasMore;

        const nothing = this.items.length === 0 && (data.folders ?? []).length === 0;
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
        const token = this.dialog.dataset.csrfToken;

        try {
            const body = new FormData();
            body.append('file', file);
            body.append('area', this.dialog.dataset.area);
            const uploaded = await this._json(this.dialog.dataset.uploadUrl, {
                method: 'POST',
                headers: { 'X-CSRF-Token': token, 'X-Requested-With': 'XMLHttpRequest' },
                body,
            });
            const item = await this._json(this.dialog.dataset.placeUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': token,
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    area: this.dialog.dataset.area,
                    path: uploaded.url,
                    folder: this.state.folder,
                }),
            });

            this.state.query = '';
            this.el('search').value = '';
            await this.load();
            this.select(this.items.find((candidate) => candidate.id === item.id) ?? item);
        } catch (error) {
            this._status(error.message || this._t('upload_failed'), true);
        }
    }

    async _json(url, init) {
        let response;
        try {
            response = await this.fetch(url, { credentials: 'same-origin', ...init });
        } catch {
            throw new Error(this._t('upload_failed'));
        }
        const data = await response.json().catch(() => ({}));
        if (!response.ok || response.redirected) {
            throw new Error(data.error || this._t('upload_failed'));
        }

        return data;
    }

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

        const openFolder = (event) => {
            const button = event.target.closest('[data-folder]');
            if (button) this.openFolder(button.dataset.folder === '' ? null : Number(button.dataset.folder));
        };
        this.el('folders').addEventListener('click', openFolder);
        this.el('trail').addEventListener('click', openFolder);
    }

    _item(button) {
        return this.items.find((item) => item.id === Number(button.dataset.id)) ?? null;
    }

    _paintTrail(breadcrumb) {
        const trail = this.el('trail');
        trail.replaceChildren();
        if (this.state.query !== '') return;

        const crumbs = [{ id: null, name: this._t('root') }, ...breadcrumb];
        crumbs.forEach((crumb, index) => {
            const last = index === crumbs.length - 1;
            const node = this.dialog.ownerDocument.createElement(last ? 'span' : 'button');
            node.className = 'cb-media__crumb';
            node.textContent = crumb.name;
            if (last) {
                node.setAttribute('aria-current', 'location');
            } else {
                node.type = 'button';
                node.dataset.folder = crumb.id ?? '';
            }
            trail.append(node);
        });
    }

    _paintFolders(folders) {
        const list = this.el('folders');
        list.replaceChildren(...folders.map((folder) => {
            const li = this.dialog.ownerDocument.createElement('li');
            const button = this.dialog.ownerDocument.createElement('button');
            button.type = 'button';
            button.className = 'cb-media__folder';
            button.dataset.folder = String(folder.id);
            button.textContent = folder.name;
            li.append(button);

            return li;
        }));
        list.hidden = folders.length === 0;
    }

    _paintGrid() {
        const doc = this.dialog.ownerDocument;
        this.el('grid').replaceChildren(...this.items.map((item) => {
            const li = doc.createElement('li');
            const button = doc.createElement('button');
            button.type = 'button';
            button.className = 'cb-media__item';
            button.dataset.id = String(item.id);
            button.setAttribute('aria-pressed', String(this.selected?.id === item.id));
            button.title = item.title;

            const frame = doc.createElement('span');
            frame.className = 'cb-media__thumb';
            if (item.kind === 'video') {
                const video = doc.createElement('video');
                video.src = item.path;
                video.muted = true;
                video.preload = 'metadata';
                frame.append(video);
            } else {
                const img = doc.createElement('img');
                img.src = item.thumb?.src ?? item.path;
                if (item.thumb?.srcset) {
                    img.srcset = item.thumb.srcset;
                    img.sizes = '160px';
                }
                img.alt = '';
                img.loading = 'lazy';
                frame.append(img);
            }

            const name = doc.createElement('span');
            name.className = 'cb-media__name';
            name.textContent = item.title;
            button.append(frame, name);
            li.append(button);

            return li;
        }));
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
