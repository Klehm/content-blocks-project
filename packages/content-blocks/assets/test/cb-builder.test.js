import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Vitest unit tests for the cb-builder controller.
 * Stimulus runtime is not booted: we instantiate the class directly and
 * stub the framework-supplied properties (element, targets, values).
 */

function setupController(options = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <button class="cb-shell__viewport-btn cb-shell__viewport-btn--active"
                    data-cb-builder-viewport-param="desktop"></button>
            <button class="cb-shell__viewport-btn"
                    data-cb-builder-viewport-param="tablet"></button>
            <button class="cb-shell__viewport-btn"
                    data-cb-builder-viewport-param="mobile"></button>
            <iframe></iframe>
            <span class="cb-shell__save-error" hidden></span>
            <div class="cb-shell__undo"
                 data-cb-builder-undo-block-deleted="Block deleted"
                 data-cb-builder-undo-section-deleted="Section deleted"
                 hidden>
                <span class="cb-shell__undo-label"></span>
                <button type="button" class="cb-shell__undo-btn"></button>
            </div>
            <aside>
                <button class="cb-shell__sidebar-toggle"></button>
                <div class="cb-shell__sidebar-content">__EMPTY__</div>
                <div class="cb-shell__sidebar-resize"></div>
            </aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const iframe = element.querySelector('iframe');
    const saveError = element.querySelector('.cb-shell__save-error');
    const undoBar = element.querySelector('.cb-shell__undo');
    const undoLabel = element.querySelector('.cb-shell__undo-label');
    const sidebar = element.querySelector('aside');
    const sidebarContent = sidebar.querySelector('.cb-shell__sidebar-content');
    const sidebarToggle = sidebar.querySelector('.cb-shell__sidebar-toggle');
    const sidebarResize = sidebar.querySelector('.cb-shell__sidebar-resize');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasIframeTarget', { value: true });
    Object.defineProperty(controller, 'iframeTarget', { value: iframe });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: sidebar });
    Object.defineProperty(controller, 'hasSidebarContentTarget', { value: true });
    Object.defineProperty(controller, 'sidebarContentTarget', { value: sidebarContent });
    Object.defineProperty(controller, 'hasSidebarToggleTarget', { value: true });
    Object.defineProperty(controller, 'sidebarToggleTarget', { value: sidebarToggle });
    Object.defineProperty(controller, 'hasSidebarResizeTarget', { value: true });
    Object.defineProperty(controller, 'sidebarResizeTarget', { value: sidebarResize });
    Object.defineProperty(controller, 'hasSaveErrorTarget', { value: true });
    Object.defineProperty(controller, 'saveErrorTarget', { value: saveError });
    Object.defineProperty(controller, 'hasUndoBarTarget', { value: true });
    Object.defineProperty(controller, 'undoBarTarget', { value: undoBar });
    Object.defineProperty(controller, 'hasUndoLabelTarget', { value: true });
    Object.defineProperty(controller, 'undoLabelTarget', { value: undoLabel });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });
    Object.defineProperty(controller, 'iframeUrlValue', { value: options.iframeUrl ?? 'http://localhost/page/1?cb_preview=1' });

    // Seed the empty-state snapshot the way connect() would; tests that
    // don't run connect() still need _resetSidebarToEmptyState to work.
    controller._sidebarEmptyHtml = sidebarContent.innerHTML;

    return { controller, element, iframe, saveError, undoBar, undoLabel, sidebar, sidebarContent, sidebarToggle, sidebarResize };
}

function postMessage(data, origin = window.location.origin) {
    return { data, origin };
}

// jsdom doesn't implement window.matchMedia, which the controller calls via
// _isMobile(). Default every test to "desktop"; tests that need the mobile
// branch override this within their own block (see the resize specs).
beforeEach(() => {
    window.matchMedia = vi.fn(() => ({
        matches: false,
        addEventListener() {},
        removeEventListener() {},
    }));
});

