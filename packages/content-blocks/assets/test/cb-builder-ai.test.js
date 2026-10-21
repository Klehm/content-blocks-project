import { describe, it, expect, beforeEach, vi } from 'vitest';
import Controller from '../controllers/cb-builder_controller.js';

/**
 * The AI actions in the builder: a field's menu offers only the actions the
 * server drew, a proposal is written only on Replace, and the generation
 * dialog says every refusal instead of failing quietly.
 */

const STRINGS = {
    rewrite: 'Rewrite',
    shorten: 'Shorten',
    tone: 'Tone: %tone%',
    alt_text: 'Describe the image',
    apply: 'Replace',
    no_image: 'Choose an image first',
    empty_field: 'Write something first',
    done: 'Generated as a draft',
    error_sections_locked: 'Sections cannot be added',
    error_provider_failed: 'The AI service did not answer',
    needs_section: 'Select the section to change first',
    edit_hint: 'The section is replaced by its new version',
};

function setup({ actions = 'rewrite shorten tone alt_text add edit_section edit_page' } = {}) {
    document.body.innerHTML = `
        <div data-controller="cb-builder" data-cb-csrf-token="tok" data-cb-api-base="/api">
            <div class="cb-ai"
                 data-cb-ai-actions="${actions}"
                 data-cb-ai-tones='[{"value":"friendly","label":"Friendly"}]'
                 data-cb-ai-strings='${JSON.stringify(STRINGS)}'>
                <div class="cb-ai-popover" hidden></div>
                <dialog class="cb-ai-dialog">
                    <form>
                        <textarea name="prompt"></textarea>
                        <input type="radio" name="scope" value="add" checked>
                        <input type="radio" name="scope" value="edit_section">
                        <input type="radio" name="scope" value="edit_page">
                        <p class="status"></p>
                        <button type="submit" class="submit">Generate</button>
                    </form>
                </dialog>
            </div>
            <aside data-cb-sidebar-block-id="7" data-cb-sidebar-section-id="3">
                <div class="cb-form-row">
                    <button type="button" class="cb-ai-trigger trigger-title">✦</button>
                    <input id="content_block_title" name="content_block[items][0][title]" value="A long title.">
                </div>
                <input id="content_block_src" type="hidden" value="/uploads/shoe.png">
                <input id="content_block_alt" name="content_block[alt]" value="">
            </aside>
        </div>
    `;
    const element = document.querySelector('[data-controller="cb-builder"]');
    const dialog = element.querySelector('dialog');
    // jsdom has no showModal / close.
    dialog.showModal = function showModal() { this.open = true; };
    dialog.close = function close() { this.open = false; };

    const controller = new Controller();
    const targets = {
        ai: element.querySelector('.cb-ai'),
        aiPopover: element.querySelector('.cb-ai-popover'),
        aiDialog: dialog,
        aiPrompt: dialog.querySelector('textarea'),
        aiStatus: dialog.querySelector('.status'),
        aiSubmit: dialog.querySelector('.submit'),
        sidebar: element.querySelector('aside'),
    };
    Object.defineProperty(controller, 'element', { value: element });
    for (const [name, node] of Object.entries(targets)) {
        Object.defineProperty(controller, `${name}Target`, { value: node });
        Object.defineProperty(controller, `has${name[0].toUpperCase()}${name.slice(1)}Target`, { value: true });
    }
    Object.defineProperty(controller, 'areaIdValue', { value: 42 });
    controller._afterStructuralOp = vi.fn();
    controller._scrollPreviewTo = vi.fn();
    controller._notify = vi.fn();
    controller._postToPreview = vi.fn();

    return { controller, element, ...targets };
}

function openField(controller, element, params) {
    controller.openAiField({
        preventDefault() {},
        params,
        currentTarget: element.querySelector('.trigger-title'),
    });
}

const items = (popover) => [...popover.querySelectorAll('.cb-ai-popover__item')].map((b) => b.textContent);

