import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-range_controller.js';

/**
 * Vitest unit tests for cb-range.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied target properties to mimic the markup produced
 * by the cb_form_theme `range_widget` block: an editable number input (the
 * submitted field) mirrored by a range slider.
 */

function setup({ value = '', min = '0', max = '1200', step = '10' } = {}) {
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

    controller.connect();
    return { controller, number, slider };
}

describe('cb-range', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

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
