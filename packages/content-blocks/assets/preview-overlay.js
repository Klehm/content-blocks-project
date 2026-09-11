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

    // Injected server-side. The English fallbacks are a safety net, not the
    // source: a string that only exists here can never be translated.
    const LABELS = (window.__cbOverlayLabels && typeof window.__cbOverlayLabels === 'object')
        ? window.__cbOverlayLabels
        : {};

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
        const types = Array.isArray(window.__cbBlockTypes) ? window.__cbBlockTypes : [];
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
                postToParent('cb:section:move-requested', { sectionId, direction: 'up' })));
            toolbar.appendChild(makeBtn('▼', t('section_move_down', 'Move down'), 'move-down', () =>
                postToParent('cb:section:move-requested', { sectionId, direction: 'down' })));
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

    function focusElement(el, kind) {
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

        // Columns: copy class + style by matching data-cb-column-id, so column
        // width changes (cb-col--weighted / --cb-col-grow) land too.
        newEl.querySelectorAll('[data-cb-column-id]').forEach((newCol) => {
            const id = newCol.getAttribute('data-cb-column-id');
            const oldCol = oldEl.querySelector(`[data-cb-column-id="${id}"]`);
            if (!oldCol) return;
            const wasColOutlined = oldCol.classList.contains('cb-overlay-outline');
            oldCol.setAttribute('class', newCol.getAttribute('class') || '');
            const colStyle = newCol.getAttribute('style');
            if (colStyle !== null) {
                oldCol.setAttribute('style', colStyle);
            } else {
                oldCol.removeAttribute('style');
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
        placeAmong(el, column, siblings, position);
        if (focusedEl === el) positionToolbarFor(el, focusedKind);
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

        if (!commit || !target) return;
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

    function computeDropTarget(x, y) {
        return dragState.kind === 'section'
            ? computeSectionDrop(x, y)
            : computeBlockDrop(x, y);
    }

    function computeSectionDrop(x, y) {
        const sections = Array.from(document.querySelectorAll('[data-cb-section-id]'))
            .filter((s) => s !== dragState.sourceEl && s.dataset.cbDeleted !== '1');

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

        const blocks = Array.from(column.querySelectorAll('[data-cb-block-id]'))
            .filter((b) => b !== dragState.sourceEl && b.dataset.cbDeleted !== '1');

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
            if (el) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            return;
        }

        if (!data.type.startsWith('cb:focus:')) return;

        if (data.type === 'cb:focus:block' && Number.isFinite(data.blockId)) {
            const el = document.querySelector(`[data-cb-block-id="${data.blockId}"]`);
            if (el) {
                focusElement(el, 'block');
            } else {
                postToParent('cb:focus:not-found');
            }
        } else if (data.type === 'cb:focus:section' && Number.isFinite(data.sectionId)) {
            const el = document.querySelector(`[data-cb-section-id="${data.sectionId}"]`);
            if (el) {
                focusElement(el, 'section');
            } else {
                postToParent('cb:focus:not-found');
            }
        }
    });

    // ---------- Ready signal ----------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => postToParent('cb:ready'));
    } else {
        postToParent('cb:ready');
    }
})();
