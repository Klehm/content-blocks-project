import { test, expect } from '@playwright/test';

/**
 * E2E for the outline panel: it lists the area, selecting a row opens the same
 * sidebar a click in the preview opens, and duplicate / delete / drag reach the
 * endpoints the preview toolbar already uses.
 */

async function createFreshPage(page) {
    const slug = `e2e-tree-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

/** Adds the first offered block type into the last column on the page. */
async function addBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').last().click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

async function openTree(page) {
    await page.locator('.cb-shell__tree-toggle').click();
    const panel = page.locator('.cb-tree');
    await expect(panel).toBeVisible();
    // Its content arrives from the tree endpoint; callers poll for what they
    // expect to find in it.
    return panel;
}

/** Section ids in the order the preview renders them. */
function previewSectionIds(frame) {
    return frame.locator('[data-cb-section-id]').evaluateAll(
        (nodes) => nodes.map((n) => n.dataset.cbSectionId),
    );
}

test.describe('tree view — the area as an outline', () => {
    test('lists sections, columns and blocks, and survives a reopen', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addBlock(page, frame);

        const panel = await openTree(page);

        await expect(panel.locator('.cb-tree__section')).toHaveCount(1);
        await expect(panel.locator('.cb-tree__row--column')).toHaveCount(1);
        await expect(panel.locator('.cb-tree__block')).toHaveCount(1);

        // Closing and reopening keeps the panel's own state out of the way of
        // the content it describes.
        await panel.locator('.cb-tree__close').click();
        await expect(panel).toBeHidden();
        await page.locator('.cb-shell__tree-toggle').click();
        await expect(panel.locator('.cb-tree__block')).toHaveCount(1);
    });

    test('a change made outside the panel refreshes it in place', async ({ page }) => {
        await openBuilder(page);
        const panel = await openTree(page);
        await expect(panel.locator('.cb-tree__section')).toHaveCount(0);

        // From the sidebar, not the preview: the panel floats over the
        // preview, and this is about the refresh, not about pointer geometry.
        await page.locator('.cb-sidebar-empty__btn[data-cb-builder-layout-param="two_cols"]').click();

        await expect(panel.locator('.cb-tree__section')).toHaveCount(1);
        await expect(panel.locator('.cb-tree__row--column')).toHaveCount(2);
    });

    test('selecting a row opens the same sidebar a preview click opens', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addBlock(page, frame);
        const panel = await openTree(page);

        await panel.locator('.cb-tree__block .cb-tree__name').first().click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
        // The sidebar *is* the selection, so the row follows it.
        await expect(panel.locator('.cb-tree__block .cb-tree__row')).toHaveClass(/cb-tree__row--selected/);
    });

    test('a preview click highlights the matching row', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addBlock(page, frame);
        const panel = await openTree(page);

        await page.locator('.cb-shell__iframe').evaluate((iframe) => {
            iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
        });

        await expect(panel.locator('.cb-tree__block .cb-tree__row')).toHaveClass(/cb-tree__row--selected/);
    });

    test('duplicating a block from the tree adds it to the page', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addBlock(page, frame);
        const panel = await openTree(page);

        const row = panel.locator('.cb-tree__block').first();
        await row.locator('.cb-tree__act').first().click();

        await expect(panel.locator('.cb-tree__block')).toHaveCount(2);
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);
    });

    test('deleting a block from the tree removes it and offers an undo', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addBlock(page, frame);
        const panel = await openTree(page);

        const row = panel.locator('.cb-tree__block').first();
        await row.locator('.cb-tree__act').nth(1).click();

        await expect(panel.locator('.cb-tree__block')).toHaveCount(0);
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);

        // The same snackbar a delete from the preview toolbar raises.
        const undo = page.locator('.cb-shell__undo');
        await expect(undo).toBeVisible();
        await undo.locator('.cb-shell__undo-btn').click();
        await expect(panel.locator('.cb-tree__block')).toHaveCount(1);
    });

    test('the panel can be dragged anywhere over the preview, and stays there', async ({ page }) => {
        await openBuilder(page);
        const panel = await openTree(page);

        const before = await panel.boundingBox();
        const header = panel.locator('.cb-tree__header');
        const grab = await header.boundingBox();
        const startX = grab.x + grab.width / 2;
        const startY = grab.y + grab.height / 2;
        await page.mouse.move(startX, startY);
        await page.mouse.down();
        await page.mouse.move(startX + 300, startY + 220, { steps: 10 });
        await page.mouse.up();

        const after = await panel.boundingBox();
        expect(Math.round(after.x - before.x)).toBe(300);
        expect(Math.round(after.y - before.y)).toBe(220);

        // Parked, not just moved: it comes back where it was left.
        await panel.locator('.cb-tree__close').click();
        await page.locator('.cb-shell__tree-toggle').click();
        await expect(panel).toBeVisible();
        const reopened = await panel.boundingBox();
        expect(Math.round(reopened.x)).toBe(Math.round(after.x));
    });

    test('dragging a section in the tree reorders the page', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFullSection(page, frame);
        const panel = await openTree(page);
        await expect(panel.locator('.cb-tree__section')).toHaveCount(2);

        const before = await previewSectionIds(frame);
        const handle = panel.locator('.cb-tree__section').nth(1)
            .locator('.cb-tree__row--section > .cb-tree__drag');
        const src = await handle.boundingBox();
        const target = await panel.locator('.cb-tree__section').nth(0).boundingBox();

        // SortableJS reacts to pointer events, so drive the mouse manually
        // past its drag threshold rather than using dragTo().
        await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2);
        await page.mouse.down();
        await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2 - 8, { steps: 4 });
        await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 12 });
        await page.mouse.move(target.x + target.width / 2, target.y + 2, { steps: 6 });
        await page.mouse.up();

        await expect.poll(() => previewSectionIds(frame)).toEqual([...before].reverse());
    });
});
