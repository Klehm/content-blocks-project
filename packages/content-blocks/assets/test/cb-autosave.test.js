import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-autosave_controller.js';

/**
 * Vitest unit tests for cb-autosave.
 * Stimulus runtime is not booted — we wire the listeners by calling
 * connect() directly and dispatch real DOM events on the form.
 */

function setup({ debounce = 250 } = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-autosave">
            <form>
                <input type="text" name="title" id="title">
                <textarea name="body" id="body"></textarea>
                <button type="button" data-cb-sidebar-save id="save">Save</button>
            </form>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-autosave"]');
    const input = element.querySelector('#title');
    const textarea = element.querySelector('#body');
    const saveBtn = element.querySelector('#save');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'debounceValue', { value: debounce });
    controller.connect();

    const clickSpy = vi.fn();
    saveBtn.addEventListener('click', clickSpy);

    return { controller, element, input, textarea, saveBtn, clickSpy };
}

/**
 * Variant with a LiveCollection-style list inside the form, used to exercise
 * the MutationObserver path. Real timers are used by these tests because the
 * observer delivers on a microtask, which fake timers don't drive.
 */
function setupCollection({ debounce = 20 } = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-autosave">
            <form>
                <input type="text" name="title" id="title" value="t">
                <div data-collection>
                    <div class="cb-item"><input name="items[0][label]" value="a"></div>
                    <div class="cb-item"><input name="items[1][label]" value="b"></div>
                </div>
                <button type="button" data-cb-sidebar-save id="save">Save</button>
            </form>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-autosave"]');
    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'debounceValue', { value: debounce });
    controller.connect();

    const clickSpy = vi.fn();
    element.querySelector('#save').addEventListener('click', clickSpy);

    return { controller, element, clickSpy, debounce };
}

const flush = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

