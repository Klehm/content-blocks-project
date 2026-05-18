import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-spacing-link_controller.js';

/**
 * Vitest unit tests for cb-spacing-link.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied properties to mimic the rendered markup
 * produced by templates/form/styling_widgets.html.twig.
 */

function setup({ linked = false } = {}) {
    document.body.innerHTML = `
        <div class="cb-box-spacing">
            <div class="cb-box-spacing__sides">
                <input class="cb-input" data-side="top" value="" />
                <input class="cb-input" data-side="right" value="" />
                <input class="cb-input" data-side="bottom" value="" />
                <input class="cb-input" data-side="left" value="" />
                <button class="cb-toggle" type="button" aria-pressed="${linked}"></button>
            </div>
            <div class="cb-box-spacing__linked-state">
                <input type="checkbox" name="linked" ${linked ? 'checked' : ''} />
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

    // Plain field for the LinkedValue (we manually toggle in tests).
    controller.linkedValue = linked;

    controller.connect();
    return { controller, element, inputs, toggle };
}

describe('cb-spacing-link', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('does not sync inputs when linked is false', () => {
        const { inputs } = setup({ linked: false });

        inputs[0].value = '10';
        inputs[0].dispatchEvent(new Event('input', { bubbles: true }));

        expect(inputs[1].value).toBe('');
        expect(inputs[2].value).toBe('');
        expect(inputs[3].value).toBe('');
    });

    it('broadcasts the edited value to all sides when linked', () => {
        const { inputs } = setup({ linked: true });

        inputs[2].value = '42';
        inputs[2].dispatchEvent(new Event('input', { bubbles: true }));

        expect(inputs[0].value).toBe('42');
        expect(inputs[1].value).toBe('42');
        expect(inputs[2].value).toBe('42');
        expect(inputs[3].value).toBe('42');
    });

    it('clicking the toggle flips linked state and updates aria-pressed + hidden checkbox', () => {
        const { controller, toggle, element } = setup({ linked: false });
        const hidden = element.querySelector('.cb-box-spacing__linked-state input[type=checkbox]');

        controller.toggle({ preventDefault: () => {} });

        expect(controller.linkedValue).toBe(true);
        expect(toggle.getAttribute('aria-pressed')).toBe('true');
        expect(hidden.checked).toBe(true);
    });

    it('activating the link broadcasts the first input to the others', () => {
        const { controller, inputs } = setup({ linked: false });
        inputs[0].value = '7';

        controller.toggle({ preventDefault: () => {} });

        expect(inputs[1].value).toBe('7');
        expect(inputs[2].value).toBe('7');
        expect(inputs[3].value).toBe('7');
    });
});