describe('cb-builder AI: field menu', () => {
    let ctx;
    beforeEach(() => { ctx = setup(); });

    it('offers the text actions the server drew, one entry per tone', () => {
        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });

        expect(ctx.aiPopover.hidden).toBe(false);
        expect(items(ctx.aiPopover)).toEqual(['Rewrite', 'Shorten', 'Tone: Friendly']);
    });

    it('leaves out an action that is switched off', () => {
        ctx = setup({ actions: 'shorten section' });
        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });

        expect(items(ctx.aiPopover)).toEqual(['Shorten']);
    });

    it('offers only the description beside an image', () => {
        openField(ctx.controller, ctx.element, { kind: 'alt', field: 'content_block_alt', image: 'content_block_src' });

        expect(items(ctx.aiPopover)).toEqual(['Describe the image']);
    });

    it('sends the field path and the open block, and replaces only on demand', async () => {
        ctx.controller._performJsonRequest = vi.fn().mockResolvedValue({ text: 'Short.' });
        const input = document.getElementById('content_block_title');
        const changed = vi.fn();
        input.addEventListener('change', changed);

        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });
        ctx.aiPopover.querySelector('[data-cb-ai-action="shorten"]').click();
        await vi.waitFor(() => expect(ctx.aiPopover.querySelector('.cb-ai-popover__text')).not.toBeNull());

        expect(ctx.controller._performJsonRequest).toHaveBeenCalledWith('POST', '/api/area/42/ai/text', {
            action: 'shorten',
            text: 'A long title.',
            html: false,
            blockId: 7,
            field: 'items.0.title',
        }, expect.anything());
        // A proposal is not a change.
        expect(input.value).toBe('A long title.');

        ctx.aiPopover.querySelector('.cb-ai-popover__btn--primary').click();

        expect(input.value).toBe('Short.');
        expect(changed).toHaveBeenCalledTimes(1);
        expect(ctx.aiPopover.hidden).toBe(true);
    });

    it('sends the tone it was picked with', async () => {
        ctx.controller._performJsonRequest = vi.fn().mockResolvedValue({ text: 'Hi!' });

        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });
        ctx.aiPopover.querySelector('[data-cb-ai-action="tone"]').click();

        await vi.waitFor(() => expect(ctx.controller._performJsonRequest).toHaveBeenCalled());
        expect(ctx.controller._performJsonRequest.mock.calls[0][2].tone).toBe('friendly');
    });

    it('lets a rich-text editor take the value itself', () => {
        const input = document.getElementById('content_block_title');
        input.addEventListener('cb:field:set', (event) => event.preventDefault());

        Controller.setFieldValue(input, '<p>New</p>');

        expect(input.value).toBe('A long title.');
    });

    it('asks for an image before describing one', async () => {
        document.getElementById('content_block_src').value = '';
        ctx.controller._performJsonRequest = vi.fn();

        openField(ctx.controller, ctx.element, { kind: 'alt', field: 'content_block_alt', image: 'content_block_src' });
        ctx.aiPopover.querySelector('.cb-ai-popover__item').click();

        expect(ctx.aiPopover.textContent).toContain('Choose an image first');
        expect(ctx.controller._performJsonRequest).not.toHaveBeenCalled();
    });

    it('describes the image the sibling field holds', async () => {
        ctx.controller._performJsonRequest = vi.fn().mockResolvedValue({ text: 'A red shoe' });

        openField(ctx.controller, ctx.element, { kind: 'alt', field: 'content_block_alt', image: 'content_block_src' });
        ctx.aiPopover.querySelector('.cb-ai-popover__item').click();
        await vi.waitFor(() => expect(ctx.aiPopover.querySelector('.cb-ai-popover__text')).not.toBeNull());

        expect(ctx.controller._performJsonRequest).toHaveBeenCalledWith(
            'POST', '/api/area/42/ai/alt-text', { src: '/uploads/shoe.png', blockId: 7 }, expect.anything(),
        );
    });

    it('says a failure and offers to retry', async () => {
        ctx.controller._performJsonRequest = vi.fn().mockResolvedValue({ error: 'provider_failed' });

        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });
        ctx.aiPopover.querySelector('[data-cb-ai-action="rewrite"]').click();
        await vi.waitFor(() => expect(ctx.aiPopover.textContent).toContain('The AI service did not answer'));

        expect(ctx.aiPopover.querySelectorAll('.cb-ai-popover__btn')).toHaveLength(2);
    });

    it('drops an answer arriving after the popover closed', async () => {
        let resolve;
        ctx.controller._performJsonRequest = vi.fn(() => new Promise((r) => { resolve = r; }));

        openField(ctx.controller, ctx.element, { kind: 'text', field: 'content_block_title' });
        ctx.aiPopover.querySelector('[data-cb-ai-action="rewrite"]').click();
        ctx.controller.closeAiPopover();
        resolve({ text: 'Late' });
        await Promise.resolve();

        expect(ctx.aiPopover.hidden).toBe(true);
        expect(ctx.aiPopover.children).toHaveLength(0);
    });
});

describe('cb-builder AI: field path', () => {
    it('reads the data path out of the input name', () => {
        expect(Controller.aiFieldPath('content_block[items][0][title]')).toBe('items.0.title');
        expect(Controller.aiFieldPath('content_block[text]')).toBe('text');
    });
});