describe('cb-builder: postMessage origin check', () => {
    let controller, errorSpy;

    beforeEach(() => {
        ({ controller } = setupController());
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('ignores messages from other origins', () => {
        const spy = vi.spyOn(controller, '_mountSidebar');
        controller._onMessage(postMessage({ type: 'cb:block:edit', blockId: 1 }, 'https://evil.com'));
        expect(spy).not.toHaveBeenCalled();
    });

    it('ignores messages without a cb: type prefix', () => {
        const spy = vi.spyOn(controller, '_mountSidebar');
        controller._onMessage(postMessage({ type: 'unrelated:event' }));
        expect(spy).not.toHaveBeenCalled();
    });

    it('ignores messages whose data is not a typed object', () => {
        const spy = vi.spyOn(controller, '_mountSidebar');
        controller._onMessage(postMessage('plain string'));
        controller._onMessage(postMessage(null));
        controller._onMessage(postMessage(42));
        expect(spy).not.toHaveBeenCalled();
    });
});

describe('cb-builder: postMessage routing', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
    });

    it('cb:block:edit triggers sidebar mount', async () => {
        const mountSpy = vi.spyOn(controller, '_mountSidebar').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:block:edit', blockId: 7 }));
        expect(mountSpy).toHaveBeenCalledWith(7);
    });

    it('cb:block:delete-requested routes to _deleteBlock', () => {
        const spy = vi.spyOn(controller, '_deleteBlock').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:block:delete-requested', blockId: 7 }));
        expect(spy).toHaveBeenCalledWith(7);
    });

    it('cb:block:add-requested routes to _addBlock', () => {
        const spy = vi.spyOn(controller, '_addBlock').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:block:add-requested', columnId: 3, blockType: 'text' }));
        expect(spy).toHaveBeenCalledWith(3, 'text');
    });

    it('cb:block:reorder routes to _moveBlock', () => {
        const spy = vi.spyOn(controller, '_moveBlock').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:block:reorder', blockId: 7, toColumnId: 2, position: 1 }));
        expect(spy).toHaveBeenCalledWith(7, 2, 1);
    });

    it('cb:section:move-requested routes to _moveSection', () => {
        const spy = vi.spyOn(controller, '_moveSection').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:section:move-requested', sectionId: 5, direction: 'up' }));
        expect(spy).toHaveBeenCalledWith(5, 'up');
    });

    it('cb:section:add-requested routes to _addSection with the requested layout', () => {
        const spy = vi.spyOn(controller, '_addSection').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:section:add-requested', layout: 'three_cols' }));
        expect(spy).toHaveBeenCalledWith('three_cols');
    });

    it('cb:section:reorder routes to _reorderSection', () => {
        const spy = vi.spyOn(controller, '_reorderSection').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:section:reorder', sectionId: 7, position: 2 }));
        expect(spy).toHaveBeenCalledWith(7, 2);
    });

    it('cb:section:duplicate-requested routes to _duplicateSection', () => {
        const spy = vi.spyOn(controller, '_duplicateSection').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:section:duplicate-requested', sectionId: 5 }));
        expect(spy).toHaveBeenCalledWith(5);
    });

    it('cb:block:duplicate-requested routes to _duplicateBlock', () => {
        const spy = vi.spyOn(controller, '_duplicateBlock').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:block:duplicate-requested', blockId: 11 }));
        expect(spy).toHaveBeenCalledWith(11);
    });

    it('cb:section:delete-requested routes to _deleteSection', () => {
        const spy = vi.spyOn(controller, '_deleteSection').mockImplementation(() => {});
        controller._onMessage(postMessage({ type: 'cb:section:delete-requested', sectionId: 5 }));
        expect(spy).toHaveBeenCalledWith(5);
    });

    it('cb:preview:outside-click resets the sidebar to the empty state', () => {
        const spy = vi.spyOn(controller, '_resetSidebarToEmptyState');
        controller._onMessage(postMessage({ type: 'cb:preview:outside-click' }));
        expect(spy).toHaveBeenCalled();
    });
});

describe('cb-builder: sidebar mount + empty state', () => {
    let controller, sidebar, sidebarContent;

    beforeEach(() => {
        ({ controller, sidebar, sidebarContent } = setupController());
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('_mountSidebar fetches the block edit URL and injects HTML into the content slot', async () => {
        const html = '<div class="cb-sidebar__block">FORM</div>';
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            text: () => Promise.resolve(html),
        }));

        await controller._mountSidebar(42);

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/block/42/edit',
            expect.objectContaining({ headers: { Accept: 'text/html' } }),
        );
        // HTML lands in the content slot, NOT on the wrapper itself —
        // the wrapper keeps its persistent header/resize chrome.
        expect(sidebarContent.innerHTML).toBe(html);
        expect(sidebar.getAttribute('data-cb-sidebar-block-id')).toBe('42');
    });

    it('_mountSidebar does not inject HTML on non-OK response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));

        await controller._mountSidebar(99);

        // The empty-state HTML is left untouched on a failed mount.
        expect(sidebarContent.innerHTML).toBe('__EMPTY__');
    });

    it('_mountSidebar expands a collapsed sidebar so the freshly mounted form is visible', async () => {
        controller.element.classList.add('cb-shell--sidebar-collapsed');
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            text: () => Promise.resolve('<form></form>'),
        }));

        await controller._mountSidebar(1);

        expect(controller.element.classList.contains('cb-shell--sidebar-collapsed')).toBe(false);
    });

    it('_resetSidebarToEmptyState restores the captured empty-state HTML and clears mount attrs', () => {
        sidebarContent.innerHTML = '<form>x</form>';
        sidebar.setAttribute('data-cb-sidebar-block-id', '42');

        controller._resetSidebarToEmptyState();

        expect(sidebarContent.innerHTML).toBe('__EMPTY__');
        expect(sidebar.hasAttribute('data-cb-sidebar-block-id')).toBe(false);
    });

    it('cb:block:saved schedules an iframe reload (debounced) and flashes the saved pill', () => {
        vi.useFakeTimers();
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onBlockSaved({ detail: { blockId: 42 } });

        // Reload is debounced — not fired synchronously.
        expect(reloadSpy).not.toHaveBeenCalled();
        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(reloadSpy).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });

    it('cb:section:saved schedules a debounced reload too', () => {
        vi.useFakeTimers();
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onSectionSaved({ detail: { sectionId: 5 } });

        expect(reloadSpy).not.toHaveBeenCalled();
        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(reloadSpy).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });

    it('back-to-back saves coalesce into a single reload', () => {
        vi.useFakeTimers();
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onBlockSaved({ detail: { blockId: 1 } });
        vi.advanceTimersByTime(100);
        controller._onBlockSaved({ detail: { blockId: 1 } });
        vi.advanceTimersByTime(100);
        controller._onBlockSaved({ detail: { blockId: 1 } });

        // None fired yet — the debounce timer keeps getting reset.
        expect(reloadSpy).not.toHaveBeenCalled();

        // Once the quiet period elapses we get exactly one reload, not
        // three.
        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(reloadSpy).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });
});