describe('cb-autosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces input events before clicking the save trigger', () => {
        const { input, clickSpy } = setup({ debounce: 250 });

        input.value = 'a';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(100);
        // Still within the debounce window — no save yet.
        expect(clickSpy).not.toHaveBeenCalled();

        input.value = 'ab';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(100);
        // The second input reset the timer; still no save.
        expect(clickSpy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        // 200ms after the second input → past the 250ms debounce.
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('change event triggers an immediate save when the value changed', () => {
        const { input, clickSpy } = setup();

        input.value = 'hello';
        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('focusout triggers an immediate save when the value changed', () => {
        const { input, clickSpy } = setup();

        input.value = 'hello';
        input.dispatchEvent(new Event('focusout', { bubbles: true }));

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('a pending debounce is cancelled when an immediate save fires', () => {
        const { input, clickSpy } = setup({ debounce: 250 });

        input.value = 'a';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(100);
        // Blur fires before the debounce elapses — should save once.
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(1);

        // Advancing past the original debounce must not produce a
        // second save (the timer was cancelled).
        vi.advanceTimersByTime(500);
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('dispatches a synthetic change event on the focused field before saving', () => {
        const { input, clickSpy } = setup({ debounce: 100 });
        input.focus();
        expect(document.activeElement).toBe(input);

        // Track *all* change events fired on the input.
        const changes = [];
        input.addEventListener('change', () => changes.push(Date.now()));

        input.value = 'hello';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(120);

        // The synthetic change was dispatched once, just before the
        // save click. (Without this dispatch, Live Component model
        // bindings would POST the pre-edit value.)
        expect(changes.length).toBeGreaterThanOrEqual(1);
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('does NOT re-dispatch change on a focused file input (would loop the upload)', () => {
        // Regression: cb-file-upload listens for `change` on the file input
        // and re-uploads on each one. If autosave synthesised a `change`
        // here, every save would re-trigger the upload — which writes a new
        // hidden src and fires another save — looping forever.
        document.body.innerHTML = `
            <div data-controller="cb-autosave" data-cb-csrf-token="x">
                <form>
                    <input type="file" id="file">
                    <input type="hidden" name="src" id="src">
                    <button type="button" data-cb-sidebar-save id="save">Save</button>
                </form>
            </div>
        `;
        const element = document.querySelector('[data-controller="cb-autosave"]');
        const controller = new Controller();
        Object.defineProperty(controller, 'element', { value: element });
        Object.defineProperty(controller, 'debounceValue', { value: 100 });
        controller.connect();

        const fileInput = element.querySelector('#file');
        const hidden = element.querySelector('#src');
        fileInput.focus();
        expect(document.activeElement).toBe(fileInput);

        const fileChanges = vi.fn();
        fileInput.addEventListener('change', fileChanges);
        const clickSpy = vi.fn();
        element.querySelector('#save').addEventListener('click', clickSpy);

        // Simulate cb-file-upload committing an upload result: the hidden
        // input changes, which bubbles to autosave and triggers a save.
        hidden.value = '/uploads/abc.jpg';
        hidden.dispatchEvent(new Event('change', { bubbles: true }));

        // The save happened…
        expect(clickSpy).toHaveBeenCalledTimes(1);
        // …but NO synthetic change leaked onto the file input, so the
        // upload controller is never re-triggered. No loop.
        expect(fileChanges).not.toHaveBeenCalled();
    });

    it('skips the save when the serialized form is identical to the last snapshot', () => {
        const { input, clickSpy } = setup();

        // First save: value changes, save fires once.
        input.value = 'hello';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(1);

        // Second trigger (focusout / change / Enter) without a real
        // value change — should be deduped so we don't reload the
        // iframe for nothing.
        input.dispatchEvent(new Event('focusout', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(1);

        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('does not save when only a box-spacing [linked] toggle flips after the snapshot', () => {
        // Regression: cb-spacing-link engages the link on connect when the four
        // sides are uniform (a freshly-focused block), checking a hidden
        // [linked] checkbox AFTER cb-autosave's baseline snapshot. That flag is
        // a UI-only convenience re-derived on load, so flipping it must not look
        // like a user edit and trip a spurious save (which would hot-reload the
        // block on mere focus).
        document.body.innerHTML = `
            <div data-controller="cb-autosave">
                <form>
                    <input type="text" name="content_block[alt]" id="alt">
                    <input type="checkbox" name="content_block[styling][margin][d][linked]" id="linked" value="1">
                    <button type="button" data-cb-sidebar-save id="save">Save</button>
                </form>
            </div>
        `;
        const element = document.querySelector('[data-controller="cb-autosave"]');
        const controller = new Controller();
        Object.defineProperty(controller, 'element', { value: element });
        Object.defineProperty(controller, 'debounceValue', { value: 100 });
        controller.connect(); // baseline snapshot: link checkbox still unchecked

        const clickSpy = vi.fn();
        element.querySelector('#save').addEventListener('click', clickSpy);

        // cb-spacing-link engages the link post-connect, then a benign trigger
        // (focusout when the user clicks away) reaches autosave.
        element.querySelector('#linked').checked = true;
        element.querySelector('#alt').dispatchEvent(new Event('focusout', { bubbles: true }));

        expect(clickSpy).not.toHaveBeenCalled();
    });

    it('still saves on a real field change even when a [linked] toggle also flipped', () => {
        document.body.innerHTML = `
            <div data-controller="cb-autosave">
                <form>
                    <input type="text" name="content_block[alt]" id="alt">
                    <input type="checkbox" name="content_block[styling][margin][d][linked]" id="linked" value="1">
                    <button type="button" data-cb-sidebar-save id="save">Save</button>
                </form>
            </div>
        `;
        const element = document.querySelector('[data-controller="cb-autosave"]');
        const controller = new Controller();
        Object.defineProperty(controller, 'element', { value: element });
        Object.defineProperty(controller, 'debounceValue', { value: 100 });
        controller.connect();

        const clickSpy = vi.fn();
        element.querySelector('#save').addEventListener('click', clickSpy);

        element.querySelector('#linked').checked = true; // UI flag flips…
        const alt = element.querySelector('#alt');
        alt.value = 'photo'; // …and the user actually edits a field
        alt.dispatchEvent(new Event('change', { bubbles: true }));

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('saves again once the user changes the value a second time', () => {
        const { input, clickSpy } = setup();

        input.value = 'hello';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(1);

        input.value = 'world';
        input.dispatchEvent(new Event('change', { bubbles: true }));
        expect(clickSpy).toHaveBeenCalledTimes(2);
    });

    it('ignores events from elements outside any form field', () => {
        const { element, clickSpy } = setup();
        const outsider = document.createElement('div');
        element.appendChild(outsider);

        outsider.dispatchEvent(new Event('input', { bubbles: true }));
        outsider.dispatchEvent(new Event('change', { bubbles: true }));
        outsider.dispatchEvent(new Event('focusout', { bubbles: true }));

        vi.advanceTimersByTime(1000);
        expect(clickSpy).not.toHaveBeenCalled();
    });

    it('Enter on a single-line input triggers save and prevents form submit', () => {
        const { input, clickSpy } = setup();
        input.value = 'hello';
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        input.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('Enter on a textarea is left alone so the user can type newlines', () => {
        const { textarea, clickSpy } = setup();
        const event = new KeyboardEvent('keydown', { key: 'Enter', bubbles: true, cancelable: true });
        textarea.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(false);
        expect(clickSpy).not.toHaveBeenCalled();
    });

    it('saves once when a structural re-render removes a collection item', async () => {
        vi.useRealTimers();
        const { controller, element, clickSpy, debounce } = setupCollection();

        // Simulate the LiveCollection "×" re-render: the item node disappears
        // from the form without any field input/change event.
        element.querySelector('[data-collection] .cb-item:last-child').remove();

        await flush(debounce + 40);
        // The observer reconciled the structural change into exactly one save.
        expect(clickSpy).toHaveBeenCalledTimes(1);

        controller.disconnect();
    });

    it('does not save when a structural re-render leaves the serialized state unchanged', async () => {
        vi.useRealTimers();
        const { controller, element, clickSpy, debounce } = setupCollection();

        // A live morph can replace nodes without changing any field/value
        // (e.g. re-rendering the same list). Churn the node tree but keep the
        // serialized form identical — _saveNow must treat it as a no-op.
        const collection = element.querySelector('[data-collection]');
        collection.innerHTML = collection.innerHTML;

        await flush(debounce + 40);
        expect(clickSpy).not.toHaveBeenCalled();

        controller.disconnect();
    });

    it('typing a value (no node mutation) still saves once, with no duplicate from the observer', async () => {
        vi.useRealTimers();
        const { controller, element, clickSpy, debounce } = setupCollection();

        // Editing an input changes its value (property), not the node tree, so
        // the childList/subtree observer stays silent — the save comes solely
        // from the input-debounce path, exactly once.
        const input = element.querySelector('#title');
        input.value = 'typed';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        await flush(debounce + 40);
        expect(clickSpy).toHaveBeenCalledTimes(1);

        controller.disconnect();
    });

    it('disconnect stops the mutation observer', async () => {
        vi.useRealTimers();
        const { controller, element, clickSpy, debounce } = setupCollection();
        controller.disconnect();

        element.querySelector('[data-collection] .cb-item:last-child').remove();
        await flush(debounce + 40);
        expect(clickSpy).not.toHaveBeenCalled();
    });

    it('disconnect tears down listeners and pending timers', () => {
        const { controller, input, clickSpy } = setup({ debounce: 250 });

        input.value = 'pending';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        controller.disconnect();
        vi.advanceTimersByTime(500);

        // The pending debounce should be cancelled by disconnect().
        expect(clickSpy).not.toHaveBeenCalled();

        // Listeners are detached: new input events should not schedule.
        input.value = 'after-disconnect';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(500);
        expect(clickSpy).not.toHaveBeenCalled();
    });
});
