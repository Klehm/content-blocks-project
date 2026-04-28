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
            <button class="cb-shell__viewport-btn cb-shell__viewport-btn--active"></button>
            <button class="cb-shell__viewport-btn"></button>
            <iframe></iframe>
            <aside hidden></aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const iframe = element.querySelector('iframe');
    const sidebar = element.querySelector('aside');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasIframeTarget', { value: true });
    Object.defineProperty(controller, 'iframeTarget', { value: iframe });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: sidebar });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });
    Object.defineProperty(controller, 'iframeUrlValue', { value: options.iframeUrl ?? 'http://localhost/page/1?cb_preview=1' });

    return { controller, element, iframe, sidebar };
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

describe('cb-builder: sidebar mount/unmount', () => {
    let controller, sidebar;

    beforeEach(() => {
        ({ controller, sidebar } = setupController());
        vi.spyOn(console, 'log').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    it('_mountSidebar fetches the block edit URL and injects HTML', async () => {
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
        expect(sidebar.innerHTML).toBe(html);
        expect(sidebar.hidden).toBe(false);
        expect(sidebar.dataset.cbSidebarBlockId).toBe('42');
    });

    it('_mountSidebar does not inject HTML on non-OK response', async () => {
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 404 }));

        await controller._mountSidebar(99);

        expect(sidebar.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
    });

    it('_unmountSidebar clears HTML, hides, and forgets the block id', () => {
        sidebar.innerHTML = '<div>X</div>';
        sidebar.hidden = false;
        sidebar.dataset.cbSidebarBlockId = '42';

        controller._unmountSidebar();

        expect(sidebar.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
        expect(sidebar.dataset.cbSidebarBlockId).toBeUndefined();
    });

    it('cb:block:saved event unmounts and reloads the iframe', () => {
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
        sidebar.innerHTML = '<form>x</form>';
        sidebar.hidden = false;

        controller._onBlockSaved({ detail: { blockId: 42 } });

        expect(sidebar.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
        expect(reloadSpy).toHaveBeenCalled();
    });

    it('cb:block:cancel event unmounts but does NOT reload', () => {
        const reloadSpy = vi.spyOn(controller, 'reload').mockImplementation(() => {});
        sidebar.innerHTML = '<form>x</form>';
        sidebar.hidden = false;

        controller._onBlockCancel({ detail: { blockId: 42 } });

        expect(sidebar.innerHTML).toBe('');
        expect(sidebar.hidden).toBe(true);
        expect(reloadSpy).not.toHaveBeenCalled();
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
        // Add a topbar Discard button + launcher badge so we can verify side-effects.
        const discard = document.createElement('button');
        discard.className = 'cb-shell__discard';
        discard.disabled = false;
        controller.element.appendChild(discard);

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

    it('_applyDraftState toggles Discard button and removes the badge when clean', () => {
        const discardBtn = controller.element.querySelector('.cb-shell__discard');
        const badge = document.querySelector('.cb-launcher__badge');

        controller._applyDraftState(false);

        expect(discardBtn.disabled).toBe(true);
        expect(document.querySelector('.cb-launcher__badge')).toBeNull();
        expect(badge.isConnected).toBe(false);
    });

    it('_applyDraftState enables Discard when the area is dirty', () => {
        const discardBtn = controller.element.querySelector('.cb-shell__discard');
        discardBtn.disabled = true;

        controller._applyDraftState(true);

        expect(discardBtn.disabled).toBe(false);
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