describe('cb-builder: block hot reload', () => {
    let controller, sidebar, iframe;

    beforeEach(() => {
        ({ controller, sidebar, iframe } = setupController());
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('cb:block:saved on a focused block schedules a hot refresh, not a full reload', () => {
        vi.useFakeTimers();
        sidebar.setAttribute('data-cb-sidebar-block-id', '7');
        const refreshSpy = vi.spyOn(controller, '_refreshBlock').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onBlockSaved({ detail: {} });

        expect(refreshSpy).not.toHaveBeenCalled(); // debounced
        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(refreshSpy).toHaveBeenCalledWith(7);
        expect(reloadSpy).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('cb:block:saved with no focused block falls back to a full reload', () => {
        vi.useFakeTimers();
        const refreshSpy = vi.spyOn(controller, '_refreshBlock').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onBlockSaved({ detail: {} });

        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(reloadSpy).toHaveBeenCalledTimes(1);
        expect(refreshSpy).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('_refreshBlock posts cb:block:replace when the server allows hot reload', async () => {
        const html = '<div class="cb-block" data-cb-block-id="7">Fresh</div>';
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ hotReload: true, type: 'text', html }),
        }));
        const postSpy = vi.spyOn(iframe.contentWindow, 'postMessage').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshBlock(7);

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/block/7/render',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:block:replace', blockId: 7, html },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_refreshBlock falls back to a full reload when the server opts out', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ hotReload: false }),
        }));
        const postSpy = vi.spyOn(iframe.contentWindow, 'postMessage').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshBlock(7);

        expect(postSpy).not.toHaveBeenCalled();
        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });

    it('_refreshBlock falls back to a full reload on a network error', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshBlock(7);

        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });

    it('_refreshBlock falls back to a full reload on a non-OK response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshBlock(7);

        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });
});

describe('cb-builder: section hot reload', () => {
    let controller, sidebar, iframe;

    beforeEach(() => {
        ({ controller, sidebar, iframe } = setupController());
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('cb:section:saved on a focused section schedules a hot refresh, not a full reload', () => {
        vi.useFakeTimers();
        sidebar.setAttribute('data-cb-sidebar-section-id', '5');
        const refreshSpy = vi.spyOn(controller, '_refreshSection').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        controller._onSectionSaved({ detail: {} });

        expect(refreshSpy).not.toHaveBeenCalled(); // debounced
        vi.advanceTimersByTime(Controller.SAVE_RELOAD_DEBOUNCE_MS + 10);
        expect(refreshSpy).toHaveBeenCalledWith(5);
        expect(reloadSpy).not.toHaveBeenCalled();
        vi.useRealTimers();
    });

    it('_refreshSection posts cb:section:patch when the server allows hot reload', async () => {
        const html = '<section class="cb-section cb-section--styled" data-cb-section-id="5"></section>';
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ hotReload: true, html }),
        }));
        const postSpy = vi.spyOn(iframe.contentWindow, 'postMessage').mockImplementation(() => {});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshSection(5);

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/section/5/render',
            expect.objectContaining({ headers: { Accept: 'application/json' } }),
        );
        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:section:patch', sectionId: 5, html },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_refreshSection falls back to a full reload on a network error', async () => {
        global.fetch = vi.fn(() => Promise.reject(new Error('offline')));
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._refreshSection(5);

        expect(reloadSpy).toHaveBeenCalledTimes(1);
    });
});

describe('cb-builder: sidebar toggle', () => {
    let controller, sidebarToggle, store;

    beforeEach(() => {
        store = {};
        global.localStorage = {
            getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); },
            removeItem: (k) => { delete store[k]; },
        };
        ({ controller, sidebarToggle } = setupController());
    });

    it('toggleSidebar adds the collapsed class on first click', () => {
        controller.toggleSidebar({ preventDefault: () => {} });

        expect(controller.element.classList.contains('cb-shell--sidebar-collapsed')).toBe(true);
        expect(sidebarToggle.getAttribute('aria-expanded')).toBe('false');
        expect(store['cb-builder.sidebarCollapsed']).toBe('1');
    });

    it('toggleSidebar removes the collapsed class on a second click', () => {
        controller.toggleSidebar({ preventDefault: () => {} });
        controller.toggleSidebar({ preventDefault: () => {} });

        expect(controller.element.classList.contains('cb-shell--sidebar-collapsed')).toBe(false);
        expect(sidebarToggle.getAttribute('aria-expanded')).toBe('true');
        expect(store['cb-builder.sidebarCollapsed']).toBe('0');
    });

    it('_restoreSidebarCollapsed applies the persisted collapsed flag', () => {
        store['cb-builder.sidebarCollapsed'] = '1';
        controller._restoreSidebarCollapsed();

        expect(controller.element.classList.contains('cb-shell--sidebar-collapsed')).toBe(true);
    });
});

describe('cb-builder: close', () => {
    it('close() closes the enclosing <dialog>', () => {
        // The launcher re-parents the dialog out of its own element, so the
        // close button's action is handled here instead. Wrap the shell in a
        // dialog the way the real markup does.
        const { controller, element } = setupController();
        const dialog = document.createElement('dialog');
        dialog.setAttribute('open', '');
        dialog.close = vi.fn(() => dialog.removeAttribute('open'));
        element.parentElement.insertBefore(dialog, element);
        dialog.appendChild(element);

        controller.close({ preventDefault: () => {} });

        expect(dialog.close).toHaveBeenCalled();
        expect(dialog.hasAttribute('open')).toBe(false);
    });

    it('close() is a no-op (no throw) when not inside a dialog', () => {
        const { controller } = setupController();
        expect(() => controller.close({ preventDefault: () => {} })).not.toThrow();
    });
});

