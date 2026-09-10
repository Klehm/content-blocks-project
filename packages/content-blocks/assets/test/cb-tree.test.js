import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-tree_controller.js';

/**
 * Unit tests for the outline panel. The Stimulus runtime isn't booted — we
 * instantiate the class directly and stub the framework-supplied
 * targets/values, mirroring the cb-builder suites.
 */

function setupController(options = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder">
            <button class="cb-shell__tree-toggle" aria-expanded="false"></button>
            <div class="cb-tree"
                 data-i18n-cb-builder-tree-empty="Nothing here yet"
                 data-i18n-cb-builder-tree-error="Failed to load the outline."
                 data-i18n-cb-builder-tree-delete="Delete"
                 hidden>
                <header class="cb-tree__header">
                    <h3 class="cb-tree__title">Content navigator</h3>
                    <button class="cb-tree__close"></button>
                </header>
                <ul class="cb-tree__list"></ul>
                <p class="cb-tree__status"></p>
            </div>
        </div>
    `;
    const shell = document.querySelector('[data-controller="cb-builder"]');
    const element = shell.querySelector('.cb-tree');
    const list = element.querySelector('.cb-tree__list');
    const status = element.querySelector('.cb-tree__status');
    const handle = element.querySelector('.cb-tree__header');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasListTarget', { value: true });
    Object.defineProperty(controller, 'listTarget', { value: list });
    Object.defineProperty(controller, 'hasStatusTarget', { value: true });
    Object.defineProperty(controller, 'statusTarget', { value: status });
    Object.defineProperty(controller, 'hasHandleTarget', { value: true });
    Object.defineProperty(controller, 'handleTarget', { value: handle });
    Object.defineProperty(controller, 'areaIdValue', { value: options.areaId ?? 42 });

    // jsdom lays nothing out: the panel measures its shell through
    // offsetParent, and both come back as zeroes without these.
    Object.defineProperty(element, 'offsetParent', { value: shell, configurable: true });
    Object.defineProperty(element, 'offsetWidth', { value: 320, configurable: true });
    Object.defineProperty(element, 'offsetHeight', { value: 400, configurable: true });
    shell.getBoundingClientRect = () => ({ left: 0, top: 0, width: 1000, height: 800 });
    element.getBoundingClientRect = () => ({
        left: parseFloat(element.style.left) || 356,
        top: parseFloat(element.style.top) || 68,
        width: 320,
        height: 400,
    });

    return { controller, shell, element, panel: element, list, status, handle };
}

/** One section, two columns, one block in the first. */
function sampleTree() {
    return {
        areaId: 42,
        sections: [{
            id: 7,
            layout: 'two_cols',
            label: 'Section 1 — 2 columns',
            columns: [
                {
                    id: 70,
                    preset: 'col-6',
                    label: 'Column 1',
                    blocks: [{
                        id: 700,
                        type: 'title',
                        typeLabel: 'Title',
                        label: 'Welcome',
                        kind: 'heading',
                        icon: '<svg data-test="title-icon"></svg>',
                        missing: false,
                    }],
                },
                { id: 71, preset: 'col-6', label: 'Column 2', blocks: [] },
            ],
        }],
    };
}

function okJson(body) {
    return Promise.resolve({ ok: true, json: () => Promise.resolve(body) });
}

/** Collects every bubbled `cb:tree:*` event the panel emits. */
function listen(shell, type) {
    const seen = [];
    shell.addEventListener(type, (e) => seen.push(e.detail));

    return seen;
}

beforeEach(() => {
    // jsdom implements neither, and both are called on selection.
    Element.prototype.scrollIntoView = vi.fn();
    window.localStorage.clear();
});

afterEach(() => {
    vi.restoreAllMocks();
});

describe('cb-tree: loading and painting', () => {
    let controller, list, status;

    beforeEach(() => {
        ({ controller, list, status } = setupController());
        controller.connect();
    });

    afterEach(() => controller.disconnect());

    it('paints sections, columns and blocks from the tree endpoint', async () => {
        global.fetch = vi.fn(() => okJson(sampleTree()));

        await controller.reload();

        expect(global.fetch).toHaveBeenCalledWith(
            '/_content-blocks/area/42/tree',
            expect.objectContaining({ credentials: 'same-origin' }),
        );
        expect(list.querySelectorAll('.cb-tree__section')).toHaveLength(1);
        expect(list.querySelectorAll('.cb-tree__blocks')).toHaveLength(2);
        expect(list.querySelector('.cb-tree__block .cb-tree__name').textContent).toBe('Welcome');
    });

    it('says the block type as a glyph, not as a second word', async () => {
        global.fetch = vi.fn(() => okJson(sampleTree()));

        await controller.reload();

        const row = list.querySelector('.cb-tree__block .cb-tree__row');
        expect(row.querySelector('.cb-tree__icon svg')).toHaveProperty(
            'dataset.test',
            'title-icon',
        );
        expect(row.querySelector('.cb-tree__icon').title).toBe('Title');
        // The type used to be repeated as a chip beside a label that is
        // often the type itself.
        expect(row.querySelector('.cb-tree__chip')).toBeNull();
    });

    it('falls back to a generic glyph for a type that ships none', async () => {
        const tree = sampleTree();
        tree.sections[0].columns[0].blocks[0].icon = null;
        global.fetch = vi.fn(() => okJson(tree));

        await controller.reload();

        expect(list.querySelector('.cb-tree__icon svg')).not.toBeNull();
    });

    it('flags a block whose type this build does not have', async () => {
        const tree = sampleTree();
        tree.sections[0].columns[0].blocks[0].missing = true;
        global.fetch = vi.fn(() => okJson(tree));

        await controller.reload();

        expect(list.querySelector('.cb-tree__icon--missing')).not.toBeNull();
    });

    it('shows the empty message rather than an empty frame', async () => {
        global.fetch = vi.fn(() => okJson({ areaId: 42, sections: [] }));

        await controller.reload();

        expect(list.children).toHaveLength(0);
        expect(status.textContent).toBe('Nothing here yet');
    });

    it('says so when the outline cannot be loaded', async () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});
        global.fetch = vi.fn(() => Promise.resolve({ ok: false, status: 500 }));

        await controller.reload();

        expect(status.textContent).toBe('Failed to load the outline.');
    });

    it('a column carries its preset but no actions of its own', async () => {
        global.fetch = vi.fn(() => okJson(sampleTree()));

        await controller.reload();

        const columnRow = list.querySelector('.cb-tree__row--column');
        expect(columnRow.querySelector('.cb-tree__chip').textContent).toBe('col-6');
        expect(columnRow.querySelector('.cb-tree__act')).toBeNull();
    });
});

describe('cb-tree: open and close', () => {
    let controller, shell, panel;

    beforeEach(() => {
        ({ controller, shell, panel } = setupController());
        global.fetch = vi.fn(() => okJson(sampleTree()));
    });

    afterEach(() => controller.disconnect());

    function toggle() {
        document.dispatchEvent(new CustomEvent('cb:tree:toggle', { detail: { areaId: 42 } }));
    }

    it('starts closed and loads nothing until opened', () => {
        controller.connect();

        expect(panel.hidden).toBe(true);
        expect(global.fetch).not.toHaveBeenCalled();
    });

    it('the relayed toggle opens it and loads the outline', async () => {
        controller.connect();
        const states = listen(shell, 'cb:tree:state');

        toggle();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

        expect(panel.hidden).toBe(false);
        expect(states).toEqual([{ open: true, areaId: 42 }]);
    });

    it('a second toggle shuts it, and says so for the topbar button', async () => {
        controller.connect();
        toggle();
        await vi.waitFor(() => expect(controller._tree).not.toBeNull());
        const states = listen(shell, 'cb:tree:state');

        toggle();

        expect(panel.hidden).toBe(true);
        expect(states).toEqual([{ open: false, areaId: 42 }]);
    });

    it('reopens where it was left, and reloads only if invalidated meanwhile', async () => {
        controller.connect();
        controller.open();
        await vi.waitFor(() => expect(controller._tree).not.toBeNull());

        controller.close();
        controller.open();

        expect(global.fetch).toHaveBeenCalledTimes(1);
    });

    it('remembers being open across a rebuild of the panel', async () => {
        controller.connect();
        controller.open();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
        controller.disconnect();

        const next = setupController();
        next.controller.connect();

        expect(next.panel.hidden).toBe(false);
        next.controller.disconnect();
    });

    it('cb:tree:close shuts it, which is how Escape reaches the panel', async () => {
        controller.connect();
        controller.open();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

        document.dispatchEvent(new CustomEvent('cb:tree:close', { detail: { areaId: 42 } }));

        expect(panel.hidden).toBe(true);
    });

    it('ignores a broadcast meant for another area', async () => {
        controller.connect();
        controller.open();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

        document.dispatchEvent(new CustomEvent('cb:tree:close', { detail: { areaId: 999 } }));

        expect(panel.hidden).toBe(false);
    });
});

describe('cb-tree: moving the panel', () => {
    let controller, element, handle;

    beforeEach(() => {
        ({ controller, element, handle } = setupController());
        global.fetch = vi.fn(() => okJson(sampleTree()));
        controller.connect();
        controller.open();
    });

    afterEach(() => controller.disconnect());

    function drag(from, to) {
        controller.startDrag({
            target: handle,
            pointerId: 1,
            clientX: from.x,
            clientY: from.y,
            preventDefault: () => {},
        });
        // jsdom has no PointerEvent; the listeners key off the type name.
        document.dispatchEvent(new MouseEvent('pointermove', { clientX: to.x, clientY: to.y }));
        document.dispatchEvent(new MouseEvent('pointerup', {}));
    }

    it('parks the panel where the header was dropped', () => {
        // Grabbed 20px into the header, so the panel lands 20px left of the
        // pointer — the grab point stays under the cursor.
        drag({ x: 376, y: 88 }, { x: 600, y: 300 });

        expect(element.style.left).toBe('580px');
        expect(element.style.top).toBe('280px');
    });

    it('remembers the position for the next builder session', () => {
        drag({ x: 376, y: 88 }, { x: 600, y: 300 });

        const next = setupController();
        next.controller.connect();
        next.controller.open();

        expect(next.element.style.left).toBe('580px');
        next.controller.disconnect();
    });

    it('keeps a panel dragged past the edge reachable', () => {
        drag({ x: 376, y: 88 }, { x: 5000, y: 5000 });

        // Shell 1000×800, panel 320×400, 8px margin.
        expect(element.style.left).toBe('672px');
        expect(element.style.top).toBe('392px');
    });

    it('does not start a drag from the close button', () => {
        const close = element.querySelector('.cb-tree__close');

        controller.startDrag({
            target: close,
            pointerId: 1,
            clientX: 600,
            clientY: 88,
            preventDefault: () => {},
        });
        document.dispatchEvent(new MouseEvent('pointermove', { clientX: 900, clientY: 400 }));

        expect(element.style.left).toBe('');
    });

    it('leaves the CSS default in charge until the panel is moved', () => {
        expect(element.style.left).toBe('');
        expect(element.style.top).toBe('');
    });

    it('re-clamps a parked panel when the window shrinks', () => {
        drag({ x: 376, y: 88 }, { x: 900, y: 600 });
        controller.element.offsetParent.getBoundingClientRect =
            () => ({ left: 0, top: 0, width: 500, height: 800 });

        window.dispatchEvent(new Event('resize'));

        expect(element.style.left).toBe('172px');
    });
});

describe('cb-tree: invalidation', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
        global.fetch = vi.fn(() => okJson(sampleTree()));
        controller.connect();
    });

    afterEach(() => controller.disconnect());

    it('a closed panel notes the change without fetching', () => {
        document.dispatchEvent(new CustomEvent('cb:tree:invalidate', { detail: { areaId: 42 } }));

        expect(global.fetch).not.toHaveBeenCalled();
        expect(controller._dirty).toBe(true);
    });

    it('the next open picks the change up', async () => {
        document.dispatchEvent(new CustomEvent('cb:tree:invalidate', { detail: { areaId: 42 } }));

        controller.open();

        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));
    });

    it('an open panel repaints, once, after a burst', async () => {
        vi.useFakeTimers();
        controller.open();
        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(1));

        for (let i = 0; i < 5; i++) {
            document.dispatchEvent(new CustomEvent('cb:tree:invalidate', { detail: { areaId: 42 } }));
        }
        vi.advanceTimersByTime(Controller.REFRESH_DEBOUNCE_MS);
        vi.useRealTimers();

        await vi.waitFor(() => expect(global.fetch).toHaveBeenCalledTimes(2));
    });
});

describe('cb-tree: acting on a node', () => {
    let controller, shell, list;

    beforeEach(async () => {
        ({ controller, shell, list } = setupController());
        global.fetch = vi.fn(() => okJson(sampleTree()));
        controller.connect();
        await controller.reload();
    });

    afterEach(() => controller.disconnect());

    it('clicking a block row asks for it to be selected', () => {
        const seen = listen(shell, 'cb:tree:select');

        list.querySelector('.cb-tree__block .cb-tree__name').click();

        expect(seen).toEqual([{ kind: 'block', id: 700, areaId: 42 }]);
    });

    it('clicking a section row asks for the section', () => {
        const seen = listen(shell, 'cb:tree:select');

        list.querySelector('.cb-tree__row--section .cb-tree__name').click();

        expect(seen).toEqual([{ kind: 'section', id: 7, areaId: 42 }]);
    });

    it('duplicate and delete emit their own events, not a selection', () => {
        const selected = listen(shell, 'cb:tree:select');
        const duplicated = listen(shell, 'cb:tree:duplicate');
        const deleted = listen(shell, 'cb:tree:delete');
        const actions = list.querySelectorAll('.cb-tree__block .cb-tree__act');

        actions[0].click();
        actions[1].click();

        expect(duplicated).toEqual([{ kind: 'block', id: 700, areaId: 42 }]);
        expect(deleted).toEqual([{ kind: 'block', id: 700, areaId: 42 }]);
        expect(selected).toEqual([]);
    });

    it('collapsing a section hides its columns and survives a repaint', () => {
        const twisty = list.querySelector('.cb-tree__row--section .cb-tree__twisty');

        twisty.click();

        expect(list.querySelector('.cb-tree__children').hidden).toBe(true);
        controller._paint();
        expect(list.querySelector('.cb-tree__children').hidden).toBe(true);
    });
});

describe('cb-tree: selection follows the sidebar', () => {
    let controller, list;

    beforeEach(async () => {
        ({ controller, list } = setupController());
        global.fetch = vi.fn(() => okJson(sampleTree()));
        controller.connect();
        await controller.reload();
    });

    afterEach(() => controller.disconnect());

    function select(detail) {
        document.dispatchEvent(new CustomEvent('cb:tree:selection', {
            detail: { areaId: 42, ...detail },
        }));
    }

    it('highlights the block the sidebar has open', () => {
        select({ blockId: 700, sectionId: null });

        const row = list.querySelector('.cb-tree__block .cb-tree__row');
        expect(row.classList.contains('cb-tree__row--selected')).toBe(true);
    });

    it('a block wins over a section, the way the clipboard reads it', () => {
        select({ blockId: 700, sectionId: 7 });

        expect(list.querySelectorAll('.cb-tree__row--selected')).toHaveLength(1);
        expect(
            list.querySelector('.cb-tree__block .cb-tree__row')
                .classList.contains('cb-tree__row--selected'),
        ).toBe(true);
    });

    it('clearing the sidebar clears the highlight', () => {
        select({ blockId: 700 });
        select({ blockId: null, sectionId: null });

        expect(list.querySelectorAll('.cb-tree__row--selected')).toHaveLength(0);
    });

    it('a repaint keeps the highlight', async () => {
        select({ sectionId: 7 });

        await controller.reload();

        expect(
            list.querySelector('.cb-tree__row--section')
                .classList.contains('cb-tree__row--selected'),
        ).toBe(true);
    });
});
