import { test, expect } from '@playwright/test';

/**
 * Undo-delete snackbar: deletes are immediate (no confirm dialog), so after
 * each block/section delete the shell offers a one-click undo for a few
 * seconds. Undo restores the soft-deleted entity (draft flag flip) and the
 * preview shows it again after the reload.
 */

async function createFreshPage(page) {
    const slug = `e2e-undo-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

/** Adds a section + a Title block; returns once both exist in the preview. */
async function seedSectionWithBlock(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(300);
}

/** Clicks the overlay toolbar delete button for the given selector's element. */
async function deleteViaToolbar(page, frame, selector) {
    await page.locator('.cb-shell__iframe').evaluate((iframe, sel) => {
        iframe.contentDocument.querySelector(sel)
            ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, selector);
    await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();
}

test('deleting a block offers Undo; clicking it brings the block back', async ({ page }) => {
    const frame = await openBuilder(page);
    await seedSectionWithBlock(page, frame);
    const snackbar = page.locator('.cb-shell__undo');
    await expect(snackbar).toBeHidden();

    await deleteViaToolbar(page, frame, '[data-cb-block-id]');

    // Block dropped from the preview, snackbar up with the block label.
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    await expect(snackbar).toBeVisible();
    await expect(snackbar.locator('.cb-shell__undo-label')).toHaveText(/Bloc supprimé|Block deleted/);

    await snackbar.locator('.cb-shell__undo-btn').click();

    // Restored: the block is back in the preview and the offer is consumed.
    await expect.poll(() => frame.locator('[data-cb-block-id]').count(), { timeout: 5000 }).toBe(1);
    await expect(snackbar).toBeHidden();
});

test('deleting a section offers Undo; clicking it brings the section back', async ({ page }) => {
    const frame = await openBuilder(page);
    await seedSectionWithBlock(page, frame);
    const snackbar = page.locator('.cb-shell__undo');

    await deleteViaToolbar(page, frame, '[data-cb-section-id]');

    // Sections stay in the DOM flagged deleted (hidden via CSS).
    await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    await expect(snackbar).toBeVisible();
    await expect(snackbar.locator('.cb-shell__undo-label')).toHaveText(/Section supprimée|Section deleted/);

    await snackbar.locator('.cb-shell__undo-btn').click();

    // Restored: no deleted flag left, the section (and its block) render again.
    await expect.poll(
        () => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count(),
        { timeout: 5000 },
    ).toBe(1);
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await expect(snackbar).toBeHidden();
});

test('the Undo offer expires on its own after a few seconds', async ({ page }) => {
    const frame = await openBuilder(page);
    await seedSectionWithBlock(page, frame);
    const snackbar = page.locator('.cb-shell__undo');

    await deleteViaToolbar(page, frame, '[data-cb-block-id]');
    await expect(snackbar).toBeVisible();

    // UNDO_TIMEOUT_MS is 6s.
    await expect(snackbar).toBeHidden({ timeout: 8000 });
    // The block stays deleted.
    expect(await frame.locator('[data-cb-block-id]').count()).toBe(0);
});
