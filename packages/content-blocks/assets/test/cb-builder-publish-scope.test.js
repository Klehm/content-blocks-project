import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Publish and Discard ask which locales they cover, in a dialog the shell
 * renders only when a bundle keeps locale drafts (the i18n package).
 */

const DIALOG = `
    <dialog class="cb-publish-scope"
            data-cb-publish-scope-strings='{"publish_confirm":"Publier","layout_note":"Mise en page commune"}'>
        <h2 class="cb-publish-scope__title"></h2>
        <p class="cb-publish-scope__intro"></p>
        <ul class="cb-publish-scope__list"></ul>
        <p class="cb-publish-scope__note" hidden></p>
        <p class="cb-publish-scope__hint" hidden></p>
        <button type="button" class="cb-publish-scope__cancel"></button>
        <button type="button" class="cb-publish-scope__confirm"></button>
    </dialog>`;

function setup({ dialog = true } = {}) {
    document.body.innerHTML = `<div data-controller="cb-builder">${dialog ? DIALOG : ''}</div>`;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const target = element.querySelector('dialog');
    if (target) {
        // jsdom has no <dialog> behaviour: open, close with a value, `close`.
        target.showModal = vi.fn(() => target.setAttribute('open', ''));
        target.close = vi.fn((value = '') => {
            target.returnValue = value;
            target.removeAttribute('open');
            target.dispatchEvent(new Event('close'));
        });
    }

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'areaIdValue', { value: 7 });
    Object.defineProperty(controller, 'hasPublishScopeDialogTarget', { value: target !== null });
    if (target) Object.defineProperty(controller, 'publishScopeDialogTarget', { value: target });

    vi.spyOn(controller, 'reload').mockImplementation(() => {});
    vi.spyOn(controller, '_applyDraftState').mockImplementation(() => {});
    vi.spyOn(controller, '_hideUndo').mockImplementation(() => {});

    return { controller, dialog: target };
}

/** Routes the GET for the scope to `scope`, every POST to a clean state. */
function answer(controller, scope) {
    return vi.spyOn(controller, '_jsonRequest').mockImplementation(async (method) => (
        method === 'GET' ? scope : { hasUnpublishedChanges: false }
    ));
}

const flush = () => new Promise((resolve) => setTimeout(resolve, 0));

function posts(spy) {
    return spy.mock.calls.filter(([method]) => method === 'POST');
}

