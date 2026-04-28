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

    function buildToolbarFor(el, kind) {
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
            toolbar.appendChild(makeBtn('⚙', 'Settings', () =>
                postToParent('cb:section:settings', { sectionId })));
            toolbar.appendChild(makeBtn('×', 'Delete', () =>
                postToParent('cb:section:delete-requested', { sectionId })));
        }
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
        if (focusedEl) return;
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

            // 3. Click on a block/section: pin focus on it and skip the
            //    outside-click event — the user is selecting an element to
            //    keep its toolbar visible, not dismissing the sidebar.
            const block = target.closest?.('[data-cb-block-id]');
            const section = target.closest?.('[data-cb-section-id]');
            if (block) {
                focusElement(block, 'block');
            } else if (section) {
                focusElement(section, 'section');
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

    // ---------- Ready signal ----------

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => postToParent('cb:ready'));
    } else {
        postToParent('cb:ready');
    }
})();
