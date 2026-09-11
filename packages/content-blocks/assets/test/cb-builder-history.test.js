import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Ctrl/Cmd-Z is keyboard-only, like copy/paste, so the same two things carry
 * it: the chord must reach the right method without stealing a keystroke the
 * editor meant for their text, and every refusal must arrive as a sentence.
 *
 * The stack itself lives on the server — nothing here re-implements it.
 */

function setupController() {
    document.body.innerHTML = `
        <div data-controller="cb-builder"
             data-cb-csrf-token="tok"
             data-i18n-cb-builder-history-nothing-to-undo="Nothing to undo"
             data-i18n-cb-builder-history-nothing-to-redo="Nothing to redo"
             data-i18n-cb-builder-history-stale="Page moved under this step"
             data-i18n-cb-builder-history-unavailable="Undo unavailable">
            <div class="cb-shell__undo" hidden>
                <span class="cb-shell__undo-label"></span>
                <button type="button" class="cb-shell__undo-btn"></button>
            </div>
            <div class="cb-shell__history">
                <button type="button" class="cb-shell__history-btn cb-shell__history-btn--undo" disabled></button>
                <button type="button" class="cb-shell__history-btn cb-shell__history-btn--redo" disabled></button>
            </div>
            <aside></aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const undoBar = element.querySelector('.cb-shell__undo');
    const undoLabel = element.querySelector('.cb-shell__undo-label');
    const undoButton = element.querySelector('.cb-shell__undo-btn');

    const undoBtn = element.querySelector('.cb-shell__history-btn--undo');
    const redoBtn = element.querySelector('.cb-shell__history-btn--redo');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'application', {
        value: { getControllerForElementAndIdentifier: () => null },
        configurable: true,
    });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: element.querySelector('aside') });
    Object.defineProperty(controller, 'hasUndoBarTarget', { value: true });
    Object.defineProperty(controller, 'undoBarTarget', { value: undoBar });
    Object.defineProperty(controller, 'hasUndoLabelTarget', { value: true });
    Object.defineProperty(controller, 'undoLabelTarget', { value: undoLabel });
    Object.defineProperty(controller, 'hasUndoButtonTarget', { value: true });
    Object.defineProperty(controller, 'undoButtonTarget', { value: undoButton });
    Object.defineProperty(controller, 'areaIdValue', { value: 42 });

    controller.reload = vi.fn();
    controller._applyDraftState = vi.fn();
    controller._resetSidebarToEmptyState = vi.fn();

    return { controller, undoBar, undoLabel, undoButton, undoBtn, redoBtn };
}

function answer(controller, payload) {
    controller._jsonRequest = vi.fn().mockResolvedValue(payload);
}

/** Puts an autosave host in the sidebar and hands back its flush(). */
function stubAutosave(controller, flush) {
    const host = document.createElement('div');
    host.setAttribute('data-controller', 'cb-autosave');
    const content = document.createElement('div');
    content.appendChild(host);
    Object.defineProperty(controller, 'hasSidebarContentTarget', { value: true, configurable: true });
    Object.defineProperty(controller, 'sidebarContentTarget', { value: content, configurable: true });
    Object.defineProperty(controller, 'application', {
        value: { getControllerForElementAndIdentifier: () => ({ flush }) },
        configurable: true,
    });
}

describe('cb-builder history: the round trip', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
    });

    it('posts undo and redo to their own endpoints', async () => {
        answer(controller, { status: 'ok', hasUnpublishedChanges: true });

        await controller.undoLastAction();
        expect(controller._jsonRequest).toHaveBeenCalledWith('POST', '/_content-blocks/area/42/undo', {});

        await controller.redoLastAction();
        expect(controller._jsonRequest).toHaveBeenCalledWith('POST', '/_content-blocks/area/42/redo', {});
    });

    // The server cannot rule on a form it was never told about.
    it('tells the endpoint what the sidebar has open', async () => {
        answer(controller, { status: 'ok', sidebar: 'keep', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-block-id', '7');

        await controller.undoLastAction();

        expect(controller._jsonRequest).toHaveBeenCalledWith(
            'POST',
            '/_content-blocks/area/42/undo',
            { open: { type: 'block', id: 7 } },
        );
    });

    it('refreshes the preview and the topbar from the answer', async () => {
        answer(controller, { status: 'ok', hasUnpublishedChanges: false });

        await controller.undoLastAction();

        expect(controller._applyDraftState).toHaveBeenCalledWith(false, { canUndo: false, canRedo: false });
        expect(controller.reload).toHaveBeenCalled();
    });

    // A stale form would autosave the values the undo just took back.
    it('drops the open sidebar when the server says its row is gone', async () => {
        answer(controller, { status: 'ok', sidebar: 'close', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-block-id', '7');

        await controller.undoLastAction();

        expect(controller._resetSidebarToEmptyState).toHaveBeenCalled();
    });

    // Closing on every undo is what cost the editor their place.
    it('leaves an untouched sidebar alone, caret included', async () => {
        answer(controller, { status: 'ok', sidebar: 'keep', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-block-id', '7');
        controller._mountSidebar = vi.fn();

        await controller.undoLastAction();

        expect(controller._resetSidebarToEmptyState).not.toHaveBeenCalled();
        expect(controller._mountSidebar).not.toHaveBeenCalled();
    });

    it('refetches the open form when the step moved its own values', async () => {
        answer(controller, { status: 'ok', sidebar: 'reload', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-block-id', '7');
        controller._mountSidebar = vi.fn();

        await controller.undoLastAction();

        expect(controller._mountSidebar).toHaveBeenCalledWith(7);
        expect(controller._resetSidebarToEmptyState).not.toHaveBeenCalled();
    });

    it('reloads a section sidebar through its own endpoint', async () => {
        answer(controller, { status: 'ok', sidebar: 'reload', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-section-id', '3');
        controller._mountSectionSettings = vi.fn();

        await controller.undoLastAction();

        expect(controller._mountSectionSettings).toHaveBeenCalledWith(3);
    });

    // An older server, or a verdict we do not know: close rather than keep a
    // form over a row that may be gone.
    it('closes when the answer carries no verdict', async () => {
        answer(controller, { status: 'ok', hasUnpublishedChanges: true });
        controller.sidebarTarget.setAttribute('data-cb-sidebar-block-id', '7');

        await controller.undoLastAction();

        expect(controller._resetSidebarToEmptyState).toHaveBeenCalled();
    });

    it('consumes a pending delete-undo offer, which the stack now owns', async () => {
        answer(controller, { status: 'ok', hasUnpublishedChanges: true });
        controller._offerUndo('block', 7);

        await controller.undoLastAction();

        expect(controller._pendingUndo).toBeNull();
    });

    /**
     * The chord fires from inside a focused field now, so the edit under it
     * may not be journalled — undoing first would skip a step.
     */
    it('commits the open edit before asking for the step before it', async () => {
        answer(controller, { status: 'ok', sidebar: 'keep', hasUnpublishedChanges: true });
        const order = [];
        const flush = vi.fn(() => {
            order.push('flush');

            return false;
        });
        stubAutosave(controller, flush);
        controller._jsonRequest = vi.fn(async () => {
            order.push('undo');

            return { status: 'ok', sidebar: 'keep', hasUnpublishedChanges: true };
        });

        await controller.undoLastAction();

        expect(order).toEqual(['flush', 'undo']);
    });

    it('waits for the flushed save to land before undoing', async () => {
        stubAutosave(controller, vi.fn(() => true));
        controller._jsonRequest = vi.fn().mockResolvedValue({ status: 'ok', sidebar: 'keep' });

        const running = controller.undoLastAction();
        await Promise.resolve();
        expect(controller._jsonRequest).not.toHaveBeenCalled();

        controller.element.dispatchEvent(new CustomEvent('cb:block:saved'));
        await running;

        expect(controller._jsonRequest).toHaveBeenCalled();
    });

    it('leaves everything alone when the request itself failed', async () => {
        answer(controller, null);

        await controller.undoLastAction();

        expect(controller.reload).not.toHaveBeenCalled();
        expect(controller._resetSidebarToEmptyState).not.toHaveBeenCalled();
    });
});

describe('cb-builder history: refusals reach the editor', () => {
    let controller, undoLabel, undoButton;

    beforeEach(() => {
        ({ controller, undoLabel, undoButton } = setupController());
    });

    it.each([
        ['nothing', 'undoLastAction', 'Nothing to undo'],
        ['nothing', 'redoLastAction', 'Nothing to redo'],
        ['stale', 'undoLastAction', 'Page moved under this step'],
        ['unavailable', 'undoLastAction', 'Undo unavailable'],
    ])('says so when the server answers %s to %s', async (status, method, message) => {
        answer(controller, { status, hasUnpublishedChanges: true });

        await controller[method]();

        expect(undoLabel.textContent).toBe(message);
        expect(undoButton.hidden).toBe(true);
        expect(controller.reload).not.toHaveBeenCalled();
    });
});

describe('cb-builder history: the shortcut', () => {
    let controller;

    beforeEach(() => {
        ({ controller } = setupController());
        controller.undoLastAction = vi.fn();
        controller.redoLastAction = vi.fn();
    });

    function press(key, init = {}) {
        const event = new KeyboardEvent('keydown', { key, ctrlKey: true, cancelable: true, ...init });
        controller._onDocumentKeydown(event);

        return event;
    }

    it('undoes on Ctrl-Z and on Cmd-Z', () => {
        press('z');
        press('z', { ctrlKey: false, metaKey: true });

        expect(controller.undoLastAction).toHaveBeenCalledTimes(2);
    });

    it('redoes on Ctrl-Shift-Z and on Ctrl-Y', () => {
        press('z', { shiftKey: true });
        press('y');

        expect(controller.redoLastAction).toHaveBeenCalledTimes(2);
        expect(controller.undoLastAction).not.toHaveBeenCalled();
    });

    function focusField(html) {
        document.body.insertAdjacentHTML('beforeend', html);
        const field = document.body.lastElementChild;
        field.focus();

        return field;
    }

    it('keeps out of a field the editor is typing in', () => {
        const input = focusField('<input>');

        const event = press('z');

        expect(controller.undoLastAction).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
        input.remove();
    });

    it.each([
        ['a textarea', '<textarea></textarea>'],
        ['a contenteditable', '<div contenteditable="true" tabindex="0"></div>'],
        ['a number input', '<input type="number">'],
        // Not listed as undoless, so it is assumed to take typing.
        ['an unknown input type', '<input type="future-thing">'],
    ])('leaves Ctrl-Z to %s, which has its own undo', (_label, html) => {
        const field = focusField(html);

        press('z');

        expect(controller.undoLastAction).not.toHaveBeenCalled();
        field.remove();
    });

    // The bug this fixes: these had to be blurred before Ctrl-Z did anything.
    it.each([
        ['a select', '<select><option>a</option></select>'],
        ['a colour swatch', '<input type="color">'],
        ['a range', '<input type="range">'],
        ['a checkbox', '<input type="checkbox">'],
    ])('undoes from inside %s, which undoes nothing itself', (_label, html) => {
        const field = focusField(html);

        press('z');

        expect(controller.undoLastAction).toHaveBeenCalled();
        field.remove();
    });

    // Undo has no selection to steal, unlike copy — which still yields.
    it('undoes through a text selection, and still lets copy have it', () => {
        const p = document.createElement('p');
        p.textContent = 'quote me';
        document.body.appendChild(p);
        const range = document.createRange();
        range.selectNodeContents(p);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);
        controller.copySelection = vi.fn();

        press('z');
        press('c');

        expect(controller.undoLastAction).toHaveBeenCalled();
        expect(controller.copySelection).not.toHaveBeenCalled();
        window.getSelection().removeAllRanges();
        p.remove();
    });

    it('still keeps copy and paste out of any focused field', () => {
        const select = focusField('<select><option>a</option></select>');
        controller.copySelection = vi.fn();
        controller.pasteClipboard = vi.fn();

        press('c');
        press('v');

        expect(controller.copySelection).not.toHaveBeenCalled();
        expect(controller.pasteClipboard).not.toHaveBeenCalled();
        select.remove();
    });

    it('leaves Alt-decorated combos to the browser', () => {
        const event = press('z', { altKey: true });

        expect(controller.undoLastAction).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    // Ctrl-Shift-V is a browser paste-as-plain-text, not our redo.
    it('does not answer to Shift on the clipboard chords', () => {
        expect(Controller.shortcutIntent('v', true)).toBeNull();
        expect(Controller.shortcutIntent('c', true)).toBeNull();
        expect(Controller.shortcutIntent('y', true)).toBeNull();
    });
});

/**
 * The topbar pair. The stack is the server's, so the buttons only ever mirror
 * it — optimistically after a mutation, authoritatively after an undo.
 */
describe('cb-builder history: the topbar buttons', () => {
    let controller, undoBtn, redoBtn;

    beforeEach(() => {
        ({ controller, undoBtn, redoBtn } = setupController());
        controller._applyDraftState = Controller.prototype._applyDraftState.bind(controller);
        controller._invalidateTree = vi.fn();
    });

    // A mutation always leaves something to undo and no future to redo.
    it('lights undo and clears redo after any mutation', () => {
        controller._applyDraftState(true);

        expect(undoBtn.disabled).toBe(false);
        expect(redoBtn.disabled).toBe(true);
    });

    // Publish and discard both empty the stack server-side.
    it('greys both when the draft goes clean', () => {
        controller._applyDraftState(true);
        controller._applyDraftState(false);

        expect(undoBtn.disabled).toBe(true);
        expect(redoBtn.disabled).toBe(true);
    });

    it('takes the counts from an undo rather than guessing', async () => {
        answer(controller, {
            status: 'ok',
            sidebar: 'keep',
            hasUnpublishedChanges: false,
            canUndo: false,
            canRedo: true,
        });

        await controller.undoLastAction();

        // Clean draft, yet a redo is still on the stack: only the server knows.
        expect(undoBtn.disabled).toBe(true);
        expect(redoBtn.disabled).toBe(false);
    });

    // "Nothing to undo" is exactly what the button needs to go grey.
    it('syncs on a refusal too', async () => {
        controller._applyDraftState(true);
        answer(controller, { status: 'nothing', canUndo: false, canRedo: false });

        await controller.undoLastAction();

        expect(undoBtn.disabled).toBe(true);
    });
});
