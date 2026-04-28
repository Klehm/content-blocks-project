import { test, expect } from '@playwright/test';

/**
 * E2E for the builder shell + structural ops.
 *
 * Each test creates its own fresh Page (via the sandbox's /page/create
 * endpoint) so structural mutations don't leak between tests. Tests that
 * need pre-existing content (a section, a block) seed it through the same
 * UI flow they exercise — that way the seed step also doubles as
 * regression coverage for the action it triggers.
 */

async function createFreshPage(page) {
    const slug = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

/** Adds a 1-column section by clicking the footer button. */
async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await page.locator('.cb-shell__bottom button[data-cb-builder-layout-param="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    // Allow the iframe load to fully settle so subsequent locators don't hit
    // a destroyed execution context.
    await page.waitForTimeout(200);
}

/** Adds the first available block type to the first column (via column overlay). */
async function addFirstBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('[data-cb-column-id]').first().hover({ position: { x: 10, y: 10 } });
    await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').first().click();
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

function attachConsoleSink(page) {
    const lines = [];
    page.on('console', (msg) => {
        if (msg.type() === 'log') {
            const text = msg.text();
            if (text.startsWith('[cb-builder]')) lines.push(text);
        }
    });
    return lines;
}

test.describe('builder shell — basics', () => {
    test('launcher opens the dialog and renders shell skeleton', async ({ page }) => {
        const url = await createFreshPage(page);
        await page.goto(url);

        const launcher = page.locator('.cb-launcher__button');
        await expect(launcher).toBeVisible();

        const dialog = page.locator('.cb-builder-dialog');
        await expect(dialog).not.toHaveAttribute('open');

        await launcher.click();

        await expect(dialog).toHaveAttribute('open', '');
        await expect(page.locator('.cb-shell__topbar')).toBeVisible();
        await expect(page.locator('.cb-shell__iframe')).toBeVisible();
        await expect(page.locator('.cb-shell__bottom')).toBeVisible();
    });

    test('iframe loads the preview URL with cb_preview=1 and emits cb:ready', async ({ page }) => {
        const logs = attachConsoleSink(page);
        const url = await createFreshPage(page);
        await page.goto(url);
        await page.locator('.cb-launcher__button').click();

        const iframe = page.locator('.cb-shell__iframe');
        await expect(iframe).toHaveAttribute('src', /\/page\/\d+\?cb_preview=1$/);

        await expect.poll(() => logs.some((l) => l.includes('iframe ready'))).toBe(true);
    });
});

test.describe('builder shell — sections', () => {
    test('add-section footer button creates a new section', async ({ page }) => {
        const frame = await openBuilder(page);
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(0);

        await page.locator('.cb-shell__bottom button[data-cb-builder-layout-param="two_cols"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        // two_cols → 2 columns inside the new section.
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(2);
    });

    test('section move-up overlay swaps order with previous section', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFullSection(page, frame);

        const readOrder = async () => {
            const count = await frame.locator('[data-cb-section-id]').count();
            const ids = [];
            for (let i = 0; i < count; i++) {
                ids.push(await frame.locator('[data-cb-section-id]').nth(i).getAttribute('data-cb-section-id'));
            }
            return ids;
        };

        const before = await readOrder();
        expect(before).toHaveLength(2);

        // Hover the top strip of the section (above the column grid) so the
        // section toolbar wins over the inner column toolbar.
        await frame.locator('[data-cb-section-id]').nth(1).hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').first().click();

        await expect.poll(async () => (await readOrder()).join(',')).toBe([before[1], before[0]].join(','));
    });

    test('section delete overlay marks the section deleted', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        // Toolbar buttons for sections: ▲ ▼ × — third button is delete.
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').nth(2).click();

        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    });
});

test.describe('builder shell — blocks', () => {
    test('column overlay + popover adds a block of the chosen type', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);

        await frame.locator('[data-cb-column-id]').first().hover({ position: { x: 10, y: 10 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').first().click();

        const popover = frame.locator('.cb-overlay-popover');
        await expect(popover).toBeVisible();

        // The list reflects the registered block types (4 in the kit:
        // text, title, image, tabs).
        const items = popover.locator('button');
        await expect(items).toHaveCount(4);

        await items.first().click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });

    test('clicking Edit on a block mounts the BlockComponent in the sidebar', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover();
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').first().click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
        await expect(sidebar.locator('button.btn-primary')).toBeVisible();
    });

    test('cancel in sidebar closes it without reloading', async ({ page }) => {
        const logs = attachConsoleSink(page);
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover();
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').first().click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        await sidebar.locator('button.btn-secondary').click();

        await expect(sidebar).toHaveAttribute('hidden', '');
        await expect.poll(() => logs.some((l) => l.startsWith('[cb-builder] block:cancel'))).toBe(true);
    });

    test('block delete overlay soft-deletes (deleted marker stays in DOM)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover();
        // Block toolbar: ✎ × — second button is delete.
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').nth(1).click();

        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(1);
        // The block is still in the DOM, just marked.
        await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);
    });
});

test.describe('builder shell — publish / discard', () => {
    test('Publish flushes drafts: never-published blocks become public, area is clean', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        // Before publish: badge present, Discard enabled.
        await expect(page.locator('.cb-shell__discard')).toBeEnabled();

        await page.locator('.cb-shell__publish').click();

        // After publish: badge gone, Discard disabled.
        await expect(page.locator('.cb-shell__discard')).toBeDisabled();
        await expect(page.locator('.cb-launcher__badge')).toHaveCount(0);

        // The block is still there and no longer flagged deleted (it never
        // was, but we want to verify the section/block didn't disappear).
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBeGreaterThanOrEqual(1);
    });

    test('Discard removes a never-published section entirely', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        expect(await frame.locator('[data-cb-section-id]').count()).toBe(1);

        await page.locator('.cb-shell__discard').click();

        // Section was added but never published → discardDraft removes it.
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(0);
        // Discard button is now disabled (no pending changes left).
        await expect(page.locator('.cb-shell__discard')).toBeDisabled();
    });

    test('Discard restores a soft-deleted block from a published area', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        // Snapshot current block id, publish so it's now part of the public state.
        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__discard')).toBeDisabled();

        // Now soft-delete the block.
        await frame.locator('[data-cb-block-id]').first().hover();
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn').nth(1).click();
        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(1);
        await expect(page.locator('.cb-shell__discard')).toBeEnabled();

        // Discard the soft-delete.
        await page.locator('.cb-shell__discard').click();

        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(0);
        await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);
    });
});
