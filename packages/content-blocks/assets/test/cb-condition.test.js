import { describe, it, expect, beforeEach } from 'vitest';
import Controller from '../controllers/cb-condition_controller.js';

/**
 * Vitest unit tests for cb-condition — the generic conditional-field
 * visibility controller. Stimulus runtime is not booted: the class is
 * instantiated directly with a stubbed `element`, mirroring the other
 * controller tests in this suite.
 */

function setup(html) {
    document.body.innerHTML = `<div id="root">${html}</div>`;
    const element = document.getElementById('root');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    controller.connect();

    return { controller, element };
}

describe('cb-condition', () => {
    beforeEach(() => { document.body.innerHTML = ''; });

    it('shows the row when the select matches and hides it otherwise', () => {
        const { element } = setup(`
            <select name="settings[styling][backgroundColor][palette]">
                <option value="" selected></option>
                <option value="#ff0000">Red</option>
                <option value="custom">Custom</option>
            </select>
            <div id="row" data-cb-condition="palette:custom">picker</div>
        `);
        const select = element.querySelector('select');
        const row = element.querySelector('#row');

        expect(row.hidden).toBe(true);

        select.value = 'custom';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(false);

        select.value = '#ff0000';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(true);
    });

    it('supports OR values with a pipe separator', () => {
        const { element } = setup(`
            <select name="size">
                <option value="sm" selected></option>
                <option value="custom"></option>
                <option value="full"></option>
            </select>
            <div id="row" data-cb-condition="size:custom|full"></div>
        `);
        const select = element.querySelector('select');
        const row = element.querySelector('#row');

        expect(row.hidden).toBe(true);

        select.value = 'full';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(false);
    });

    it('ANDs `;`-separated clauses — all must match', () => {
        // Mirrors the image block: height reveals only when size is "custom"
        // AND "auto height" is off.
        const { element } = setup(`
            <select name="data[size]">
                <option value="md"></option>
                <option value="custom" selected></option>
            </select>
            <input type="checkbox" name="data[customHeightAuto]" checked>
            <div id="row" data-cb-condition="size:custom;customHeightAuto:false"></div>
        `);
        const select = element.querySelector('select');
        const box = element.querySelector('input');
        const row = element.querySelector('#row');

        // size matches but auto height is on → hidden.
        expect(row.hidden).toBe(true);

        // Turn auto height off → both clauses match → shown.
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(false);

        // Switch away from custom size → first clause fails → hidden again,
        // even though auto height is still off.
        select.value = 'md';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(true);
    });

    it('maps checkbox state to true/false', () => {
        const { element } = setup(`
            <input type="checkbox" name="settings[stylingCustom]">
            <div id="row" data-cb-condition="stylingCustom:true"></div>
        `);
        const box = element.querySelector('input');
        const row = element.querySelector('#row');

        expect(row.hidden).toBe(true);

        box.checked = true;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(false);

        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(true);
    });

    it('reads the checked radio of a group', () => {
        const { element } = setup(`
            <input type="radio" name="settings[widthMode]" value="full" checked>
            <input type="radio" name="settings[widthMode]" value="centered">
            <div id="row" data-cb-condition="widthMode:centered"></div>
        `);
        const row = element.querySelector('#row');
        const radios = element.querySelectorAll('input');

        expect(row.hidden).toBe(true);

        radios[1].checked = true;
        radios[1].dispatchEvent(new Event('change', { bubbles: true }));
        expect(row.hidden).toBe(false);
    });

    it('treats a value-less condition as "non-empty"', () => {
        const { element } = setup(`
            <input type="text" name="settings[link]" value="">
            <div id="row" data-cb-condition="link"></div>
        `);
        const input = element.querySelector('input');
        const row = element.querySelector('#row');

        expect(row.hidden).toBe(true);

        input.value = 'https://example.com';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        expect(row.hidden).toBe(false);
    });

    it('leaves the row visible when no control matches the field name', () => {
        const { element } = setup(`
            <input type="text" name="settings[other]" value="">
            <div id="row" data-cb-condition="doesNotExist:x"></div>
        `);

        expect(element.querySelector('#row').hidden).toBe(false);
    });

    it('leaves rows owned by a nested cb-condition instance to that instance', () => {
        // Outer sidebar form controller + inner palette compound controller.
        // The outer instance must not touch the palette's custom row, even
        // though it has a `palette` select in scope elsewhere.
        const { element } = setup(`
            <select name="settings[other][palette]">
                <option value="custom" selected></option>
            </select>
            <div data-controller="cb-condition">
                <select name="settings[bg][palette]"><option value="" selected></option></select>
                <div id="inner" data-cb-condition="palette:custom"></div>
            </div>
            <div id="outer" data-cb-condition="palette:custom"></div>
        `);

        // Outer row managed with the outer scope's (first) palette select…
        expect(element.querySelector('#outer').hidden).toBe(false);
        // …inner row untouched by the outer instance (stays at its default
        // non-hidden state until its own controller instance connects).
        expect(element.querySelector('#inner').hidden).toBe(false);
    });

    it('scopes lookups to the controller element', () => {
        // Two sibling scopes each holding a `palette` sub-field: the
        // controller attached to scope A must not read scope B's select.
        document.body.innerHTML = `
            <div id="a">
                <select name="f[a][palette]"><option value="custom" selected></option></select>
                <div id="rowA" data-cb-condition="palette:custom"></div>
            </div>
            <div id="b">
                <select name="f[b][palette]"><option value="" selected></option></select>
                <div id="rowB" data-cb-condition="palette:custom"></div>
            </div>
        `;
        const a = new Controller();
        Object.defineProperty(a, 'element', { value: document.getElementById('a') });
        a.connect();
        const b = new Controller();
        Object.defineProperty(b, 'element', { value: document.getElementById('b') });
        b.connect();

        expect(document.getElementById('rowA').hidden).toBe(false);
        expect(document.getElementById('rowB').hidden).toBe(true);
    });
});
