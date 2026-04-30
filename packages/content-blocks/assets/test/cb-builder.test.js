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
            <aside hidden>
                <div class="cb-shell__sidebar-resize"></div>
                <div class="cb-shell__sidebar-content"></div>
            </aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const iframe = element.querySelector('iframe');
    const sidebar = element.querySelector('aside');
    const sidebarContent = sidebar.querySelector('.cb-shell__sidebar-content');
    const sidebarResize = sidebar.querySelector('.cb-shell__sidebar-resize');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasIframeTarget', { value: true });
    Object.defineProperty(controller, 'iframeTarget', { value: iframe });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: sidebar });
    Object.defineProperty(controller, 'hasSidebarContentTarget', { value: true });
    Object.defineProperty(controller, 'sidebarContentTarget', { value: sidebarContent });
    Object.defineProperty(controller, 'hasSidebarResizeTarget', { value: true });
    Object.defineProperty(controller, 'sidebarResizeTarget', { value: sidebarResize });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });
    Object.defineProperty(controller, 'iframeUrlValue', { value: options.iframeUrl ?? 'http://localhost/page/1?cb_preview=1' });

    return { controller, element, iframe, sidebar, sidebarContent, sidebarResize };
}

function postMessage(data, origin = window.location.origin) {
    return { data, origin };
}

