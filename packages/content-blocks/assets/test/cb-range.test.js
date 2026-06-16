import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import Controller from '../controllers/cb-range_controller.js';

/**
 * Vitest unit tests for cb-range.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied target properties to mimic the markup produced
 * by the cb_form_theme `range_widget` block: an editable number input (the
 * submitted field) mirrored by a range slider.
 */

function setup({ value = '', min = '0', max = '1200', step = '10', commitDelay = 400 } = {}) {
    document.body.innerHTML = `
        <div class="cb-form-range-wrap" data-controller="cb-range">
            <input type="number" data-cb-range-target="number"
                   min="${min}" max="${max}" step="1"
                   ${value !== '' ? `value="${value}"` : ''} />
            <input type="range" class="cb-form-range" data-cb-range-target="slider"
                   min="${min}" max="${max}" step="${step}"
                   ${value !== '' ? `value="${value}"` : ''} />
        </div>
    `;

    const element = document.querySelector('.cb-form-range-wrap');
    const number = element.querySelector('input[type=number]');
    const slider = element.querySelector('input[type=range]');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasNumberTarget', { value: true });
    Object.defineProperty(controller, 'numberTarget', { value: number });
    Object.defineProperty(controller, 'hasSliderTarget', { value: true });
    Object.defineProperty(controller, 'sliderTarget', { value: slider });
    Object.defineProperty(controller, 'commitDelayValue', { value: commitDelay });

    controller.connect();
    return { controller, number, slider };
}

describe('cb-range', () => {
    beforeEach(() => { document.body.innerHTML = ''; });
    afterEach(() => { vi.useRealTimers(); });

    it('connect reflects the number value onto the slider', () => {
        const { slider } = setup({ value: '300' });
        expect(slider.value).toBe('300');
    });

    it('dragging the slider mirrors its value onto the number input', () => {
        const { controller, number, slider } = setup({ value: '100' });

        slider.value = '250';
        controller.fromSlider();

        expect(number.value).toBe('250');
    });

    it('dragging the slider re-emits input on the number so Live/autosave observe it', () => {
        // The number input is the only model-bound field; a programmatic
        // value set fires no event, so the controller must re-dispatch one.
        const { controller, number, slider } = setup({ value: '100' });
        const events = [];
        number.addEventListener('input', () => events.push('input'));
        number.addEventListener('change', () => events.push('change'));

        slider.value = '250';
        controller.fromSlider();
        expect(events).toEqual(['input']);

        slider.value = '260';
        controller.commitSlider();
        expect(events).toEqual(['input', 'change']);
        expect(number.value).toBe('260');
    });

    it('typing a precise value moves the slider thumb', () => {
        const { controller, number, slider } = setup({ value: '100' });

        // 345 is off the slider's step grid (step=10) — only possible by typing.
        number.value = '345';
        controller.fromNumber();

        // The slider snaps to its grid; jsdom keeps the raw assignment, but the
        // number input — the submitted field — preserves the precise value.
        expect(slider.value).toBe('345');
        expect(number.value).toBe('345');
    });

    it('keeps a precise (off-step) value on the submitted number input', () => {
        const { number } = setup({ value: '100' });

        number.value = '337';
        // No clamp/snap touches the number on input; it stays exact.
        expect(number.value).toBe('337');
    });

    it('debounces typed keystrokes into a single change after the idle window', () => {
        // Without the debounce each keystroke's input would reach autosave and
        // commit mid-typing (clamp + slider snap + Live morph) — the "jump".
        vi.useFakeTimers();
        const { controller, number } = setup({ value: '100', commitDelay: 400 });
        const changes = [];
        number.addEventListener('change', () => changes.push(number.value));

        number.value = '1';
        controller.fromNumber(new Event('input', { bubbles: true }));
        vi.advanceTimersByTime(200); // pause shorter than the window
        number.value = '15';
        controller.fromNumber(new Event('input', { bubbles: true }));

        // Still mid-debounce after the latest keystroke -> nothing committed yet.
        vi.advanceTimersByTime(399);
        expect(changes).toEqual([]);

        // The idle window elapses -> exactly one change, carrying the last value.
        vi.advanceTimersByTime(1);
        expect(changes).toEqual(['15']);
    });

    it('stops a typed input event from bubbling to autosave (commits on pause instead)', () => {
        const { controller } = setup({ value: '100' });
        const event = new Event('input', { bubbles: true });
        const stop = vi.spyOn(event, 'stopPropagation');

        controller.fromNumber(event);

        expect(stop).toHaveBeenCalledOnce();
    });

    it('does not debounce or swallow the slider-mirrored input event', () => {
        vi.useFakeTimers();
        const { controller, number, slider } = setup({ value: '100' });
        // Mimic the Stimulus `input->cb-range#fromNumber` wiring so the mirrored
        // event reaches the handler exactly as it does in the browser.
        number.addEventListener('input', (e) => controller.fromNumber(e));
        let reachedAutosave = false; // autosave listens on an ancestor element
        controller.element.addEventListener('input', () => { reachedAutosave = true; });
        const changes = [];
        number.addEventListener('change', () => changes.push(number.value));

        slider.value = '250';
        controller.fromSlider();

        expect(number.value).toBe('250');
        expect(reachedAutosave).toBe(true); // mirror was not stopped
        vi.advanceTimersByTime(400);
        expect(changes).toEqual([]); // and no debounced commit was scheduled
    });

    it('a real change (blur/Enter -> clampNumber) cancels the pending debounced commit', () => {
        vi.useFakeTimers();
        const { controller, number } = setup({ value: '100' });
        const changes = [];
        number.addEventListener('change', () => changes.push(number.value));

        number.value = '50';
        controller.fromNumber(new Event('input', { bubbles: true }));
        controller.clampNumber(); // the commit the browser fires on blur/Enter

        vi.advanceTimersByTime(400);
        expect(changes).toEqual([]); // _commit must not fire a second change
    });

    it('releasing the slider cancels a pending typed-value commit', () => {
        vi.useFakeTimers();
        const { controller, number, slider } = setup({ value: '100' });
        const changes = [];
        number.addEventListener('change', () => changes.push(number.value));

        number.value = '7';
        controller.fromNumber(new Event('input', { bubbles: true }));

        slider.value = '250';
        controller.commitSlider();
        expect(changes).toEqual(['250']);

        vi.advanceTimersByTime(400);
        expect(changes).toEqual(['250']); // the typed commit did not fire afterwards
    });

    it('clampNumber pins a value above max down to max', () => {
        const { controller, number, slider } = setup({ value: '100', max: '1200' });

        number.value = '5000';
        controller.clampNumber();

        expect(number.value).toBe('1200');
        expect(slider.value).toBe('1200');
    });

    it('clampNumber pins a value below min up to min', () => {
        const { controller, number, slider } = setup({ value: '100', min: '0' });

        number.value = '-40';
        controller.clampNumber();

        expect(number.value).toBe('0');
        expect(slider.value).toBe('0');
    });

    it('clampNumber leaves an in-range value untouched', () => {
        const { controller, number } = setup({ value: '100' });

        number.value = '742';
        controller.clampNumber();

        expect(number.value).toBe('742');
    });

    it('clampNumber ignores empty input without throwing', () => {
        const { controller, number } = setup({ value: '100' });

        // A type=number input coerces non-numeric text to '' on assignment, so
        // the controller only ever sees an empty string here — it must no-op.
        number.value = '';
        controller.clampNumber();
        expect(number.value).toBe('');

        number.value = 'abc';
        expect(number.value).toBe(''); // coerced by the input itself
        controller.clampNumber();
        expect(number.value).toBe('');
    });
});
