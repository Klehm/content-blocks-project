import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-tabs_controller.js';

/**
 * Vitest unit tests for cb-tabs: sidebar tabs (`cb_group`), collapsible
 * panels (`cb_panel`), their memory for the session, and panel summaries.
 * Every panel stays in the DOM so autosave/validation keep working.
 */

const TABS = `
    <div class="cb-sidebar-tabs__list">
        <button class="cb-sidebar-tabs__tab" data-cb-tab="0" aria-selected="true"></button>
        <button class="cb-sidebar-tabs__tab" data-cb-tab="1" aria-selected="false"></button>
        <button class="cb-sidebar-tabs__tab" data-cb-tab="2" aria-selected="false"></button>
    </div>
    <section class="cb-sidebar-tabs__panel" data-cb-tab="0">A</section>
    <section class="cb-sidebar-tabs__panel" data-cb-tab="1" hidden>B</section>
    <section class="cb-sidebar-tabs__panel" data-cb-tab="2" hidden>C</section>
`;

function fold(label, body, { open = false, error = false, group = 'styling' } = {}) {
    return `
        <details class="cb-panel${error ? ' cb-panel--has-error' : ''}"
                 data-cb-panel="${label}" data-cb-panel-group="${group}"${open ? ' open' : ''}>
            <summary class="cb-panel__header">
                <span class="cb-panel__title">${label}</span>
                <span class="cb-panel__summary"></span>
            </summary>
            <div class="cb-panel__body">${body}</div>
        </details>`;
}

function setup(extra = '', { storageKey = '' } = {}) {
    document.body.innerHTML = `<div class="cb-sidebar-tabs">${TABS}${extra}</div>`;

    const element = document.querySelector('.cb-sidebar-tabs');
    const tabs = Array.from(element.querySelectorAll('.cb-sidebar-tabs__tab'));
    const panels = Array.from(element.querySelectorAll('.cb-sidebar-tabs__panel'));
    const folds = Array.from(element.querySelectorAll('details[data-cb-panel]'));

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'tabTargets', { value: tabs });
    Object.defineProperty(controller, 'panelTargets', { value: panels });
    Object.defineProperty(controller, 'foldTargets', { value: folds });
    controller.activeValue = '0';
    controller.storageKeyValue = storageKey;

    controller.connect();
    folds.forEach((f) => controller.foldTargetConnected(f));

    return { controller, element, tabs, panels, folds };
}

const summaryOf = (f) => f.querySelector('.cb-panel__summary').getAttribute('data-cb-summary');

describe('cb-tabs — tabs', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.sessionStorage.clear();
    });

    it('on connect: shows the active panel, hides the others, marks the active tab', () => {
        const { tabs, panels } = setup();

        expect(panels[0].hidden).toBe(false);
        expect(panels[1].hidden).toBe(true);
        expect(panels[2].hidden).toBe(true);

        expect(tabs[0].classList.contains('cb-sidebar-tabs__tab--active')).toBe(true);
        expect(tabs[0].getAttribute('aria-selected')).toBe('true');
        expect(tabs[1].getAttribute('aria-selected')).toBe('false');
    });

    it('selecting a tab switches the visible panel and moves the active state', () => {
        const { controller, tabs, panels } = setup();

        controller.select({ preventDefault: () => {}, currentTarget: tabs[2] });

        expect(panels[0].hidden).toBe(true);
        expect(panels[1].hidden).toBe(true);
        expect(panels[2].hidden).toBe(false);

        expect(tabs[0].classList.contains('cb-sidebar-tabs__tab--active')).toBe(false);
        expect(tabs[2].classList.contains('cb-sidebar-tabs__tab--active')).toBe(true);
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

    it('reopens the tab last picked for the same sidebar kind', () => {
        const first = setup('', { storageKey: 'section' });
        first.controller.select({ preventDefault: () => {}, currentTarget: first.tabs[2] });
        first.controller.disconnect();

        const { panels, tabs } = setup('', { storageKey: 'section' });

        expect(panels[2].hidden).toBe(false);
        expect(tabs[2].getAttribute('aria-selected')).toBe('true');
    });

    it('keeps no memory without a storage key', () => {
        const first = setup();
        first.controller.select({ preventDefault: () => {}, currentTarget: first.tabs[2] });

        const { panels } = setup();

        expect(panels[0].hidden).toBe(false);
        expect(window.sessionStorage.length).toBe(0);
    });

    it('ignores a remembered tab that no longer exists', () => {
        window.sessionStorage.setItem('cb-sidebar:block:title', JSON.stringify({ tab: '7' }));

        const { panels } = setup('', { storageKey: 'block:title' });

        expect(panels[0].hidden).toBe(false);
    });
});

