import { test, expect } from '@playwright/test';

/**
 * Duplicating LiveCollection items via cb-collection-sort.
 *
 * Each collection card carries a duplicate button (⧉) that calls the Block
 * component's `duplicateCollectionItem` live action, inserting a copy of the
 * entry right after the original. Like a reorder, this is an in-place value
 * change (the copy reuses the next positional widget id) with no childList
 * mutation the cb-autosave MutationObserver would catch, so the action
 * persists the draft itself and dispatches cb:block:saved — the new entry
 * survives a full reload.
 *
 * The kit's Tabs block (type `tabs`) renders a LiveCollectionType and makes a
 * clean fixture. Its sidebar edit form lives in the parent page (not the
 * preview iframe), so clicks use page coordinates directly.
 */

async function createFreshPage(page) {
    const slug = `e2e-duplicate-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    await frame.locator('[data-cb-block-id]').first().click({ position: { x: 10, y: 10 } });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    return sidebar;
}

/**
 * Build a known 3-tab list ("A", "B", "C") and persist it to the draft.
 * Mirrors the reorder spec: name-then-add, settling between steps so add-clicks
 * don't race in-flight autosaves (Live ignores actions while a request runs).
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

test.describe('builder shell — collection duplicate', () => {
    test('duplicating an entry inserts a copy after it and persists after reload', async ({ page }) => {
        const frame = await openBuilder(page);
        await addTabsBlock(page, frame);
        let sidebar = await openBlockEditor(page, frame);

        await seedThreeTabs(page, sidebar);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C']);

        // Duplicate the second entry ("B"). The copy lands right after it, so
        // the list becomes A, B, B, C. duplicateCollectionItem persists the
        // draft in the same round-trip.
        const items = sidebar.locator('.cb-form-collection__item');
        await items.nth(1).locator('.cb-form-collection__duplicate').click();
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'B', 'C']);
        await page.waitForTimeout(800); // action round-trip + flush

        sidebar = await reopenSidebar(page);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'B', 'C']);
    });

    test('duplicating the last entry appends the copy at the end', async ({ page }) => {
        const frame = await openBuilder(page);
        await addTabsBlock(page, frame);
        const sidebar = await openBlockEditor(page, frame);

        await seedThreeTabs(page, sidebar);
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C']);

        const items = sidebar.locator('.cb-form-collection__item');
        await items.nth(2).locator('.cb-form-collection__duplicate').click();
        await expect.poll(() => titleValues(sidebar)).toEqual(['A', 'B', 'C', 'C']);
    });
});