describe('cb-builder: sidebar resize', () => {
    let controller, sidebar, iframe, store;

    beforeEach(() => {
        store = {};
        global.localStorage = {
            getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); },
            removeItem: (k) => { delete store[k]; },
        };
        // matchMedia not implemented in jsdom; stub to "desktop".
        window.matchMedia = vi.fn(() => ({ matches: false, addEventListener() {} }));
    });

    it('_restoreSidebarWidth applies the stored width on connect', () => {
        store['cb-builder.sidebarWidth'] = '500';
        ({ controller } = setupController());
        controller._restoreSidebarWidth();

        expect(controller.element.style.getPropertyValue('--cb-sidebar-width')).toBe('500px');
    });

    it('_restoreSidebarWidth clamps stored values to the [MIN, MAX] range', () => {
        store['cb-builder.sidebarWidth'] = '99999';
        ({ controller } = setupController());
        controller._restoreSidebarWidth();

        expect(controller.element.style.getPropertyValue('--cb-sidebar-width')).toBe('800px');
    });

    it('_restoreSidebarWidth skips when no value is stored', () => {
        ({ controller } = setupController());
        controller._restoreSidebarWidth();

        expect(controller.element.style.getPropertyValue('--cb-sidebar-width')).toBe('');
    });

    it('startSidebarResize + _onResizeMove + _onResizeEnd resize and persist the width', () => {
        ({ controller, sidebar, iframe } = setupController());
        // Pretend the sidebar was 340px wide before drag.
        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 340, top: 0, bottom: 0, left: 0, right: 0, height: 0 }),
        });

        controller.startSidebarResize({ clientX: 100, clientY: 500, preventDefault: () => {} });
        // Iframe gets pointer-events: none during the drag so mousemove
        // events bubble up to the document.
        expect(iframe.style.pointerEvents).toBe('none');

        // Drag rightward by 60px — sidebar is left-anchored, so it grows.
        controller._onResizeMove({ clientX: 160, clientY: 500 });
        expect(controller.element.style.getPropertyValue('--cb-sidebar-width')).toBe('400px');

        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 400, top: 0, bottom: 0, left: 0, right: 0, height: 0 }),
        });
        controller._onResizeEnd();

        expect(iframe.style.pointerEvents).toBe('');
        expect(store['cb-builder.sidebarWidth']).toBe('400');
    });

    it('startSidebarResize is a no-op on mobile (resize handle is hidden)', () => {
        window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {} }));
        ({ controller, iframe } = setupController());

        controller.startSidebarResize({ clientX: 100, clientY: 500, preventDefault: () => {} });

        // No iframe lock means no drag was started.
        expect(iframe.style.pointerEvents).toBe('');
    });
});

describe('cb-builder: action methods', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
    });

    it('addSection POSTs to area/{id}/sections with the layout, then reloads', async () => {
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({});
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller.addSection({ params: { layout: 'two_cols' }, preventDefault: () => {} });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/sections', { layout: 'two_cols' });
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('addSection defaults to "full" when layout is missing', async () => {
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({});
        vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller.addSection();

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/sections', { layout: 'full' });
    });

    it('_addSection falls back to "full" for an unknown layout token', async () => {
        const reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({});
        vi.spyOn(controller, 'reload').mockImplementation(() => {});

        await controller._addSection('totally_made_up');

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/sections', { layout: 'full' });
    });
});

