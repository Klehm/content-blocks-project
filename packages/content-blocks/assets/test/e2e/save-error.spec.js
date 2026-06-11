import { test, expect } from '@playwright/test';

/**
 * Save-failure feedback: any failed save path must surface the persistent
 * topbar error banner (previously failures were console-only — the editor
 * had no way to know their edits were not stored), and the banner must
 * clear as soon as a later save succeeds.
 */

async function createFreshPage(page) {
    const slug = `e2e-saveerr-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

test('a failed structural op shows the banner; the next successful save clears it', async ({ page }) => {
    const frame = await openBuilder(page);
    const banner = page.locator('.cb-shell__save-error');
    await expect(banner).toBeHidden();

    // Cut the network for the section-create endpoint and try to add one.
    await page.route('**/_content-blocks/area/*/sections', (route) => route.abort());
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();

    await expect(banner).toBeVisible();
    // Nothing was created.
    expect(await frame.locator('[data-cb-section-id]').count()).toBe(0);

    // Network restored: the same action succeeds and clears the banner.
    await page.unroute('**/_content-blocks/area/*/sections');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await expect(banner).toBeHidden();
});

test('a failed block autosave shows the banner and the next interaction retries', async ({ page }) => {
    const frame = await openBuilder(page);
    const banner = page.locator('.cb-shell__save-error');

    // Seed a section + a Title block (single text input bound to data.text).
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    const field = sidebar.locator('.cb-block__edit-form input[type=text]').first();
    await expect(field).toBeVisible();
    await page.waitForTimeout(300);

    // Cut the network for Live Component saves, then edit.
    await page.route('**/_components/**', (route) => route.abort());
    await field.fill('hello offline');
    await field.blur();

    await expect(banner).toBeVisible();

    // Restore the network. The autosave baseline was reset on failure, so
    // simply re-focusing and leaving the field retries the save with the
    // unchanged value — and the success clears the banner.
    await page.unroute('**/_components/**');
    const saves = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && /_components\/ContentBlocks:Block\/save/.test(r.url())) saves.push(1);
    });
    await field.focus();
    await field.blur();

    await expect.poll(() => saves.length, { timeout: 5000 }).toBeGreaterThan(0);
    await expect(banner).toBeHidden();
});
