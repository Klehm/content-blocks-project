import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder-launcher_controller.js';

/**
 * Vitest unit tests for cb-builder-launcher.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied properties.
 */

function setup({ withSidebarForm = false } = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder-launcher">
            <button data-action="cb-builder-launcher#open">Open</button>
            <dialog>
                <div data-controller="cb-builder">
                    <iframe data-cb-builder-target="iframe"></iframe>
                    <aside data-cb-builder-target="sidebar" ${withSidebarForm ? '' : 'hidden'}>
                        ${withSidebarForm ? '<form><input/></form>' : ''}
                    </aside>
                </div>
            </dialog>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder-launcher"]');
    const dialog = element.querySelector('dialog');

    // Stub <dialog> APIs (jsdom support is partial).
    dialog.showModal = vi.fn(() => { dialog.setAttribute('open', ''); });
    dialog.close = vi.fn(() => { dialog.removeAttribute('open'); });

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasDialogTarget', { value: true });
    Object.defineProperty(controller, 'dialogTarget', { value: dialog });
    Object.defineProperty(controller, 'confirmCloseMessageValue', { value: 'Discard?' });
    controller.connect();

    return { controller, element, dialog };
}

describe('cb-builder-launcher: open', () => {
    it('sets iframe src on first open from the shell data attribute', () => {
        const { controller, dialog } = setup();
        const iframe = dialog.querySelector('iframe');
        const shell = dialog.querySelector('[data-controller~="cb-builder"]');
        shell.dataset.cbBuilderIframeUrlValue = 'http://example.test/p/1?cb_preview=1';

        controller.open();

        expect(iframe.getAttribute('src')).toBe('http://example.test/p/1?cb_preview=1');
        expect(dialog.showModal).toHaveBeenCalled();
    });

    it('does not reset iframe src on subsequent opens', () => {
        const { controller, dialog } = setup();
        const iframe = dialog.querySelector('iframe');
        const shell = dialog.querySelector('[data-controller~="cb-builder"]');
        shell.dataset.cbBuilderIframeUrlValue = 'http://example.test/url1';
        iframe.setAttribute('src', 'http://example.test/already-loaded');

        controller.open();

        expect(iframe.getAttribute('src')).toBe('http://example.test/already-loaded');
    });
});

describe('cb-builder-launcher: close guard', () => {
    let confirmSpy;

    beforeEach(() => {
        confirmSpy = vi.spyOn(window, 'confirm');
    });

    it('closes immediately when sidebar has no open form', () => {
        const { controller, dialog } = setup();
        dialog.setAttribute('open', '');

        controller.close({ preventDefault: () => {} });

        expect(dialog.close).toHaveBeenCalled();
        expect(confirmSpy).not.toHaveBeenCalled();
    });

    it('asks for confirmation when sidebar has an open form', () => {
        const { controller, dialog } = setup({ withSidebarForm: true });
        dialog.setAttribute('open', '');
        confirmSpy.mockReturnValue(true);

        controller.close({ preventDefault: () => {} });

        expect(confirmSpy).toHaveBeenCalledWith('Discard?');
        expect(dialog.close).toHaveBeenCalled();
    });

    it('keeps the dialog open when the user cancels the confirmation', () => {
        const { controller, dialog } = setup({ withSidebarForm: true });
        dialog.setAttribute('open', '');
        confirmSpy.mockReturnValue(false);

        controller.close({ preventDefault: () => {} });

        expect(confirmSpy).toHaveBeenCalled();
        expect(dialog.close).not.toHaveBeenCalled();
    });

    it('preventDefault on the native cancel event when user declines', () => {
        const { controller } = setup({ withSidebarForm: true });
        confirmSpy.mockReturnValue(false);

        const event = new Event('cancel', { cancelable: true });
        controller._onCancel(event);

        expect(confirmSpy).toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(true);
    });

    it('lets the cancel event proceed when user accepts', () => {
        const { controller } = setup({ withSidebarForm: true });
        confirmSpy.mockReturnValue(true);

        const event = new Event('cancel', { cancelable: true });
        controller._onCancel(event);

        expect(event.defaultPrevented).toBe(false);
    });
});