describe('cb-tabs — panels', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.sessionStorage.clear();
    });

    const three = () => fold('Spacing', '', { open: true }) + fold('Background', '') + fold('Size', '');

    it('reopens the panel last opened in the same group', () => {
        window.sessionStorage.setItem('cb-sidebar:section', JSON.stringify({ panels: { styling: 'Size' } }));

        const { folds } = setup(three(), { storageKey: 'section' });

        expect(folds.map((f) => f.open)).toEqual([false, false, true]);
    });

    it('keeps every panel shut when that is how it was left', () => {
        window.sessionStorage.setItem('cb-sidebar:section', JSON.stringify({ panels: { styling: '' } }));

        const { folds } = setup(three(), { storageKey: 'section' });

        expect(folds.some((f) => f.open)).toBe(false);
    });

    it('leaves the server pick alone when a panel holds an error', () => {
        window.sessionStorage.setItem('cb-sidebar:section', JSON.stringify({ panels: { styling: 'Size' } }));
        const html = fold('Spacing', '') + fold('Background', '', { open: true, error: true }) + fold('Size', '');

        const { folds } = setup(html, { storageKey: 'section' });

        expect(folds.map((f) => f.open)).toEqual([false, true, false]);
    });

    it('ignores a remembered panel that is not in the group any more', () => {
        window.sessionStorage.setItem('cb-sidebar:section', JSON.stringify({ panels: { styling: 'Gone' } }));

        const { folds } = setup(three(), { storageKey: 'section' });

        expect(folds.map((f) => f.open)).toEqual([true, false, false]);
    });

    it('remembers a panel opened, then closed, per group', () => {
        const { controller, folds } = setup(three(), { storageKey: 'section' });
        const toggle = (f) => controller._onToggle({ target: f });

        folds[0].open = false;
        toggle(folds[0]);
        folds[1].open = true;
        toggle(folds[1]);
        expect(JSON.parse(window.sessionStorage.getItem('cb-sidebar:section')).panels.styling).toBe('Background');

        folds[1].open = false;
        toggle(folds[1]);
        expect(JSON.parse(window.sessionStorage.getItem('cb-sidebar:section')).panels.styling).toBe('');
    });

    it('a sibling closing after another opened does not erase the memory', () => {
        const { controller, folds } = setup(three(), { storageKey: 'section' });

        folds[1].open = true;
        controller._onToggle({ target: folds[1] });
        folds[0].open = false;
        controller._onToggle({ target: folds[0] });

        expect(JSON.parse(window.sessionStorage.getItem('cb-sidebar:section')).panels.styling).toBe('Background');
    });
});

