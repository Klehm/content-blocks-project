import { test, expect } from '@playwright/test';

/**
 * Reordering LiveCollection items via cb-collection-sort.
 *
 * Dragging an entry (or using its keyboard up/down fallback) calls the
 * Block component's `moveCollectionItem` live action, which reorders the
 * form data positionally and persists the draft itself (a reorder re-renders
 * the same positional widget ids with swapped values, so the cb-autosave
 * MutationObserver — which only watches childList — wouldn't catch it). The
 * action dispatches cb:block:saved, reloading the preview, and the order
 * survives a full reload.
 *
 * The kit's Tabs block (type `tabs`) renders a LiveCollectionType and makes
 * a clean fixture. Its sidebar edit form lives in the parent page (not the
 * preview iframe), so pointer drags use page coordinates directly.
 */

async function createFreshPage(page) {
    const slug = `e2e-reorder-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addTabsBlock(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);
}

async function openBlockEditor(page, frame) {
    // Wait for the block to exist (the iframe may still be loading after a
    // reload), then dispatch the click in-iframe so the section's top-left
    // select handle (revealed on hover) can't intercept a coordinate click.
    await frame.locator('[data-cb-block-id]').first().waitFor();
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    // Let the autosave controller connect before callers start editing — an
    // edit fired before connect never persists.
    await page.waitForTimeout(300);
    return sidebar;
}

/**
 * Build a known 3-tab list ("A", "B", "C") and persist it to the draft.
 *
 * Each tab title is NotBlank and the block autosaves on blur, so a half-filled
 * collection is briefly invalid. To avoid racing add-clicks against in-flight
 * autosaves (Live ignores actions while a request is pending), we name the
 * default tab first, then add-then-name each new one, settling between steps.
 */
async function seedThreeTabs(page, sidebar) {
    const items = sidebar.locator('.cb-form-collection__item');
    const titleOf = (i) => items.nth(i).locator('input[type="text"]').first();
    const addBtn = sidebar.locator('.cb-form-btn--success'); // "+ Add tab"

    const nameTab = async (i, label) => {
        await titleOf(i).fill(label);
        await titleOf(i).blur();
        await expect(titleOf(i)).toHaveValue(label);
        await page.waitForTimeout(900); // let the on-blur autosave settle
    };

    await expect(items).toHaveCount(1);
    await nameTab(0, 'A');
    await addBtn.click();
    await expect(items).toHaveCount(2);
    await nameTab(1, 'B');
    await addBtn.click();
    await expect(items).toHaveCount(3);
    await nameTab(2, 'C');

    await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C']);
    await page.waitForTimeout(1000); // final persist round-trip
}

async function reopenSidebar(page) {
    await page.reload();
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return openBlockEditor(page, page.frameLocator('.cb-shell__iframe'));
}

function titleValues(sidebar) {
    return sidebar
        .locator('.cb-form-collection__item input[type="text"]')
        .evaluateAll((els) => els.map((el) => el.value));
}

test.describe('builder shell — collection reorder', () => {
    test('keyboard "move up" reorders an entry and the order persists after reload', async ({ page }) => {
        const frame = await openBuilder(page);
        await addTabsBlock(page, frame);
        let sidebar = await openBlockEditor(page, frame);

        await seedThreeTabs(page, sidebar);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C']);

        // Move the third entry ("C") up one slot → A, C, B. moveCollectionItem
        // reorders and persists the draft in the same round-trip.
        const items = sidebar.locator('.cb-form-collection__item');
        await items.nth(2).locator('.cb-form-collection__move--up').click();
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'C', 'B']);
        await page.waitForTimeout(800); // action round-trip + flush

        sidebar = await reopenSidebar(page);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'C', 'B']);
    });

    test('dragging a card reorders it and the new order persists after reload', async ({ page }) => {
        const frame = await openBuilder(page);
        await addTabsBlock(page, frame);
        let sidebar = await openBlockEditor(page, frame);

        await seedThreeTabs(page, sidebar);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C']);

        const items = sidebar.locator('.cb-form-collection__item');
        const handle = items.nth(2).locator('.cb-form-collection__drag-handle');
        await expect(handle).toBeVisible();

        const src = await handle.boundingBox();
        const target = await items.nth(0).boundingBox();

        // SortableJS reacts to pointer events (real CDP input generates them),
        // so drive the mouse manually with steps past its drag threshold. We
        // drag the last card ("C") up over the first one. Sortable's exact
        // landing slot depends on sub-pixel midpoint math, so assert that the
        // order *changed* and that whatever it became survives a reload —
        // that's the contract (the keyboard test pins exact ordering).
        await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2);
        await page.mouse.down();
        await page.mouse.move(src.x + src.width / 2, src.y + src.height / 2 + 6, { steps: 4 });
        await page.mouse.move(target.x + target.width / 2, target.y + target.height / 2, { steps: 12 });
        await page.mouse.move(target.x + target.width / 2, target.y + 2, { steps: 6 });
        await page.mouse.up();

        // The drag changed the order (C is no longer last).
        await expect.poll(() => titleValues(sidebar)).not.toEqual(['A', 'B', 'C']);
        const reordered = await titleValues(sidebar);
        expect(reordered.slice().sort()).toEqual(['A', 'B', 'C']); // same set, new order
        await page.waitForTimeout(800); // action round-trip + flush

        sidebar = await reopenSidebar(page);
        await expect.poll(() => titleValues(sidebar)).toEqual(reordered);
    });
});
