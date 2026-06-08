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

function setup({ name = 'content_block[items]', count = 3 } = {}) {
    const items = Array.from({ length: count }, (_, i) =>
        `<div class="cb-form-collection__item">
            <div class="cb-form-collection__controls">
                <button class="cb-form-collection__drag-handle"></button>
                <button class="cb-form-collection__move--up" data-action="cb-collection-sort#moveUp">▲</button>
                <button class="cb-form-collection__move--down" data-action="cb-collection-sort#moveDown">▼</button>
            </div>
            <input name="items[${i}][label]" value="v${i}">
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
});
