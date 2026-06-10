import { test, expect } from '@playwright/test';

/**
 * In-place block add (no full iframe reload).
 *
 * Adding a block used to reload the whole preview iframe. Now the create
 * endpoint ships the new block's rendered markup when its type opts into
 * preview hot-reload (supportsPreviewHotReload), and the parent posts a
 * `cb:block:insert` message so the overlay drops the live node into its column
 * — ahead of the permanent "+ Block" button — without rebuilding the iframe.
 * A JS-dependent block (which doesn't ship html) still falls back to a reload;
 * that branch is covered by the parent-side unit tests.
 */

async function createFreshPage(page) {
    const slug = `e2e-add-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addSection(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
}

async function stampReloadSentinel(page) {
    await page.locator('.cb-shell__iframe').evaluate((el) => {
        el.contentWindow.__cbReloadSentinel = 'alive';
    });
}

function reloadSentinelSurvived(page) {
    return page.locator('.cb-shell__iframe').evaluate(
        (el) => el.contentWindow.__cbReloadSentinel === 'alive',
    );
}

test.describe('preview add block — in place', () => {
    test('adds a hot-reload block in place without reloading, focuses it, and persists', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);

        await stampReloadSentinel(page);

        // Title is a static / CSS-only block → it ships markup and inserts in place.
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

        // Inserted in place — the iframe was never fully reloaded.
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The new block landed inside the column, ahead of the +Block button.
        const lastChildIsAddBtn = await frame.locator('[data-cb-column-id]').first().evaluate(
            (col) => col.lastElementChild?.classList.contains('cb-add-block-inline') === true,
        );
        expect(lastChildIsAddBtn).toBe(true);

        // The block is focused (outline pinned) and its edit sidebar opened.
        await expect(frame.locator('[data-cb-block-id].cb-overlay-outline')).toHaveCount(1);
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // The add was written to the draft: the block survives a real reload.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator('[data-cb-block-id]').first()).toBeVisible();
        await expect.poll(() => reloaded.locator('[data-cb-block-id]').count()).toBe(1);
    });

    test('a second block lands after the first, both ahead of the +Block button', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);

        // First block (empty column → pill is interactable).
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        const firstId = await frame.locator('[data-cb-block-id]').first().getAttribute('data-cb-block-id');

        await stampReloadSentinel(page);

        // Second block: hover the (now non-empty) column to reveal its pill,
        // then add. It must slot in after the first, before the +Block button.
        const column = frame.locator('[data-cb-column-id]').first();
        await column.hover();
        await column.locator('.cb-add-block-inline').click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);

        expect(await reloadSentinelSurvived(page)).toBe(true);

        // Order: the new block comes after the first one.
        const ids = await frame.locator('[data-cb-block-id]').evaluateAll(
            (els) => els.map((el) => el.getAttribute('data-cb-block-id')),
        );
        expect(ids[0]).toBe(firstId);
        expect(ids).toHaveLength(2);

        // The +Block button is still the column's last child.
        const lastChildIsAddBtn = await column.evaluate(
            (col) => col.lastElementChild?.classList.contains('cb-add-block-inline') === true,
        );
        expect(lastChildIsAddBtn).toBe(true);
    });
});