describe('cb-builder: structural AJAX handlers', () => {
    let controller, reqSpy, reloadSpy;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        // Stamp the CSRF token onto the element since _jsonRequest reads it.
        controller.element.dataset.cbCsrfToken = 'tok-123';
        reqSpy = vi.spyOn(controller, '_jsonRequest').mockResolvedValue({});
        reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
    });

    it('_addBlock inserts the block in place when the server ships hot-reload html', async () => {
        reqSpy.mockResolvedValueOnce({ id: 9, hotReload: true, html: '<div data-cb-block-id="9"></div>' });
        const insertSpy = vi.spyOn(controller, '_insertBlockInPreview').mockImplementation(() => {});

        await controller._addBlock(7, 'text');

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/column/7/blocks', { type: 'text' });
        expect(insertSpy).toHaveBeenCalledWith(7, '<div data-cb-block-id="9"></div>');
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_addBlock falls back to a full reload for a JS-dependent block (no html)', async () => {
        reqSpy.mockResolvedValueOnce({ id: 9, hotReload: false });
        const insertSpy = vi.spyOn(controller, '_insertBlockInPreview').mockImplementation(() => {});

        await controller._addBlock(7, 'custom');

        expect(reloadSpy).toHaveBeenCalled();
        expect(insertSpy).not.toHaveBeenCalled();
    });

    it('_addBlock leaves the preview untouched when create fails', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const insertSpy = vi.spyOn(controller, '_insertBlockInPreview').mockImplementation(() => {});

        await controller._addBlock(7, 'text');

        expect(insertSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_insertBlockInPreview posts cb:block:insert to the iframe', () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});

        controller._insertBlockInPreview(7, '<div data-cb-block-id="9"></div>');

        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:block:insert', columnId: 7, html: '<div data-cb-block-id="9"></div>' },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_addBlock no-ops when columnId or type is missing', async () => {
        await controller._addBlock(undefined, 'text');
        await controller._addBlock(7, undefined);
        expect(reqSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_addBlock auto-opens the edit sidebar on the freshly created block', async () => {
        reqSpy.mockResolvedValueOnce({ id: 123 });
        const mountSpy = vi.spyOn(controller, '_mountSidebar').mockImplementation(() => {});

        await controller._addBlock(7, 'text');

        expect(mountSpy).toHaveBeenCalledWith(123);
    });

    it('_addBlock skips sidebar mount when the response has no id', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const mountSpy = vi.spyOn(controller, '_mountSidebar').mockImplementation(() => {});

        await controller._addBlock(7, 'text');

        expect(mountSpy).not.toHaveBeenCalled();
    });

    it('_addSection auto-opens the settings sidebar on the freshly created section', async () => {
        reqSpy.mockResolvedValueOnce({ id: 456 });
        const mountSpy = vi.spyOn(controller, '_mountSectionSettings').mockImplementation(() => {});

        await controller._addSection('two_cols');

        expect(mountSpy).toHaveBeenCalledWith(456);
    });

    it('_addSection skips settings mount when the response has no id', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const mountSpy = vi.spyOn(controller, '_mountSectionSettings').mockImplementation(() => {});

        await controller._addSection('full');

        expect(mountSpy).not.toHaveBeenCalled();
    });

    it('_deleteBlock issues DELETE and removes the block in place (no full reload)', async () => {
        reqSpy.mockResolvedValueOnce({ deleted: true });
        const removeSpy = vi.spyOn(controller, '_removeBlockFromPreview').mockImplementation(() => {});

        await controller._deleteBlock(42);

        expect(reqSpy).toHaveBeenCalledWith('DELETE', '/_content-blocks/block/42');
        expect(removeSpy).toHaveBeenCalledWith(42);
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_deleteBlock leaves the preview untouched when the DELETE fails', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const removeSpy = vi.spyOn(controller, '_removeBlockFromPreview').mockImplementation(() => {});

        await controller._deleteBlock(42);

        expect(removeSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_removeBlockFromPreview posts cb:block:remove to the iframe', () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});

        controller._removeBlockFromPreview(42);

        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:block:remove', blockId: 42 },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_moveBlock posts the move then relocates the block in place (no full reload)', async () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});
        await controller._moveBlock(42, 3, 2);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/block/42/move', {
            toColumnId: 3,
            position: 2,
        });
        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:block:reorder:apply', blockId: 42, toColumnId: 3, position: 2 },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_moveBlock leaves the preview untouched when the move fails', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});
        await controller._moveBlock(42, 3, 2);
        expect(postSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_moveSection posts the move then nudges the section in place (no full reload)', async () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});
        await controller._moveSection(5, 'up');
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/5/move', { direction: 'up' });
        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:section:move:apply', sectionId: 5, direction: 'up' },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_moveSection does not touch the preview when the section is already at the edge', async () => {
        reqSpy.mockResolvedValueOnce({ moved: false });
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});
        await controller._moveSection(5, 'up');
        expect(postSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_moveSection rejects unknown direction', async () => {
        await controller._moveSection(5, 'sideways');
        expect(reqSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_reorderSection posts the move then relocates the section in place (no full reload)', async () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});
        await controller._reorderSection(5, 3);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/5/move', { position: 3 });
        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:section:reorder:apply', sectionId: 5, position: 3 },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_reorderSection no-ops on a missing or invalid position', async () => {
        await controller._reorderSection(5, undefined);
        await controller._reorderSection(5, -1);
        await controller._reorderSection(5, 'top');
        expect(reqSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateSection inserts the copy in place when the server ships hot-reload html', async () => {
        reqSpy.mockResolvedValueOnce({ id: 8, sourceId: 7, hotReload: true, html: '<section data-cb-section-id="8"></section>' });
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateSection(7);

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/7/duplicate');
        expect(dupSpy).toHaveBeenCalledWith({ type: 'cb:section:duplicate:apply', sourceId: 7, html: '<section data-cb-section-id="8"></section>' });
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateSection falls back to a full reload when a block opts out of hot reload', async () => {
        reqSpy.mockResolvedValueOnce({ id: 8, sourceId: 7, hotReload: false });
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateSection(7);

        expect(reloadSpy).toHaveBeenCalled();
        expect(dupSpy).not.toHaveBeenCalled();
    });

    it('_duplicateSection leaves the preview untouched when the duplicate fails', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateSection(7);

        expect(dupSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateBlock inserts the copy in place when the server ships hot-reload html', async () => {
        reqSpy.mockResolvedValueOnce({ id: 43, sourceId: 42, hotReload: true, html: '<div data-cb-block-id="43"></div>' });
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateBlock(42);

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/block/42/duplicate');
        expect(dupSpy).toHaveBeenCalledWith({ type: 'cb:block:duplicate:apply', sourceId: 42, html: '<div data-cb-block-id="43"></div>' });
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateBlock falls back to a full reload for a JS-dependent block (no html)', async () => {
        reqSpy.mockResolvedValueOnce({ id: 43, sourceId: 42, hotReload: false });
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateBlock(42);

        expect(reloadSpy).toHaveBeenCalled();
        expect(dupSpy).not.toHaveBeenCalled();
    });

    it('_duplicateBlock leaves the preview untouched when the duplicate fails', async () => {
        reqSpy.mockResolvedValueOnce(null);
        const dupSpy = vi.spyOn(controller, '_duplicateInPreview').mockImplementation(() => {});

        await controller._duplicateBlock(42);

        expect(dupSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateInPreview posts the apply message to the iframe', () => {
        const postSpy = vi.spyOn(controller.iframeTarget.contentWindow, 'postMessage').mockImplementation(() => {});

        controller._duplicateInPreview({ type: 'cb:block:duplicate:apply', sourceId: 42, html: '<div data-cb-block-id="43"></div>' });

        expect(postSpy).toHaveBeenCalledWith(
            { type: 'cb:block:duplicate:apply', sourceId: 42, html: '<div data-cb-block-id="43"></div>' },
            window.location.origin,
        );
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_deleteSection issues DELETE and reloads', async () => {
        await controller._deleteSection(5);
        expect(reqSpy).toHaveBeenCalledWith('DELETE', '/_content-blocks/section/5');
        expect(reloadSpy).toHaveBeenCalled();
    });
});

describe('cb-builder: publish/discard', () => {
    let controller, reqSpy, reloadSpy, applySpy;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        controller.element.dataset.cbCsrfToken = 'tok';
        // Add a topbar Discard + Publish button + launcher badge so we can
        // verify the draft-state side-effects.
        const discard = document.createElement('button');
        discard.className = 'cb-shell__discard';
        controller.element.appendChild(discard);
        const publish = document.createElement('button');
        publish.className = 'cb-shell__publish';
        controller.element.appendChild(publish);

        const badge = document.createElement('span');
        badge.className = 'cb-launcher__badge';
        document.body.appendChild(badge);

        vi.spyOn(console, 'error').mockImplementation(() => {});
        reqSpy = vi.spyOn(controller, '_jsonRequest');
        reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
        applySpy = vi.spyOn(controller, '_applyDraftState');
    });

    it('publish posts to area/{id}/publish, applies state, reloads', async () => {
        reqSpy.mockResolvedValue({ hasUnpublishedChanges: false });

        await controller.publish({ preventDefault: () => {} });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/publish');
        expect(applySpy).toHaveBeenCalledWith(false);
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('discard posts to area/{id}/discard, applies state, reloads (when confirmed)', async () => {
        vi.spyOn(window, 'confirm').mockReturnValue(true);
        reqSpy.mockResolvedValue({ hasUnpublishedChanges: false });

        await controller.discard({ preventDefault: () => {} });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/discard');
        expect(applySpy).toHaveBeenCalledWith(false);
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('discard asks for confirmation and does nothing when declined', async () => {
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        await controller.discard({ preventDefault: () => {} });

        expect(confirmSpy).toHaveBeenCalled();
        expect(reqSpy).not.toHaveBeenCalled();
        expect(applySpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('discard confirm prompt uses the localized text from the shell root', async () => {
        controller.element.setAttribute('data-i18n-cb-builder-discard-confirm', 'Tout annuler ?');
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(false);

        await controller.discard({ preventDefault: () => {} });

        expect(confirmSpy).toHaveBeenCalledWith('Tout annuler ?');
    });

    it('publish does not act when the request fails', async () => {
        reqSpy.mockResolvedValue(null);

        await controller.publish();

        expect(applySpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_applyDraftState hides Discard, disables Publish and removes the badge when clean', () => {
        const discardBtn = controller.element.querySelector('.cb-shell__discard');
        const publishBtn = controller.element.querySelector('.cb-shell__publish');
        const badge = document.querySelector('.cb-launcher__badge');

        controller._applyDraftState(false);

        expect(discardBtn.hidden).toBe(true);
        expect(publishBtn.disabled).toBe(true);
        expect(document.querySelector('.cb-launcher__badge')).toBeNull();
        expect(badge.isConnected).toBe(false);
    });

    it('_applyDraftState reveals Discard and enables Publish when the area is dirty', () => {
        const discardBtn = controller.element.querySelector('.cb-shell__discard');
        discardBtn.hidden = true;
        const publishBtn = controller.element.querySelector('.cb-shell__publish');
        publishBtn.disabled = true;

        controller._applyDraftState(true);

        expect(discardBtn.hidden).toBe(false);
        expect(publishBtn.disabled).toBe(false);
    });
});

describe('cb-builder: _jsonRequest', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
        controller.element.dataset.cbCsrfToken = 'csrf-xyz';
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('forwards the CSRF token in the X-CSRF-Token header', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ ok: 1 }),
        }));

        await controller._jsonRequest('POST', '/some/url', { foo: 'bar' });

        const init = global.fetch.mock.calls[0][1];
        expect(init.method).toBe('POST');
        expect(init.headers['X-CSRF-Token']).toBe('csrf-xyz');
        expect(init.headers['Content-Type']).toBe('application/json');
        expect(init.body).toBe(JSON.stringify({ foo: 'bar' }));
    });

    it('returns null on non-OK response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));
        const result = await controller._jsonRequest('DELETE', '/some/url');
        expect(result).toBeNull();
    });
});

