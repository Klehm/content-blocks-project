import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * The builder's Publish and Discard choose which languages they cover, once
 * some translation is waiting: the real builder, workbench and public pages.
 */

async function createFreshPage(page) {
    const slug = `e2e-i18n-scope-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

/** A published page holding one Tabs block: its tab title is translatable. */
async function publishedPage(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(300);

    // No translation yet: Publish asks nothing, as it always did.
    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });
    await expect(page.locator('.cb-publish-scope')).toBeHidden();

    return url.match(/\/admin\/page\/(\d+)/)[1];
}

/** Translates the first workbench row in each locale, then closes it. */
async function translate(page, texts) {
    await page.locator('.cb-shell__actions-toggle').click();
    const [workbench] = await Promise.all([
        page.waitForEvent('popup'),
        page.locator('.cb-shell__action--translate').click(),
    ]);
    await workbench.waitForLoadState('domcontentloaded');
    const base = workbench.url().replace(/\/[a-z]{2}(\?.*)?$/, '');

    for (const [locale, text] of Object.entries(texts)) {
        await workbench.goto(`${base}/${locale}`);
        const row = workbench.locator('.cb-wb__row').first();
        await row.locator('[data-target="input"]').fill(text);
        await expect(row).toHaveAttribute('data-status', 'translated', { timeout: 5000 });
    }
    await workbench.close();
    await page.bringToFront();
    await page.reload();
    await launchBuilder(page);
}

async function publicText(page, path) {
    const response = await page.request.get(path);
    expect(response.status()).toBe(200);

    return response.text();
}

function scopeDialog(page) {
    return page.locator('.cb-publish-scope');
}

async function untick(page, code) {
    await scopeDialog(page).locator(`input[value="${code}"]`).uncheck();
}

/** Confirms the dialog and waits for the publish or discard to answer. */
async function confirm(page, action) {
    await Promise.all([
        page.waitForResponse((r) => r.request().method() === 'POST'
            && new RegExp(`/area/\\d+/${action}$`).test(new URL(r.url()).pathname)),
        scopeDialog(page).locator('.cb-publish-scope__confirm').click(),
    ]);
    await expect(scopeDialog(page)).toBeHidden();
}

test('Publish puts the ticked languages live and holds the others back', async ({ page }) => {
    const id = await publishedPage(page);
    await translate(page, { en: 'Tab EN', de: 'Tab DE' });

    const publish = page.locator('.cb-shell__publish');
    await expect(publish).toBeEnabled();
    await publish.click();

    const dialog = scopeDialog(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('input[type=checkbox]')).toHaveCount(2);
    await expect(dialog.locator('input[value="en"]')).toBeChecked();
    await expect(dialog.locator('input[value="de"]')).toBeChecked();
    await expect(dialog).toContainText('Deutsch');

    await untick(page, 'de');
    await confirm(page, 'publish');

    // German still waits, so there is still something to publish.
    await expect(publish).toBeEnabled();
    expect(await publicText(page, `/en/page/${id}`)).toContain('Tab EN');
    expect(await publicText(page, `/de/page/${id}`)).not.toContain('Tab DE');

    await publish.click();
    await expect(dialog.locator('input[type=checkbox]')).toHaveCount(1);
    await expect(dialog.locator('input[value="de"]')).toBeChecked();
    await confirm(page, 'publish');

    await expect(publish).toBeDisabled({ timeout: 10000 });
    expect(await publicText(page, `/de/page/${id}`)).toContain('Tab DE');
});

test('Discard keeps the draft of a language left unticked', async ({ page }) => {
    const id = await publishedPage(page);
    await translate(page, { en: 'Tab EN', de: 'Tab DE' });

    // The dialog replaces the confirm prompt: no native dialog may open.
    page.on('dialog', (d) => { throw new Error(`unexpected ${d.type()}: ${d.message()}`); });
    const discard = page.locator('.cb-shell__discard');
    await expect(discard).toBeVisible();
    await discard.click();

    const dialog = scopeDialog(page);
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.cb-publish-scope__confirm--danger')).toBeVisible();
    await untick(page, 'en');
    await confirm(page, 'discard');

    // English kept its draft: it is still the one thing to publish.
    const publish = page.locator('.cb-shell__publish');
    await expect(publish).toBeEnabled();
    await publish.click();
    await expect(dialog.locator('input[type=checkbox]')).toHaveCount(1);
    await expect(dialog.locator('input[value="en"]')).toBeVisible();
    await confirm(page, 'publish');
    await expect(publish).toBeDisabled({ timeout: 10000 });

    expect(await publicText(page, `/en/page/${id}`)).toContain('Tab EN');
    expect(await publicText(page, `/de/page/${id}`)).not.toContain('Tab DE');
});

test('Cancel and Escape leave everything as it was', async ({ page }) => {
    const id = await publishedPage(page);
    await translate(page, { en: 'Tab EN' });

    const publish = page.locator('.cb-shell__publish');
    const dialog = scopeDialog(page);
    await publish.click();
    await dialog.locator('.cb-publish-scope__cancel').click();
    await expect(dialog).toBeHidden();

    await publish.click();
    await expect(dialog).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    await expect(publish).toBeEnabled();
    expect(await publicText(page, `/en/page/${id}`)).not.toContain('Tab EN');
});
