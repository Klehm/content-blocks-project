import { test, expect } from '@playwright/test';

/**
 * In-place duplicate (no full iframe reload).
 *
 * Duplicating a block or a section used to reload the whole preview iframe.
 * Now the duplicate endpoint ships the copy's rendered markup when it's safe to
 * hot-reload (the block — or every block in the section — opts into
 * supportsPreviewHotReload), and the parent posts a `cb:*:duplicate:apply`
 * message so the overlay drops the copy in place, right after its source,
 * without rebuilding the iframe. A copy carrying a JS-dependent block still
 * falls back to a reload; that branch is covered by the parent-side unit tests.
 */

async function createFreshPage(page) {
    const slug = `e2e-dup-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addTitleBlock(page, frame) {
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
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

test.describe('preview duplicate — in place', () => {
    test('duplicates a block in place without reloading, right after the source, and persists', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addTitleBlock(page, frame);
        const sourceId = await frame.locator('[data-cb-block-id]').first().getAttribute('data-cb-block-id');

        await stampReloadSentinel(page);

        // The freshly-added block is already focused (toolbar pinned on it),
        // so fire its toolbar duplicate action directly.
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="duplicate"]').click();

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);

        // Inserted in place — the iframe was never fully reloaded.
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The copy landed right after the source, both ahead of the +Block button.
        const ids = await frame.locator('[data-cb-block-id]').evaluateAll(
            (els) => els.map((el) => el.getAttribute('data-cb-block-id')),
        );
        expect(ids[0]).toBe(sourceId);
        expect(ids[1]).not.toBe(sourceId);
        const lastChildIsAddBtn = await frame.locator('[data-cb-column-id]').first().evaluate(
            (col) => col.lastElementChild?.classList.contains('cb-add-block-inline') === true,
        );
        expect(lastChildIsAddBtn).toBe(true);

        // The duplicate was written to the draft: both blocks survive a real reload.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator('[data-cb-block-id]').first()).toBeVisible();
        await expect.poll(() => reloaded.locator('[data-cb-block-id]').count()).toBe(2);
    });

    test('duplicates a section in place without reloading, right after the source, and persists', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addTitleBlock(page, frame);
        const sourceId = await frame.locator('[data-cb-section-id]').first().getAttribute('data-cb-section-id');

        await stampReloadSentinel(page);

        // Pin focus on the section via its top strip (away from the block),
        // then fire its toolbar duplicate action.
        await frame.locator(`[data-cb-section-id="${sourceId}"]`).click({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="duplicate"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(2);

        // Inserted in place — the iframe was never fully reloaded.
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The copy landed right after the source.
        const ids = await frame.locator('[data-cb-section-id]').evaluateAll(
            (els) => els.map((el) => el.getAttribute('data-cb-section-id')),
        );
        expect(ids[0]).toBe(sourceId);
        expect(ids[1]).not.toBe(sourceId);

        // The cloned section carries its own (copied) block.
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);

        // The duplicate was written to the draft: both sections survive a real reload.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator('[data-cb-section-id]').first()).toBeVisible();
        await expect.poll(() => reloaded.locator('[data-cb-section-id]').count()).toBe(2);
    });
});
