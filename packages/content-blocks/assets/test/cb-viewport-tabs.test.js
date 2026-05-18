import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-viewport-tabs_controller.js';

/**
 * Vitest unit tests for cb-viewport-tabs.
 * The controller sits inside a `.cb-form-row` next to a widget block that
 * holds three `[data-viewport]` panes (D/T/M) — selecting a tab toggles
 * `hidden` on the other panes.
 */

function setup() {
    document.body.innerHTML = `
        <div class="cb-form-row">
            <div class="cb-form-row__header">
                <label>Padding</label>
                <div class="cb-viewport-tabs">
                    <button class="tab" data-viewport="d" aria-pressed="true"></button>
                    <button class="tab" data-viewport="t" aria-pressed="false"></button>
                    <button class="tab" data-viewport="m" aria-pressed="false"></button>
                </div>
            </div>
            <div class="cb-responsive-box-spacing">
                <div class="pane" data-viewport="d">D</div>
                <div class="pane" data-viewport="t">T</div>
                <div class="pane" data-viewport="m">M</div>
            </div>
        </div>
    `;

    const element = document.querySelector('.cb-viewport-tabs');
    const tabs = Array.from(element.querySelectorAll('.tab'));

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'tabTargets', { value: tabs });
    controller.activeValue = 'd';

    controller.connect();
    return { controller, element, tabs };
}

describe('cb-viewport-tabs', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('on connect: shows the active viewport pane and hides the others', () => {
        setup();
        const panes = document.querySelectorAll('.pane');
        expect(panes[0].hidden).toBe(false);
        expect(panes[1].hidden).toBe(true);
        expect(panes[2].hidden).toBe(true);
    });

    it('clicking a tab switches the visible pane and updates aria-pressed', () => {
        const { controller, tabs } = setup();

        controller.select({ preventDefault: () => {}, currentTarget: tabs[2] });

        const panes = document.querySelectorAll('.pane');
        expect(panes[0].hidden).toBe(true);
        expect(panes[1].hidden).toBe(true);
        expect(panes[2].hidden).toBe(false);

        expect(tabs[0].getAttribute('aria-pressed')).toBe('false');
        expect(tabs[2].getAttribute('aria-pressed')).toBe('true');
    });

    it('does not toggle the tab buttons themselves (they share data-viewport)', () => {
        const { controller, tabs } = setup();

        controller.select({ preventDefault: () => {}, currentTarget: tabs[1] });

        // Tabs must remain visible regardless of which viewport is active.
        tabs.forEach(t => expect(t.hidden).toBe(false));
    });
});