describe('cb-builder: postMessage origin check', () => {
    let controller, logSpy;

    beforeEach(() => {
        ({ controller } = setupController());
        logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('ignores messages from other origins', () => {
        controller._onMessage(postMessage({ type: 'cb:ready' }, 'https://evil.com'));
        expect(logSpy).not.toHaveBeenCalled();
    });

    it('ignores messages without a cb: type prefix', () => {
        controller._onMessage(postMessage({ type: 'unrelated:event' }));
        expect(logSpy).not.toHaveBeenCalled();
    });

    it('ignores messages whose data is not a typed object', () => {
        controller._onMessage(postMessage('plain string'));
        controller._onMessage(postMessage(null));
        controller._onMessage(postMessage(42));
        expect(logSpy).not.toHaveBeenCalled();
    });
});

describe('cb-builder: postMessage routing', () => {
    let controller, logSpy;

    beforeEach(() => {
        ({ controller } = setupController());
        logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('logs cb:ready', () => {
        controller._onMessage(postMessage({ type: 'cb:ready' }));
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] iframe ready');
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

    it('logs unknown cb: types under the unknown branch', () => {
        controller._onMessage(postMessage({ type: 'cb:weird' }));
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] unknown message type', 'cb:weird', { type: 'cb:weird' });
    });
});

describe('cb-builder: sidebar mount/close', () => {
    let controller, sidebar, sidebarContent;

    beforeEach(() => {
        ({ controller, sidebar, sidebarContent } = setupController());
        vi.spyOn(console, 'log').mockImplementation(() => {});
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
        expect(sidebar.hidden).toBe(false);
        expect(sidebar.getAttribute('data-cb-sidebar-block-id')).toBe('42');
    });

    it('_mountSidebar does not inject HTML on non-OK response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));

        await controller._mountSidebar(99);

        expect(sidebarContent.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
    });

    it('closeSidebar clears the content, hides, and drops mount-id attrs', () => {
        sidebarContent.innerHTML = '<form>x</form>';
        sidebar.hidden = false;
        sidebar.setAttribute('data-cb-sidebar-block-id', '42');

        controller.closeSidebar({ preventDefault: () => {} });

        expect(sidebarContent.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
        expect(sidebar.hasAttribute('data-cb-sidebar-block-id')).toBe(false);
    });

    it('cb:block:saved keeps the sidebar open and reloads the iframe', () => {
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
        sidebarContent.innerHTML = '<form>x</form>';
        sidebar.hidden = false;

        controller._onBlockSaved({ detail: { blockId: 42 } });

        // Stays open — the user can keep tweaking and saving iteratively.
        expect(sidebarContent.innerHTML).toBe('<form>x</form>');
        expect(sidebar.hidden).toBe(false);
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('cb:section:saved keeps the sidebar open and reloads the iframe', () => {
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
        sidebarContent.innerHTML = '<form>x</form>';
        sidebar.hidden = false;

        controller._onSectionSaved({ detail: { sectionId: 5 } });

        expect(sidebarContent.innerHTML).toBe('<form>x</form>');
        expect(sidebar.hidden).toBe(false);
        expect(reloadSpy).toHaveBeenCalled();
    });
});

describe('cb-builder: sidebar resize', () => {
    let controller, sidebar, iframe, store;

    beforeEach(() => {
        store = {};
        // Tiny localStorage stub so the test doesn't depend on jsdom's.
        global.localStorage = {
            getItem: (k) => (k in store ? store[k] : null),
            setItem: (k, v) => { store[k] = String(v); },
            removeItem: (k) => { delete store[k]; },
        };
        // matchMedia isn't implemented by jsdom; stub it to "desktop"
        // (mobile tests below override per-case).
        window.matchMedia = vi.fn(() => ({ matches: false, addEventListener() {} }));
        vi.spyOn(console, 'log').mockImplementation(() => {});
    });

    it('_restoreSidebarWidth applies the stored width on connect', () => {
        store['cb-builder.sidebarWidth'] = '500';
        ({ controller, sidebar } = setupController());
        controller._restoreSidebarWidth();

        expect(sidebar.style.width).toBe('500px');
    });

    it('_restoreSidebarWidth clamps stored values to the [MIN, MAX] range', () => {
        store['cb-builder.sidebarWidth'] = '99999';
        ({ controller, sidebar } = setupController());
        controller._restoreSidebarWidth();

        expect(sidebar.style.width).toBe('800px');
    });

    it('_restoreSidebarWidth skips when no value is stored', () => {
        ({ controller, sidebar } = setupController());
        controller._restoreSidebarWidth();

        expect(sidebar.style.width).toBe('');
    });

    it('startSidebarResize + _onResizeMove + _onResizeEnd resize and persist the width', () => {
        ({ controller, sidebar, iframe } = setupController());
        // Pretend the sidebar was 380px wide before drag.
        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 380, top: 0, bottom: 0, left: 0, right: 0, height: 0 }),
        });

        controller.startSidebarResize({ clientX: 1000, clientY: 500, preventDefault: () => {} });
        // Iframe gets pointer-events: none during the drag so mousemove
        // events bubble up to the document.
        expect(iframe.style.pointerEvents).toBe('none');

        controller._onResizeMove({ clientX: 900, clientY: 500 });
        // 100px to the left → +100px on the sidebar width.
        expect(sidebar.style.width).toBe('480px');

        // Pretend the new bounding box.
        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 480, top: 0, bottom: 0, left: 0, right: 0, height: 0 }),
        });
        controller._onResizeEnd();

        expect(iframe.style.pointerEvents).toBe('');
        expect(store['cb-builder.sidebarWidth']).toBe('480');
    });

    // ---------- Mobile (vertical-axis resize, height stored separately) ----------

    it('mobile: _restoreSidebarWidth applies the stored height to --cb-sidebar-height', () => {
        window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {} }));
        store['cb-builder.sidebarHeight'] = '420';
        ({ controller } = setupController());
        // Force a known viewport height for clamping (jsdom default may be tall).
        Object.defineProperty(window, 'innerHeight', { value: 1000, configurable: true });

        controller._restoreSidebarWidth();

        expect(controller.element.style.getPropertyValue('--cb-sidebar-height')).toBe('420px');
    });

    it('mobile: vertical drag resizes height and persists in the height key', () => {
        window.matchMedia = vi.fn(() => ({ matches: true, addEventListener() {} }));
        ({ controller, sidebar, iframe } = setupController());
        Object.defineProperty(window, 'innerHeight', { value: 1000, configurable: true });
        // Pretend the sidebar was 300px tall before drag.
        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 0, top: 0, bottom: 0, left: 0, right: 0, height: 300 }),
        });

        controller.startSidebarResize({ clientX: 100, clientY: 800, preventDefault: () => {} });
        controller._onResizeMove({ clientX: 100, clientY: 700, preventDefault: () => {} });
        // 100px upward → +100px on the sidebar height.
        expect(controller.element.style.getPropertyValue('--cb-sidebar-height')).toBe('400px');

        Object.defineProperty(sidebar, 'getBoundingClientRect', {
            configurable: true,
            value: () => ({ width: 0, top: 0, bottom: 0, left: 0, right: 0, height: 400 }),
        });
        controller._onResizeEnd();

        expect(store['cb-builder.sidebarHeight']).toBe('400');
        // Width key should remain untouched in mobile mode.
        expect(store['cb-builder.sidebarWidth']).toBeUndefined();
    });
});

