import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-tabs_controller.js';

/**
 * Vitest unit tests for cb-tabs.
 * The controller groups block-edit fields into tabs: a tablist of buttons
 * over a set of panels. Each tab/panel carries a `data-cb-tab` index;
 * selecting a tab shows the matching panel and hides the others. Every
 * panel stays in the DOM so autosave/validation keep working on hidden tabs.
 */

function setup() {
    document.body.innerHTML = `
        <div class="cb-block__tabs">
            <div class="cb-block__tablist">
                <button class="cb-block__tab" data-cb-tab="0" aria-selected="true"></button>
                <button class="cb-block__tab" data-cb-tab="1" aria-selected="false"></button>
                <button class="cb-block__tab" data-cb-tab="2" aria-selected="false"></button>
            </div>
            <section class="cb-block__tabpanel" data-cb-tab="0">A</section>
            <section class="cb-block__tabpanel" data-cb-tab="1" hidden>B</section>
            <section class="cb-block__tabpanel" data-cb-tab="2" hidden>C</section>
        </div>
    `;

    const element = document.querySelector('.cb-block__tabs');
    const tabs = Array.from(element.querySelectorAll('.cb-block__tab'));
    const panels = Array.from(element.querySelectorAll('.cb-block__tabpanel'));

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'tabTargets', { value: tabs });
    Object.defineProperty(controller, 'panelTargets', { value: panels });
    controller.activeValue = '0';

    controller.connect();
    return { controller, element, tabs, panels };
}

describe('cb-tabs', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('on connect: shows the active panel, hides the others, marks the active tab', () => {
        const { tabs, panels } = setup();

        expect(panels[0].hidden).toBe(false);
        expect(panels[1].hidden).toBe(true);
        expect(panels[2].hidden).toBe(true);

        expect(tabs[0].classList.contains('cb-block__tab--active')).toBe(true);
        expect(tabs[0].getAttribute('aria-selected')).toBe('true');
        expect(tabs[1].getAttribute('aria-selected')).toBe('false');
    });

    it('selecting a tab switches the visible panel and moves the active state', () => {
        const { controller, tabs, panels } = setup();

        controller.select({ preventDefault: () => {}, currentTarget: tabs[2] });

        expect(panels[0].hidden).toBe(true);
        expect(panels[1].hidden).toBe(true);
        expect(panels[2].hidden).toBe(false);

        expect(tabs[0].classList.contains('cb-block__tab--active')).toBe(false);
        expect(tabs[2].classList.contains('cb-block__tab--active')).toBe(true);
        expect(tabs[0].getAttribute('aria-selected')).toBe('false');
        expect(tabs[2].getAttribute('aria-selected')).toBe('true');
    });

    it('stores the active index so it survives a re-render (mutation tracking)', () => {
        const { controller, tabs } = setup();

        controller.select({ preventDefault: () => {}, currentTarget: tabs[1] });

        expect(controller.activeValue).toBe('1');
    });

    it('re-selecting the already-active tab is a no-op and does not throw', () => {
        const { controller, tabs, panels } = setup();

        expect(() =>
            controller.select({ preventDefault: () => {}, currentTarget: tabs[0] })
        ).not.toThrow();

        expect(panels[0].hidden).toBe(false);
        expect(controller.activeValue).toBe('0');
    });

    it('calls preventDefault on the triggering event', () => {
        const { controller, tabs } = setup();
        let prevented = false;

        controller.select({
            preventDefault: () => { prevented = true; },
            currentTarget: tabs[1],
        });

        expect(prevented).toBe(true);
    });
});