describe('cb-builder: which locales Publish and Discard cover', () => {
    beforeEach(() => {
        vi.restoreAllMocks();
    });

    it('asks nothing without the dialog, as before i18n', async () => {
        const { controller } = setup({ dialog: false });
        const spy = answer(controller, null);

        await controller.publish();

        expect(spy).toHaveBeenCalledTimes(1);
        expect(spy).toHaveBeenCalledWith('POST', '/_content-blocks/area/7/publish', undefined, { tolerate: [409] });
    });

    it('publishes everything without asking when no locale is pending', async () => {
        const { controller, dialog } = setup();
        const spy = answer(controller, { hasOwnDraft: true, locales: [] });

        await controller.publish();

        expect(dialog.showModal).not.toHaveBeenCalled();
        expect(posts(spy)).toEqual([['POST', '/_content-blocks/area/7/publish', undefined, { tolerate: [409] }]]);
    });

    it('lists the pending locales, all ticked, and publishes the ones kept', async () => {
        const { controller, dialog } = setup();
        const spy = answer(controller, {
            hasOwnDraft: true,
            locales: [{ code: 'en', label: 'English' }, { code: 'de', label: 'Deutsch' }],
        });

        const done = controller.publish();
        await flush();

        const boxes = [...dialog.querySelectorAll('input[type=checkbox]')];
        expect(boxes.map((b) => [b.value, b.checked])).toEqual([['en', true], ['de', true]]);
        expect(dialog.querySelector('.cb-publish-scope__list').textContent).toContain('Deutsch');
        expect(dialog.querySelector('.cb-publish-scope__confirm').textContent).toBe('Publier');

        boxes[1].checked = false;
        boxes[1].dispatchEvent(new Event('change', { bubbles: true }));
        dialog.querySelector('.cb-publish-scope__confirm').click();
        await done;

        expect(posts(spy)).toEqual([
            ['POST', '/_content-blocks/area/7/publish', { locales: ['en'] }, { tolerate: [409] }],
        ]);
        expect(controller.reload).toHaveBeenCalled();
    });

    // The page's own draft goes live; every translation keeps its draft.
    it('sends an empty list when every locale is unticked', async () => {
        const { controller, dialog } = setup();
        const spy = answer(controller, { hasOwnDraft: true, locales: [{ code: 'en', label: 'English' }] });

        const done = controller.publish();
        await flush();
        const box = dialog.querySelector('input[type=checkbox]');
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));

        const confirm = dialog.querySelector('.cb-publish-scope__confirm');
        expect(confirm.disabled).toBe(false);
        confirm.click();
        await done;

        expect(posts(spy)[0][2]).toEqual({ locales: [] });
    });

    // No page draft and nothing ticked: the call would do nothing at all.
    it('disables the confirmation when nothing would be published', async () => {
        const { controller, dialog } = setup();
        answer(controller, { hasOwnDraft: false, locales: [{ code: 'en', label: 'English' }] });

        controller.publish();
        await flush();
        const box = dialog.querySelector('input[type=checkbox]');
        box.checked = false;
        box.dispatchEvent(new Event('change', { bubbles: true }));

        expect(dialog.querySelector('.cb-publish-scope__confirm').disabled).toBe(true);
        expect(dialog.querySelector('.cb-publish-scope__hint').hidden).toBe(false);
    });

    it('shows the shared-layout note only when the page itself changed', async () => {
        const { controller, dialog } = setup();
        answer(controller, { hasOwnDraft: false, locales: [{ code: 'en', label: 'English' }] });

        controller.publish();
        await flush();
        expect(dialog.querySelector('.cb-publish-scope__note').hidden).toBe(true);
        dialog.close('cancel');

        answer(controller, { hasOwnDraft: true, locales: [{ code: 'en', label: 'English' }] });
        controller.publish();
        await flush();
        expect(dialog.querySelector('.cb-publish-scope__note').hidden).toBe(false);
        expect(dialog.querySelector('.cb-publish-scope__note').textContent).toBe('Mise en page commune');
    });

    it('does nothing when the dialog is cancelled', async () => {
        const { controller, dialog } = setup();
        const spy = answer(controller, { hasOwnDraft: true, locales: [{ code: 'en', label: 'English' }] });

        const done = controller.publish();
        await flush();
        dialog.querySelector('.cb-publish-scope__cancel').click();
        await done;

        expect(posts(spy)).toEqual([]);
        expect(controller._applyDraftState).not.toHaveBeenCalled();
    });

    // Escape closes the dialog with no value: the same as Cancel.
    it('treats a dialog closed without a value as cancelled', async () => {
        const { controller, dialog } = setup();
        const spy = answer(controller, { hasOwnDraft: true, locales: [{ code: 'en', label: 'English' }] });

        const done = controller.publish();
        await flush();
        expect(controller._closeTopModal()).toBe(true);
        await done;

        expect(dialog.open).toBe(false);
        expect(posts(spy)).toEqual([]);
    });

    it('publishes nothing when the scope cannot be read', async () => {
        const { controller } = setup();
        const spy = answer(controller, null);

        await controller.publish();

        expect(posts(spy)).toEqual([]);
    });

    it('discard asks in the dialog instead of the confirm prompt', async () => {
        const { controller, dialog } = setup();
        const confirmSpy = vi.spyOn(window, 'confirm');
        const spy = answer(controller, {
            hasOwnDraft: true,
            locales: [{ code: 'en', label: 'English' }, { code: 'de', label: 'Deutsch' }],
        });

        const done = controller.discard();
        await flush();
        expect(dialog.querySelector('.cb-publish-scope__confirm')
            .classList.contains('cb-publish-scope__confirm--danger')).toBe(true);
        const keep = dialog.querySelectorAll('input[type=checkbox]')[0];
        keep.checked = false;
        keep.dispatchEvent(new Event('change', { bubbles: true }));
        dialog.querySelector('.cb-publish-scope__confirm').click();
        await done;

        expect(confirmSpy).not.toHaveBeenCalled();
        expect(posts(spy)).toEqual([
            ['POST', '/_content-blocks/area/7/discard', { locales: ['de'] }, { tolerate: [409] }],
        ]);
    });

    it('discard keeps its confirm prompt when no locale is pending', async () => {
        const { controller } = setup();
        const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true);
        const spy = answer(controller, { hasOwnDraft: true, locales: [] });

        await controller.discard();

        expect(confirmSpy).toHaveBeenCalled();
        expect(posts(spy)).toEqual([['POST', '/_content-blocks/area/7/discard', undefined, { tolerate: [409] }]]);
    });

    // A label is server data: it is text, never markup.
    it('renders locale labels as text', async () => {
        const { controller, dialog } = setup();
        answer(controller, { hasOwnDraft: true, locales: [{ code: 'xx', label: '<img src=x onerror=alert(1)>' }] });

        controller.publish();
        await flush();

        expect(dialog.querySelector('img')).toBeNull();
        expect(dialog.querySelector('.cb-publish-scope__name').textContent).toBe('<img src=x onerror=alert(1)>');
    });
});
