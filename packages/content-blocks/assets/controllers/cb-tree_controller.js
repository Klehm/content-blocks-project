import { Controller } from '@hotwired/stimulus';
import Sortable from 'sortablejs';

/**
 * The area as an outline: a floating panel over the preview, with drag,
 * duplicate and delete on sections and blocks.
 *
 * @see docs/internals/frontend.md#the-tree-is-a-second-view-not-a-second-state
 */
export default class extends Controller {
    static targets = ['list', 'status', 'handle'];

    static values = {
        areaId: Number,
    };

    /** Persisted: the outline is a workspace, not a modal. */
    static OPEN_KEY = 'cb-builder.treeOpen';
    /** Where the editor last parked the panel, in shell coordinates. */
    static POSITION_KEY = 'cb-builder.treePosition';
    /** Autosave fires many saved-events per second; one repaint per pause. */
    static REFRESH_DEBOUNCE_MS = 500;
    /** SortableJS group name shared by every column list, so blocks cross. */
    static BLOCK_GROUP = 'cb-tree-blocks';
    /** Kept on screen: a panel dragged fully out has no way back. */
    static EDGE_MARGIN = 8;
    /**
     * For a block type that ships none. The overlay carries its own copy: it
     * is a separate document and shares no module graph with this one.
     */
    static FALLBACK_ICON =
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" '
        + 'stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">'
        + '<rect x="3" y="3" width="18" height="18" rx="2"/></svg>';

    connect() {
        this._onInvalidate = this._onInvalidate.bind(this);
        this._onSelection = this._onSelection.bind(this);
        this._onClose = this._onClose.bind(this);
        this._onToggle = this._onToggle.bind(this);
        this._onDragMove = this._onDragMove.bind(this);
        this._onDragEnd = this._onDragEnd.bind(this);
        this._onWindowResize = this._onWindowResize.bind(this);
        // The other half of the conversation lives on the shell root, so
        // these ride the bubble up to document rather than a hard reference.
        document.addEventListener('cb:tree:invalidate', this._onInvalidate);
        document.addEventListener('cb:tree:selection', this._onSelection);
        document.addEventListener('cb:tree:close', this._onClose);
        document.addEventListener('cb:tree:toggle', this._onToggle);
        window.addEventListener('resize', this._onWindowResize);

        this._sortables = [];
        this._collapsed = new Set();
        this._selection = { blockId: null, sectionId: null };
        this._tree = null;
        this._position = this._readPosition();

        if (this._readOpenPreference()) this.open();
    }

    disconnect() {
        document.removeEventListener('cb:tree:invalidate', this._onInvalidate);
        document.removeEventListener('cb:tree:selection', this._onSelection);
        document.removeEventListener('cb:tree:close', this._onClose);
        document.removeEventListener('cb:tree:toggle', this._onToggle);
        window.removeEventListener('resize', this._onWindowResize);
        this._endDrag();
        clearTimeout(this._refreshTimer);
        this._destroySortables();
    }

    // ---------- Open / close ----------

    /** Relayed from the topbar button, which is outside this scope. */
    _onToggle(event) {
        if (!this._isForThisArea(event)) return;
        if (this._isOpen()) this.close(); else this.open();
    }

    open() {
        this.element.hidden = false;
        // Only now is the panel measurable, so a stored position that no
        // longer fits (narrower window, collapsed sidebar) is clamped here.
        this._applyPosition();
        this._emit('cb:tree:state', { open: true });
        this._writeOpenPreference(true);
        // A tree loaded while the panel was shut may be stale by now, and
        // nothing repainted it — reload rather than show an old outline.
        if (this._tree === null || this._dirty) this.reload();
    }

    /** Action: the panel's × button. */
    close(event) {
        if (event) event.preventDefault();
        this.element.hidden = true;
        this._emit('cb:tree:state', { open: false });
        this._writeOpenPreference(false);
    }

    _isOpen() {
        return !this.element.hidden;
    }

