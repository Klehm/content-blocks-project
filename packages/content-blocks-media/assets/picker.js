/**
 * The media picker: answers the upload widget's `cb:media:browse` with a
 * dialog, and hands the chosen file back through `pick()`.
 *
 * @see docs/internals/media.md#the-picker-is-self-contained
 */
export class MediaPicker {
    static SEARCH_DELAY = 250;
    // The dataTransfer type of files dragged inside the picker.
    static DRAG_TYPE = 'application/x-cb-media';

    constructor(dialog, {
        fetch = globalThis.fetch?.bind(globalThis),
        confirm = (message) => globalThis.confirm(message),
        prompt = (message, value) => globalThis.prompt(message, value),
        IntersectionObserver = globalThis.IntersectionObserver,
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
        // The selection is ids, so it survives a repaint; `anchor` is where
        // a Shift-click range starts.
        this.selection = new Set();
        this.anchor = null;
        this.edits = new Map();
        this.usage = null;
        this.dropDepth = 0;
        this.hasMore = false;
        this.loadingMore = false;
        this.listing = false;
        this.sentinelVisible = false;
        // Answers to a superseded request are dropped, not painted over.
        this.generation = 0;
        this.usageGeneration = 0;

        this.el = (name) => dialog.querySelector(`[data-cb-media-${name}]`);
        this._bind();
        this._observeEnd(IntersectionObserver);
    }

    supports(kind) {
        return this.kinds.includes(kind);
    }

    /** The one selected file, or null when none or several are. */
    get selected() {
        if (this.selection.size !== 1) return null;
        const [id] = this.selection;
        const item = this.items.find((candidate) => candidate.id === id);

        return item ? { ...item, ...(this.edits.get(id) ?? {}) } : null;
    }

    open(request) {
        this.request = request;
        this.state = { kind: request.kind || 'image', folder: null, query: '', page: 1 };
        this.el('title').textContent = this._t(this.state.kind);
        this.el('search').value = '';
        this.el('file').setAttribute('accept', request.accept || '');
        this._clearNotice();
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
        // A new listing supersedes everything in flight; a next page only
        // belongs to the listing it extends, and never cancels it.
        const generation = append ? this.generation : ++this.generation;
        if (!append) {
            this.state.page = 1;
            this.listing = true;
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
            if (generation === this.generation) {
                if (!append) this.listing = false;
                this._notice(this._t('failed'), true);
            }
            return false;
        }
        if (generation !== this.generation) return false;
        if (!append) this.listing = false;

        if (append) {
            // Only the new page is painted: a full repaint reloads every image.
            const known = new Set(this.items.map((item) => item.id));
            const added = data.items.filter((item) => !known.has(item.id));
            this.items = [...this.items, ...added];
            this.el('grid').append(...added.map((item) => this._tile(item)));
        } else {
            this.items = data.items;
            this.folders = data.folders ?? [];
            this._paintTrail(data.breadcrumb ?? []);
            this._paintFolders();
            this._paintGrid();
            // A file no longer listed cannot stay selected.
            this._keepListed();
            this.el('main').scrollTop = 0;
        }
        this.hasMore = Boolean(data.hasMore);
        this.el('more').hidden = !this.hasMore;

        const nothing = this.items.length === 0 && this.folders.length === 0;
        this._status(nothing ? this._t(this.state.query === '' ? 'empty' : 'no_match') : '');
        // The grid was replaced: what the observer last said is stale. Asked
        // again, it answers with where the end is now, and loads on if seen.
        if (!append) this._reobserve();

        return true;
    }

    openFolder(id) {
        this.state.folder = id;
        this.state.query = '';
        this.el('search').value = '';
        this.select(null);

        return this.load();
    }

    search(value) {
        this.state.query = value.trim();

        return this.load();
    }

    /** The next page, once at a time: a fast scroll asks more than once. */
    async more() {
        if (this.loadingMore || this.listing || !this.hasMore) return;
        this.loadingMore = true;
        this.state.page += 1;
        try {
            if (!(await this.load({ append: true }))) this.state.page -= 1;
        } finally {
            this.loadingMore = false;
        }
        this._continue();
    }

    _reobserve() {
        const sentinel = this.el('sentinel');
        if (!this.observer || !sentinel) return;
        this.sentinelVisible = false;
        this.observer.unobserve(sentinel);
        this.observer.observe(sentinel);
    }

    _continue() {
        if (this.sentinelVisible && this.hasMore && !this.loadingMore && !this.listing) this.more();
    }

    /** The end of the grid coming into view loads the next page. */
    _observeEnd(Observer) {
        const sentinel = this.el('sentinel');
        if (typeof Observer !== 'function' || !sentinel) return;

        this.observer = new Observer((entries) => {
            this.sentinelVisible = entries.some((entry) => entry.isIntersecting);
            this._continue();
        }, { root: this.el('main'), rootMargin: '0px 0px 300px 0px' });
        this.observer.observe(sentinel);
    }

    // ---- selection ---------------------------------------------------------

    /** Selects one file only, or nothing with null. */
    select(item) {
        this._setSelection(item ? [item.id] : [], item?.id ?? null);
    }

    /** Ctrl/Cmd-click: in or out of the selection, the others untouched. */
    toggle(item) {
        const ids = new Set(this.selection);
        if (ids.has(item.id)) ids.delete(item.id);
        else ids.add(item.id);
        this._setSelection([...ids], item.id);
    }

    /** Shift-click: every file between the anchor and this one. */
    extendTo(item) {
        const ids = this.items.map((candidate) => candidate.id);
        const from = ids.indexOf(this.anchor);
        const to = ids.indexOf(item.id);
        if (from === -1) {
            this.select(item);
            return;
        }
        const [start, end] = from < to ? [from, to] : [to, from];
        this._setSelection(ids.slice(start, end + 1), this.anchor);
    }

    selectAll() {
        this._setSelection(this.items.map((item) => item.id), this.items[0]?.id ?? null);
    }

    /** A plain click: select it, or let go of it if it was all there was. */
    clickItem(item, { toggle = false, range = false } = {}) {
        if (range) this.extendTo(item);
        else if (toggle) this.toggle(item);
        else if (this.selection.size === 1 && this.selection.has(item.id)) this.select(null);
        else this.select(item);
    }

    insert() {
        const item = this.selected;
        if (!item || !this.request) return;
        const request = this.request;
        this.close();
        request.pick({ path: item.path, alt: item.alt });
    }

    // ---- uploads -----------------------------------------------------------

    upload(file) {
        return this.uploadMany(file ? [file] : []);
    }

    /**
     * Through the core's upload endpoint one file at a time, each filed
     * under this folder; what made it in is selected.
     */
    async uploadMany(files) {
        const list = [...files];
        if (list.length === 0) return;

        const placed = [];
        const failures = [];
        for (const [index, file] of list.entries()) {
            this._notice(list.length > 1
                ? `${this._t('uploading')} ${index + 1} / ${list.length}`
                : this._t('uploading'));
            try {
                placed.push(await this._uploadOne(file));
            } catch (error) {
                failures.push(error.message);
            }
        }

        if (placed.length > 0) {
            this.state.query = '';
            this.el('search').value = '';
            await this.load();
            this._setSelection(placed.map((item) => item.id), placed[0].id);
        }
        if (failures.length === 0) {
            this._clearNotice();
        } else if (list.length === 1) {
            this._notice(failures[0], true);
        } else {
            const head = this._t('upload_partial')
                .replace('%failed%', String(failures.length))
                .replace('%count%', String(list.length));
            this._notice(`${head} ${[...new Set(failures)].join(' · ')}`, true);
        }
    }

    async _uploadOne(file) {
        const body = new FormData();
        body.append('file', file);
        body.append('area', this.dialog.dataset.area);
        const uploaded = await this._json(this.urls.upload, {
            method: 'POST',
            headers: { 'X-CSRF-Token': this.dialog.dataset.csrfToken },
            body,
        }, 'upload_failed');

        return this._post('place', {
            area: this.dialog.dataset.area,
            path: uploaded.url,
            folder: this.state.folder,
        }, 'upload_failed');
    }

    // ---- managing ----------------------------------------------------------

    async saveField(field, value) {
        const item = this.selected;
        if (!item || !this.canManage) return;

        try {
            const saved = await this._post('fileUpdate', { id: item.id, [field]: value });
            this._replace(saved);
        } catch (error) {
            this._notice(error.message, true);
        }
    }

    /** The selection, or the files given (a drag), into a folder. */
    async move(folder, ids = [...this.selection]) {
        if (ids.length === 0 || !this.canManage) return;
        if (folder === this.state.folder && this.state.query === '') return;

        try {
            await this._post('fileMove', { ids, folder });
            this.select(null);
            await this.load();
        } catch (error) {
            this._notice(error.message, true);
        }
    }

    async remove() {
        const ids = [...this.selection];
        if (ids.length === 0) return;

        let message;
        if (ids.length > 1) {
            message = this._t('remove_confirm_many').replace('%count%', String(ids.length));
        } else {
            const used = this.usage ? this.usage.pages.length + this.usage.others : 0;
            message = this._t(used > 0 ? 'remove_confirm_used' : 'remove_confirm')
                .replace('%name%', this.selected?.title ?? '')
                .replace('%count%', String(used));
        }
        if (!this.confirm(message)) return;

        try {
            await this._post('fileRemove', { ids });
            this.select(null);
            await this.load();
        } catch (error) {
            this._notice(error.message, true);
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
            this._notice(error.message, true);
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
            this._notice(error.message, true);
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
            this._notice(error.message, true);
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
        this.el('usage').replaceChildren();

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
        this.edits.delete(saved.id);
        const name = this.el('grid').querySelector(`[data-id="${saved.id}"] .cb-media__name`);
        if (name) name.textContent = saved.title;
        this._paintSelection();
    }

    _setSelection(ids, anchor) {
        const before = this.selected?.id ?? null;
        this.selection = new Set(ids);
        this.anchor = anchor;
        for (const button of this.el('grid').querySelectorAll('[data-id]')) {
            button.setAttribute('aria-pressed', String(this.selection.has(Number(button.dataset.id))));
        }
        this._paintSelection();
        this._paintDetail();
        const item = this.selected;
        if (item && item.id !== before) this._loadUsage(item);
    }

    _keepListed() {
        const listed = new Set(this.items.map((item) => item.id));
        const kept = [...this.selection].filter((id) => listed.has(id));
        if (kept.length !== this.selection.size) this._setSelection(kept, this.anchor);
    }

    // ---- events ------------------------------------------------------------

    _bind() {
        // The builder's shortcuts would act behind the dialog: Escape on the
        // Navigator, Ctrl-V pasting a block. Its keys stay its own.
        this.dialog.addEventListener('keydown', (event) => this._onKeydown(event));
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
        this.el('notice-close')?.addEventListener('click', () => this._clearNotice());
        this.el('insert').addEventListener('click', () => this.insert());
        this.el('file').addEventListener('change', (event) => {
            this.uploadMany(event.target.files ?? []);
            event.target.value = '';
        });

        this.el('main').addEventListener('click', (event) => {
            const button = event.target.closest('[data-id]');
            if (button) {
                const item = this._item(button);
                if (item) this.clickItem(item, { toggle: event.ctrlKey || event.metaKey, range: event.shiftKey });
            } else if (!event.target.closest('button, a, input, select, textarea')) {
                // A click on nothing lets go of everything.
                this.select(null);
            }
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
                const item = this.selected;
                if (!item || !this.canManage) return;
                this.edits.set(item.id, { ...(this.edits.get(item.id) ?? {}), [name]: field.value });
            });
            field.addEventListener('change', () => this.saveField(name, field.value));
        }
        this.el('move')?.addEventListener('change', (event) => {
            this.move(event.target.value === '' ? null : Number(event.target.value));
        });
        this.el('remove')?.addEventListener('click', () => this.remove());

        this._bindDrops();
    }

    _onKeydown(event) {
        event.stopPropagation();
        const typing = event.target.closest?.('input, textarea, select');

        if (event.key === 'Escape') {
            event.preventDefault();
            // Like a file browser: the first Escape lets go of the selection.
            if (this.selection.size > 0 && !typing) this.select(null);
            else this.close();

            return;
        }
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'a' && !typing) {
            event.preventDefault();
            this.selectAll();
        }
    }

