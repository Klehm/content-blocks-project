import { describe, it, expect, beforeEach, vi } from 'vitest';
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
            <aside>
                <button class="cb-shell__sidebar-toggle"></button>
                <div class="cb-shell__sidebar-content">__EMPTY__</div>
                <div class="cb-shell__sidebar-resize"></div>
            </aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const iframe = element.querySelector('iframe');
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
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });
    Object.defineProperty(controller, 'iframeUrlValue', { value: options.iframeUrl ?? 'http://localhost/page/1?cb_preview=1' });

    // Seed the empty-state snapshot the way connect() would; tests that
    // don't run connect() still need _resetSidebarToEmptyState to work.
    controller._sidebarEmptyHtml = sidebarContent.innerHTML;

    return { controller, element, iframe, sidebar, sidebarContent, sidebarToggle, sidebarResize };
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

    it('_addBlock posts to column/{id}/blocks with type and reloads', async () => {
        await controller._addBlock(7, 'text');
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/column/7/blocks', { type: 'text' });
        expect(reloadSpy).toHaveBeenCalled();
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

    it('_moveBlock posts to block/{id}/move with target column + position', async () => {
        await controller._moveBlock(42, 3, 2);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/block/42/move', {
            toColumnId: 3,
            position: 2,
        });
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('_moveSection posts to section/{id}/move with direction', async () => {
        await controller._moveSection(5, 'up');
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/5/move', { direction: 'up' });
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('_moveSection rejects unknown direction', async () => {
        await controller._moveSection(5, 'sideways');
        expect(reqSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_reorderSection posts to section/{id}/move with position and reloads', async () => {
        await controller._reorderSection(5, 3);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/5/move', { position: 3 });
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('_reorderSection no-ops on a missing or invalid position', async () => {
        await controller._reorderSection(5, undefined);
        await controller._reorderSection(5, -1);
        await controller._reorderSection(5, 'top');
        expect(reqSpy).not.toHaveBeenCalled();
        expect(reloadSpy).not.toHaveBeenCalled();
    });

    it('_duplicateSection posts to section/{id}/duplicate and reloads', async () => {
        await controller._duplicateSection(7);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/section/7/duplicate');
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('_duplicateBlock posts to block/{id}/duplicate and reloads', async () => {
        await controller._duplicateBlock(42);
        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/block/42/duplicate');
        expect(reloadSpy).toHaveBeenCalled();
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

    it('discard posts to area/{id}/discard, applies state, reloads', async () => {
        reqSpy.mockResolvedValue({ hasUnpublishedChanges: false });

        await controller.discard({ preventDefault: () => {} });

        expect(reqSpy).toHaveBeenCalledWith('POST', '/_content-blocks/area/99/discard');
        expect(applySpy).toHaveBeenCalledWith(false);
        expect(reloadSpy).toHaveBeenCalled();
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
