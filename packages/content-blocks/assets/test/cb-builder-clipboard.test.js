import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * Copy / paste is keyboard-only, so two things carry the whole feature and
 * both are pinned here: the shortcut must fire on the right entity *and* keep
 * its hands off a genuine text copy, and every refusal must reach the editor
 * as a sentence rather than as nothing happening.
 *
 * The clipboard lives in localStorage — jsdom gives us a real one, so these
 * tests exercise the actual store rather than a stub of it.
 */

const CLIPBOARD_KEY = 'cb-builder.clipboard';

const SECTION_ENTRY = {
    format: 'content-blocks/clipboard-v1',
    scope: 'section',
    contentVersion: 1,
    payload: { format: 'content-blocks/section-v1', layout: 'full', columns: [] },
};

function setupController() {
    document.body.innerHTML = `
        <div data-controller="cb-builder"
             data-cb-csrf-token="tok"
             data-i18n-cb-builder-clipboard-section-copied="Section copied"
             data-i18n-cb-builder-clipboard-block-copied="Block copied"
             data-i18n-cb-builder-clipboard-nothing-selected="Select something to copy"
             data-i18n-cb-builder-clipboard-empty="Nothing copied yet"
             data-i18n-cb-builder-clipboard-no-target="Select a section or a block first"
             data-i18n-cb-builder-clipboard-stale-version="Copied under another schema version">
            <div class="cb-shell__undo" hidden>
                <span class="cb-shell__undo-label"></span>
                <button type="button" class="cb-shell__undo-btn"></button>
            </div>
            <aside></aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const undoBar = element.querySelector('.cb-shell__undo');
    const undoLabel = element.querySelector('.cb-shell__undo-label');
    const undoButton = element.querySelector('.cb-shell__undo-btn');
    const sidebar = element.querySelector('aside');

    const controller = new Controller();
    Object.defineProperty(controller, 'element', { value: element });
    Object.defineProperty(controller, 'hasSidebarTarget', { value: true });
    Object.defineProperty(controller, 'sidebarTarget', { value: sidebar });
    Object.defineProperty(controller, 'hasUndoBarTarget', { value: true });
    Object.defineProperty(controller, 'undoBarTarget', { value: undoBar });
    Object.defineProperty(controller, 'hasUndoLabelTarget', { value: true });
    Object.defineProperty(controller, 'undoLabelTarget', { value: undoLabel });
    Object.defineProperty(controller, 'hasUndoButtonTarget', { value: true });
    Object.defineProperty(controller, 'undoButtonTarget', { value: undoButton });
    Object.defineProperty(controller, 'areaIdValue', { value: 42 });

    // The reload path is out of scope here — every paste test would otherwise
    // need an iframe target.
    controller._afterStructuralOp = vi.fn();
    controller._scrollPreviewTo = vi.fn();

    return { controller, undoBar, undoLabel, undoButton, sidebar };
}

/** Selection = whatever the sidebar has open, so tests set it the same way. */
function select(sidebar, { blockId, sectionId } = {}) {
    sidebar.removeAttribute('data-cb-sidebar-block-id');
    sidebar.removeAttribute('data-cb-sidebar-section-id');
    if (blockId) sidebar.setAttribute('data-cb-sidebar-block-id', String(blockId));
    if (sectionId) sidebar.setAttribute('data-cb-sidebar-section-id', String(sectionId));
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('cb-builder clipboard: copy', () => {
    let controller, sidebar, undoLabel, undoButton;

    beforeEach(() => {
        ({ controller, sidebar, undoLabel, undoButton } = setupController());
    });

    it('copies the open block and stores the envelope it got back', async () => {
        select(sidebar, { blockId: 7, sectionId: 3 });
        controller._jsonRequest = vi.fn().mockResolvedValue(SECTION_ENTRY);

        await controller.copySelection();

        // The block wins over its section: it is the more specific selection.
        expect(controller._jsonRequest).toHaveBeenCalledWith('GET', '/_content-blocks/block/7/copy');
        expect(JSON.parse(window.localStorage.getItem(CLIPBOARD_KEY))).toEqual(SECTION_ENTRY);
        expect(undoLabel.textContent).toBe('Block copied');
    });

    it('copies the open section when no block is open', async () => {
        select(sidebar, { sectionId: 3 });
        controller._jsonRequest = vi.fn().mockResolvedValue(SECTION_ENTRY);

        await controller.copySelection();

        expect(controller._jsonRequest).toHaveBeenCalledWith('GET', '/_content-blocks/section/3/copy');
        expect(undoLabel.textContent).toBe('Section copied');
    });

    it('acknowledges with a message-only snackbar — there is nothing to undo', async () => {
        select(sidebar, { sectionId: 3 });
        controller._jsonRequest = vi.fn().mockResolvedValue(SECTION_ENTRY);

        await controller.copySelection();

        expect(undoButton.hidden).toBe(true);
        expect(controller._pendingUndo).toBeNull();
    });

    it('says so rather than copying nothing when the sidebar is empty', async () => {
        select(sidebar);
        controller._jsonRequest = vi.fn();

        await controller.copySelection();

        expect(controller._jsonRequest).not.toHaveBeenCalled();
        expect(undoLabel.textContent).toBe('Select something to copy');
        expect(window.localStorage.getItem(CLIPBOARD_KEY)).toBeNull();
    });

    it('leaves the previous entry alone when the copy request fails', async () => {
        window.localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(SECTION_ENTRY));
        select(sidebar, { sectionId: 3 });
        controller._jsonRequest = vi.fn().mockResolvedValue(null);

        await controller.copySelection();

        expect(JSON.parse(window.localStorage.getItem(CLIPBOARD_KEY))).toEqual(SECTION_ENTRY);
    });
});

describe('cb-builder clipboard: paste', () => {
    let controller, sidebar, undoLabel;

    beforeEach(() => {
        ({ controller, sidebar, undoLabel } = setupController());
        window.localStorage.setItem(CLIPBOARD_KEY, JSON.stringify(SECTION_ENTRY));
    });

    it('sends the entry with the current selection as the target', async () => {
        select(sidebar, { blockId: 7, sectionId: 3 });
        controller._jsonRequest = vi.fn().mockResolvedValue({ scope: 'section', sectionId: 9 });

        await controller.pasteClipboard();

        expect(controller._jsonRequest).toHaveBeenCalledWith(
            'POST',
            '/_content-blocks/area/42/paste',
            { payload: SECTION_ENTRY, targetBlockId: 7, targetSectionId: 3 },
            { tolerate: [422] },
        );
        expect(controller._afterStructuralOp).toHaveBeenCalled();
    });

    it('sends no target when nothing is selected', async () => {
        select(sidebar);
        controller._jsonRequest = vi.fn().mockResolvedValue({ scope: 'section', sectionId: 9 });

        await controller.pasteClipboard();

        const body = controller._jsonRequest.mock.calls[0][2];
        expect(body).toEqual({ payload: SECTION_ENTRY });
    });

    it('says the clipboard is empty rather than calling the server', async () => {
        window.localStorage.clear();
        controller._jsonRequest = vi.fn();

        await controller.pasteClipboard();

        expect(controller._jsonRequest).not.toHaveBeenCalled();
        expect(undoLabel.textContent).toBe('Nothing copied yet');
    });

    it('turns a "no target" refusal into a sentence, and keeps the entry', async () => {
        controller._jsonRequest = vi.fn().mockResolvedValue({ error: 'no_target' });

        await controller.pasteClipboard();

        expect(undoLabel.textContent).toBe('Select a section or a block first');
        expect(controller._afterStructuralOp).not.toHaveBeenCalled();
        // The editor only has to select something — the copy is still good.
        expect(window.localStorage.getItem(CLIPBOARD_KEY)).not.toBeNull();
    });

    it('drops an entry the server will never accept', async () => {
        controller._jsonRequest = vi.fn().mockResolvedValue({ error: 'incompatible_content_version' });

        await controller.pasteClipboard();

        expect(undoLabel.textContent).toBe('Copied under another schema version');
        expect(window.localStorage.getItem(CLIPBOARD_KEY)).toBeNull();
    });

    it('drops an entry that will not survive a JSON.parse', async () => {
        window.localStorage.setItem(CLIPBOARD_KEY, '{not json');
        controller._jsonRequest = vi.fn();

        await controller.pasteClipboard();

        expect(controller._jsonRequest).not.toHaveBeenCalled();
        expect(window.localStorage.getItem(CLIPBOARD_KEY)).toBeNull();
    });

    it('reports the blocks a paste had to skip', async () => {
        controller._jsonRequest = vi.fn().mockResolvedValue({
            scope: 'section',
            sectionId: 9,
            skippedBlockCount: 2,
            skippedBlockTypes: ['legacy_map'],
            droppedFields: [],
        });

        await controller.pasteClipboard();

        expect(undoLabel.textContent).toContain('legacy_map');
        expect(controller._afterStructuralOp).toHaveBeenCalled();
    });

    it('reports the fields a paste had to reset', async () => {
        controller._jsonRequest = vi.fn().mockResolvedValue({
            scope: 'block',
            sectionId: 9,
            blockId: 11,
            skippedBlockCount: 0,
            skippedBlockTypes: [],
            droppedFields: [{ blockType: 'button', droppedFields: ['url'] }],
        });

        await controller.pasteClipboard();

        expect(undoLabel.textContent).toContain('button');
    });
});

describe('cb-builder clipboard: the shortcut', () => {
    let controller, sidebar;

    beforeEach(() => {
        ({ controller, sidebar } = setupController());
        controller.copySelection = vi.fn();
        controller.pasteClipboard = vi.fn();
        select(sidebar, { sectionId: 3 });
    });

    function press(key, init = {}) {
        const event = new KeyboardEvent('keydown', { key, ctrlKey: true, cancelable: true, ...init });
        controller._onDocumentKeydown(event);

        return event;
    }

    it('copies on Ctrl-C and pastes on Ctrl-V', () => {
        press('c');
        expect(controller.copySelection).toHaveBeenCalled();

        press('v');
        expect(controller.pasteClipboard).toHaveBeenCalled();
    });

    it('answers to Cmd-C as well', () => {
        press('c', { ctrlKey: false, metaKey: true });
        expect(controller.copySelection).toHaveBeenCalled();
    });

    it('keeps out of a field the editor is typing in', () => {
        const input = document.createElement('input');
        document.body.appendChild(input);
        input.focus();

        const event = press('c');

        expect(controller.copySelection).not.toHaveBeenCalled();
        expect(event.defaultPrevented).toBe(false);
    });

    it('keeps out of a genuine text selection', () => {
        const p = document.createElement('p');
        p.textContent = 'quote me';
        document.body.appendChild(p);
        const range = document.createRange();
        range.selectNodeContents(p);
        window.getSelection().removeAllRanges();
        window.getSelection().addRange(range);

        press('c');

        expect(controller.copySelection).not.toHaveBeenCalled();
        window.getSelection().removeAllRanges();
    });

    it('ignores Ctrl-Shift-C and other decorated combos', () => {
        press('c', { shiftKey: true });
        expect(controller.copySelection).not.toHaveBeenCalled();
    });

    it('is relayed from the preview iframe', () => {
        const origin = window.location.origin;
        controller._onMessage({ origin, data: { type: 'cb:clipboard:copy-requested' } });
        expect(controller.copySelection).toHaveBeenCalled();

        controller._onMessage({ origin, data: { type: 'cb:clipboard:paste-requested' } });
        expect(controller.pasteClipboard).toHaveBeenCalled();
    });
});
