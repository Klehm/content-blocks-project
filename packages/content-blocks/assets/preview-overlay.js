/**
 * Runs INSIDE the preview iframe. Plain JS, no AJAX: it signals intents and
 * the parent's cb-builder controller acts on them.
 *
 * @see docs/internals/frontend.md#two-documents-one-editor
 */
(function () {
    'use strict';

    if (window === window.parent) {
        // Not embedded; nothing to talk to.
        return;
    }

    const PARENT_ORIGIN = location.origin;

    function postToParent(type, payload) {
        try {
            window.parent.postMessage({ type, ...(payload || {}) }, PARENT_ORIGIN);
        } catch (_) {
            // Parent unreachable (cross-origin or detached); silently ignore.
        }
    }

    // Styling lives in builder.css, <link>-ed by the render template.

    // ---------- Viewport ----------

    // What the CSS applies, not the topbar button: a narrow builder window
    // renders tablet order even with "desktop" pressed. Mirrors layout.css.
    const MOBILE_QUERY = window.matchMedia('(max-width: 540px)');
    const TABLET_QUERY = window.matchMedia('(max-width: 768px)');

    function currentViewport() {
        if (MOBILE_QUERY.matches) return 'mobile';
        return TABLET_QUERY.matches ? 'tablet' : 'desktop';
    }

    function announceViewport() {
        postToParent('cb:viewport:changed', { viewport: currentViewport() });
    }
    [MOBILE_QUERY, TABLET_QUERY].forEach((query) => {
        query.addEventListener?.('change', announceViewport);
    });

    // ---------- Toolbar (single reusable element) ----------

    const toolbar = document.createElement('div');
    toolbar.className = 'cb-overlay-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    document.body.appendChild(toolbar);

    // hoveredEl follows the cursor; focusedEl is pinned by a click.
    // See docs/internals/frontend.md#focus-and-the-sidebar
    let hoveredEl = null;
    let hoveredKind = null;
    let focusedEl = null;
    let focusedKind = null;
    let hideTimer = null;

    /**
     * Server data from a `<script type="application/json">` block, or the
     * `window` global an older copied template still sets.
     */
    function serverData(id, legacyGlobal) {
        const el = document.getElementById(id);
        if (el) {
            try {
                return JSON.parse(el.textContent);
            } catch {
                return null;
            }
        }
        return window[legacyGlobal] ?? null;
    }

    // Injected server-side. The English fallbacks are a safety net, not the
    // source: a string that only exists here can never be translated.
    const labelData = serverData('cb-overlay-labels', '__cbOverlayLabels');
    const LABELS = (labelData && typeof labelData === 'object') ? labelData : {};

    function t(key, fallback) {
        const value = LABELS[key];
        return typeof value === 'string' && value !== '' ? value : fallback;
    }

    function makeBtn(label, title, action, onclick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cb-overlay-toolbar__btn';
        b.textContent = label;
        b.title = title;
        b.setAttribute('aria-label', title);
        // Stable attribute for tests / external selectors so translation
        // changes never break automated lookups.
        b.dataset.cbAction = action;
        b.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            onclick(e);
        });
        return b;
    }

    // ---------- Block-type popover ----------

    const popover = document.createElement('div');
    popover.className = 'cb-overlay-popover';
    popover.hidden = true;
    document.body.appendChild(popover);

    // Generic fallback glyph for block types that don't ship an icon.
    const FALLBACK_ICON =
        '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" ' +
        'stroke="currentColor" stroke-width="1.6" stroke-linecap="round" ' +
        'stroke-linejoin="round" aria-hidden="true">' +
        '<rect x="3" y="3" width="18" height="18" rx="2"/>' +
        '<path d="M12 8v8M8 12h8"/></svg>';

    function openBlockTypePopover(triggerBtn, columnId) {
        const typeData = serverData('cb-block-types', '__cbBlockTypes');
        const types = Array.isArray(typeData) ? typeData : [];
        if (types.length === 0) return;

        popover.innerHTML = '';

        const header = document.createElement('div');
        header.className = 'cb-overlay-popover__header';
        header.textContent = t('add_block', 'Add a block');
        popover.appendChild(header);

        const grid = document.createElement('div');
        grid.className = 'cb-overlay-popover__grid';
        for (const item of types) {
            const tile = document.createElement('button');
            tile.type = 'button';
            tile.className = 'cb-overlay-popover__tile';
            tile.title = item.label;

            const icon = document.createElement('span');
            icon.className = 'cb-overlay-popover__icon';
            // Trusted markup: block-author SVG, never user input.
            icon.innerHTML = item.icon || FALLBACK_ICON;

            const label = document.createElement('span');
            label.className = 'cb-overlay-popover__label';
            label.textContent = item.label;

            tile.append(icon, label);
            tile.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                hidePopover();
                postToParent('cb:block:add-requested', { columnId, blockType: item.type });
            });
            grid.appendChild(tile);
        }
        popover.appendChild(grid);

        const rect = triggerBtn.getBoundingClientRect();
        popover.style.top = (rect.bottom + window.scrollY + 4) + 'px';
        popover.style.left = (rect.left + window.scrollX) + 'px';
        popover.hidden = false;
    }

    function hidePopover() {
        popover.hidden = true;
        popover.innerHTML = '';
    }

    document.addEventListener('click', (e) => {
        if (popover.hidden) return;
        if (popover.contains(e.target)) return;
        // Ignore clicks on the toolbar trigger that opened it.
        if (e.target.closest?.('.cb-overlay-toolbar')) return;
        hidePopover();
    });

    function buildToolbarFor(el, kind) {
        toolbar.innerHTML = '';

        // No Edit button: clicking the element itself opens the sidebar, so
        // the toolbar carries only structural actions.
        if (kind === 'block') {
            const blockId = parseInt(el.dataset.cbBlockId, 10);
            toolbar.appendChild(makeDragHandle('block', blockId, el));
            toolbar.appendChild(makeBtn('⎘', t('block_duplicate', 'Duplicate'), 'duplicate', () =>
                postToParent('cb:block:duplicate-requested', { blockId })));
            toolbar.appendChild(makeBtn('×', t('block_delete', 'Remove'), 'delete', () =>
                postToParent('cb:block:delete-requested', { blockId })));
        } else if (kind === 'section') {
            const sectionId = parseInt(el.dataset.cbSectionId, 10);
            toolbar.appendChild(makeDragHandle('section', sectionId, el));
            toolbar.appendChild(makeBtn('▲', t('section_move_up', 'Move up'), 'move-up', () =>
                moveSectionRequested(el, sectionId, 'up')));
            toolbar.appendChild(makeBtn('▼', t('section_move_down', 'Move down'), 'move-down', () =>
                moveSectionRequested(el, sectionId, 'down')));
            toolbar.appendChild(makeBtn('⎘', t('section_duplicate', 'Duplicate'), 'duplicate', () =>
                postToParent('cb:section:duplicate-requested', { sectionId })));
            toolbar.appendChild(makeBtn('☆', t('section_save_template', 'Save as template'), 'save-template', () =>
                postToParent('cb:section:save-template-requested', { sectionId })));
            toolbar.appendChild(makeBtn('×', t('section_delete', 'Remove'), 'delete', () =>
                postToParent('cb:section:delete-requested', { sectionId })));
        }
    }

    function makeDragHandle(kind, id, el) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cb-overlay-toolbar__btn cb-overlay-toolbar__btn--drag';
        b.textContent = '⋮⋮';
        const dragLabel = t(kind === 'block' ? 'block_drag' : 'section_drag', 'Drag to move');
        b.title = dragLabel;
        b.setAttribute('aria-label', dragLabel);
        b.dataset.cbAction = 'drag';
        // `pointerdown`, not click, so touch/pen/mouse share one path.
        // See docs/internals/frontend.md#smaller-decisions-worth-keeping
        b.style.touchAction = 'none';
        b.addEventListener('pointerdown', (event) => {
            // Only react to the primary pointer (left mouse / first touch);
            // ignore right-clicks and secondary contacts.
            if (event.button !== undefined && event.button !== 0) return;
            event.preventDefault();
            event.stopPropagation();
            startDrag(event, kind, id, el);
        });
        b.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
        });
        return b;
    }

    function positionToolbarFor(el, _kind) {
        // A header chip overlapping the top border by half its height,
        // clamped so an element flush with the top stays on screen.
        toolbar.classList.add('is-visible');
        const rect = el.getBoundingClientRect();
        const overlap = rect.top + window.scrollY - toolbar.offsetHeight / 2;
        const top = Math.max(window.scrollY + 2, overlap);
        const left = rect.left + window.scrollX + (rect.width - toolbar.offsetWidth) / 2;
        toolbar.style.top = top + 'px';
        toolbar.style.left = Math.max(0, left) + 'px';
    }

    function showHoverToolbar(el, kind) {
        // Suppressed while focused, and during a drag so the toolbar does
        // not pop up over everything on the way to a drop target.
        if (focusedEl || dragState) return;
        if (hoveredEl === el) {
            clearTimeout(hideTimer);
            return;
        }
        clearTimeout(hideTimer);
        if (hoveredEl) hoveredEl.classList.remove('cb-overlay-outline');
        hoveredEl = el;
        hoveredKind = kind;
        el.classList.add('cb-overlay-outline');
        buildToolbarFor(el, kind);
        positionToolbarFor(el, kind);
    }

    // ---------- Tabs and accordion displays ----------

    // A block in a closed tab or panel is in the DOM but invisible.
    // See docs/internals/rendering.md#columns-and-tabs
    const PANEL_STORAGE_PREFIX = 'cb-builder.tab:';
    const PANEL_INPUTS = ':scope > .cb-tabs__radio, :scope > .cb-row > .cb-accordion__toggle:not(.cb-accordion__none)';

    function panelColumns(section) {
        return Array.from(section.querySelectorAll(':scope > .cb-row > [data-cb-column-id]'));
    }

    /** One input per column, in column order: radios or checkboxes. */
    function panelInputs(section) {
        return Array.from(section.querySelectorAll(PANEL_INPUTS));
    }

    function openColumnIds(section) {
        const columns = panelColumns(section);
        return panelInputs(section)
            .map((input, i) => (input.checked ? columns[i]?.getAttribute('data-cb-column-id') : null))
            .filter((id) => id);
    }

    /** Opens the tab or panel holding `el`, or scrolls its slide in. */
    function revealTab(el) {
        const column = el.closest('[data-cb-column-id]');
        const section = column?.parentElement?.parentElement;
        if (!section) return;
        const row = column.parentElement;
        if (getComputedStyle(row).scrollSnapType.indexOf('x') !== -1) {
            const first = Array.from(row.querySelectorAll(':scope > [data-cb-column-id]'))
                .find((c) => c.getClientRects().length > 0);
            row.scrollTo({ left: column.offsetLeft - (first ? first.offsetLeft : 0) });
        }
        const input = panelInputs(section)[panelColumns(section).indexOf(column)];
        if (input && !input.checked) {
            input.checked = true;
            rememberPanels(section);
        }
    }

    function rememberPanels(section) {
        try {
            sessionStorage.setItem(
                PANEL_STORAGE_PREFIX + section.getAttribute('data-cb-section-id'),
                openColumnIds(section).join(','),
            );
        } catch (_) {
            // Storage blocked: a reload opens the first panel, as in public.
        }
    }

    /**
     * Checks the inputs of the listed columns. A tab bar with none of them
     * left keeps the one the server opened; a one-at-a-time accordion closes.
     */
    function applyOpenColumns(section, ids) {
        const columns = panelColumns(section);
        const live = (i) => columns[i] && columns[i].getAttribute('data-cb-deleted') !== '1'
            && ids.includes(columns[i].getAttribute('data-cb-column-id'));
        let matched = false;
        panelInputs(section).forEach((input, i) => {
            if (input.type === 'checkbox') {
                input.checked = live(i);
            } else if (live(i)) {
                input.checked = true;
                matched = true;
            }
        });
        const none = section.querySelector(':scope > .cb-row > .cb-accordion__none');
        if (none && !matched) none.checked = true;
    }

    /** A reload would reopen the first panel under the editor's feet. */
    function restoreTabs(root) {
        root.querySelectorAll('.cb-section[data-cb-section-id]').forEach((section) => {
            if (panelInputs(section).length === 0) return;
            let stored = null;
            try {
                stored = sessionStorage.getItem(PANEL_STORAGE_PREFIX + section.getAttribute('data-cb-section-id'));
            } catch (_) {
                return;
            }
            if (stored === null) return;
            applyOpenColumns(section, stored === '' ? [] : stored.split(','));
        });
    }

    document.addEventListener('change', (event) => {
        const input = event.target;
        if (!(input instanceof HTMLInputElement)) return;
        if (!input.classList.contains('cb-tabs__radio') && !input.classList.contains('cb-accordion__toggle')) return;
        const section = input.closest('[data-cb-section-id]');
        if (section) rememberPanels(section);
    });

    function focusElement(el, kind) {
        revealTab(el);
        // Drop any prior hover/focus highlight before moving on.
        if (hoveredEl && hoveredEl !== el) hoveredEl.classList.remove('cb-overlay-outline');
        if (focusedEl && focusedEl !== el) focusedEl.classList.remove('cb-overlay-outline');
        clearTimeout(hideTimer);
        focusedEl = el;
        focusedKind = kind;
        hoveredEl = null;
        hoveredKind = null;
        el.classList.add('cb-overlay-outline');
        buildToolbarFor(el, kind);
        positionToolbarFor(el, kind);
    }

    function clearFocus() {
        if (!focusedEl) return;
        focusedEl.classList.remove('cb-overlay-outline');
        focusedEl = null;
        focusedKind = null;
        toolbar.classList.remove('is-visible');
    }

    // ---------- Keyboard shortcuts (focused element) ----------
    // They post the same intents the toolbar does. See frontend.md

    /** True for fields where a keystroke means "type", not "act on element". */
    function isTypingTarget(t) {
        if (!t || t.nodeType !== 1) return false;
        if (t.isContentEditable) return true;
        const tag = t.tagName;
        return tag === 'INPUT' || tag === 'TEXTAREA' || tag === 'SELECT';
    }

    document.addEventListener('keydown', (event) => {
        // Only when an element is pinned, never mid-drag, and never while the
        // block-type popover owns the keyboard.
        if (!focusedEl || dragState || !popover.hidden) return;
        // Don't hijack keystrokes typed into a preview form field.
        if (isTypingTarget(event.target)) return;
        // Let modifier combos (browser/OS shortcuts) through untouched.
        if (event.ctrlKey || event.metaKey || event.altKey) return;

        const blockId = () => parseInt(focusedEl.dataset.cbBlockId, 10);
        const sectionId = () => parseInt(focusedEl.dataset.cbSectionId, 10);

        switch (event.key) {
            // Delete the focused element (soft-delete in draft, revertible via
            // Discard) — mirrors the toolbar × button.
            case 'Delete':
            case 'Backspace':
                event.preventDefault();
                if (focusedKind === 'block' && Number.isFinite(blockId())) {
                    postToParent('cb:block:delete-requested', { blockId: blockId() });
                } else if (focusedKind === 'section' && Number.isFinite(sectionId())) {
                    postToParent('cb:section:delete-requested', { sectionId: sectionId() });
                }
                break;
            // Deselect: drop the pin and let the parent close the sidebar.
            case 'Escape':
                event.preventDefault();
                clearFocus();
                postToParent('cb:preview:outside-click');
                break;
        }
    });

    /**
     * Relayed, not handled: no clipboard state lives in the preview, and a
     * field keystroke or a real text selection must never be stolen.
     *
     * @see docs/internals/frontend.md#keyboard-and-clipboard
     */
    /** Ctrl/Cmd chord to the intent the parent answers with. */
    const CHORDS = {
        c: 'cb:clipboard:copy-requested',
        v: 'cb:clipboard:paste-requested',
        z: 'cb:history:undo-requested',
        'shift+z': 'cb:history:redo-requested',
        y: 'cb:history:redo-requested',
    };

    /** Input types that take no typing, so Ctrl-Z steps on nothing there. */
    const UNDOLESS_INPUT_TYPES = [
        'color', 'range', 'checkbox', 'radio', 'file',
        'button', 'submit', 'reset', 'image', 'hidden',
    ];

    /** Mirrors the shell's `_hasNativeUndo`; the two rules must not drift. */
    function hasNativeUndo(t) {
        if (!t || t.nodeType !== 1) return false;
        if (t.isContentEditable) return true;
        if (t.tagName === 'TEXTAREA') return true;
        if (t.tagName !== 'INPUT') return false;

        return UNDOLESS_INPUT_TYPES.indexOf((t.type || 'text').toLowerCase()) === -1;
    }

    document.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey) || event.altKey) return;
        const key = event.key && event.key.toLowerCase();
        const intent = CHORDS[(event.shiftKey ? 'shift+' : '') + key];
        if (!intent) return;

        // Undo yields only to a real undo stack; copy/paste to any field and
        // to a live selection, which it would otherwise steal.
        if (intent.indexOf('cb:history:') === 0) {
            if (hasNativeUndo(event.target)) return;
        } else {
            if (isTypingTarget(event.target)) return;
            const selection = window.getSelection && window.getSelection();
            if (selection && !selection.isCollapsed) return;
        }

        event.preventDefault();
        postToParent(intent);
    });

    // ---------- Single-block hot reload ----------

    /**
     * Swaps one block's markup in place, re-pinning hover/focus onto the fresh
     * node and dispatching `cb:block:rendered` on it.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    function replaceBlock(blockId, html) {
        const oldEl = document.querySelector(`[data-cb-block-id="${blockId}"]`);
        if (!oldEl) {
            // The block vanished (e.g. deleted in another path) — let the
            // parent know so it can clear the stale sidebar.
            postToParent('cb:focus:not-found');
            return;
        }

        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl) return;

        const wasFocused = focusedEl === oldEl;
        // Drop references to the node we're about to detach so the overlay
        // never holds a pointer to an orphaned element.
        if (hoveredEl === oldEl) { hoveredEl = null; hoveredKind = null; }
        if (focusedEl === oldEl) { focusedEl = null; focusedKind = null; }

        oldEl.replaceWith(newEl);

        newEl.dispatchEvent(new CustomEvent('cb:block:rendered', {
            bubbles: true,
            detail: { blockId },
        }));

        if (wasFocused) {
            focusElement(newEl, 'block');
        }
    }

    /**
     * Copies the freshly-rendered wrapper attributes onto the existing nodes,
     * never touching the inner blocks — always safe.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     *
     * Overlay-owned classes (the focus/hover outline) are re-applied after the
     * className swap since the server markup doesn't know about them.
     */
    function patchSection(sectionId, html) {
        const oldEl = document.querySelector(`[data-cb-section-id="${sectionId}"]`);
        if (!oldEl) {
            postToParent('cb:focus:not-found');
            return;
        }

        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl) return;

        // Section wrapper: copy class + style, preserving the overlay outline.
        const wasOutlined = oldEl.classList.contains('cb-overlay-outline');
        oldEl.setAttribute('class', newEl.getAttribute('class') || '');
        const newStyle = newEl.getAttribute('style');
        if (newStyle !== null) {
            oldEl.setAttribute('style', newStyle);
        } else {
            oldEl.removeAttribute('style');
        }
        if (wasOutlined) oldEl.classList.add('cb-overlay-outline');
        // slider.js watches the attribute and rebuilds its controls.
        const slider = newEl.getAttribute('data-cb-slider');
        if (slider !== null) {
            oldEl.setAttribute('data-cb-slider', slider);
        } else {
            oldEl.removeAttribute('data-cb-slider');
        }

        // Tab bar and accordion headers follow the display and the column
        // labels, keeping what was open when those columns are still there.
        const hadPanels = panelInputs(oldEl).length > 0;
        const open = openColumnIds(oldEl);
        oldEl.querySelectorAll(':scope > .cb-tabs__radio, :scope > .cb-tabs__nav, '
            + ':scope > .cb-row > .cb-accordion__toggle, :scope > .cb-row > .cb-accordion__header')
            .forEach((n) => n.remove());
        const row = oldEl.querySelector(':scope > .cb-row');
        newEl.querySelectorAll(':scope > .cb-tabs__radio, :scope > .cb-tabs__nav').forEach((n) => {
            oldEl.insertBefore(n, row);
        });
        // Whatever precedes a column in the new row (toggle, headers, the
        // "none" radio) goes before the same column in the old one.
        let pending = [];
        for (const node of Array.from(newEl.querySelector(':scope > .cb-row')?.children ?? [])) {
            const id = node.getAttribute('data-cb-column-id');
            if (!id) {
                if (node.matches('.cb-accordion__toggle, .cb-accordion__header')) pending.push(node);
                continue;
            }
            const oldCol = row?.querySelector(`:scope > [data-cb-column-id="${id}"]`);
            if (oldCol) pending.forEach((n) => row.insertBefore(n, oldCol));
            pending = [];
        }
        if (hadPanels) applyOpenColumns(oldEl, open);

        // Columns: copy class + style by matching data-cb-column-id, so column
        // width changes (cb-col--weighted / --cb-col-grow) land too.
        newEl.querySelectorAll('[data-cb-column-id]').forEach((newCol) => {
            const id = newCol.getAttribute('data-cb-column-id');
            const oldCol = oldEl.querySelector(`[data-cb-column-id="${id}"]`);
            if (!oldCol) return;
            const wasColOutlined = oldCol.classList.contains('cb-overlay-outline');
            oldCol.setAttribute('class', newCol.getAttribute('class') || '');
            for (const name of ['style', 'id', 'role', 'aria-labelledby']) {
                const value = newCol.getAttribute(name);
                if (value !== null) {
                    oldCol.setAttribute(name, value);
                } else {
                    oldCol.removeAttribute(name);
                }
            }
            if (wasColOutlined) oldCol.classList.add('cb-overlay-outline');
        });

        // Its box may have moved or resized — re-place the toolbar.
        if (focusedEl === oldEl) positionToolbarFor(oldEl, focusedKind);
    }

    /**
     * Removes a block in place. Same end state as a reload, where a
     * soft-deleted block renders hidden.
     */
    function removeBlock(blockId) {
        const el = document.querySelector(`[data-cb-block-id="${blockId}"]`);
        if (!el) return;
        // Drop overlay references to the node we're removing and retract the
        // toolbar if it was pinned to this block.
        if (hoveredEl === el) { hoveredEl = null; hoveredKind = null; }
        if (focusedEl === el) {
            focusedEl = null;
            focusedKind = null;
            toolbar.classList.remove('is-visible');
        }
        el.remove();
    }

    /**
     * Inserts a rendered block at the end of its column, ahead of the "+ Block"
     * sentinel, then dispatches cb:block:rendered and focuses it.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    function insertBlock(columnId, html) {
        const column = document.querySelector(`[data-cb-column-id="${columnId}"]`);
        if (!column) {
            // Column not in the DOM — let the parent fall back to a reload.
            postToParent('cb:reorder:desync');
            return;
        }
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl) return;

        // A new block always lands at the end of its column, before the +Block
        // button (its only non-block sibling).
        const addBtn = column.querySelector('.cb-add-block-inline');
        if (addBtn) {
            addBtn.before(newEl);
        } else {
            column.appendChild(newEl);
        }

        newEl.dispatchEvent(new CustomEvent('cb:block:rendered', {
            bubbles: true,
            detail: { blockId: parseInt(newEl.getAttribute('data-cb-block-id'), 10) },
        }));

        focusElement(newEl, 'block');
        if (hasViewportOrder(Array.from(column.querySelectorAll('[data-cb-block-id]')))) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * Drops a duplicate right after its source, the slot the server used.
     * Focus is left alone, so the source stays selected.
     */
    function insertBlockAfter(sourceId, html) {
        const source = document.querySelector(`[data-cb-block-id="${sourceId}"]`);
        if (!source) {
            // The source vanished — the DOM drifted from the server's model.
            postToParent('cb:reorder:desync');
            return;
        }
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl) return;

        source.after(newEl);

        newEl.dispatchEvent(new CustomEvent('cb:block:rendered', {
            bubbles: true,
            detail: { blockId: parseInt(newEl.getAttribute('data-cb-block-id'), 10) },
        }));
        if (hasViewportOrder(Array.from(source.parentElement.querySelectorAll(':scope > [data-cb-block-id]')))) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * Same, one level up. Re-fires cb:block:rendered on every inner block —
     * safe, since the endpoint only ships markup when they all opt in.
     */
    function insertSectionAfter(sourceId, html) {
        const source = document.querySelector(`[data-cb-section-id="${sourceId}"]`);
        if (!source) {
            postToParent('cb:reorder:desync');
            return;
        }
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl) return;

        source.after(newEl);

        newEl.querySelectorAll('[data-cb-block-id]').forEach((block) => {
            block.dispatchEvent(new CustomEvent('cb:block:rendered', {
                bubbles: true,
                detail: { blockId: parseInt(block.getAttribute('data-cb-block-id'), 10) },
            }));
        });
        if (source.parentElement?.classList.contains('cb-content-area--ordered')) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * Appends a new, empty section ahead of the add-section tray. Two trays
     * mean two editable areas, and no way to tell which one grew.
     */
    function insertSection(sectionId, html) {
        const trays = document.querySelectorAll('.cb-add-section-tray');
        const tray = trays.length === 1 ? trays[0] : null;
        const area = tray && tray.closest('.cb-content-area');
        if (!area) {
            postToParent('cb:reorder:desync');
            return;
        }
        const tpl = document.createElement('template');
        tpl.innerHTML = html.trim();
        const newEl = tpl.content.firstElementChild;
        if (!newEl || newEl.getAttribute('data-cb-section-id') !== String(sectionId)) {
            postToParent('cb:reorder:desync');
            return;
        }

        tray.before(newEl);
        syncEmptyState(area);
        focusElement(newEl, 'section');
        newEl.scrollIntoView({ behavior: 'smooth', block: 'center' });
        if (area.classList.contains('cb-content-area--ordered')) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * Flags a section deleted, down to its blocks: the markup a reload
     * renders, which Discard and the undo snackbar both rely on.
     */
    function removeSection(sectionId) {
        const el = document.querySelector(`[data-cb-section-id="${sectionId}"]`);
        if (!el) return;
        if (hoveredEl && el.contains(hoveredEl)) { hoveredEl = null; hoveredKind = null; }
        if (focusedEl && el.contains(focusedEl)) {
            focusedEl.classList.remove('cb-overlay-outline');
            focusedEl = null;
            focusedKind = null;
            toolbar.classList.remove('is-visible');
        }
        el.classList.remove('cb-overlay-outline');

        const marks = [[el, 'cb-section--deleted']];
        el.querySelectorAll('[data-cb-column-id]').forEach((c) => marks.push([c, 'cb-col--deleted']));
        el.querySelectorAll('[data-cb-block-id]').forEach((b) => marks.push([b, 'cb-block--deleted']));
        for (const [node, cls] of marks) {
            node.classList.add(cls);
            node.setAttribute('data-cb-deleted', '1');
        }

        const area = el.closest('.cb-content-area');
        if (area) syncEmptyState(area);
    }

    /** The empty-state class and tray label, as content_area.html.twig. */
    function syncEmptyState(area) {
        const empty = !area.querySelector(
            ':scope > [data-cb-section-id]:not([data-cb-deleted="1"])',
        );
        area.classList.toggle('cb-content-area--empty', empty);

        const tray = area.querySelector(':scope > .cb-add-section-tray');
        const labelEl = tray && tray.querySelector('.cb-add-section-tray__label');
        if (!labelEl) return;
        const label = t(empty ? 'empty_cta' : 'add_section', labelEl.textContent);
        labelEl.textContent = label;
        tray.setAttribute('aria-label', label);
    }

    /**
     * Moves the **live** node, so a rich-text editor mid-edit survives.
     * `position` indexes visible blocks only.
     *
     * @see docs/internals/frontend.md#hot-reload-and-when-it-is-refused
     */
    function moveBlockInPlace(blockId, toColumnId, position) {
        const el = document.querySelector(`[data-cb-block-id="${blockId}"]`);
        const column = document.querySelector(`[data-cb-column-id="${toColumnId}"]`);
        if (!el || !column) {
            // The DOM drifted from the server's model — bail to a full reload.
            postToParent('cb:reorder:desync');
            return;
        }
        const siblings = Array.from(column.querySelectorAll('[data-cb-block-id]'))
            .filter((b) => b !== el && b.dataset.cbDeleted !== '1');
        const ranked = hasViewportOrder([el, ...siblings]);
        placeAmong(el, column, siblings, position);
        if (focusedEl === el) positionToolbarFor(el, focusedKind);
        if (ranked) postToParent('cb:reorder:desync');
    }

    /**
     * Relocates a section node to a new index in place (drag & drop reorder).
     * `position` is the index among visible (non-deleted) sibling sections.
     */
    function moveSectionInPlace(sectionId, position) {
        const el = document.querySelector(`[data-cb-section-id="${sectionId}"]`);
        if (!el || !el.parentElement) {
            postToParent('cb:reorder:desync');
            return;
        }
        const container = el.parentElement;
        const siblings = Array.from(container.querySelectorAll(':scope > [data-cb-section-id]'))
            .filter((s) => s !== el && s.dataset.cbDeleted !== '1');
        placeAmong(el, container, siblings, position);
        if (focusedEl === el) positionToolbarFor(el, focusedKind);
        if (container.classList.contains('cb-content-area--ordered')) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * The toolbar arrows: swaps the node with its previous or next visible
     * sibling, as the server did to the positions.
     */
    function moveSectionByDirection(sectionId, direction) {
        const el = document.querySelector(`[data-cb-section-id="${sectionId}"]`);
        if (!el || !el.parentElement) {
            postToParent('cb:reorder:desync');
            return;
        }
        const visible = Array.from(el.parentElement.querySelectorAll(':scope > [data-cb-section-id]'))
            .filter((s) => s.dataset.cbDeleted !== '1');
        const idx = visible.indexOf(el);
        if (direction === 'up' && idx > 0) {
            visible[idx - 1].before(el);
        } else if (direction === 'down' && idx < visible.length - 1) {
            visible[idx + 1].after(el);
        }
        if (focusedEl === el) positionToolbarFor(el, focusedKind);
        if (el.parentElement.classList.contains('cb-content-area--ordered')) {
            postToParent('cb:reorder:desync');
        }
    }

    /**
     * Anchors after the last sibling rather than appending, so the node lands
     * ahead of any trailing sentinel — the add button or the section tray.
     */
    function placeAmong(el, container, siblings, position) {
        if (position < siblings.length) {
            siblings[position].before(el);
        } else if (siblings.length > 0) {
            siblings[siblings.length - 1].after(el);
        } else {
            container.prepend(el);
        }
    }

    // ---------- Drag & drop ----------

    // One reusable drop indicator, positioned at the insertion point as the
    // user drags. Its CSS lives in builder.css.
    const dropIndicator = document.createElement('div');
    dropIndicator.className = 'cb-drop-indicator';
    dropIndicator.hidden = true;
    document.body.appendChild(dropIndicator);

    let dragState = null;
    // A drop's pointerup still fires a click, on whatever lies under it, in
    // the same task: the flag is gone by the next one.
    let swallowDropClick = false;
    const HANDLE_DRAG_THRESHOLD = 4;

    /**
     * The section's hanging label drags it too. Past a few pixels only, so a
     * click on it still selects the section.
     */
    document.addEventListener('pointerdown', (event) => {
        const handle = event.target.closest?.('.cb-section-handle');
        if (!handle || dragState) return;
        if (event.button !== undefined && event.button !== 0) return;
        const section = handle.closest('[data-cb-section-id]');
        const sectionId = section ? parseInt(section.dataset.cbSectionId, 10) : NaN;
        if (!Number.isFinite(sectionId)) return;

        // No preventDefault: it would keep focus out of the preview, and the
        // keyboard shortcuts with it. builder.css stops the text selection.
        const origin = { x: event.clientX, y: event.clientY, id: event.pointerId };
        const same = (e) => origin.id === undefined || e.pointerId === origin.id;
        const cleanup = () => {
            document.removeEventListener('pointermove', onMove);
            document.removeEventListener('pointerup', onUp);
            document.removeEventListener('pointercancel', onUp);
        };
        const onMove = (e) => {
            if (!same(e)) return;
            const distance = Math.hypot(e.clientX - origin.x, e.clientY - origin.y);
            if (distance < HANDLE_DRAG_THRESHOLD) return;
            cleanup();
            window.getSelection()?.removeAllRanges();
            startDrag(e, 'section', sectionId, section);
        };
        const onUp = (e) => { if (same(e)) cleanup(); };
        document.addEventListener('pointermove', onMove);
        document.addEventListener('pointerup', onUp);
        document.addEventListener('pointercancel', onUp);
    });

    function startDrag(event, kind, id, sourceEl) {
        // Cancel any popover/toolbar UI; the drag takes over the screen.
        toolbar.classList.remove('is-visible');
        hidePopover();
        clearFocus();

        const pointerId = event.pointerId ?? null;
        const handlers = {
            move: (e) => {
                // Only the pointer that started the drag: a second finger
                // would otherwise derail the indicator math.
                if (pointerId !== null && e.pointerId !== pointerId) return;
                onDragMove(e);
            },
            up: (e) => {
                if (pointerId !== null && e.pointerId !== pointerId) return;
                endDrag(true);
            },
            cancelPointer: (e) => {
                if (pointerId !== null && e.pointerId !== pointerId) return;
                endDrag(false);
            },
            cancelKey: (e) => { if (e.key === 'Escape') endDrag(false); },
        };
        dragState = { kind, id, sourceEl, target: null, handlers, pointerId };

        sourceEl.classList.add('cb-drag-source');
        document.body.classList.add('cb-dragging');
        // Lets builder.css mute the guides that are not valid targets for
        // this kind of drag.
        document.body.classList.add('cb-dragging--' + kind);

        document.addEventListener('pointermove', handlers.move);
        document.addEventListener('pointerup', handlers.up);
        document.addEventListener('pointercancel', handlers.cancelPointer);
        document.addEventListener('keydown', handlers.cancelKey);

        // Compute the initial drop target from the press point so the
        // indicator appears immediately, not on first move.
        onDragMove(event);
    }

    function onDragMove(event) {
        if (!dragState) return;
        const target = computeDropTarget(event.clientX, event.clientY);
        dragState.target = target;
        renderDropIndicator(target);
    }

    function endDrag(commit) {
        if (!dragState) return;
        const { handlers, sourceEl, kind, id, target } = dragState;
        document.removeEventListener('pointermove', handlers.move);
        document.removeEventListener('pointerup', handlers.up);
        document.removeEventListener('pointercancel', handlers.cancelPointer);
        document.removeEventListener('keydown', handlers.cancelKey);
        sourceEl.classList.remove('cb-drag-source');
        document.body.classList.remove('cb-dragging', 'cb-dragging--section', 'cb-dragging--block');
        dropIndicator.hidden = true;
        dragState = null;
        swallowDropClick = true;
        setTimeout(() => { swallowDropClick = false; }, 0);

        if (!commit || !target) return;
        const viewport = currentViewport();
        if (viewport !== 'desktop') {
            requestViewportOrder(viewport, kind, sourceEl, target);
            return;
        }
        if (kind === 'section') {
            postToParent('cb:section:reorder', {
                sectionId: id,
                position: target.position,
            });
        } else if (kind === 'block' && Number.isFinite(target.columnId)) {
            postToParent('cb:block:reorder', {
                blockId: id,
                toColumnId: target.columnId,
                position: target.position,
            });
        }
    }

    /** Siblings as the eye reads them, which `order` can make differ. */
    function visualOrder(nodes) {
        return nodes
            .map((node) => ({ node, top: node.getBoundingClientRect().top }))
            .sort((a, b) => a.top - b.top)
            .map((entry) => entry.node);
    }

    /**
     * On tablet or mobile nothing moves in the DOM: the new visual sequence is
     * posted, and the answer sets --cb-order-*. A block stays in its column.
     *
     * @see docs/internals/rendering.md#order-per-viewport
     */
    function requestViewportOrder(viewport, kind, sourceEl, target) {
        if (kind === 'block') {
            const column = sourceEl.closest('[data-cb-column-id]');
            const columnId = column ? parseInt(column.dataset.cbColumnId, 10) : NaN;
            if (columnId !== target.columnId) {
                postToParent('cb:viewport-order:refused', { viewport, reason: 'column' });
                return;
            }
            const siblings = visualOrder(Array.from(column.querySelectorAll('[data-cb-block-id]'))
                .filter((b) => b.dataset.cbDeleted !== '1'));
            postViewportOrder(viewport, 'block', siblings, sourceEl, target.position, { columnId });
            return;
        }
        const siblings = visualOrder(Array.from(document.querySelectorAll('[data-cb-section-id]'))
            .filter((s) => s.dataset.cbDeleted !== '1'));
        postViewportOrder(viewport, 'section', siblings, sourceEl, target.position, {});
    }

    /** `position` indexes the siblings minus the moved one, as a drop does. */
    function postViewportOrder(viewport, scope, siblings, moved, position, extra) {
        const idOf = (node) => parseInt(scope === 'block' ? node.dataset.cbBlockId : node.dataset.cbSectionId, 10);
        const before = siblings.map(idOf);
        const rest = siblings.filter((node) => node !== moved);
        rest.splice(Math.max(0, Math.min(position, rest.length)), 0, moved);
        const ids = rest.map(idOf);
        if (ids.every((id, i) => id === before[i])) return;
        postToParent('cb:viewport-order:requested', { viewport, scope, ids, ...extra });
    }

    /** The toolbar arrows, which follow the viewport like a drag does. */
    function moveSectionRequested(el, sectionId, direction) {
        const viewport = currentViewport();
        if (viewport === 'desktop') {
            postToParent('cb:section:move-requested', { sectionId, direction });
            return;
        }
        const siblings = visualOrder(Array.from(document.querySelectorAll('[data-cb-section-id]'))
            .filter((s) => s.dataset.cbDeleted !== '1'));
        const index = siblings.indexOf(el);
        const position = direction === 'up' ? index - 1 : index + 1;
        if (index === -1 || position < 0 || position >= siblings.length) return;
        postViewportOrder(viewport, 'section', siblings, el, position, {});
    }

    /** Sets what the server computed; a sibling it left bare loses its vars. */
    function applyViewportOrder(scope, orders) {
        const attr = scope === 'block' ? 'data-cb-block-id' : 'data-cb-section-id';
        let ordered = false;
        Object.keys(orders || {}).forEach((id) => {
            const node = document.querySelector(`[${attr}="${id}"]`);
            if (!node) return;
            node.style.removeProperty('--cb-order-t');
            node.style.removeProperty('--cb-order-m');
            const vars = orders[id] || {};
            Object.keys(vars).forEach((name) => {
                node.style.setProperty(name, vars[name]);
                ordered = true;
            });
            if (node.getAttribute('style') === '') node.removeAttribute('style');
        });
        if (scope === 'section') {
            document.querySelectorAll('.cb-content-area').forEach((area) => {
                area.classList.toggle('cb-content-area--ordered', ordered);
            });
        }
        if (focusedEl) positionToolbarFor(focusedEl, focusedKind);
    }

    /**
     * A hot insert or move among ranked siblings changes their sequence, which
     * only the server computes: reload instead.
     */
    function hasViewportOrder(nodes) {
        return nodes.some((node) => node && /--cb-order-/.test(node.getAttribute('style') || ''));
    }

    function computeDropTarget(x, y) {
        return dragState.kind === 'section'
            ? computeSectionDrop(x, y)
            : computeBlockDrop(x, y);
    }

    function computeSectionDrop(x, y) {
        const sections = visualOrder(Array.from(document.querySelectorAll('[data-cb-section-id]'))
            .filter((s) => s !== dragState.sourceEl && s.dataset.cbDeleted !== '1'));

        // Empty area (no siblings) — drop at index 0; no indicator needed
        // because there's nothing visible to anchor it to.
        if (sections.length === 0) {
            return { position: 0, indicator: null };
        }

        for (let i = 0; i < sections.length; i++) {
            const rect = sections[i].getBoundingClientRect();
            const mid = rect.top + rect.height / 2;
            if (y < mid) {
                return {
                    position: i,
                    indicator: { y: rect.top, x: rect.left, width: rect.width },
                };
            }
        }
        const last = sections[sections.length - 1];
        const lastRect = last.getBoundingClientRect();
        return {
            position: sections.length,
            indicator: { y: lastRect.bottom, x: lastRect.left, width: lastRect.width },
        };
    }

    function computeBlockDrop(x, y) {
        // `cb-drag-source` carries pointer-events: none, so this sees
        // through the dragged node to the column below it.
        const under = document.elementFromPoint(x, y);
        if (!under) return null;
        const column = under.closest?.('[data-cb-column-id]');
        if (!column) return null;

        const columnId = parseInt(column.dataset.cbColumnId, 10);
        if (!Number.isFinite(columnId)) return null;

        const blocks = visualOrder(Array.from(column.querySelectorAll('[data-cb-block-id]'))
            .filter((b) => b !== dragState.sourceEl && b.dataset.cbDeleted !== '1'));

        if (blocks.length === 0) {
            const colRect = column.getBoundingClientRect();
            return {
                columnId,
                position: 0,
                indicator: { y: colRect.top + 4, x: colRect.left + 4, width: colRect.width - 8 },
            };
        }
        for (let i = 0; i < blocks.length; i++) {
            const rect = blocks[i].getBoundingClientRect();
            const mid = rect.top + rect.height / 2;
            if (y < mid) {
                return {
                    columnId,
                    position: i,
                    indicator: { y: rect.top - 1, x: rect.left, width: rect.width },
                };
            }
        }
        const last = blocks[blocks.length - 1];
        const lastRect = last.getBoundingClientRect();
        return {
            columnId,
            position: blocks.length,
            indicator: { y: lastRect.bottom - 1, x: lastRect.left, width: lastRect.width },
        };
    }

    function renderDropIndicator(target) {
        if (!target?.indicator) {
            dropIndicator.hidden = true;
            return;
        }
        dropIndicator.hidden = false;
        dropIndicator.style.top = (target.indicator.y + window.scrollY) + 'px';
        dropIndicator.style.left = (target.indicator.x + window.scrollX) + 'px';
        dropIndicator.style.width = target.indicator.width + 'px';
    }

    function scheduleHide() {
        if (focusedEl) return;
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            toolbar.classList.remove('is-visible');
            if (hoveredEl) {
                hoveredEl.classList.remove('cb-overlay-outline');
                hoveredEl = null;
                hoveredKind = null;
            }
        }, 120);
    }

    // On layout shifts, or the chip floats where the element used to be.
    window.addEventListener('resize', () => {
        if (focusedEl) positionToolbarFor(focusedEl, focusedKind);
        else if (hoveredEl) positionToolbarFor(hoveredEl, hoveredKind);
    });

    // Block wins over section, so the most granular action is offered.
    // Columns expose `+ Block` permanently instead of on hover.
    document.addEventListener('mouseover', (event) => {
        const block = event.target.closest?.('[data-cb-block-id]');
        if (block) {
            showHoverToolbar(block, 'block');
            return;
        }
        const section = event.target.closest?.('[data-cb-section-id]');
        if (section) {
            showHoverToolbar(section, 'section');
            return;
        }
    });

    document.addEventListener('mouseout', (event) => {
        if (!hoveredEl || focusedEl) return;
        const related = event.relatedTarget;
        // If we're leaving for a child of the hovered element, keep it.
        if (related && hoveredEl.contains(related)) return;
        // If the relatedTarget is the toolbar, keep it.
        if (related === toolbar || toolbar.contains(related)) return;
        scheduleHide();
    });

    toolbar.addEventListener('mouseenter', () => clearTimeout(hideTimer));
    toolbar.addEventListener('mouseleave', scheduleHide);

    // ---------- Block intra-iframe navigation ----------

    // Capture phase, so a real link or form never navigates the iframe away.
    // Also drives click-to-focus, inline adds and outside-click forwarding.
    document.addEventListener(
        'click',
        (event) => {
            const target = event.target;

            if (swallowDropClick) {
                swallowDropClick = false;
                event.preventDefault();
                event.stopImmediatePropagation();
                return;
            }

            // 1. Permanent in-iframe affordances: handle their intent and
            //    bail out before any outside-click / link suppression runs.
            const addBlockBtn = target.closest?.('.cb-add-block-inline');
            if (addBlockBtn) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const columnId = parseInt(addBlockBtn.dataset.cbAddBlockColumnId, 10);
                if (!Number.isNaN(columnId)) {
                    openBlockTypePopover(addBlockBtn, columnId);
                }
                return;
            }
            const addSectionBtn = target.closest?.('.cb-add-section-tray__btn');
            if (addSectionBtn) {
                event.preventDefault();
                event.stopImmediatePropagation();
                const layout = addSectionBtn.dataset.cbAddSection;
                if (layout) {
                    postToParent('cb:section:add-requested', { layout });
                }
                return;
            }
            const insertTemplateBtn = target.closest?.('[data-cb-insert-template]');
            if (insertTemplateBtn) {
                event.preventDefault();
                event.stopImmediatePropagation();
                postToParent('cb:template:insert-requested', {});
                return;
            }

            // 2. Overlay UI handles its own intent in makeBtn(); skipping
            //    outside-click keeps it from closing the sidebar it drives.
            const onOverlay = target.closest?.('.cb-overlay-toolbar, .cb-overlay-popover');
            if (onOverlay) return;

            // 3. Pin focus and open the sidebar. Block first, so a click
            //    on a nested block does not escalate to its section.
            const block = target.closest?.('[data-cb-block-id]');
            const section = target.closest?.('[data-cb-section-id]');
            if (block) {
                const blockId = parseInt(block.dataset.cbBlockId, 10);
                focusElement(block, 'block');
                if (Number.isFinite(blockId)) {
                    postToParent('cb:block:edit', { blockId });
                }
            } else if (section) {
                const sectionId = parseInt(section.dataset.cbSectionId, 10);
                focusElement(section, 'section');
                if (Number.isFinite(sectionId)) {
                    postToParent('cb:section:settings', { sectionId });
                }
            } else {
                clearFocus();
                postToParent('cb:preview:outside-click');
            }

            // 4. Block intra-iframe navigation: prevent anchor follows /
            //    form submits that would replace the previewed page.
            const link = target.closest?.('a[href]');
            if (!link) return;
            // Allow explicit new-tab links (and modifier-key clicks).
            if (link.target === '_blank' || event.ctrlKey || event.metaKey || event.shiftKey) {
                return;
            }
            event.preventDefault();
            event.stopImmediatePropagation();
        },
        true,
    );

    document.addEventListener(
        'submit',
        (event) => {
            event.preventDefault();
            event.stopImmediatePropagation();
        },
        true,
    );

    // Suppressed: a few pixels of travel on a draggable link starts a native
    // drag and the browser fires NO click, making such a block unselectable.
    document.addEventListener(
        'dragstart',
        (event) => {
            event.preventDefault();
        },
        true,
    );

    // ---------- Inbound focus messages ----------
    // Posted after a reload so the edited element keeps its toolbar.
    window.addEventListener('message', (event) => {
        if (event.origin !== PARENT_ORIGIN) return;
        const data = event.data;
        if (!data || typeof data.type !== 'string') return;

        // Hot reload: swap a single block's markup in place.
        if (data.type === 'cb:block:replace'
            && Number.isFinite(data.blockId)
            && typeof data.html === 'string') {
            replaceBlock(data.blockId, data.html);
            return;
        }

        // Hot delete: drop a single block from the preview in place.
        if (data.type === 'cb:block:remove' && Number.isFinite(data.blockId)) {
            removeBlock(data.blockId);
            return;
        }

        // Hot insert: drop a freshly-added block into its column in place.
        if (data.type === 'cb:block:insert'
            && Number.isFinite(data.columnId)
            && typeof data.html === 'string') {
            insertBlock(data.columnId, data.html);
            return;
        }

        // Hot duplicate: drop a block copy right after its source.
        if (data.type === 'cb:block:duplicate:apply'
            && Number.isFinite(data.sourceId)
            && typeof data.html === 'string') {
            insertBlockAfter(data.sourceId, data.html);
            return;
        }

        // Hot add: a new, empty section lands at the end of the area.
        if (data.type === 'cb:section:insert'
            && Number.isFinite(data.sectionId)
            && typeof data.html === 'string') {
            insertSection(data.sectionId, data.html);
            return;
        }

        // Hot delete: flag a section deleted in place.
        if (data.type === 'cb:section:remove' && Number.isFinite(data.sectionId)) {
            removeSection(data.sectionId);
            return;
        }

        // Hot duplicate: drop a section copy right after its source.
        if (data.type === 'cb:section:duplicate:apply'
            && Number.isFinite(data.sourceId)
            && typeof data.html === 'string') {
            insertSectionAfter(data.sourceId, data.html);
            return;
        }

        // Hot reload: patch one section's wrapper and columns in place.
        if (data.type === 'cb:section:patch'
            && Number.isFinite(data.sectionId)
            && typeof data.html === 'string') {
            patchSection(data.sectionId, data.html);
            return;
        }

        // Reorder: relocate a block node in place after a confirmed move.
        if (data.type === 'cb:block:reorder:apply'
            && Number.isFinite(data.blockId)
            && Number.isFinite(data.toColumnId)
            && Number.isFinite(data.position)) {
            moveBlockInPlace(data.blockId, data.toColumnId, data.position);
            return;
        }

        // Reorder: relocate a section node in place (drag & drop).
        if (data.type === 'cb:section:reorder:apply'
            && Number.isFinite(data.sectionId)
            && Number.isFinite(data.position)) {
            moveSectionInPlace(data.sectionId, data.position);
            return;
        }

        // Reorder: nudge a section up/down in place (toolbar arrows).
        if (data.type === 'cb:section:move:apply'
            && Number.isFinite(data.sectionId)
            && (data.direction === 'up' || data.direction === 'down')) {
            moveSectionByDirection(data.sectionId, data.direction);
            return;
        }

        // A viewport reorder the server accepted.
        if (data.type === 'cb:viewport-order:apply'
            && (data.scope === 'section' || data.scope === 'block')) {
            applyViewportOrder(data.scope, data.orders);
            return;
        }

        // Sent instead of the usual scroll restore, so the editor sees
        // what they just added.
        if (data.type === 'cb:section:scroll-into-view' && Number.isFinite(data.sectionId)) {
            const el = document.querySelector(`[data-cb-section-id="${data.sectionId}"]`);
            if (el) {
                // `center`: a short section pinned to the top reads as
                // clipped, and its neighbour is the context.
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        // The tree's counterpart: a selected row can be a page away from
        // whatever the preview is currently showing.
        if (data.type === 'cb:block:scroll-into-view' && Number.isFinite(data.blockId)) {
            const el = document.querySelector(`[data-cb-block-id="${data.blockId}"]`);
            if (el) {
                revealTab(el);
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return;
        }

        if (!data.type.startsWith('cb:focus:')) return;

        // A flagged node is hidden, so it counts as gone: that is how a block
        // open inside a just-deleted section gets its sidebar cleared.
        if (data.type === 'cb:focus:block' && Number.isFinite(data.blockId)) {
            const el = document.querySelector(`[data-cb-block-id="${data.blockId}"]:not([data-cb-deleted="1"])`);
            if (el) {
                focusElement(el, 'block');
            } else {
                postToParent('cb:focus:not-found');
            }
        } else if (data.type === 'cb:focus:section' && Number.isFinite(data.sectionId)) {
            const el = document.querySelector(`[data-cb-section-id="${data.sectionId}"]:not([data-cb-deleted="1"])`);
            if (el) {
                focusElement(el, 'section');
            } else {
                postToParent('cb:focus:not-found');
            }
        }
    });

    // ---------- Ready signal ----------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            restoreTabs(document);
            postToParent('cb:ready');
            announceViewport();
        });
    } else {
        restoreTabs(document);
        postToParent('cb:ready');
        announceViewport();
    }
})();
