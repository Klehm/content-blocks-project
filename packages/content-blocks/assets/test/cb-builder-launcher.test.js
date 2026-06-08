import { describe, it, expect, vi } from 'vitest';
import Controller from '../controllers/cb-builder-launcher_controller.js';

/**
 * Vitest unit tests for cb-builder-launcher.
 * Stimulus runtime is not booted — we instantiate the class directly and
 * stub the framework-supplied properties.
 */

function setup() {
    document.body.innerHTML = `
        <div data-controller="cb-builder-launcher">
            <button data-action="cb-builder-launcher#open">Open</button>
            <dialog>
                <div data-controller="cb-builder">
                    <iframe data-cb-builder-target="iframe"></iframe>
                    <aside data-cb-builder-target="sidebar"></aside>
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
    controller.connect();

    return { controller, element, dialog };
}

describe('cb-builder-launcher: dialog re-parenting', () => {
    it('moves the dialog out of the launcher element to document.body on connect', () => {
        // Launcher rendered inside a host form (typical Sylius/EasyAdmin edit page).
        document.body.innerHTML = `
            <form id="host-form">
                <div data-controller="cb-builder-launcher">
                    <dialog>
                        <div data-controller="cb-builder">
                            <iframe data-cb-builder-target="iframe"></iframe>
                            <aside data-cb-builder-target="sidebar"></aside>
                        </div>
                    </dialog>
                </div>
            </form>
        `;
        const element = document.querySelector('[data-controller="cb-builder-launcher"]');
        const dialog = element.querySelector('dialog');
        dialog.showModal = vi.fn();
        dialog.close = vi.fn();

        const controller = new Controller();
        Object.defineProperty(controller, 'element', { value: element });
        Object.defineProperty(controller, 'hasDialogTarget', { value: true });
        Object.defineProperty(controller, 'dialogTarget', { value: dialog });
        controller.connect();

        // Dialog (and any forms it eventually contains) must not be nested
        // inside the host form, otherwise the browser flattens the forms
        // and Live Component POSTs lose the form data.
        expect(dialog.parentElement).toBe(document.body);
        expect(document.querySelector('#host-form dialog')).toBeNull();
    });

    it('removes the orphaned dialog from <body> on disconnect', () => {
        const { controller, dialog } = setup();
        expect(dialog.parentElement).toBe(document.body);

        controller.disconnect();

        expect(dialog.parentElement).toBeNull();
    });
});

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