describe('cb-builder: header save delegation', () => {
    let controller, sidebarContent;

    beforeEach(() => {
        ({ controller, sidebarContent } = setupController());
        // Add a header save button to the shell as the template would.
        const headerBtn = document.createElement('button');
        headerBtn.className = 'cb-shell__sidebar-save';
        controller.element.appendChild(headerBtn);
        window.matchMedia = vi.fn(() => ({ matches: false, addEventListener() {} }));
    });

    it('saveSidebar clicks the form button marked [data-cb-sidebar-save]', () => {
        const inFormBtn = document.createElement('button');
        inFormBtn.dataset.cbSidebarSave = '';
        const clickSpy = vi.fn();
        inFormBtn.addEventListener('click', clickSpy);
        sidebarContent.appendChild(inFormBtn);

        controller.saveSidebar({ preventDefault: () => {} });

        expect(clickSpy).toHaveBeenCalled();
    });

    it('saveSidebar is a no-op when no [data-cb-sidebar-save] target exists', () => {
        // No target inserted — saveSidebar should bail without throwing.
        expect(() => controller.saveSidebar({ preventDefault: () => {} })).not.toThrow();
    });

    it('saveSidebar blurs the focused sidebar input before clicking save (flushes Live model on(change))', () => {
        // Repro: Live Component model bindings sync `on(change)`. If the
        // user types in an input then clicks the header Save button, the
        // input is still focused at click time. A programmatic .click()
        // does NOT move focus, so without an explicit blur the change
        // event never fires and Live POSTs the previous value.
        const input = document.createElement('input');
        input.type = 'text';
        const blurSpy = vi.fn();
        input.addEventListener('blur', blurSpy);
        sidebarContent.appendChild(input);
        input.focus();
        expect(document.activeElement).toBe(input);

        const clickSpy = vi.fn();
        const inFormBtn = document.createElement('button');
        inFormBtn.dataset.cbSidebarSave = '';
        inFormBtn.addEventListener('click', clickSpy);
        sidebarContent.appendChild(inFormBtn);

        controller.saveSidebar({ preventDefault: () => {} });

        expect(blurSpy).toHaveBeenCalledOnce();
        expect(clickSpy).toHaveBeenCalledOnce();
    });

    it('saveSidebar does not blur an element focused outside the sidebar', () => {
        // Defensive: an unrelated focused input on the page (e.g. host's
        // global search) must not lose focus when the user saves.
        const outsider = document.createElement('input');
        document.body.appendChild(outsider);
        const blurSpy = vi.fn();
        outsider.addEventListener('blur', blurSpy);
        outsider.focus();
        expect(document.activeElement).toBe(outsider);

        const inFormBtn = document.createElement('button');
        inFormBtn.dataset.cbSidebarSave = '';
        sidebarContent.appendChild(inFormBtn);

        controller.saveSidebar({ preventDefault: () => {} });

        expect(blurSpy).not.toHaveBeenCalled();
        document.body.removeChild(outsider);
    });

    it('_refreshSaveButtonState toggles the disabled attr based on form presence', () => {
        const headerBtn = controller.element.querySelector('.cb-shell__sidebar-save');

        // No form mounted yet → disabled.
        controller._refreshSaveButtonState();
        expect(headerBtn.disabled).toBe(true);

        // Add a saveable element → enabled.
        const inFormBtn = document.createElement('button');
        inFormBtn.dataset.cbSidebarSave = '';
        sidebarContent.appendChild(inFormBtn);
        controller._refreshSaveButtonState();
        expect(headerBtn.disabled).toBe(false);
    });
});

describe('cb-builder: action methods', () => {
    let controller, logSpy;

    beforeEach(() => {
        ({ controller } = setupController({ areaId: 99 }));
        logSpy = vi.spyOn(console, 'log').mockImplementation(() => {});
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
        vi.spyOn(console, 'log').mockImplementation(() => {});
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

    it('_deleteBlock issues DELETE and reloads', async () => {
        await controller._deleteBlock(42);
        expect(reqSpy).toHaveBeenCalledWith('DELETE', '/_content-blocks/block/42');
        expect(reloadSpy).toHaveBeenCalled();
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

        vi.spyOn(console, 'log').mockImplementation(() => {});
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
        vi.spyOn(console, 'log').mockImplementation(() => {});
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
        vi.spyOn(console, 'log').mockImplementation(() => {});
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
        vi.spyOn(console, 'log').mockImplementation(() => {});
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