    /** Desktop files onto the main zone; the picker's own onto a folder. */
    _bindDrops() {
        const main = this.el('main');
        const hasFiles = (event) => [...(event.dataTransfer?.types ?? [])].includes('Files');
        const isOurs = (event) => [...(event.dataTransfer?.types ?? [])].includes(MediaPicker.DRAG_TYPE);
        const highlight = (on) => main.classList.toggle('cb-media__main--drop', on);

        main.addEventListener('dragenter', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            this.dropDepth += 1;
            highlight(true);
        });
        main.addEventListener('dragover', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            event.dataTransfer.dropEffect = 'copy';
        });
        main.addEventListener('dragleave', (event) => {
            if (!hasFiles(event)) return;
            this.dropDepth = Math.max(0, this.dropDepth - 1);
            if (this.dropDepth === 0) highlight(false);
        });
        main.addEventListener('drop', (event) => {
            if (!hasFiles(event)) return;
            event.preventDefault();
            this.dropDepth = 0;
            highlight(false);
            this.uploadMany(event.dataTransfer.files ?? []);
        });

        this.el('grid').addEventListener('dragstart', (event) => {
            const button = event.target.closest?.('[data-id]');
            if (!button || !this.canManage) return;
            const id = Number(button.dataset.id);
            // Dragging a file outside the selection drags that file alone.
            if (!this.selection.has(id)) this.select(this._item(button));
            event.dataTransfer.setData(MediaPicker.DRAG_TYPE, JSON.stringify([...this.selection]));
            event.dataTransfer.effectAllowed = 'move';
            this._startDrag(event);
        });
        this.el('grid').addEventListener('dragend', () => this._endDrag());

        // A folder's whole row is the target, its rename and delete included.
        const target = (event) => {
            if (!isOurs(event)) return null;
            const row = event.target.closest?.('.cb-media__folder-row, button.cb-media__crumb');
            const folder = row?.matches('[data-folder]') ? row : row?.querySelector('[data-folder]');

            return folder ? { row, folder } : null;
        };
        for (const zone of [this.el('folders'), this.el('trail')]) {
            zone.addEventListener('dragover', (event) => {
                const hit = target(event);
                if (!hit) return;
                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';
                this._highlight(hit.row);
            });
            zone.addEventListener('dragleave', (event) => {
                // Crossing into a child of the row is not leaving it.
                if (this.dropTarget && !this.dropTarget.contains(event.relatedTarget)) this._highlight(null);
            });
            zone.addEventListener('drop', (event) => {
                const hit = target(event);
                if (!hit) return;
                event.preventDefault();
                const ids = JSON.parse(event.dataTransfer.getData(MediaPicker.DRAG_TYPE) || '[]');
                this._endDrag();
                this.move(hit.folder.dataset.folder === '' ? null : Number(hit.folder.dataset.folder), ids);
            });
        }
    }

    /** A compact ghost: the tile itself would hide the folders it aims at. */
    _startDrag(event) {
        const ids = [...this.selection];
        this.dialog.classList.add('cb-media--dragging');
        for (const id of ids) {
            this.el('grid').querySelector(`[data-id="${id}"]`)?.classList.add('cb-media__item--dragged');
        }

        const first = this.items.find((item) => item.id === ids[0]);
        const ghost = this._node('div', 'cb-media__drag-ghost');
        if (first && first.kind !== 'video') {
            const img = this._visual(first, true);
            img.loading = 'eager';
            ghost.append(img);
        }
        ghost.append(this._node('span', '', ids.length > 1
            ? this._t('selected_many').replace('%count%', String(ids.length))
            : (first?.title ?? '')));
        // Inside the dialog: the top layer is where a modal's content paints.
        this.dialog.append(ghost);
        event.dataTransfer.setDragImage?.(ghost, 14, 14);
        setTimeout(() => ghost.remove(), 0);
    }

    _endDrag() {
        this._highlight(null);
        this.dialog.classList.remove('cb-media--dragging');
        for (const tile of this.el('grid').querySelectorAll('.cb-media__item--dragged')) {
            tile.classList.remove('cb-media__item--dragged');
        }
    }

    _highlight(row) {
        if (this.dropTarget === row) return;
        this.dropTarget?.classList.remove('cb-media__drop-target');
        this.dropTarget = row;
        row?.classList.add('cb-media__drop-target');
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
        this.el('grid').replaceChildren(...this.items.map((item) => this._tile(item)));
    }

    _tile(item) {
        const li = this._node('li');
        const button = this._node('button', 'cb-media__item');
        button.type = 'button';
        button.dataset.id = String(item.id);
        button.setAttribute('aria-pressed', String(this.selection.has(item.id)));
        button.title = item.title;
        button.draggable = this.canManage;

        const frame = this._node('span', 'cb-media__thumb');
        frame.append(this._visual(item, true));
        button.append(frame, this._node('span', 'cb-media__name', item.title));
        li.append(button);

        return li;
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
        img.draggable = false;
        if (thumbnail) img.loading = 'lazy';

        return img;
    }

    _paintSelection() {
        const item = this.selected;
        this.el('insert').disabled = item === null;
        if (this.selection.size > 1) {
            this.el('selection').textContent = this._t('selected_many').replace('%count%', String(this.selection.size));
            return;
        }
        if (item === null) {
            this.el('selection').textContent = '';
            return;
        }
        const size = item.width && item.height ? ` — ${item.width} × ${item.height}` : '';
        this.el('selection').textContent = `${item.title}${size}`;
    }

    async _paintDetail() {
        const count = this.selection.size;
        const item = this.selected;
        const detail = this.el('detail');
        detail.hidden = count === 0;
        detail.classList.toggle('cb-media__detail--many', count > 1);
        this.dialog.classList.toggle('cb-media--detail', count > 0);
        if (count === 0) return;

        if (count > 1) {
            this.el('preview').replaceChildren();
            this.el('facts').textContent = this._t('selected_many').replace('%count%', String(count));
            this.el('usage').replaceChildren();
        } else if (item) {
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
        }

        const select = this.el('move');
        if (!select) return;
        const folders = await this._folderTree();
        if (this.selection.size !== count) return;
        // Several files may sit in several folders: none is preselected then.
        const current = count === 1 ? (item?.folder ?? null) : undefined;
        const options = [{ id: null, name: this._t('root'), depth: 0 }, ...folders.map((f) => ({ ...f, depth: f.depth + 1 }))];
        const nodes = options.map((folder) => {
            const option = this._node('option', '', `${'  '.repeat(folder.depth)}${folder.name}`);
            option.value = folder.id === null ? '' : String(folder.id);
            option.selected = current === folder.id;

            return option;
        });
        if (count > 1) {
            const placeholder = this._node('option', '', this._t('move_to'));
            placeholder.value = '__';
            placeholder.disabled = true;
            placeholder.selected = true;
            nodes.unshift(placeholder);
        }
        select.replaceChildren(...nodes);
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

    /** What the grid holds: loading, empty, no match. It scrolls with it. */
    _status(message) {
        this.el('status').textContent = message;
    }

    /** What an action did: always in view, above the grid, until closed. */
    _notice(message, error = false) {
        const notice = this.el('notice');
        if (!notice) return;
        this.el('notice-text').textContent = message;
        notice.classList.toggle('cb-media__notice--error', error);
        notice.hidden = false;
    }

    _clearNotice() {
        const notice = this.el('notice');
        if (notice) notice.hidden = true;
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
