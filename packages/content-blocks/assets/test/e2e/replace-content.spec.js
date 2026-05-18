import { test, expect } from '@playwright/test';

/**
 * E2E for the "Insert content" replace-with flow.
 *
 * Each test creates two fresh Pages — a source (with content) and a target
 * (initially empty) — then opens the builder on the target and replaces its
 * content with the source's. The default ContentAreaProvider shipped with
 * the package surfaces every area by id + updatedAt, so the source page is
 * findable from the picker without any host-specific provider override.
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

async function openBuilder(page, url) {
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

/** Adds a single-column section to the area currently being edited. */
async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

async function addFirstBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

/**
 * Extracts the numeric ContentArea id of the target page from its builder
 * URL. The shell wrapper carries it as data-cb-builder-area-id-value.
 */
async function readAreaId(page) {
    const id = await page.locator('[data-cb-builder-area-id-value]').first().getAttribute('data-cb-builder-area-id-value');
    return Number(id);
}

test.describe('replace-content picker — UI affordance', () => {
    test('Insert content button opens the picker overlay and closes it again', async ({ page }) => {
        const url = await createFreshPage(page);
        await openBuilder(page, url);

        const picker = page.locator('.cb-replace-picker');
        await expect(picker).toBeHidden();

        await page.locator('.cb-shell__replace').click();
        await expect(picker).toBeVisible();
        await expect(page.locator('.cb-shell__replace')).toHaveAttribute('aria-expanded', 'true');

        await picker.locator('.cb-replace-picker__close').click();
        await expect(picker).toBeHidden();
        await expect(page.locator('.cb-shell__replace')).toHaveAttribute('aria-expanded', 'false');
    });

    test('default unfiltered candidate list is populated by the default provider', async ({ page }) => {
        // Seed two other pages so the picker has something to show that isn't
        // the current area (which is always excluded).
        await createFreshPage(page);
        await createFreshPage(page);

        const targetUrl = await createFreshPage(page);
        await openBuilder(page, targetUrl);

        await page.locator('.cb-shell__replace').click();
        const picker = page.locator('.cb-replace-picker');
        await expect(picker).toBeVisible();

        // At least one row appears, none of them references the current area.
        const items = picker.locator('.cb-replace-picker__item-btn');
        await expect.poll(async () => items.count()).toBeGreaterThanOrEqual(2);
        const targetAreaId = await readAreaId(page);
        const ids = await items.evaluateAll(
            (els) => els.map((el) => el.dataset.cbReplaceSourceId),
        );
        expect(ids).not.toContain(String(targetAreaId));
    });

    test('filtering by area id narrows the list', async ({ page }) => {
        // Build a known source area we can then search for by exact id.
        const sourceUrl = await createFreshPage(page);
        await openBuilder(page, sourceUrl);
        const sourceAreaId = await readAreaId(page);
        // Close the dialog so we leave the page cleanly.
        await page.locator('.cb-shell__close').click();

        // Now operate from a different target page.
        const targetUrl = await createFreshPage(page);
        await openBuilder(page, targetUrl);
        await page.locator('.cb-shell__replace').click();

        const picker = page.locator('.cb-replace-picker');
        await picker.locator('.cb-replace-picker__search').fill(String(sourceAreaId));

        // Debounce + fetch + render.
        await expect.poll(async () => {
            return await picker.locator('.cb-replace-picker__item-btn').count();
        }).toBe(1);
        const onlyRow = picker.locator('.cb-replace-picker__item-btn').first();
        await expect(onlyRow).toHaveAttribute('data-cb-replace-source-id', String(sourceAreaId));
    });
});

test.describe('replace-content picker — happy path', () => {
    test('replace clones the source area into the target draft (confirm accepted)', async ({ page }) => {
        // Source page: 2 sections, each with one block.
        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        // Publish so the listener stamps updatedAt and the area is "real".
        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled();
        const sourceAreaId = await readAreaId(page);
        await page.locator('.cb-shell__close').click();

        // Target page: empty.
        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(0);

        // Open picker, search the source's id, click the row, accept confirm.
        await page.locator('.cb-shell__replace').click();
        const picker = page.locator('.cb-replace-picker');
        await picker.locator('.cb-replace-picker__search').fill(String(sourceAreaId));
        await expect.poll(() => picker.locator('.cb-replace-picker__item-btn').count()).toBe(1);

        page.once('dialog', async (d) => { await d.accept(); });
        await picker.locator('.cb-replace-picker__item-btn').first().click();

        // Picker closes, the iframe reloads with 2 cloned sections + 2 blocks
        // in draft state, Discard is now visible.
        await expect(picker).toBeHidden();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(2);
        await expect.poll(() => targetFrame.locator('[data-cb-block-id]').count()).toBe(2);
        await expect(page.locator('.cb-shell__discard')).toBeVisible();
        await expect(page.locator('.cb-shell__publish')).toBeEnabled();
    });

    test('declining the confirm dialog leaves the target untouched', async ({ page }) => {
        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await page.locator('.cb-shell__publish').click();
        const sourceAreaId = await readAreaId(page);
        await page.locator('.cb-shell__close').click();

        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);

        await page.locator('.cb-shell__replace').click();
        const picker = page.locator('.cb-replace-picker');
        await picker.locator('.cb-replace-picker__search').fill(String(sourceAreaId));
        await expect.poll(() => picker.locator('.cb-replace-picker__item-btn').count()).toBe(1);

        page.once('dialog', async (d) => { await d.dismiss(); });
        await picker.locator('.cb-replace-picker__item-btn').first().click();

        // Nothing happened — target still empty, picker still open.
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(0);
        await expect(picker).toBeVisible();
    });

    test('discarding after a replace restores the previously empty target', async ({ page }) => {
        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        await page.locator('.cb-shell__publish').click();
        const sourceAreaId = await readAreaId(page);
        await page.locator('.cb-shell__close').click();

        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);

        await page.locator('.cb-shell__replace').click();
        const picker = page.locator('.cb-replace-picker');
        await picker.locator('.cb-replace-picker__search').fill(String(sourceAreaId));
        await expect.poll(() => picker.locator('.cb-replace-picker__item-btn').count()).toBe(1);

        page.once('dialog', async (d) => { await d.accept(); });
        await picker.locator('.cb-replace-picker__item-btn').first().click();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(1);

        // Now discard — the target had no published content, so discardDraft
        // should remove the cloned never-published sections entirely.
        await page.locator('.cb-shell__discard').click();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(0);
        await expect(page.locator('.cb-shell__discard')).toBeHidden();
    });
});
