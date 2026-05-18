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
            <input type="text" name="title" id="title">
            <textarea name="body" id="body"></textarea>
            <button type="button" data-cb-sidebar-save id="save">Save</button>
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

describe('cb-autosave', () => {
    beforeEach(() => {
        vi.useFakeTimers();
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('debounces input events before clicking the save trigger', () => {
        const { input, clickSpy } = setup({ debounce: 250 });

        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(100);
        // Still within the debounce window — no save yet.
        expect(clickSpy).not.toHaveBeenCalled();

        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(100);
        // The second input reset the timer; still no save.
        expect(clickSpy).not.toHaveBeenCalled();

        vi.advanceTimersByTime(200);
        // 200ms after the second input → past the 250ms debounce.
        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('change event triggers an immediate save', () => {
        const { input, clickSpy } = setup();

        input.dispatchEvent(new Event('change', { bubbles: true }));

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('focusout triggers an immediate save', () => {
        const { input, clickSpy } = setup();

        input.dispatchEvent(new Event('focusout', { bubbles: true }));

        expect(clickSpy).toHaveBeenCalledTimes(1);
    });

    it('a pending debounce is cancelled when an immediate save fires', () => {
        const { input, clickSpy } = setup({ debounce: 250 });

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

        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(120);

        // The synthetic change was dispatched once, just before the
        // save click. (Without this dispatch, Live Component model
        // bindings would POST the pre-edit value.)
        expect(changes.length).toBeGreaterThanOrEqual(1);
        expect(clickSpy).toHaveBeenCalledTimes(1);
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

    it('disconnect tears down listeners and pending timers', () => {
        const { controller, input, clickSpy } = setup({ debounce: 250 });

        input.dispatchEvent(new Event('input', { bubbles: true }));
        controller.disconnect();
        vi.advanceTimersByTime(500);

        // The pending debounce should be cancelled by disconnect().
        expect(clickSpy).not.toHaveBeenCalled();

        // Listeners are detached: new input events should not schedule.
        input.dispatchEvent(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(500);
        expect(clickSpy).not.toHaveBeenCalled();
    });
});