    // ---------- Moving the panel ----------

    /**
     * Action: pointerdown on the header. Pointer events, so mouse, pen and
     * touch share one path.
     */
    startDrag(event) {
        // The × lives in the header too, and a drag must not swallow it.
        if (event.target.closest('button')) return;
        const shell = this._shellRect();
        if (!shell) return;
        event.preventDefault();

        const rect = this.element.getBoundingClientRect();
        this._drag = {
            pointerId: event.pointerId,
            offsetX: event.clientX - rect.left,
            offsetY: event.clientY - rect.top,
        };
        this.element.classList.add('cb-tree--dragging');
        try {
            this.handleTarget.setPointerCapture(event.pointerId);
        } catch (_) {
            // No capture (synthetic event, detached node) — the document
            // listeners below still carry the drag.
        }
        document.addEventListener('pointermove', this._onDragMove);
        document.addEventListener('pointerup', this._onDragEnd);
        document.addEventListener('pointercancel', this._onDragEnd);
    }

    _onDragMove(event) {
        if (!this._drag) return;
        const shell = this._shellRect();
        if (!shell) return;
        this._position = this._clamp(
            event.clientX - shell.left - this._drag.offsetX,
            event.clientY - shell.top - this._drag.offsetY,
        );
        this._writeInlinePosition();
    }

    _onDragEnd() {
        this._endDrag();
        this._writePosition(this._position);
    }

    _endDrag() {
        if (!this._drag) return;
        try {
            this.handleTarget.releasePointerCapture(this._drag.pointerId);
        } catch (_) {
            // Never captured, or already released.
        }
        this._drag = null;
        this.element.classList.remove('cb-tree--dragging');
        document.removeEventListener('pointermove', this._onDragMove);
        document.removeEventListener('pointerup', this._onDragEnd);
        document.removeEventListener('pointercancel', this._onDragEnd);
    }

    /**
     * A resize can leave a parked panel off-screen; re-clamping is cheap and
     * the alternative is a panel the editor cannot reach.
     */
    _onWindowResize() {
        if (!this._isOpen() || !this._position) return;
        this._position = this._clamp(this._position.left, this._position.top);
        this._writeInlinePosition();
    }

    /** Never fully off: a sliver of the header always stays grabbable. */
    _clamp(left, top) {
        const shell = this._shellRect();
        const margin = this.constructor.EDGE_MARGIN;
        if (!shell) return { left, top };
        const maxLeft = Math.max(margin, shell.width - this.element.offsetWidth - margin);
        const maxTop = Math.max(margin, shell.height - this.element.offsetHeight - margin);

        return {
            left: Math.min(Math.max(left, margin), maxLeft),
            top: Math.min(Math.max(top, margin), maxTop),
        };
    }

    /**
     * No stored position means the CSS default — which follows the sidebar's
     * width — so nothing is written until the editor moves the panel.
     */
    _applyPosition() {
        if (!this._position) return;
        this._position = this._clamp(this._position.left, this._position.top);
        this._writeInlinePosition();
    }

    _writeInlinePosition() {
        if (!this._position) return;
        this.element.style.left = `${this._position.left}px`;
        this.element.style.top = `${this._position.top}px`;
    }

    _shellRect() {
        return this.element.offsetParent?.getBoundingClientRect() ?? null;
    }

    // ---------- Loading ----------

    /**
     * Marks the outline stale. Repaints only while the panel is open — a
     * closed panel re-fetches on its next open instead.
     */
    _onInvalidate(event) {
        if (!this._isForThisArea(event)) return;
        this._dirty = true;
        if (!this._isOpen()) return;
        clearTimeout(this._refreshTimer);
        this._refreshTimer = setTimeout(
            () => this.reload(),
            this.constructor.REFRESH_DEBOUNCE_MS,
        );
    }

