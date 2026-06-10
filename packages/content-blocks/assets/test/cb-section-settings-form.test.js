import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-section-settings-form_controller.js';

/**
 * Unit tests for the column-widths logic of cb-section-settings-form.
 *
 * The Stimulus runtime isn't booted; we construct the controller and define
 * its target accessors by hand (same approach as cb-collection-sort.test.js),
 * then call the width handlers directly. The number inputs carry no `name`, so
 * only the hidden `widthsField` is the canonical value — committing dispatches
 * a bubbling `change` on it, which is what cb-autosave would pick up.
 */

function setup({ count = 2, value = '', presets = ['50,50', '40,60', '60,40'] } = {}) {
    const inputsHtml = Array.from({ length: count }, () => '<input type="number" class="wi">').join('');
    const presetsHtml = presets
        .map((v) => `<button class="cb-col-widths__preset" data-cb-widths="${v}"></button>`)
        .join('');
    document.body.innerHTML = `
        <div data-controller="cb-section-settings-form">
            <form>
                <input type="hidden" id="wf" value="${value}">
                <div class="cb-col-widths__presets">
                    ${presetsHtml}
                    <button id="ct" class="cb-col-widths__preset cb-col-widths__preset--custom"></button>
                </div>
                <div id="cr" hidden>
                    ${inputsHtml}
                    <span id="wt"></span>
                </div>
            </form>
        </div>`;

    const root = document.querySelector('[data-controller]');
    const field = document.getElementById('wf');
    const inputs = Array.from(document.querySelectorAll('.wi'));
    const total = document.getElementById('wt');
    const customRow = document.getElementById('cr');
    const customToggle = document.getElementById('ct');

    const c = new Controller();
    Object.defineProperty(c, 'element', { value: root });
    Object.defineProperty(c, 'hasWidthsFieldTarget', { value: true });
    Object.defineProperty(c, 'widthsFieldTarget', { value: field });
    Object.defineProperty(c, 'widthInputTargets', { value: inputs });
    Object.defineProperty(c, 'hasWidthsTotalTarget', { value: true });
    Object.defineProperty(c, 'widthsTotalTarget', { value: total });
    Object.defineProperty(c, 'hasCustomRowTarget', { value: true });
    Object.defineProperty(c, 'customRowTarget', { value: customRow });
    Object.defineProperty(c, 'hasCustomToggleTarget', { value: true });
    Object.defineProperty(c, 'customToggleTarget', { value: customToggle });

    return { c, field, inputs, total, customRow, customToggle };
}

describe('cb-section-settings-form — column widths', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('_equalSplit distributes 100 and absorbs the remainder in the first slot', () => {
        const { c } = setup();
        expect(c._equalSplit(2)).toEqual([50, 50]);
        expect(c._equalSplit(3)).toEqual([34, 33, 33]);
        expect(c._sum(c._equalSplit(3))).toBe(100);
    });

    it('_isValid requires 2+ values in 1..99 summing to 100', () => {
        const { c } = setup();
        expect(c._isValid([40, 60])).toBe(true);
        expect(c._isValid([40, 50])).toBe(false); // sum 90
        expect(c._isValid([0, 100])).toBe(false); // 0 out of range
        expect(c._isValid([100])).toBe(false);    // single column
        expect(c._isValid([34, 33, 33])).toBe(true);
    });

    it('_parseCsv yields ints, empty string yields []', () => {
        const { c } = setup();
        expect(c._parseCsv('40,60')).toEqual([40, 60]);
        expect(c._parseCsv(' 25, 50 ,25')).toEqual([25, 50, 25]);
        expect(c._parseCsv('')).toEqual([]);
    });

    it('applyWidthPreset writes the CSV, fills inputs and dispatches change', () => {
        const { c, field, inputs } = setup();
        const onChange = vi.fn();
        field.addEventListener('change', onChange);

        c.applyWidthPreset({ currentTarget: { dataset: { cbWidths: '40,60' } } });

        expect(field.value).toBe('40,60');
        expect(inputs.map((i) => i.value)).toEqual(['40', '60']);
        expect(onChange).toHaveBeenCalledTimes(1);
    });

    it('applyWidthPreset marks the matching preset button as active', () => {
        const { c } = setup({ value: '40,60' });
        // Re-init so the 40,60 preset starts active, then switch to 50,50.
        c._initWidths();
        const presetEls = c._presetButtons();
        const p4060 = presetEls.find((b) => b.dataset.cbWidths === '40,60');
        const p5050 = presetEls.find((b) => b.dataset.cbWidths === '50,50');
        expect(p4060.classList.contains('cb-col-widths__preset--active')).toBe(true);

        c.applyWidthPreset({ currentTarget: { dataset: { cbWidths: '50,50' } } });
        expect(p5050.classList.contains('cb-col-widths__preset--active')).toBe(true);
        expect(p4060.classList.contains('cb-col-widths__preset--active')).toBe(false);
    });

    it('_initWidths highlights the first/equal preset when no width is set', () => {
        const { c } = setup({ value: '' });
        c._initWidths();
        const first = c._presetButtons()[0];
        expect(first.classList.contains('cb-col-widths__preset--active')).toBe(true);
    });

    it('onWidthInput auto-completes the sibling for a 2-column section and commits', () => {
        const { c, field, inputs } = setup();
        inputs[0].value = '30';

        c.onWidthInput({ currentTarget: inputs[0] });

        expect(inputs[1].value).toBe('70');
        expect(field.value).toBe('30,70');
    });

    it('onWidthInput does not commit an invalid 3-column set', () => {
        const { c, field, inputs } = setup({ count: 3 });
        inputs[0].value = '40';
        inputs[1].value = '40';
        inputs[2].value = '40'; // sum 120 → invalid

        c.onWidthInput({ currentTarget: inputs[2] });

        expect(field.value).toBe(''); // nothing committed
        expect(c.widthsTotalTarget.classList.contains('cb-col-widths__total--invalid')).toBe(true);
    });

    it('onWidthCommit snaps the last input so the set sums to 100, then commits', () => {
        const { c, field, inputs } = setup({ count: 3 });
        inputs[0].value = '30';
        inputs[1].value = '30';
        inputs[2].value = '99'; // sum 159 → last snapped to 40

        c.onWidthCommit();

        expect(inputs.map((i) => i.value)).toEqual(['30', '30', '40']);
        expect(field.value).toBe('30,30,40');
    });

    it('the free inputs are revealed by showCustomWidths and hidden by a preset', () => {
        const { c, customRow, customToggle } = setup();

        c.showCustomWidths();
        expect(customRow.hidden).toBe(false);
        expect(customToggle.classList.contains('cb-col-widths__preset--active')).toBe(true);

        c.applyWidthPreset({ currentTarget: { dataset: { cbWidths: '40,60' } } });
        expect(customRow.hidden).toBe(true);
        expect(customToggle.classList.contains('cb-col-widths__preset--active')).toBe(false);
    });

    it('_initWidths keeps the free inputs hidden for a preset value', () => {
        const { c, customRow } = setup({ value: '40,60' });
        c._initWidths();
        expect(customRow.hidden).toBe(true);
    });

    it('_initWidths reveals the free inputs for a custom (non-preset) value', () => {
        const { c, customRow, inputs } = setup({ value: '35,65' });
        c._initWidths();
        expect(customRow.hidden).toBe(false);
        expect(inputs.map((i) => i.value)).toEqual(['35', '65']);
    });
});
