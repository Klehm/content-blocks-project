import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * The builder's half of the tree conversation: it answers the panel's
 * `cb:tree:*` signals with the endpoints it already owns, and tells the panel
 * when the area moved under it.
 */

function setupController() {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <iframe></iframe>
            <button class="cb-shell__publish"></button>
            <button class="cb-shell__discard" hidden></button>
            <button class="cb-shell__tree-toggle" aria-expanded="false"></button>
            <div class="cb-tree" hidden></div>
            <aside>
                <div class="cb-shell__sidebar-content">__EMPTY__</div>
            </aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const iframe = element.querySelector('iframe');
    const sidebar = element.querySelector('aside');
    const sidebarContent = sidebar.querySelector('.cb-shell__sidebar-content');
    const panel = element.querySelector('.cb-tree');
    const toggle = element.querySelector('.cb-shell__tree-toggle');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasIframeTarget', { value: true });
    Object.defineProperty(controller, 'iframeTarget', { value: iframe });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: sidebar });
    Object.defineProperty(controller, 'hasSidebarContentTarget', { value: true });
    Object.defineProperty(controller, 'sidebarContentTarget', { value: sidebarContent });
    Object.defineProperty(controller, 'areaIdValue', { value: 42 });
    Object.defineProperty(controller, 'iframeUrlValue', { value: 'http://localhost/page/1?cb_preview=1' });
    controller._sidebarEmptyHtml = sidebarContent.innerHTML;

    return { controller, element, iframe, sidebar, panel, toggle };
}

function emit(element, type, detail) {
    element.dispatchEvent(new CustomEvent(type, { bubbles: true, detail }));
}

beforeEach(() => {
    window.matchMedia = vi.fn(() => ({ matches: false, addEventListener() {}, removeEventListener() {} }));
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('cb-builder: acting on tree signals', () => {
    let controller, element;

    beforeEach(() => {
        ({ controller, element } = setupController());
        controller.connect();
        // jsdom cannot navigate, and the preview refresh is not under test.
        controller.reload = vi.fn();
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            json: () => Promise.resolve({ moved: true }),
            text: () => Promise.resolve('<div>sidebar</div>'),
        }));
    });

    afterEach(() => controller.disconnect());

    it('selecting a block mounts its sidebar and brings the preview to it', async () => {
        const posted = [];
        controller._postToPreview = (message) => posted.push(message);

        emit(element, 'cb:tree:select', { kind: 'block', id: 700 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalled());

        expect(global.fetch.mock.calls[0][0]).toBe('/_content-blocks/block/700/edit');
        expect(posted).toEqual([
            { type: 'cb:focus:block', blockId: 700 },
            { type: 'cb:block:scroll-into-view', blockId: 700 },
        ]);
    });

    it('selecting a section mounts its settings and scrolls to it', async () => {
        const posted = [];
        controller._postToPreview = (message) => posted.push(message);

        emit(element, 'cb:tree:select', { kind: 'section', id: 7 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalled());

        expect(global.fetch.mock.calls[0][0]).toBe('/_content-blocks/section/7/settings');
        expect(posted).toEqual([
            { type: 'cb:focus:section', sectionId: 7 },
            { type: 'cb:section:scroll-into-view', sectionId: 7 },
        ]);
    });

    it('a dragged block goes to the move endpoint the preview drag uses', async () => {
        emit(element, 'cb:tree:block:move', { blockId: 700, toColumnId: 71, position: 2 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalled());

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/_content-blocks/block/700/move');
        expect(JSON.parse(init.body)).toEqual({ toColumnId: 71, position: 2 });
    });

    it('a dragged section posts its new index, not a direction', async () => {
        emit(element, 'cb:tree:section:move', { sectionId: 7, position: 0 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalled());

        const [url, init] = global.fetch.mock.calls[0];
        expect(url).toBe('/_content-blocks/section/7/move');
        expect(JSON.parse(init.body)).toEqual({ position: 0 });
    });

    it('duplicate and delete reach the endpoints the overlay toolbar uses', async () => {
        emit(element, 'cb:tree:duplicate', { kind: 'section', id: 7 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
        emit(element, 'cb:tree:delete', { kind: 'block', id: 700 });
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));

        expect(global.fetch.mock.calls[0][0]).toBe('/_content-blocks/section/7/duplicate');
        expect(global.fetch.mock.calls[1][0]).toBe('/_content-blocks/block/700');
        expect(global.fetch.mock.calls[1][1].method).toBe('DELETE');
    });

    it('ignores a signal carrying no usable id', () => {
        emit(element, 'cb:tree:select', { kind: 'block' });
        emit(element, 'cb:tree:delete', { kind: 'section', id: null });

        expect(global.fetch).not.toHaveBeenCalled();
    });
});

describe('cb-builder: telling the tree what changed', () => {
    let controller, element;

    beforeEach(() => {
        ({ controller, element } = setupController());
        controller.connect();
    });

    afterEach(() => controller.disconnect());

    it('every draft-state change invalidates the outline', () => {
        const seen = [];
        element.addEventListener('cb:tree:invalidate', (e) => seen.push(e.detail));

        controller._applyDraftState(true);

        expect(seen).toEqual([{ areaId: 42 }]);
    });

    it('clearing the sidebar broadcasts an empty selection', () => {
        const seen = [];
        element.addEventListener('cb:tree:selection', (e) => seen.push(e.detail));

        controller._resetSidebarToEmptyState();

        expect(seen).toEqual([{ areaId: 42, blockId: null, sectionId: null }]);
    });

    it('mounting a block sidebar broadcasts it as the selection', async () => {
        global.fetch = vi.fn(() => Promise.resolve({
            ok: true,
            text: () => Promise.resolve('<div>sidebar</div>'),
        }));
        const seen = [];
        element.addEventListener('cb:tree:selection', (e) => seen.push(e.detail));

        await controller._mountSidebar(700);

        expect(seen).toEqual([{ areaId: 42, blockId: 700, sectionId: null }]);
    });
});

describe('cb-builder: the topbar button and the panel beside main', () => {
    let controller, element, panel, toggle;

    beforeEach(() => {
        ({ controller, element, panel, toggle } = setupController());
        controller.connect();
    });

    afterEach(() => controller.disconnect());

    it('the toggle relays to the panel, which is outside its scope', () => {
        const seen = [];
        element.addEventListener('cb:tree:toggle', (e) => seen.push(e.detail));

        controller.toggleTree({ preventDefault: () => {} });

        expect(seen).toEqual([{ areaId: 42 }]);
    });

    it('the panel reporting its state moves the button with it', () => {
        element.dispatchEvent(new CustomEvent('cb:tree:state', {
            bubbles: true,
            detail: { areaId: 42, open: true },
        }));

        expect(toggle.getAttribute('aria-expanded')).toBe('true');
        expect(toggle.classList.contains('cb-shell__tree-toggle--open')).toBe(true);
    });

    it('Escape closes an open outline, after every real modal', () => {
        panel.hidden = false;
        const seen = [];
        element.addEventListener('cb:tree:close', (e) => seen.push(e.detail));

        expect(controller._closeTopModal()).toBe(true);
        expect(seen).toEqual([{ areaId: 42 }]);
    });

    it('Escape does nothing when the outline is shut', () => {
        expect(controller._closeTopModal()).toBe(false);
    });
});
