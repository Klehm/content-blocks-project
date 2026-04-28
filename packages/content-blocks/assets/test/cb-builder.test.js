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

    it.each([
        'cb:block:delete-requested',
        'cb:block:add-requested',
        'cb:block:reorder',
        'cb:section:move-requested',
        'cb:section:delete-requested',
    ])('routes %s', (type) => {
        const payload = { type, foo: 'bar' };
        controller._onMessage(postMessage(payload));
        expect(logSpy).toHaveBeenCalled();
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

    it('publish logs intent with areaId', () => {
        controller.publish();
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] publish requested', { areaId: 99 });
    });

    it('discard logs intent with areaId', () => {
        controller.discard();
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] discard requested', { areaId: 99 });
    });

    it('addSection reads the layout from event.params and logs', () => {
        controller.addSection({ params: { layout: 'two_cols' }, preventDefault: () => {} });
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] addSection', { areaId: 99, layout: 'two_cols' });
    });

    it('addSection defaults to "full" when layout is missing', () => {
        controller.addSection();
        expect(logSpy).toHaveBeenCalledWith('[cb-builder] addSection', { areaId: 99, layout: 'full' });
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
