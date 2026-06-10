import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-spacing-link_controller.js';

/**
 * Vitest unit tests for cb-spacing-link.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied properties to mimic the rendered markup
 * produced by templates/form/styling_widgets.html.twig.
 *
 * The link is engaged only when the four sides are uniform (all empty or all
 * strictly equal); it's derived from the values on connect.
 */

function setup({ values = ['', '', '', ''] } = {}) {
    const sides = ['top', 'right', 'bottom', 'left'];
    document.body.innerHTML = `
        <div class="cb-box-spacing">
            <div class="cb-box-spacing__sides">
                ${sides.map((s, i) => `<input class="cb-input" data-side="${s}" value="${values[i]}" />`).join('')}
                <button class="cb-toggle" type="button" aria-pressed="false"></button>
            </div>
            <div class="cb-box-spacing__linked-state">
                <input type="checkbox" name="linked" />
            </div>
        </div>
    `;

    const element = document.querySelector('.cb-box-spacing');
    const inputs = Array.from(element.querySelectorAll('.cb-input'));
    const toggle = element.querySelector('.cb-toggle');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'inputTargets', { value: inputs });
    Object.defineProperty(controller, 'hasToggleTarget', { value: true });
    Object.defineProperty(controller, 'toggleTarget', { value: toggle });
    controller.linkedValue = false; // overwritten by connect() from uniformity

    controller.connect();
    return { controller, element, inputs, toggle };
}

describe('cb-spacing-link', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('starts unlinked for non-uniform values: editing a side leaves the others', () => {
        const { controller, inputs } = setup({ values: ['45', '0', '45', '0'] });
        expect(controller.linkedValue).toBe(false);

        inputs[0].value = '30';
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));

        expect(inputs[1].value).toBe('0');
        expect(inputs[2].value).toBe('45');
        expect(inputs[3].value).toBe('0');
    });

    it('starts linked for uniform values: editing a side broadcasts to all', () => {
        const { controller, inputs } = setup({ values: ['45', '45', '45', '45'] });
        expect(controller.linkedValue).toBe(true);

        inputs[2].value = '42';
        inputs[2].dispatchEvent(new Event('input', { bubbles: true }));

        expect(inputs.map(i => i.value)).toEqual(['42', '42', '42', '42']);
    });

    it('starts linked when all sides are empty: typing broadcasts to all', () => {
        const { controller, inputs } = setup({ values: ['', '', '', ''] });
        expect(controller.linkedValue).toBe(true);

        inputs[0].value = '10';
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));

        expect(inputs.map(i => i.value)).toEqual(['10', '10', '10', '10']);
    });

    it('reflects the derived link state on aria-pressed', () => {
        expect(setup({ values: ['1', '2', '3', '4'] }).toggle.getAttribute('aria-pressed')).toBe('false');
        expect(setup({ values: ['8', '8', '8', '8'] }).toggle.getAttribute('aria-pressed')).toBe('true');
    });

    it('clicking the toggle on a non-uniform set links it and equalises the sides', () => {
        const { controller, inputs, toggle, element } = setup({ values: ['45', '0', '45', '0'] });
        const hidden = element.querySelector('.cb-box-spacing__linked-state input[type=checkbox]');

        controller.toggle({ preventDefault: () => {} });

        expect(controller.linkedValue).toBe(true);
        expect(toggle.getAttribute('aria-pressed')).toBe('true');
        expect(hidden.checked).toBe(true);
        // First side's value is broadcast to the others.
        expect(inputs.map(i => i.value)).toEqual(['45', '45', '45', '45']);
    });

    it('clicking the toggle on a uniform set unlinks it: editing then stays independent', () => {
        const { controller, inputs } = setup({ values: ['45', '45', '45', '45'] });
        expect(controller.linkedValue).toBe(true);

        controller.toggle({ preventDefault: () => {} });
        expect(controller.linkedValue).toBe(false);

        inputs[0].value = '0';
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));
        expect(inputs.map(i => i.value)).toEqual(['0', '45', '45', '45']);
    });
});