describe('cb-builder: _jsonRequest serialization', () => {
    let controller;
    // setTimeout(0) drains the microtask queue AND lets the serialization
    // chain advance to the next request — robust without fake timers.
    const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

    beforeEach(() => {
        ({ controller } = setupController());
        controller.element.dataset.cbCsrfToken = 'csrf-xyz';
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('runs structural requests one at a time, in submission order', async () => {
        // Each fetch hangs until we resolve it by hand, so we can observe that
        // the second request never starts while the first is still in flight.
        const resolvers = [];
        global.fetch = vi.fn(() => new Promise((resolve) => { resolvers.push(resolve); }));

        controller._jsonRequest('POST', '/first');
        controller._jsonRequest('POST', '/second');
        await flush();

        // Only the first request was dispatched; the second waits in the queue.
        expect(global.fetch).toHaveBeenCalledTimes(1);
        expect(global.fetch.mock.calls[0][0]).toBe('/first');

        // Settling the first must release the slot for the second.
        resolvers[0]({ ok: true, json: () => Promise.resolve({}) });
        await flush();

        expect(global.fetch).toHaveBeenCalledTimes(2);
        expect(global.fetch.mock.calls[1][0]).toBe('/second');

        resolvers[1]({ ok: true, json: () => Promise.resolve({}) });
        await flush();
    });

    it('a failed request does not wedge the queued ones behind it', async () => {
        global.fetch = vi.fn()
            .mockImplementationOnce(() => Promise.reject(new Error('offline')))
            .mockImplementationOnce(() => Promise.resolve({ ok: true, json: () => Promise.resolve({ ok: 1 }) }));

        const firstP = controller._jsonRequest('POST', '/first');
        const secondP = controller._jsonRequest('POST', '/second');
        const [first, second] = await Promise.all([firstP, secondP]);
        await flush();

        expect(first).toBeNull();           // network failure → null, slot released
        expect(second).toEqual({ ok: 1 });  // the queue advanced past the failure
        expect(global.fetch).toHaveBeenCalledTimes(2);
    });
});

describe('cb-builder: setViewport', () => {
    let controller, element, iframe;

    beforeEach(() => {
        ({ controller, element, iframe } = setupController());
    });

    it('toggles --active on the clicked button and resizes iframe', () => {
        const buttons = element.querySelectorAll('.cb-shell__viewport-btn');
        const tabletBtn = buttons[1];

        controller.setViewport({
            params: { viewport: 'tablet' },
            currentTarget: tabletBtn,
            preventDefault: () => {},
        });

        expect(buttons[0].classList.contains('cb-shell__viewport-btn--active')).toBe(false);
        expect(tabletBtn.classList.contains('cb-shell__viewport-btn--active')).toBe(true);
        expect(iframe.style.maxWidth).toBe('768px');
        expect(iframe.style.margin).toBe('0px auto');
    });

    it('resets to full width on desktop', () => {
        controller.setViewport({
            params: { viewport: 'desktop' },
            preventDefault: () => {},
        });

        expect(iframe.style.maxWidth).toBe('100%');
        expect(iframe.style.margin).toBe('0px');
    });
});

describe('cb-builder: viewport visibility', () => {
    let controller, element, iframe;

    beforeEach(() => {
        ({ controller, element, iframe } = setupController());
    });

    function setShellWidth(width) {
        Object.defineProperty(element, 'clientWidth', { value: width, configurable: true });
    }

    it('hides viewport buttons whose target width exceeds the shell width', () => {
        setShellWidth(500);
        controller._refreshViewportButtons();

        const desktop = element.querySelector('[data-cb-builder-viewport-param="desktop"]');
        const tablet = element.querySelector('[data-cb-builder-viewport-param="tablet"]');
        const mobile = element.querySelector('[data-cb-builder-viewport-param="mobile"]');

        // Desktop tracks the shell width, always available.
        expect(desktop.hidden).toBe(false);
        // Mobile preview = 375px, fits in a 500px shell.
        expect(mobile.hidden).toBe(false);
        // Tablet preview = 768px, doesn't fit in a 500px shell — hidden.
        expect(tablet.hidden).toBe(true);
    });

    it('keeps every viewport visible on a wide shell', () => {
        setShellWidth(1400);
        controller._refreshViewportButtons();
        element.querySelectorAll('.cb-shell__viewport-btn').forEach((btn) => {
            expect(btn.hidden).toBe(false);
        });
    });

    it('falls back to desktop when the active viewport gets hidden by a resize', () => {
        // Start active on mobile in a wide shell.
        setShellWidth(1400);
        controller._applyViewport('mobile');
        const mobile = element.querySelector('[data-cb-builder-viewport-param="mobile"]');
        const desktop = element.querySelector('[data-cb-builder-viewport-param="desktop"]');
        expect(mobile.classList.contains('cb-shell__viewport-btn--active')).toBe(true);

        // Shrink the shell below the mobile preview width — mobile button
        // should hide and the active viewport should snap back to desktop.
        setShellWidth(300);
        controller._refreshViewportButtons();

        expect(mobile.hidden).toBe(true);
        expect(mobile.classList.contains('cb-shell__viewport-btn--active')).toBe(false);
        expect(desktop.classList.contains('cb-shell__viewport-btn--active')).toBe(true);
        expect(iframe.style.maxWidth).toBe('100%');
    });
});

describe('cb-builder: save-error feedback', () => {
    let controller, saveError, element, errorSpy;

    beforeEach(() => {
        ({ controller, saveError, element } = setupController());
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        errorSpy.mockRestore();
        vi.unstubAllGlobals();
    });

    it('_jsonRequest shows the banner on an HTTP error response', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500 }));

        const result = await controller._jsonRequest('POST', '/_content-blocks/area/42/publish');

        expect(result).toBeNull();
        expect(saveError.hidden).toBe(false);
    });

    it('_jsonRequest shows the banner on a network failure instead of throwing', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        // Must resolve to null, NOT reject — callers never catch.
        const result = await controller._jsonRequest('DELETE', '/_content-blocks/block/7');

        expect(result).toBeNull();
        expect(saveError.hidden).toBe(false);
    });

    it('a later successful _jsonRequest clears the banner', async () => {
        controller._showSaveError();
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({
            ok: true,
            json: () => Promise.resolve({ ok: true }),
        }));

        await controller._jsonRequest('POST', '/_content-blocks/area/42/sections', { layout: 'full' });

        expect(saveError.hidden).toBe(true);
    });

    it('a later successful autosave (_flashSaved) clears the banner', () => {
        controller._showSaveError();
        expect(saveError.hidden).toBe(false);

        controller._flashSaved();

        expect(saveError.hidden).toBe(true);
    });

    it('a bubbling cb:save:error event shows the banner', () => {
        controller.connect();
        const inner = document.createElement('div');
        element.appendChild(inner);

        inner.dispatchEvent(new CustomEvent('cb:save:error', { bubbles: true }));

        expect(saveError.hidden).toBe(false);
        controller.disconnect();
    });

    it('live:connect hooks response:error — suppresses the Live modal and signals the failure', () => {
        controller.connect();
        // Simulated Live component root containing an autosave wrapper, the
        // way Block.html.twig nests them.
        const liveRoot = document.createElement('div');
        liveRoot.innerHTML = '<div data-controller="cb-autosave"></div>';
        element.appendChild(liveRoot);

        const hooks = {};
        const component = {
            element: liveRoot,
            on: (name, cb) => { hooks[name] = cb; },
        };
        element.dispatchEvent(new CustomEvent('live:connect', { bubbles: true, detail: { component } }));
        expect(typeof hooks['response:error']).toBe('function');
        expect(typeof hooks['loading.state:started']).toBe('function');

        // The autosave wrapper must receive cb:save:error (baseline reset)…
        const autosaveEl = liveRoot.querySelector('[data-controller~="cb-autosave"]');
        const received = vi.fn();
        autosaveEl.addEventListener('cb:save:error', received);

        const controls = { displayError: true };
        hooks['response:error']({}, controls);

        // …Live's raw error modal is suppressed, and the banner is up.
        expect(controls.displayError).toBe(false);
        expect(received).toHaveBeenCalledOnce();
        expect(saveError.hidden).toBe(false);
        controller.disconnect();
    });

    it('live:connect hooks the request promise — a network rejection signals the failure', async () => {
        controller.connect();
        const liveRoot = document.createElement('div');
        element.appendChild(liveRoot);

        const hooks = {};
        const component = {
            element: liveRoot,
            on: (name, cb) => { hooks[name] = cb; },
        };
        element.dispatchEvent(new CustomEvent('live:connect', { bubbles: true, detail: { component } }));

        // Live never attaches a rejection handler to its own request — ours
        // must catch it and surface the banner (no autosave wrapper here, so
        // the direct fallback path shows it).
        const request = { promise: Promise.reject(new Error('offline')) };
        component.backendRequest = request;
        hooks['loading.state:started'](liveRoot, request);
        await new Promise((r) => setTimeout(r, 0));

        expect(saveError.hidden).toBe(false);
        // The wedged component was unblocked: Live leaves `backendRequest`
        // set forever on rejection, which queues every later save behind a
        // dead request. Our catch must clear it so retries can run.
        expect(component.backendRequest).toBeNull();
        controller.disconnect();
    });

    it('ignores live:connect events without a usable component', () => {
        controller.connect();
        // Must not throw.
        element.dispatchEvent(new CustomEvent('live:connect', { bubbles: true, detail: {} }));
        element.dispatchEvent(new CustomEvent('live:connect', { bubbles: true }));
        expect(saveError.hidden).toBe(true);
        controller.disconnect();
    });
});

