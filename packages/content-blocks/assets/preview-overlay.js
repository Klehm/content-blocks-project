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
        b.addEventListener('click', (e) => {
            e.preventDefault();
            e.stopPropagation();
            onclick();
        });
        return b;
    }

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

    // Hover routing — block markers take priority over section markers
    // (a block lives inside a section, but block actions are more granular).
    document.addEventListener('mouseover', (event) => {
        const block = event.target.closest?.('[data-cb-block-id]');
        if (block) {
            showToolbarFor(block, 'block');
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

    // ---------- Ready signal ----------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => postToParent('cb:ready'));
    } else {
        postToParent('cb:ready');
    }
})();
