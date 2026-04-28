/**
 * preview-overlay.js — runs INSIDE the iframe rendered by BlockRenderer in
 * PREVIEW mode.
 *
 * Plain JS (no Stimulus) so the host app's front theme doesn't have to
 * carry our Stimulus loader. The script is auto-injected by BlockRenderer
 * via the @ContentBlocks/render/content_area.html.twig template.
 *
 * Responsibilities:
 *  - Signal cb:ready to the parent admin window once the DOM is up.
 *  - Show a floating action toolbar when hovering an entity carrying a
 *    data-cb-block-id or data-cb-section-id marker.
 *  - Forward toolbar clicks to the parent as typed postMessage events.
 *  - Soft-deleted elements (data-cb-deleted="1") are dimmed visually so the
 *    user sees what will go away on the next publish.
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

    // ---------- Stylesheet (injected once) ----------

    const style = document.createElement('style');
    style.textContent = `
        .cb-overlay-toolbar {
            position: absolute;
            display: inline-flex;
            gap: 2px;
            background: #1f2330;
            border-radius: 6px;
            padding: 2px;
            box-shadow: 0 2px 8px rgba(0,0,0,.25);
            z-index: 2147483000;
            pointer-events: auto;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            opacity: 0;
            transition: opacity .12s ease-out;
        }
        .cb-overlay-toolbar.is-visible { opacity: 1; }
        .cb-overlay-toolbar__btn {
            background: transparent;
            border: 0;
            color: #fff;
            cursor: pointer;
            padding: 4px 8px;
            border-radius: 4px;
            line-height: 1;
            font: inherit;
        }
        .cb-overlay-toolbar__btn:hover { background: rgba(255,255,255,.15); }
        .cb-overlay-outline { outline: 2px solid #4f8df9 !important; outline-offset: -1px; }
        [data-cb-deleted="1"] {
            opacity: .5;
            text-decoration: line-through;
        }
        /* Make empty sections/columns hoverable in preview mode + leave a
           strip at the top of each section dedicated to section-level hover
           (otherwise inner columns absorb every hover). */
        [data-cb-section-id] {
            min-height: 60px;
            box-sizing: border-box;
            padding-top: 18px;
            position: relative;
        }
        [data-cb-column-id] {
            min-height: 50px;
            box-sizing: border-box;
            padding: 4px;
        }
        .cb-overlay-popover {
            position: absolute;
            background: #fff;
            border: 1px solid #e3e5e9;
            border-radius: 6px;
            box-shadow: 0 4px 12px rgba(0,0,0,.15);
            z-index: 2147483001;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            font-size: 13px;
            padding: 4px;
            min-width: 160px;
        }
        .cb-overlay-popover[hidden] { display: none; }
        .cb-overlay-popover__btn {
            display: block;
            width: 100%;
            text-align: left;
            background: transparent;
            border: 0;
            padding: 6px 10px;
            cursor: pointer;
            border-radius: 4px;
            color: #1f2330;
            font: inherit;
        }
        .cb-overlay-popover__btn:hover { background: #f0f1f4; }
    `;
    document.head.appendChild(style);

    // ---------- Toolbar (single reusable element) ----------

    const toolbar = document.createElement('div');
    toolbar.className = 'cb-overlay-toolbar';
    toolbar.setAttribute('role', 'toolbar');
    document.body.appendChild(toolbar);

    let hoveredEl = null;
    let hideTimer = null;

    function makeBtn(label, title, onclick) {
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'cb-overlay-toolbar__btn';
        b.textContent = label;
        b.title = title;
        b.setAttribute('aria-label', title);
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

    function showToolbarFor(el, kind) {
        if (hoveredEl === el) {
            // Same element re-hovered — just keep it visible.
            clearTimeout(hideTimer);
            return;
        }

        clearTimeout(hideTimer);
        if (hoveredEl) hoveredEl.classList.remove('cb-overlay-outline');
        hoveredEl = el;
        el.classList.add('cb-overlay-outline');

        // Build buttons for this kind.
        toolbar.innerHTML = '';

        if (kind === 'block') {
            const blockId = parseInt(el.dataset.cbBlockId, 10);
            toolbar.appendChild(makeBtn('✎', 'Edit', () =>
                postToParent('cb:block:edit', { blockId })));
            toolbar.appendChild(makeBtn('×', 'Delete', () =>
                postToParent('cb:block:delete-requested', { blockId })));
        } else if (kind === 'section') {
            const sectionId = parseInt(el.dataset.cbSectionId, 10);
            toolbar.appendChild(makeBtn('▲', 'Move up', () =>
                postToParent('cb:section:move-requested', { sectionId, direction: 'up' })));
            toolbar.appendChild(makeBtn('▼', 'Move down', () =>
                postToParent('cb:section:move-requested', { sectionId, direction: 'down' })));
            toolbar.appendChild(makeBtn('×', 'Delete', () =>
                postToParent('cb:section:delete-requested', { sectionId })));
        } else if (kind === 'column') {
            const columnId = parseInt(el.dataset.cbColumnId, 10);
            toolbar.appendChild(makeBtn('+ Block', 'Add block', (event) =>
                openBlockTypePopover(event.currentTarget, columnId)));
        }

        // Reveal first so we can measure size, then position top-right of element.
        toolbar.classList.add('is-visible');
        const rect = el.getBoundingClientRect();
        const top = rect.top + window.scrollY + 4;
        const left = rect.right + window.scrollX - toolbar.offsetWidth - 4;
        toolbar.style.top = top + 'px';
        toolbar.style.left = Math.max(0, left) + 'px';
    }

    function scheduleHide() {
        clearTimeout(hideTimer);
        hideTimer = setTimeout(() => {
            toolbar.classList.remove('is-visible');
            if (hoveredEl) {
                hoveredEl.classList.remove('cb-overlay-outline');
                hoveredEl = null;
            }
        }, 120);
    }

    // Hover routing — block > column > section in priority order so the most
    // granular action available always wins.
    document.addEventListener('mouseover', (event) => {
        const block = event.target.closest?.('[data-cb-block-id]');
        if (block) {
            showToolbarFor(block, 'block');
            return;
        }
        const column = event.target.closest?.('[data-cb-column-id]');
        if (column) {
            showToolbarFor(column, 'column');
            return;
        }
        const section = event.target.closest?.('[data-cb-section-id]');
        if (section) {
            showToolbarFor(section, 'section');
            return;
        }
    });

    document.addEventListener('mouseout', (event) => {
        if (!hoveredEl) return;
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
    // Exceptions:
    //  - Buttons / clicks inside the toolbar or popover continue to work —
    //    they're handled by makeBtn() with stopPropagation already.
    //  - Anchors with `target="_blank"` (or that point to a different host)
    //    are allowed through so the user can still pop external references
    //    into a new tab if needed.
    document.addEventListener(
        'click',
        (event) => {
            const link = event.target.closest?.('a[href]');
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

    // ---------- Ready signal ----------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => postToParent('cb:ready'));
    } else {
        postToParent('cb:ready');
    }
})();