describe('cb-tabs — panel summaries', () => {
    beforeEach(() => {
        document.body.innerHTML = '';
        window.sessionStorage.clear();
    });

    it('lists box-spacing sides, empty ones as 0, desktop only', () => {
        const body = `
            <div class="cb-form-row">
                <div data-viewport="desktop">
                    <input name="s[padding][desktop][top]" value="40">
                    <input name="s[padding][desktop][right]" value="12">
                    <input name="s[padding][desktop][bottom]" value="">
                    <input name="s[padding][desktop][left]" value="12">
                    <input type="checkbox" name="s[padding][desktop][linked]" checked>
                </div>
                <div data-viewport="mobile" hidden>
                    <input name="s[padding][mobile][top]" value="99">
                </div>
            </div>`;

        const { folds } = setup(fold('Spacing', body));

        expect(summaryOf(folds[0])).toBe('40 · 12 · 0 · 12');
        expect(folds[0].hasAttribute('data-cb-filled')).toBe(true);
    });

    it('names the picked choice, not the placeholder, and a select option', () => {
        const body = `
            <fieldset class="cb-form-row">
                <label title="Cover"><input type="radio" name="s[size]" value="" checked></label>
                <label title="Contain"><input type="radio" name="s[size]" value="contain"></label>
            </fieldset>
            <fieldset class="cb-form-row">
                <label title="Centre"><input type="radio" name="s[pos]" value=""></label>
                <label title="Top right"><input type="radio" name="s[pos]" value="top right" checked></label>
            </fieldset>
            <div class="cb-form-row">
                <select name="s[bg][palette]"><option value="">None</option><option value="#eb0540" selected>Brand</option></select>
            </div>`;

        const { folds } = setup(fold('Background', body));

        expect(summaryOf(folds[0])).toBe('Top right · Brand');
    });

    it('shows a custom colour as its hex', () => {
        const body = `
            <div class="cb-form-row">
                <select name="s[bg][palette]"><option value="custom" selected>Custom…</option></select>
                <input type="color" name="s[bg][custom]" value="#123456">
            </div>`;

        const { folds } = setup(fold('Background', body));

        expect(summaryOf(folds[0])).toBe('#123456');
    });

    it('adds a length unit, and names an upload by its file', () => {
        const body = `
            <div class="cb-form-row">
                <span class="cb-length">
                    <input name="s[minHeight][value]" value="60">
                    <select name="s[minHeight][unit]" class="cb-length__unit"><option value="vh" selected>vh</option></select>
                </span>
            </div>
            <div class="cb-form-row">
                <div class="cb-image-upload"><input type="hidden" name="s[img]" value="/uploads/a/hero.jpg"></div>
            </div>`;

        const { folds } = setup(fold('Size', body));

        expect(summaryOf(folds[0])).toBe('60 vh · hero.jpg');
    });

    it('skips a slider resting on its minimum, hidden rows and plain hidden inputs', () => {
        const body = `
            <div class="cb-form-row">
                <div class="cb-form-range-wrap"><input type="number" min="0" name="s[opacity]" value="0"></div>
            </div>
            <div class="cb-form-row" hidden><input name="s[gone]" value="7"></div>
            <input type="hidden" name="s[_token]" value="abc">`;

        const { folds } = setup(fold('Background', body));

        expect(summaryOf(folds[0])).toBeNull();
        expect(folds[0].hasAttribute('data-cb-filled')).toBe(false);
    });

    it('names a ticked checkbox by its label', () => {
        const body = `
            <div class="cb-form-row">
                <div class="cb-form-check">
                    <input type="checkbox" id="rev" name="s[reverse]" checked>
                    <label for="rev">Reverse on mobile</label>
                </div>
            </div>`;

        const { folds } = setup(fold('Width', body));

        expect(summaryOf(folds[0])).toBe('Reverse on mobile');
    });

    it('follows an edit, and leaves a fixed summary alone', () => {
        const html = fold('Spacing', '<div class="cb-form-row"><input name="s[gap]" value=""></div>')
            + `<details data-cb-panel="Columns" data-cb-panel-group="tab-0">
                   <summary><span class="cb-panel__summary" data-cb-summary="2 columns" data-cb-summary-fixed></span></summary>
                   <div class="cb-panel__body"><input name="s[label]" value="x"></div>
               </details>`;
        const { element, folds } = setup(html);
        const input = folds[0].querySelector('input');

        input.value = '24';
        input.dispatchEvent(new Event('input', { bubbles: true }));

        expect(summaryOf(folds[0])).toBe('24');
        expect(summaryOf(element.querySelector('[data-cb-panel="Columns"]'))).toBe('2 columns');
    });
});
