import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-collection-sort_controller.js';
import { __setMockComponent } from './__stubs__/ux-live-component.js';

/**
 * Vitest unit tests for cb-collection-sort.
 *
 * The Stimulus runtime is not booted and SortableJS (a CDN dynamic import)
 * is never loaded — we drive the controller's reorder logic directly:
 * the keyboard up/down handlers and the live-action dispatch. The Live
 * component is faked via the ux-live-component stub.
 */

function setup({ name = 'content_block[items]', count = 3, collapsed = [] } = {}) {
    const items = Array.from({ length: count }, (_, i) =>
        `<div class="cb-form-collection__item${collapsed[i] ? ' cb-form-collection__item--collapsed' : ''}">
            <div class="cb-form-collection__controls">
                <button class="cb-form-collection__drag-handle"></button>
                <button class="cb-form-collection__toggle" aria-expanded="true"></button>
                <button class="cb-form-collection__move--up" data-action="cb-collection-sort#moveUp">▲</button>
                <button class="cb-form-collection__move--down" data-action="cb-collection-sort#moveDown">▼</button>
                <button class="cb-form-collection__duplicate" data-action="cb-collection-sort#duplicate">⧉</button>
            </div>
            <div class="cb-form-collection__item-body">
                <input name="items[${i}][label]" value="v${i}">
            </div>
            <button class="cb-form-collection__delete"></button>
        </div>`,
    ).join('');

    // Wrap in a Live component root: _move() resolves the action target by
    // walking up to the nearest [data-controller~="live"] ancestor.
    document.body.innerHTML = `<div data-controller="live"><div id="content_block_items">${items}</div></div>`;
    const element = document.querySelector('#content_block_items');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'nameValue', { value: name });

    const action = vi.fn();
    __setMockComponent({ action });

    return { controller, element, action, name };
}

const up = (item) => item.querySelector('.cb-form-collection__move--up');
const down = (item) => item.querySelector('.cb-form-collection__move--down');
const dup = (item) => item.querySelector('.cb-form-collection__duplicate');
const fold = (item) => item.querySelector('.cb-form-collection__toggle');
const folded = (element) => Array.from(
    element.querySelectorAll('.cb-form-collection__item'),
    (item) => item.classList.contains('cb-form-collection__item--collapsed'),
);

