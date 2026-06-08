/**
 * preview-overlay.js — runs INSIDE the iframe rendered by BlockRenderer in
 * PREVIEW mode.
 *
 * Plain JS (no Stimulus) so the host app's front theme doesn't have to
 * carry our Stimulus loader. The script is auto-injected by BlockRenderer
 * via the @ContentBlocks/render/content_area.html.twig template; the
 * matching builder.css stylesheet is loaded via <link>.
 *
 * Responsibilities (logic only — all styling lives in builder.css):
 *  - Signal cb:ready to the parent admin window once the DOM is up.
 *  - Show a floating action toolbar when hovering an entity carrying a
 *    data-cb-block-id, data-cb-column-id or data-cb-section-id marker.
 *  - Forward toolbar clicks to the parent as typed postMessage events.
 *  - Block intra-iframe navigation (link clicks + form submits) so the
 *    user can't accidentally leave the page being edited.
 *
 * No AJAX here — this script only dispatches intents. The parent's
 * cb-builder Stimulus controller handles them.
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

    // Style sheet: see assets/styles/builder.css, served at
    // /_content-blocks/builder.css and <link>-ed by the render template
    // when in PREVIEW mode.

    // ---------- Toolbar (single reusable element) ----------

    const toolbar = document.createElement('div');
    toolbar.className = 'cb-overlay-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    document.body.appendChild(toolbar);

    // hoveredEl: element currently under the mouse (transient, follows cursor).
    // focusedEl: element pinned by an explicit click — its toolbar stays
    // visible and hover events stop moving the toolbar elsewhere. Cleared
    // when the user clicks empty space inside the iframe.
    let hoveredEl = null;
    let hoveredKind = null;
    let focusedEl = null;
    let focusedKind = null;
    let hideTimer = null;

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

    function openBlockTypePopover(triggerBtn, columnId) {
        const types = Array.isArray(window.__cbBlockTypes) ? window.__cbBlockTypes : [];
        if (types.length === 0) return;

        popover.innerHTML = '';
        for (const item of types) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'cb-overlay-popover__btn';
            btn.textContent = item.label;
            btn.addEventListener('click', (e) => {
                e.preventDefault();
                e.stopPropagation();
                hidePopover();
                postToParent('cb:block:add-requested', { columnId, blockType: item.type });
            });
            popover.appendChild(btn);
        }

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

        // Edit / Settings buttons are intentionally absent — clicking the
        // section or block itself opens the sidebar editor (see the
        // document click handler below). The toolbar only carries the
        // structural actions (drag, move, duplicate, delete).
        if (kind === 'block') {
            const blockId = parseInt(el.dataset.cbBlockId, 10);
            toolbar.appendChild(makeDragHandle('block', blockId, el));
            toolbar.appendChild(makeBtn('⎘', 'Duplicate', 'duplicate', () =>
                postToParent('cb:block:duplicate-requested', { blockId })));
            toolbar.appendChild(makeBtn('×', 'Delete', 'delete', () =>
                postToParent('cb:block:delete-requested', { blockId })));
        } else if (kind === 'section') {
            const sectionId = parseInt(el.dataset.cbSectionId, 10);
            toolbar.appendChild(makeDragHandle('section', sectionId, el));
            toolbar.appendChild(makeBtn('▲', 'Move up', 'move-up', () =>
                postToParent('cb:section:move-requested', { sectionId, direction: 'up' })));
            toolbar.appendChild(makeBtn('▼', 'Move down', 'move-down', () =>
                postToParent('cb:section:move-requested', { sectionId, direction: 'down' })));
            toolbar.appendChild(makeBtn('⎘', 'Duplicate', 'duplicate', () =>
                postToParent('cb:section:duplicate-requested', { sectionId })));
            toolbar.appendChild(makeBtn('×', 'Delete', 'delete', () =>
                postToParent('cb:section:delete-requested', { sectionId })));
        }
    }

    function makeDragHandle(kind, id, el) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cb-overlay-toolbar__btn cb-overlay-toolbar__btn--drag';
        b.textContent = '⋮⋮';
        b.title = 'Drag to move';
        b.setAttribute('aria-label', 'Drag to move');
        b.dataset.cbAction = 'drag';
        // Not a click action — `pointerdown` enters drag mode. We use
        // pointer events instead of mouse-only so touch + pen + mouse all
        // share the same code path, with `touch-action: none` on the handle
        // so a touch-drag doesn't get hijacked by the page's scroll
        // gesture. We swallow click so the global click handler doesn't
        // interpret a drag-start as an outside-click that closes the
        // sidebar.
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
        // Toolbar reads as a "header chip" pinned to the element by overlapping
        // its top border by half the toolbar height. Clamp against the
        // viewport so an element flush with the top of the iframe doesn't
        // push the chip off-screen.
        toolbar.classList.add('is-visible');
        const rect = el.getBoundingClientRect();
        const overlap = rect.top + window.scrollY - toolbar.offsetHeight / 2;
        const top = Math.max(window.scrollY + 2, overlap);
        const left = rect.left + window.scrollX + (rect.width - toolbar.offsetWidth) / 2;
        toolbar.style.top = top + 'px';
        toolbar.style.left = Math.max(0, left) + 'px';
    }

    function showHoverToolbar(el, kind) {
        // Hover is suppressed while an element is focused — the focused
        // toolbar stays in place even as the cursor wanders elsewhere.
        // It's also suppressed during a drag so the toolbar doesn't pop
        // up over sections/blocks the user is just passing across on the
        // way to a drop target.
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

    // ---------- Single-block hot reload ----------

    /**
     * Replaces one block's markup in place with server-rendered HTML, instead
     * of reloading the whole iframe. Used by the parent after an inline edit
     * of a block whose type opts into hot reload (supportsPreviewHotReload).
     *
     * Preserves the editing experience across the swap: any hover/focus
     * pinned on the old node is dropped (it's about to be detached) and
     * re-pinned on the fresh node so the blue outline + toolbar survive.
     *
     * A `cb:block:rendered` event is dispatched on the new element so a
     * JS-enhanced view can (re)initialise — this is the only place page
     * scripts don't re-run on their own, since we inject HTML rather than
     * reload the document.
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
     * Removes one block from the preview in place after a delete, instead of
     * reloading the whole iframe. The block is soft-deleted on the server
     * (Discard can still bring it back via a later full reload); visually it
     * just disappears — same end state as a reload, where deleted blocks
     * render hidden (`[data-cb-deleted="1"] { display: none }`).
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

    // ---------- Drag & drop ----------

    // Single reusable drop indicator (a thin blue bar). We position it at the
    // insertion point as the user drags so they can see exactly where the
    // entity will land. Its CSS lives in builder.css.
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
                // Multi-pointer guard: only act on the pointer that started
                // the drag, otherwise a second touch finger would derail
                // the indicator math.
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
        // Kind-specific class lets builder.css mute the dashed guides that
        // aren't valid drop targets — section drags only land between
        // sections, so column outlines are noise; block drags only land in
        // columns, so section outlines are noise.
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
        // Pick whichever column is under the cursor. `cb-drag-source` carries
        // pointer-events: none, so elementFromPoint sees through the dragged
        // source to whatever column is below it.
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

    // Reposition the focused/hovered toolbar on layout shifts (window resize,
    // section reflow). Without this, a structural change leaves the chip
    // floating where the element used to be.
    window.addEventListener('resize', () => {
        if (focusedEl) positionToolbarFor(focusedEl, focusedKind);
        else if (hoveredEl) positionToolbarFor(hoveredEl, hoveredKind);
    });

    // Hover routing — block wins over section so the most granular action
    // available is the one offered. Columns no longer surface a toolbar:
    // their `+ Block` action is exposed permanently at the bottom of each
    // column instead (.cb-add-block-inline).
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

    // The preview is meant for read-only inspection — clicking a real link or
    // submitting a real form would navigate the iframe away from the page
    // we're editing, which is jarring (the parent admin loses context). We
    // intercept those interactions in the capture phase so they never reach
    // the front-app handlers.
    //
    // The same listener also drives:
    //  - Click-to-focus (pin the toolbar on the clicked block/section).
    //  - Permanent inline add affordances rendered in the iframe content
    //    (`.cb-add-block-inline`, `.cb-add-section-tray__btn`).
    //  - Outside-click forwarding so the parent admin closes its sidebar
    //    when the user clicks empty preview space.
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

            // 2. Overlay UI (toolbar buttons / popover items): they already
            //    handle their own intent in makeBtn() with stopPropagation.
            //    Skip outside-click so we don't also tell the parent to
            //    close the sidebar that the click is interacting with.
            const onOverlay = target.closest?.('.cb-overlay-toolbar, .cb-overlay-popover');
            if (onOverlay) return;

            // 3. Click on a block/section: pin focus AND ask the parent
            //    to open the matching editor in the sidebar. The Edit /
            //    Settings toolbar buttons were removed — clicking the
            //    element itself is now the only way to enter the editor.
            //    Block matching is checked first so a click on a nested
            //    block doesn't escalate to its surrounding section.
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

    // ---------- Inbound focus messages ----------
    //
    // The parent posts `cb:focus:block` / `cb:focus:section` after an
    // iframe reload so the currently-edited element keeps its blue
    // outline + pinned toolbar instead of being lost in the rebuilt
    // DOM. The element is identified by the same data-cb-* marker used
    // throughout the overlay.
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
