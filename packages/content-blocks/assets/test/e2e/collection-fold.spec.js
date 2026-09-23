import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';
import { reveal } from './helpers/sidebar.js';

/**
 * Folding LiveCollection entries down to their header, so a long list can be
 * reordered at a glance. The folded state is the browser's alone: it has to
 * survive the Live re-render that a reorder triggers, and follow the entry.
 * Fixture: the kit's Tabs block, as in collection-reorder.spec.js.
 */

async function openBuilder(page) {
    const slug = `e2e-fold-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    await page.goto(response.headers()['location']);
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

async function openTabsEditor(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    await page.waitForTimeout(300);
    return sidebar;
}

/** Three tabs "A", "B", "C", each named and saved before the next. */
async function seedThreeTabs(page, sidebar) {
    const items = sidebar.locator('.cb-form-collection__item');
    const titleOf = (i) => items.nth(i).locator('input[type="text"]').first();
    for (const [i, label] of ['A', 'B', 'C'].entries()) {
        if (i > 0) {
            await sidebar.locator('.cb-form-btn--primary').click();
            await expect(items).toHaveCount(i + 1);
        }
        await (await reveal(titleOf(i))).fill(label);
        await titleOf(i).blur();
        await page.waitForTimeout(900);
    }
    await expect(sidebar.locator('.cb-form-collection__summary')).toHaveText(['A', 'B', 'C']);
}

const foldedStates = (sidebar) => sidebar.locator('.cb-form-collection__item').evaluateAll(
    (items) => items.map((item) => item.classList.contains('cb-form-collection__item--collapsed')),
);

test.describe('builder shell — collection folding', () => {
    test('entries fold to a named header, and stay folded through a reorder', async ({ page }) => {
        const sidebar = await openTabsEditor(page, await openBuilder(page));
        await seedThreeTabs(page, sidebar);
        const items = sidebar.locator('.cb-form-collection__item');

        await sidebar.locator('[data-action="cb-collection-sort#collapseAll"]').click();
        await expect(items.locator('.cb-form-collection__item-body')).toHaveCount(3);
        for (const i of [0, 1, 2]) {
            await expect(items.nth(i).locator('.cb-form-collection__item-body')).toBeHidden();
        }

        // Unfold "A" only, then move "C" up past "B": the server re-renders
        // the list and the folds must follow the entries, not the slots.
        await items.nth(0).locator('.cb-form-collection__toggle').click();
        await expect(items.nth(0).locator('.cb-form-collection__item-body')).toBeVisible();
        await expect(items.nth(0).locator('.cb-form-collection__toggle')).toHaveAttribute('aria-expanded', 'true');

        await items.nth(2).locator('.cb-form-collection__move--up').click();
        await expect(sidebar.locator('.cb-form-collection__summary')).toHaveText(['A', 'C', 'B']);
        await page.waitForTimeout(500);
        expect(await foldedStates(sidebar)).toEqual([false, true, true]);

        await sidebar.locator('[data-action="cb-collection-sort#expandAll"]').click();
        expect(await foldedStates(sidebar)).toEqual([false, false, false]);
    });

    test('kit entries open folded (cb_open_entries: none); an added one opens', async ({ page }) => {
        const frame = await openBuilder(page);
        let sidebar = await openTabsEditor(page, frame);
        const items = sidebar.locator('.cb-form-collection__item');

        expect(await foldedStates(sidebar)).toEqual([true]);
        await expect(items.nth(0).locator('.cb-form-collection__toggle'))
            .toHaveAttribute('aria-expanded', 'false');

        await sidebar.locator('.cb-form-btn--primary').click();
        await expect(items).toHaveCount(2);
        expect(await foldedStates(sidebar)).toEqual([true, false]);
        await items.nth(1).locator('input[type="text"]').first().fill('B');
        await items.nth(1).locator('input[type="text"]').first().blur();
        await page.waitForTimeout(900);

        // A fresh sidebar starts from the server's render again.
        await page.reload();
        await launchBuilder(page);
        await expect(page.locator('.cb-shell')).toBeVisible();
        await expect(page.frameLocator('.cb-shell__iframe').locator('[data-cb-block-id]'))
            .toHaveCount(1);
        await page.waitForTimeout(300);
        await page.locator('.cb-shell__iframe').evaluate((iframe) => {
            iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
        });
        sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-form-collection__item')).toHaveCount(2);
        expect(await foldedStates(sidebar)).toEqual([true, true]);
    });
});