    async reload() {
        if (!this.hasListTarget) return;
        clearTimeout(this._refreshTimer);
        if (this._tree === null) this._setStatus(this._t('cb.builder.tree.loading', 'Loading…'));

        let payload;
        try {
            const response = await fetch(`/_content-blocks/area/${this.areaIdValue}/tree`, {
                credentials: 'same-origin',
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) throw new Error(`status ${response.status}`);
            payload = await response.json();
        } catch (e) {
            console.error('[cb-tree] outline failed', e);
            this._setStatus(this._t('cb.builder.tree.error', 'Failed to load the outline.'));
            return;
        }

        this._dirty = false;
        this._tree = Array.isArray(payload?.sections) ? payload.sections : [];
        this._paint();
    }

    // ---------- Painting ----------

    _paint() {
        if (!this.hasListTarget) return;
        this._destroySortables();
        const list = this.listTarget;
        list.innerHTML = '';

        const sections = this._tree ?? [];
        if (sections.length === 0) {
            this._setStatus(this._t('cb.builder.tree.empty', 'Nothing here yet — add a section to start.'));
            return;
        }
        this._setStatus('');

        for (const section of sections) {
            list.appendChild(this._buildSection(section));
        }

        this._bindSortables();
        this._applySelection();
    }

    _buildSection(section) {
        const el = document.createElement('li');
        el.className = 'cb-tree__section';
        el.dataset.cbTreeSectionId = String(section.id ?? '');

        const collapsed = this._collapsed.has(section.id);
        el.appendChild(this._buildRow({
            kind: 'section',
            id: section.id,
            label: section.label ?? `#${section.id}`,
            twisty: { collapsed, onToggle: () => this._toggleCollapse(section.id) },
        }));

        const children = document.createElement('div');
        children.className = 'cb-tree__children';
        children.hidden = collapsed;
        for (const column of (Array.isArray(section.columns) ? section.columns : [])) {
            children.appendChild(this._buildColumn(column));
        }
        el.appendChild(children);

        return el;
    }

    /**
     * Columns are read-only nodes: they have no CRUD of their own, being
     * derived from the section's layout.
     */
    _buildColumn(column) {
        const el = document.createElement('div');
        el.className = 'cb-tree__column';

        const row = document.createElement('div');
        row.className = 'cb-tree__row cb-tree__row--column';
        const label = document.createElement('span');
        label.className = 'cb-tree__name';
        label.textContent = column.label ?? '';
        row.appendChild(label);
        if (column.preset) {
            const chip = document.createElement('span');
            chip.className = 'cb-tree__chip';
            chip.textContent = column.preset;
            row.appendChild(chip);
        }
        el.appendChild(row);

        const blocks = document.createElement('ul');
        blocks.className = 'cb-tree__blocks';
        blocks.dataset.cbTreeColumnId = String(column.id ?? '');
        for (const block of (Array.isArray(column.blocks) ? column.blocks : [])) {
            blocks.appendChild(this._buildBlock(block));
        }
        el.appendChild(blocks);

        return el;
    }

    _buildBlock(block) {
        const el = document.createElement('li');
        el.className = 'cb-tree__block';
        el.dataset.cbTreeBlockId = String(block.id ?? '');
        el.appendChild(this._buildRow({
            kind: 'block',
            id: block.id,
            label: block.label ?? block.typeLabel ?? '',
            // The type as a glyph, not a second word: a block with nothing to
            // summarise already reads its type as its label.
            icon: { svg: block.icon, title: block.typeLabel ?? block.type ?? '' },
            hintKind: block.kind,
            missing: block.missing === true,
        }));

        return el;
    }

    /**
     * One row shape for sections and blocks: handle, optional twisty, label
     * button, then the two actions.
     */
    _buildRow({ kind, id, label, twisty = null, icon = null, hintKind = null, missing = false }) {
        const row = document.createElement('div');
        row.className = `cb-tree__row cb-tree__row--${kind}`;
        row.dataset.cbTreeKind = kind;
        row.dataset.cbTreeId = String(id ?? '');

        const handle = document.createElement('span');
        handle.className = 'cb-tree__drag';
        handle.title = this._t('cb.builder.tree.drag', 'Drag to move');
        handle.setAttribute('aria-hidden', 'true');
        handle.textContent = '⠿';
        row.appendChild(handle);

        if (twisty) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cb-tree__twisty';
            btn.setAttribute('aria-expanded', twisty.collapsed ? 'false' : 'true');
            btn.title = twisty.collapsed
                ? this._t('cb.builder.tree.expand', 'Expand')
                : this._t('cb.builder.tree.collapse', 'Collapse');
            btn.setAttribute('aria-label', btn.title);
            btn.textContent = twisty.collapsed ? '▸' : '▾';
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                twisty.onToggle();
            });
            row.appendChild(btn);
        }

        if (icon) row.appendChild(this._buildIcon(icon, missing));

        const name = document.createElement('button');
        name.type = 'button';
        name.className = 'cb-tree__name cb-tree__name--action';
        if (hintKind) name.dataset.cbTreeHint = hintKind;
        name.textContent = label;
        name.addEventListener('click', () => this._select(kind, id));
        row.appendChild(name);

        const actions = document.createElement('span');
        actions.className = 'cb-tree__actions';
        actions.appendChild(this._buildAction(
            '⧉',
            this._t('cb.builder.tree.duplicate', 'Duplicate'),
            () => this._emit('cb:tree:duplicate', { kind, id }),
        ));
        actions.appendChild(this._buildAction(
            '🗑',
            this._t('cb.builder.tree.delete', 'Delete'),
            () => this._emit('cb:tree:delete', { kind, id }),
        ));
        row.appendChild(actions);

        return row;
    }

    /**
     * Decorative: the row's label is its accessible name, and the tooltip
     * carries the type for whoever needs it spelled out.
     */
    _buildIcon({ svg, title }, missing) {
        const el = document.createElement('span');
        el.className = 'cb-tree__icon';
        if (missing) el.classList.add('cb-tree__icon--missing');
        el.setAttribute('aria-hidden', 'true');
        if (title) el.title = title;
        // Trusted markup: block-author SVG, never user input.
        el.innerHTML = typeof svg === 'string' && svg !== '' ? svg : this.constructor.FALLBACK_ICON;

        return el;
    }

    _buildAction(glyph, title, onClick) {
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'cb-tree__act';
        btn.title = title;
        btn.setAttribute('aria-label', title);
        btn.textContent = glyph;
        btn.addEventListener('click', (e) => {
            e.stopPropagation();
            onClick();
        });

        return btn;
    }

    _toggleCollapse(sectionId) {
        if (this._collapsed.has(sectionId)) {
            this._collapsed.delete(sectionId);
        } else {
            this._collapsed.add(sectionId);
        }
        this._paint();
    }

    // ---------- Drag & drop ----------

    _bindSortables() {
        this._sortables.push(Sortable.create(this.listTarget, {
            draggable: '.cb-tree__section',
            handle: '.cb-tree__row--section > .cb-tree__drag',
            animation: 120,
            onEnd: (event) => {
                const sectionId = this._idOf(event.item, 'cbTreeSectionId');
                if (sectionId === null || event.newIndex === event.oldIndex) return;
                this._emit('cb:tree:section:move', { sectionId, position: event.newIndex });
            },
        }));

        for (const list of this.listTarget.querySelectorAll('.cb-tree__blocks')) {
            this._sortables.push(Sortable.create(list, {
                group: this.constructor.BLOCK_GROUP,
                draggable: '.cb-tree__block',
                handle: '.cb-tree__drag',
                animation: 120,
                onEnd: (event) => {
                    const blockId = this._idOf(event.item, 'cbTreeBlockId');
                    const toColumnId = this._idOf(event.to, 'cbTreeColumnId');
                    if (blockId === null || toColumnId === null) return;
                    if (event.to === event.from && event.newIndex === event.oldIndex) return;
                    this._emit('cb:tree:block:move', { blockId, toColumnId, position: event.newIndex });
                },
            }));
        }
    }

    _destroySortables() {
        for (const sortable of this._sortables ?? []) sortable.destroy();
        this._sortables = [];
    }

    _idOf(el, key) {
        const value = parseInt(el?.dataset?.[key] ?? '', 10);

        return Number.isFinite(value) ? value : null;
    }

    // ---------- Selection ----------

    _select(kind, id) {
        this._emit('cb:tree:select', { kind, id });
    }

    /**
     * The sidebar is the selection, so the highlight follows it — whether the
     * click landed here or in the preview.
     */
    _onSelection(event) {
        if (!this._isForThisArea(event)) return;
        const detail = event.detail ?? {};
        this._selection = {
            blockId: detail.blockId ?? null,
            sectionId: detail.sectionId ?? null,
        };
        this._applySelection();
    }

    _applySelection() {
        if (!this.hasListTarget) return;
        for (const row of this.listTarget.querySelectorAll('.cb-tree__row')) {
            row.classList.remove('cb-tree__row--selected');
        }
        const { blockId, sectionId } = this._selection ?? {};
        const kind = blockId ? 'block' : (sectionId ? 'section' : null);
        if (!kind) return;
        const row = this.listTarget.querySelector(
            `.cb-tree__row[data-cb-tree-kind="${kind}"][data-cb-tree-id="${blockId ?? sectionId}"]`,
        );
        if (!row) return;
        row.classList.add('cb-tree__row--selected');
        row.scrollIntoView({ block: 'nearest' });
    }

    _onClose(event) {
        if (!this._isForThisArea(event)) return;
        this.close();
    }

    // ---------- Plumbing ----------

    /**
     * The panel signals; `cb-builder` acts. Every mutation the tree offers is
     * an endpoint the builder already owns, and its queue serializes them.
     */
    _emit(type, detail) {
        this.element.dispatchEvent(new CustomEvent(type, {
            bubbles: true,
            detail: { ...detail, areaId: this.areaIdValue },
        }));
    }

    /** A stray broadcast from another builder on the page is not ours. */
    _isForThisArea(event) {
        const areaId = event?.detail?.areaId;

        return areaId === undefined || areaId === null || areaId === this.areaIdValue;
    }

    _setStatus(text) {
        if (!this.hasStatusTarget) return;
        this.statusTarget.textContent = text;
    }

    _readOpenPreference() {
        try {
            return window.localStorage.getItem(this.constructor.OPEN_KEY) === '1';
        } catch (_) {
            return false;
        }
    }

    _writeOpenPreference(open) {
        try {
            window.localStorage.setItem(this.constructor.OPEN_KEY, open ? '1' : '0');
        } catch (_) {
            // ignore — non-blocking persistence
        }
    }

    /** Null for "never moved", which leaves the CSS default in charge. */
    _readPosition() {
        let raw = null;
        try {
            raw = window.localStorage.getItem(this.constructor.POSITION_KEY);
        } catch (_) {
            return null;
        }
        if (!raw) return null;
        try {
            const value = JSON.parse(raw);
            if (!Number.isFinite(value?.left) || !Number.isFinite(value?.top)) return null;

            return { left: value.left, top: value.top };
        } catch {
            return null;
        }
    }

    _writePosition(position) {
        if (!position) return;
        try {
            window.localStorage.setItem(this.constructor.POSITION_KEY, JSON.stringify(position));
        } catch (_) {
            // ignore — non-blocking persistence
        }
    }

    /**
     * Reads precomputed strings off `data-i18n-*`, falling back to English —
     * same convention as `cb-builder`.
     */
    _t(key, fallback) {
        const value = this.element.getAttribute('data-i18n-' + key.replace(/[._]/g, '-'));

        return value && value.length > 0 ? value : fallback;
    }
}