describe('cb-builder AI: generation dialog', () => {
    let ctx;
    beforeEach(() => { ctx = setup(); });

    it('adds content after the open section', async () => {
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue({ sectionIds: [12], droppedFields: [], skippedBlockTypes: [] });
        ctx.controller.openAiDialog();
        ctx.aiPrompt.value = 'A hero for our shoe';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest).toHaveBeenCalledWith('POST', '/api/area/42/ai/content', {
            action: 'add',
            prompt: 'A hero for our shoe',
            targetSectionId: 3,
        }, expect.anything());
        expect(ctx.aiDialog.open).toBe(false);
        expect(ctx.controller._notify).toHaveBeenCalledWith('Generated as a draft');
        expect(ctx.controller._scrollPreviewTo).toHaveBeenCalledWith(12);
        expect(ctx.controller._afterStructuralOp).toHaveBeenCalled();
    });

    it('adds at the end when nothing is selected', async () => {
        ctx.sidebar.removeAttribute('data-cb-sidebar-section-id');
        ctx.sidebar.removeAttribute('data-cb-sidebar-block-id');
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue({ sectionIds: [], droppedFields: [], skippedBlockTypes: [] });
        ctx.controller.openAiDialog();
        ctx.aiPrompt.value = 'A page';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest.mock.calls[0][2]).toEqual({ action: 'add', prompt: 'A page' });
    });

    it('keeps the dialog open and says a refusal', async () => {
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue({ error: 'sections_locked' });
        ctx.controller.openAiDialog();
        ctx.aiPrompt.value = 'A page';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.aiDialog.open).toBe(true);
        expect(ctx.aiStatus.textContent).toBe('Sections cannot be added');
        expect(ctx.aiSubmit.disabled).toBe(false);
        expect(ctx.controller._afterStructuralOp).not.toHaveBeenCalled();
    });

    it('sends nothing for a blank prompt', async () => {
        ctx.controller._jsonRequest = vi.fn();
        ctx.controller.openAiDialog();

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest).not.toHaveBeenCalled();
    });
});

describe('cb-builder AI: revising what is there', () => {
    let ctx;
    beforeEach(() => { ctx = setup(); });

    const ok = { sectionIds: [21], droppedFields: [], skippedBlockTypes: [] };

    it('opens on the section the preview toolbar named, and sends it', async () => {
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue(ok);

        ctx.controller.openAiSectionEdit(9);

        expect(ctx.aiDialog.open).toBe(true);
        expect(ctx.aiDialog.querySelector('input[value="edit_section"]').checked).toBe(true);
        expect(ctx.aiStatus.textContent).toBe('The section is replaced by its new version');

        ctx.aiPrompt.value = 'Shorter, punchier';
        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest.mock.calls[0][2]).toEqual({
            action: 'edit_section',
            prompt: 'Shorter, punchier',
            targetSectionId: 9,
        });
    });

    it('falls back on the selection, a block naming its section server-side', async () => {
        ctx.sidebar.removeAttribute('data-cb-sidebar-section-id');
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue(ok);
        ctx.controller.openAiDialog();
        ctx.aiDialog.querySelector('input[value="edit_section"]').checked = true;
        ctx.aiPrompt.value = 'x';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest.mock.calls[0][2]).toEqual({ action: 'edit_section', prompt: 'x', targetBlockId: 7 });
    });

    it('asks for a section rather than guessing one', async () => {
        ctx.sidebar.removeAttribute('data-cb-sidebar-section-id');
        ctx.sidebar.removeAttribute('data-cb-sidebar-block-id');
        ctx.controller._jsonRequest = vi.fn();
        ctx.controller.openAiDialog();
        ctx.aiDialog.querySelector('input[value="edit_section"]').checked = true;
        ctx.controller.onAiScopeChange();
        ctx.aiPrompt.value = 'x';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest).not.toHaveBeenCalled();
        expect(ctx.aiStatus.textContent).toBe('Select the section to change first');
    });

    it('revises the page with no target', async () => {
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue(ok);
        ctx.controller.openAiDialog();
        ctx.aiDialog.querySelector('input[value="edit_page"]').checked = true;
        ctx.controller.onAiScopeChange();
        ctx.aiPrompt.value = 'x';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest.mock.calls[0][2]).toEqual({ action: 'edit_page', prompt: 'x' });
    });

    it('a toolbar target does not outlive its dialog', async () => {
        ctx.controller._jsonRequest = vi.fn().mockResolvedValue(ok);
        ctx.controller.openAiSectionEdit(9);
        ctx.controller.closeAiDialog();
        ctx.controller.openAiDialog();
        ctx.aiDialog.querySelector('input[value="edit_section"]').checked = true;
        ctx.aiPrompt.value = 'x';

        await ctx.controller.submitAiDialog({ preventDefault() {} });

        expect(ctx.controller._jsonRequest.mock.calls[0][2].targetSectionId).toBe(3);
    });

    it('tells the preview it may offer the toolbar button, only then', () => {
        ctx.controller._announceAiToPreview();
        expect(ctx.controller._postToPreview).toHaveBeenCalledWith({ type: 'cb:ai:capabilities', editSection: true });

        const off = setup({ actions: 'add edit_page' });
        off.controller._announceAiToPreview();
        expect(off.controller._postToPreview).not.toHaveBeenCalled();
    });

    it('opens from the preview message', () => {
        ctx.controller._onMessage({
            origin: window.location.origin,
            data: { type: 'cb:ai:section-requested', sectionId: 5 },
        });

        expect(ctx.aiDialog.open).toBe(true);
        expect(ctx.controller._aiTargetSectionId).toBe(5);
    });
});
