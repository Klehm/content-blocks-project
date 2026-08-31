import { test, expect } from '@playwright/test';

/**
 * The published page does not move until Publish is pressed.
 *
 * The unit-level guarantee lives in `tests/Rendering/PublishedRenderImmutabilityTest.php`;
 * this is the same contract observed the way an editor's visitors observe it —
 * a real browser, a real database, the sandbox's own public route, and the
 * builder driven through its actual buttons.
 *
 * The bug this pins down was reported from a live site: an editor rearranged a
 * page without publishing and the public page changed under them, into
 * something that matched neither the old page nor the builder. It had several
 * causes at once (the public render honoured the draft `deleted` flag, showed
 * sections that had never been published, and followed a block dragged into
 * another column), so the assertion here is deliberately blunt: the public
 * markup must come back byte for byte.
 */

async function createFreshPage(page) {
    const slug = `e2e-immutable-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addSection(page, frame, layout) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator(`.cb-add-section-tray__btn[data-cb-add-section="${layout}"]`).click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(250);
}

/** Adds a Title block through the nth "+ Block" pill of the preview. */
async function addBlock(page, frame, pillIndex) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').nth(pillIndex).click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(250);
}

/** Clicks the overlay toolbar's delete button for the first matching element. */
async function deleteViaToolbar(page, frame, selector) {
    await page.locator('.cb-shell__iframe').evaluate((iframe, sel) => {
        iframe.contentDocument.querySelector(sel)
            ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, selector);
    await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();
    await page.waitForTimeout(400);
}

async function publish(page) {
    const button = page.locator('.cb-shell__publish');
    await expect(button).toBeEnabled();
    await button.click();
    await expect(button).toBeDisabled({ timeout: 10000 });
    await page.waitForTimeout(400);
}

/**
 * Drives the block-move endpoint the drag-and-drop handler calls. A synthetic
 * HTML5 drag across two columns is the one interaction Playwright cannot
 * reproduce faithfully, and the endpoint is the whole of what the drag does.
 */
async function moveBlockToColumn(page, frame, blockIndex, columnIndex) {
    const blockId = await frame.locator('[data-cb-block-id]').nth(blockIndex).getAttribute('data-cb-block-id');
    const columnId = await frame.locator('[data-cb-column-id]').nth(columnIndex).getAttribute('data-cb-column-id');
    const token = await page.locator('[data-cb-csrf-token]').first().getAttribute('data-cb-csrf-token');

    const response = await page.request.post(`/_content-blocks/block/${blockId}/move`, {
        headers: { 'X-CSRF-Token': token, 'Content-Type': 'application/json' },
        data: { toColumnId: Number(columnId), position: 0 },
    });
    expect(response.ok()).toBeTruthy();
}

test('no builder action changes the published page until Publish', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    // A published starting point: two columns with a block each, plus a
    // second, single-column section — enough shape for a delete, a reorder
    // and a cross-column move to have somewhere to go.
    await addSection(page, frame, 'two_cols');
    await addBlock(page, frame, 0);
    await addBlock(page, frame, 1);
    await addSection(page, frame, 'full');
    await addBlock(page, frame, 2);
    await publish(page);

    // The public page, read the way a visitor reads it.
    const publicUrl = builderUrl.replace('/admin/page/', '/page/');
    const viewer = await context.newPage();
    const publicMarkup = async () => {
        await viewer.goto(publicUrl);

        return viewer.locator('.cb-content-area').innerHTML();
    };

    const published = await publicMarkup();
    expect(published).toContain('cb-block');

    // ---- A full editing session, nothing published ----

    // Add a section, and a block inside it.
    await addSection(page, frame, 'two_cols');
    await addBlock(page, frame, 3);
    expect(await publicMarkup()).toBe(published);

    // Delete a block that IS published.
    await deleteViaToolbar(page, frame, '[data-cb-block-id]');
    expect(await publicMarkup()).toBe(published);

    // Drag a published block into the other column.
    await moveBlockToColumn(page, frame, 0, 1);
    await page.reload();
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    expect(await publicMarkup()).toBe(published);

    // Delete a whole published section.
    const reopened = page.frameLocator('.cb-shell__iframe');
    await deleteViaToolbar(page, reopened, '[data-cb-section-id]');
    expect(await publicMarkup()).toBe(published);

    // ---- …and now Publish ----

    await publish(page);
    expect(await publicMarkup()).not.toBe(published);

    await viewer.close();
});

test('discarding a session leaves the published page exactly where it was', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await addSection(page, frame, 'two_cols');
    await addBlock(page, frame, 0);
    await addBlock(page, frame, 1);
    await publish(page);

    const publicUrl = builderUrl.replace('/admin/page/', '/page/');
    const viewer = await context.newPage();
    const publicMarkup = async () => {
        await viewer.goto(publicUrl);

        return viewer.locator('.cb-content-area').innerHTML();
    };

    const published = await publicMarkup();

    // Delete first, then move: the toolbar needs the overlay bound to the
    // preview it is looking at, and the cross-column move is a server-side
    // action whose effect on the DOM this test never reads.
    await deleteViaToolbar(page, frame, '[data-cb-block-id]');
    await addSection(page, frame, 'full');
    await moveBlockToColumn(page, frame, 0, 1);

    expect(await publicMarkup()).toBe(published);

    page.once('dialog', (dialog) => dialog.accept());
    await page.locator('.cb-shell__discard').click();
    await expect(page.locator('.cb-shell__publish')).toBeDisabled({ timeout: 10000 });

    // Discard is the other exit from a session: it must land the public page
    // where it already was, and the draft with it.
    expect(await publicMarkup()).toBe(published);

    await viewer.close();
});
