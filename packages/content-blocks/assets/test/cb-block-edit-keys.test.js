import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-block-edit-keys_controller.js';

/**
 * Unit tests for cb-block-edit-keys controller.
 * The Stimulus runtime is not booted here — we instantiate the class
 * directly and feed it a fake target lookup, then dispatch real
 * KeyboardEvents on the DOM it observes.
 */

function setupForm() {
    document.body.innerHTML = `
        <div class="cb-block__edit-form" data-controller="cb-block-edit-keys">
            <input type="text" id="single" />
            <textarea id="multi"></textarea>
            <div id="rich" contenteditable="true"></div>
            <button type="button" id="save" data-cb-block-edit-keys-target="saveBtn">Save</button>
            <button type="button" id="cancel" data-cb-block-edit-keys-target="cancelBtn">Cancel</button>
        </div>
    `;

    const element = document.querySelector('.cb-block__edit-form');
    const saveBtn = document.getElementById('save');
    const cancelBtn = document.getElementById('cancel');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasSaveBtnTarget', { value: true });
    Object.defineProperty(controller, 'saveBtnTarget', { value: saveBtn });
    Object.defineProperty(controller, 'hasCancelBtnTarget', { value: true });
    Object.defineProperty(controller, 'cancelBtnTarget', { value: cancelBtn });
    controller.connect();

    return { controller, element, saveBtn, cancelBtn };
}

function dispatchKey(target, key) {
    const event = new KeyboardEvent('keydown', { key, bubbles: true, cancelable: true });
    target.dispatchEvent(event);
    return event;
}

describe('cb-block-edit-keys', () => {
    let saveSpy, cancelSpy;

    beforeEach(() => {
        document.body.innerHTML = '';
    });

    it('Enter on a text input clicks Save and prevents default form submit', () => {
        const { saveBtn } = setupForm();
        saveSpy = vi.spyOn(saveBtn, 'click');

        const event = dispatchKey(document.getElementById('single'), 'Enter');

        expect(saveSpy).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
    });

    it('Enter inside a textarea is left alone', () => {
        const { saveBtn } = setupForm();
        saveSpy = vi.spyOn(saveBtn, 'click');

        const event = dispatchKey(document.getElementById('multi'), 'Enter');

        expect(saveSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('Enter inside a contenteditable region is left alone', () => {
        const { saveBtn } = setupForm();
        saveSpy = vi.spyOn(saveBtn, 'click');

        const event = dispatchKey(document.getElementById('rich'), 'Enter');

        expect(saveSpy).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('Escape clicks Cancel from any field', () => {
        const { cancelBtn } = setupForm();
        cancelSpy = vi.spyOn(cancelBtn, 'click');

        const event = dispatchKey(document.getElementById('multi'), 'Escape');

        expect(cancelSpy).toHaveBeenCalledOnce();
        expect(event.defaultPrevented).toBe(true);
    });
});