describe('cb-collection-sort', () => {
    beforeEach(() => {
        __setMockComponent(null);
    });

    it('_indexOf resolves the position of the item containing the clicked button', () => {
        const { controller, element } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');
        expect(controller._indexOf(up(items[0]))).toBe(0);
        expect(controller._indexOf(down(items[2]))).toBe(2);
    });

    it('moveDown dispatches moveCollectionItem with from/to and the field name', async () => {
        const { controller, element, action, name } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.moveDown({ currentTarget: down(items[0]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).toHaveBeenCalledTimes(1);
        expect(action).toHaveBeenCalledWith('moveCollectionItem', { name, from: 0, to: 1 });
    });

    it('moveUp dispatches moveCollectionItem moving the entry one slot up', async () => {
        const { controller, element, action, name } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.moveUp({ currentTarget: up(items[2]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).toHaveBeenCalledWith('moveCollectionItem', { name, from: 2, to: 1 });
    });

    it('moveUp on the first entry is a no-op (no boundary wrap)', async () => {
        const { controller, element, action } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.moveUp({ currentTarget: up(items[0]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).not.toHaveBeenCalled();
    });

    it('moveDown on the last entry is a no-op', async () => {
        const { controller, element, action } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.moveDown({ currentTarget: down(items[2]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).not.toHaveBeenCalled();
    });

    it('_move ignores a same-position drop', async () => {
        const { controller, action } = setup({ count: 3 });

        controller._move(1, 1);
        await Promise.resolve();
        await Promise.resolve();

        expect(action).not.toHaveBeenCalled();
    });

    it('_move forwards an arbitrary drag (e.g. last → first)', async () => {
        const { controller, action, name } = setup({ count: 4 });

        controller._move(3, 0);
        await Promise.resolve();
        await Promise.resolve();

        expect(action).toHaveBeenCalledWith('moveCollectionItem', { name, from: 3, to: 0 });
    });

    it('duplicate dispatches duplicateCollectionItem with the clicked index and field name', async () => {
        const { controller, element, action, name } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.duplicate({ currentTarget: dup(items[1]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).toHaveBeenCalledTimes(1);
        expect(action).toHaveBeenCalledWith('duplicateCollectionItem', { name, index: 1 });
    });

    it('duplicate works on the last entry', async () => {
        const { controller, element, action, name } = setup({ count: 3 });
        const items = element.querySelectorAll('.cb-form-collection__item');

        controller.duplicate({ currentTarget: dup(items[2]) });
        await Promise.resolve();
        await Promise.resolve();

        expect(action).toHaveBeenCalledWith('duplicateCollectionItem', { name, index: 2 });
    });

    it('_duplicate is a no-op when there is no Live component in scope', async () => {
        const { controller, action } = setup({ count: 2 });
        // Strip the live root so getComponent has nothing to resolve.
        document.body.innerHTML = '';
        document.body.appendChild(controller.element);

        controller._duplicate(0);
        await Promise.resolve();
        await Promise.resolve();

        expect(action).not.toHaveBeenCalled();
    });

    describe('folding', () => {
        it('toggle folds only the clicked entry and flips aria-expanded', () => {
            const { controller, element } = setup({ count: 3 });
            const items = element.querySelectorAll('.cb-form-collection__item');

            controller.toggle({ currentTarget: fold(items[1]) });

            expect(folded(element)).toEqual([false, true, false]);
            expect(fold(items[1]).getAttribute('aria-expanded')).toBe('false');
            expect(fold(items[0]).getAttribute('aria-expanded')).toBe('true');

            controller.toggle({ currentTarget: fold(items[1]) });
            expect(folded(element)).toEqual([false, false, false]);
        });

        it('collapseAll and expandAll fold and unfold every entry', () => {
            const { controller, element } = setup({ count: 3 });

            controller.collapseAll();
            expect(folded(element)).toEqual([true, true, true]);

            controller.expandAll();
            expect(folded(element)).toEqual([false, false, false]);
        });

        it('a folded entry stays folded where a move takes it', () => {
            const { controller, element } = setup({ count: 3 });
            const items = element.querySelectorAll('.cb-form-collection__item');
            controller.toggle({ currentTarget: fold(items[0]) });

            // Keyboard path: the DOM keeps its order until Live re-renders.
            controller._move(0, 2);
            controller._applyCollapsed();

            expect(folded(element)).toEqual([false, false, true]);
        });

        it('a duplicate opens unfolded right after its original', () => {
            const { controller, element } = setup({ count: 2 });
            controller.collapseAll();

            controller._duplicate(0);
            // What Live renders: one more entry at the end.
            const extra = element.querySelector('.cb-form-collection__item').cloneNode(true);
            extra.classList.remove('cb-form-collection__item--collapsed');
            element.appendChild(extra);
            controller._applyCollapsed();

            expect(folded(element)).toEqual([true, false, true]);
        });

        it('deleting an entry drops its folded state', () => {
            const { controller, element } = setup({ count: 3 });
            const items = element.querySelectorAll('.cb-form-collection__item');
            controller.toggle({ currentTarget: fold(items[2]) });

            controller._forgetDeleted({ target: items[0].querySelector('.cb-form-collection__delete') });
            items[0].remove();
            controller._applyCollapsed();

            expect(folded(element)).toEqual([false, true]);
        });

        it('starts from the folds the server rendered', () => {
            const { controller, element } = setup({ count: 3, collapsed: [true, true, false] });

            controller.toggle({ currentTarget: fold(element.querySelectorAll('.cb-form-collection__item')[0]) });

            expect(folded(element)).toEqual([false, true, false]);
        });

        it('an entry added after connect opens, whatever the server folds', async () => {
            const { controller, element } = setup({ count: 2, collapsed: [true, true] });
            const hooks = {};
            __setMockComponent({ action: vi.fn(), on: (name, cb) => { hooks[name] = cb; }, off: vi.fn() });
            controller.connect();
            await Promise.resolve();
            await Promise.resolve();

            // What Live renders under `cb_open_entries: none`: all folded.
            element.appendChild(element.querySelector('.cb-form-collection__item').cloneNode(true));
            hooks['render:finished']();

            expect(folded(element)).toEqual([true, true, false]);
            controller.disconnect();
        });

        it('re-applies the folded state after every Live render', async () => {
            const { controller, element } = setup({ count: 2 });
            const hooks = {};
            __setMockComponent({ action: vi.fn(), on: (name, cb) => { hooks[name] = cb; }, off: vi.fn() });
            controller.connect();
            await Promise.resolve();
            await Promise.resolve();

            controller.collapseAll();
            // A morph that resets the markup to what the server rendered.
            element.querySelectorAll('.cb-form-collection__item')
                .forEach((item) => item.classList.remove('cb-form-collection__item--collapsed'));
            hooks['render:finished']();

            expect(folded(element)).toEqual([true, true]);
            controller.disconnect();
        });
    });
});
