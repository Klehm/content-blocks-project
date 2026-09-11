import { test, expect } from '@playwright/test';

/**
 * E2E for the action history (Ctrl/Cmd-Z).
 *
 * The stack lives on the server, so what needs a real browser is everything
 * around it: the chord pressed *inside the preview iframe* — a separate
 * document whose keydown never reaches the builder window on its own — the
 * round trip landing in the draft rather than on the published page, and the
 * stack surviving a full page reload, which is the whole reason it is a table
 * and not a variable.
 */

async function createFreshPage(page) {
    const slug = `e2e-undo-redo-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

async function openBuilder(page, url) {
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    return page.frameLocator('.cb-shell__iframe');
}

/**
 * Blocks the editor can see. A soft-deleted one stays in the builder's own
 * preview markup, flagged — undo is a draft flag flip, not a removal.
 */
const live = (frame) => frame.locator('[data-cb-block-id]:not([data-cb-deleted])').count();

async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(300);
}

/** Hover first: a non-empty column only reveals its +Block pill on hover. */
async function addBlock(page, frame) {
    const before = await live(frame);
    const column = frame.locator('[data-cb-column-id]').first();
    await column.hover();
    await column.locator('.cb-add-block-inline').click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => live(frame)).toBe(before + 1);
    await page.waitForTimeout(300);
}

/** Fires the chord with focus inside the preview, i.e. through the relay. */
async function undo(page) {
    await page.keyboard.press('Control+z');
    await page.waitForTimeout(600);
}

async function redo(page) {
    await page.keyboard.press('Control+Shift+z');
    await page.waitForTimeout(600);
}

const snackbar = (page) => page.locator('.cb-shell__undo');

test.describe('action history — undo / redo', () => {
    test('undoes an added block from inside the preview, and redoes it', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addBlock(page, frame);

        // Focus is in the iframe: the chord only works because the overlay
        // relays it to the builder window. The bottom edge, because the
        // block's own toolbar hovers over both top corners.
        const box = await frame.locator('[data-cb-block-id]').first().boundingBox();
        await page.mouse.click(box.x + 20, box.y + box.height - 4);
        await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-block-id', /\d+/);

        await undo(page);
        await expect.poll(() => live(frame), { timeout: 5000 }).toBe(0);

        await redo(page);
        await expect.poll(() => live(frame), { timeout: 5000 }).toBe(1);
    });

    test('walks back through several actions, newest first', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addFullSection(page, frame);
        await expect.poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count()).toBe(2);

        await undo(page);
        await expect
            .poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count(), { timeout: 5000 })
            .toBe(1);

        await undo(page);
        await expect
            .poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count(), { timeout: 5000 })
            .toBe(0);
    });

    test('the stack survives a full page reload — the reason it is a table', async ({ page }) => {
        const url = await createFreshPage(page);
        const frame = await openBuilder(page, url);
        await addFullSection(page, frame);
        await addBlock(page, frame);

        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect.poll(() => live(reloaded)).toBe(1);

        await undo(page);

        await expect.poll(() => live(reloaded), { timeout: 5000 }).toBe(0);
    });

    test('says so rather than doing nothing when there is nothing left to undo', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);

        await undo(page);
        await expect
            .poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count(), { timeout: 5000 })
            .toBe(0);

        await undo(page);

        await expect(snackbar(page)).toBeVisible();
        await expect(snackbar(page).locator('.cb-shell__undo-label')).toHaveText(/Rien à annuler|Nothing to undo/);
        await expect(snackbar(page).locator('.cb-shell__undo-btn')).toBeHidden();
    });

    /**
     * Publish removes the soft-deleted rows the stack points at, so its entries
     * are dangling — an undo that "worked" there would resurrect a draft the
     * editor already committed.
     */
    test('publishing empties the stack', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addBlock(page, frame);

        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled({ timeout: 10000 });

        await undo(page);

        await expect(snackbar(page).locator('.cb-shell__undo-label')).toHaveText(/Rien à annuler|Nothing to undo/);
        await expect.poll(() => live(frame)).toBe(1);
    });
});

/**
 * The chord fires mid-edit now, which only a real browser can exercise: a
 * native `<select>` holding focus, and a sidebar that must still be there.
 */
test.describe('action history — undoing without leaving the field', () => {
    const styleSelect = (page) =>
        page.locator('.cb-shell__sidebar select[name="section_settings[styleName]"]');

    const textInput = (page) =>
        page.locator('.cb-shell__sidebar input[name="content_block[text]"]');

    /** Clicks the section chrome and waits for its settings form. */
    async function openSectionSidebar(page, frame) {
        await frame.locator('.cb-section-handle').first().click({ force: true });
        await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-section-id', /\d+/);
        await expect(styleSelect(page)).toBeVisible();

        return page.locator('.cb-shell__sidebar').getAttribute('data-cb-sidebar-section-id');
    }

    /**
     * The bug: a `<select>` has no undo of its own, so yielding to it left
     * Ctrl-Z dead until the editor blurred the field.
     */
    test('undoes from inside a select, and keeps the form open', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        const sectionId = await openSectionSidebar(page, frame);

        await styleSelect(page).selectOption('boxed');
        await expect.poll(() => styleSelect(page).inputValue(), { timeout: 5000 }).toBe('boxed');
        await page.waitForTimeout(1000);

        // Focus stays in the field: this is the whole point of the fix.
        await styleSelect(page).focus();
        await undo(page);

        await expect.poll(() => styleSelect(page).inputValue(), { timeout: 5000 }).toBe('');
        // And the editor is still on the section they were editing.
        await expect(page.locator('.cb-shell__sidebar'))
            .toHaveAttribute('data-cb-sidebar-section-id', sectionId);
    });

    /** The other half of the rule: a text field keeps its own undo stack. */
    test('leaves Ctrl-Z to a text field, which undoes its own typing', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addBlock(page, frame);
        const box = await frame.locator('[data-cb-block-id]').first().boundingBox();
        await page.mouse.click(box.x + 20, box.y + box.height - 4);
        await expect(textInput(page)).toBeVisible();

        await textInput(page).focus();
        await undo(page);

        // The block is still there: the chord never reached the builder.
        await expect.poll(() => live(frame)).toBe(1);
    });
});

/**
 * The topbar pair. What needs a browser is the state: the buttons are rendered
 * by the server on load, then kept in step by the client alone.
 */
test.describe('action history — the topbar buttons', () => {
    const undoBtn = (page) => page.locator('.cb-shell__history-btn--undo');
    const redoBtn = (page) => page.locator('.cb-shell__history-btn--redo');

    test('grey on an untouched page, then follow the stack', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await expect(undoBtn(page)).toBeDisabled();
        await expect(redoBtn(page)).toBeDisabled();

        await addFullSection(page, frame);
        await expect(undoBtn(page)).toBeEnabled();
        await expect(redoBtn(page)).toBeDisabled();

        await undoBtn(page).click();
        await expect.poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count()).toBe(0);
        await expect(undoBtn(page)).toBeDisabled();
        await expect(redoBtn(page)).toBeEnabled();

        await redoBtn(page).click();
        await expect.poll(() => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count()).toBe(1);
        await expect(undoBtn(page)).toBeEnabled();
    });

    /** The stack is a table, so the buttons must not open grey after a reload. */
    test('undo is still offered after a full page reload', async ({ page }) => {
        const url = await createFreshPage(page);
        const frame = await openBuilder(page, url);
        await addFullSection(page, frame);

        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();

        await expect(undoBtn(page)).toBeEnabled();
    });

    test('publishing greys them, because it empties the stack', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);

        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled({ timeout: 10000 });

        await expect(undoBtn(page)).toBeDisabled();
    });
});