describe('cb-builder: undo delete snackbar', () => {
    let controller, undoBar, undoLabel, saveError, errorSpy;

    function okJson(payload = {}) {
        return { ok: true, json: () => Promise.resolve(payload) };
    }

    beforeEach(() => {
        ({ controller, undoBar, undoLabel, saveError } = setupController());
        errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {});
        // Deletes route preview updates through these; not under test here.
        vi.spyOn(controller, '_removeBlockFromPreview').mockImplementation(() => {});
        vi.spyOn(controller, '_afterStructuralOp').mockImplementation(() => {});
        vi.spyOn(controller, '_applyDraftState').mockImplementation(() => {});
        vi.spyOn(controller, 'reload').mockImplementation(() => {});
    });

    afterEach(() => {
        errorSpy.mockRestore();
        vi.unstubAllGlobals();
        vi.useRealTimers();
    });

    it('a successful block delete offers the undo with the block label', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okJson({ deleted: true })));

        await controller._deleteBlock(7);

        expect(undoBar.hidden).toBe(false);
        expect(undoLabel.textContent).toBe('Block deleted');
        expect(controller._pendingUndo).toEqual({ kind: 'block', id: 7 });
    });

    it('a successful section delete offers the undo with the section label', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okJson({ deleted: true })));

        await controller._deleteSection(5);

        expect(undoBar.hidden).toBe(false);
        expect(undoLabel.textContent).toBe('Section deleted');
        expect(controller._pendingUndo).toEqual({ kind: 'section', id: 5 });
    });

    it('a failed section delete neither reloads nor offers an undo', async () => {
        // Regression: _deleteSection used to ignore the request result and
        // reload anyway, hiding the failure.
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: false, status: 500 }));

        await controller._deleteSection(5);

        expect(undoBar.hidden).toBe(true);
        expect(controller._afterStructuralOp).not.toHaveBeenCalled();
        expect(saveError.hidden).toBe(false);
    });

    it('undoDelete POSTs the restore endpoint, hides the bar and reloads', async () => {
        const fetchMock = vi.fn().mockResolvedValue(okJson({ deleted: true }));
        vi.stubGlobal('fetch', fetchMock);
        await controller._deleteBlock(7);

        fetchMock.mockResolvedValue(okJson({ restored: true }));
        await controller.undoDelete();

        const [url, init] = fetchMock.mock.calls.at(-1);
        expect(url).toBe('/_content-blocks/block/7/restore');
        expect(init.method).toBe('POST');
        expect(undoBar.hidden).toBe(true);
        expect(controller._pendingUndo).toBeNull();
        expect(controller.reload).toHaveBeenCalled();
    });

    it('a failed restore consumes the offer and surfaces the save-error banner', async () => {
        const fetchMock = vi.fn().mockResolvedValue(okJson({ deleted: true }));
        vi.stubGlobal('fetch', fetchMock);
        await controller._deleteBlock(7);

        fetchMock.mockResolvedValue({ ok: false, status: 404 });
        await controller.undoDelete();

        expect(undoBar.hidden).toBe(true);
        expect(controller.reload).not.toHaveBeenCalled();
        expect(saveError.hidden).toBe(false);
    });

    it('undoDelete without a pending offer is a no-op', async () => {
        const fetchMock = vi.fn();
        vi.stubGlobal('fetch', fetchMock);

        await controller.undoDelete();

        expect(fetchMock).not.toHaveBeenCalled();
        expect(controller.reload).not.toHaveBeenCalled();
    });

    it('the offer expires after UNDO_TIMEOUT_MS', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okJson({ deleted: true })));
        vi.useFakeTimers();

        await controller._deleteBlock(7);
        expect(undoBar.hidden).toBe(false);

        vi.advanceTimersByTime(controller.constructor.UNDO_TIMEOUT_MS + 1);

        expect(undoBar.hidden).toBe(true);
        expect(controller._pendingUndo).toBeNull();
    });

    it('a newer delete replaces the pending offer (single slot)', async () => {
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue(okJson({ deleted: true })));

        await controller._deleteBlock(7);
        await controller._deleteSection(5);

        expect(controller._pendingUndo).toEqual({ kind: 'section', id: 5 });
        expect(undoLabel.textContent).toBe('Section deleted');
    });

    it('publish and discard withdraw the pending offer', async () => {
        const fetchMock = vi.fn().mockResolvedValue(okJson({ deleted: true }));
        vi.stubGlobal('fetch', fetchMock);
        await controller._deleteBlock(7);
        expect(undoBar.hidden).toBe(false);

        fetchMock.mockResolvedValue(okJson({ hasUnpublishedChanges: false }));
        await controller.publish();
        expect(undoBar.hidden).toBe(true);
        expect(controller._pendingUndo).toBeNull();

        await controller._deleteBlock(8);
        expect(undoBar.hidden).toBe(false);
        vi.spyOn(window, 'confirm').mockReturnValue(true); // discard is now gated by a confirm
        await controller.discard();
        expect(undoBar.hidden).toBe(true);
        expect(controller._pendingUndo).toBeNull();
    });
});
