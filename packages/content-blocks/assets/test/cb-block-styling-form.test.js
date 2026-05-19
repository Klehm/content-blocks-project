import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-block-styling-form_controller.js';

/**
 * Stimulus runtime is not booted — we wire the controller manually,
 * mimicking the markup produced by Block.html.twig and the
 * cb_horizontal_align_row form-theme block.
 */

function setup({ maxWidth = '' } = {}) {
    document.body.innerHTML = `
        <div class="cb-block__edit-form">
            <input name="content_block[styling][maxWidth][value]" value="${maxWidth}" />
            <select name="content_block[styling][maxWidth][unit]">
                <option value="px" selected>px</option>
            </select>
            <div class="row" data-row="alignSelf" hidden>
                <input type="radio" name="content_block[styling][alignSelf]" value="start" />
                <input type="radio" name="content_block[styling][alignSelf]" value="center" />
                <input type="radio" name="content_block[styling][alignSelf]" value="end" />
            </div>
        </div>
    `;

    const element = document.querySelector('.cb-block__edit-form');
    const row = element.querySelector('[data-row="alignSelf"]');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'alignSelfRowTarget', { value: row });
    Object.defineProperty(controller, 'hasAlignSelfRowTarget', { value: true });

    controller.connect();
    return { controller, element, row };
}

describe('cb-block-styling-form', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('keeps alignSelf row hidden when maxWidth is empty', () => {
        const { row } = setup({ maxWidth: '' });
        expect(row.hidden).toBe(true);
    });

    it('reveals alignSelf row when maxWidth has a positive value on connect', () => {
        const { row } = setup({ maxWidth: '720' });
        expect(row.hidden).toBe(false);
    });

    it('keeps alignSelf row hidden when maxWidth value is 0', () => {
        const { row } = setup({ maxWidth: '0' });
        expect(row.hidden).toBe(true);
    });

    it('reveals the row when the user types a max-width value', () => {
        const { element, row } = setup({ maxWidth: '' });
        const input = element.querySelector('input[name$="[maxWidth][value]"]');

        input.value = '500';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(row.hidden).toBe(false);
    });

    it('hides the row again when the user clears max-width', () => {
        const { element, row } = setup({ maxWidth: '720' });
        const input = element.querySelector('input[name$="[maxWidth][value]"]');

        input.value = '';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(row.hidden).toBe(true);
    });

    it('ignores input events from unrelated fields', () => {
        const { element, row } = setup({ maxWidth: '720' });
        const unrelated = element.querySelector('select');

        unrelated.dispatchEvent(new Event('change', { bubbles: true }));

        expect(row.hidden).toBe(false);
    });
});
