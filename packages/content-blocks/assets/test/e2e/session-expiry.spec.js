import { test, expect } from '@playwright/test';

/**
 * The editor's session runs out while the builder is open. The host firewall
 * answers with a login redirect (simulated by the sandbox's
 * E2eSessionExpirySimulator, keyed on a cookie), which `fetch()` follows.
 *
 * The builder must not call that a save, must not reload the preview onto
 * the page the redirect lands on, must say the session is gone, and must send
 * the swallowed edit again once the session is back.
 */

async function createFreshPage(page) {
    const slug = `e2e-session-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

async function expireSession(page) {
    const { origin } = new URL(page.url());
    await page.context().addCookies([
        { name: 'cb_e2e_session', value: 'expired', url: origin },
    ]);
}

async function restoreSession(page) {
    await page.context().clearCookies({ name: 'cb_e2e_session' });
}

/** Coming back to the tab; rechecks are spaced, so it is repeated. */
async function returnToTab(page, banner) {
    await expect.poll(async () => {
        await page.evaluate(() => window.dispatchEvent(new Event('focus')));
        return banner.isHidden();
    }, { timeout: 10000, intervals: [500, 1000] }).toBe(true);
}

async function addSection(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
}

test('a section edit into a dead session is kept, said, and sent once back', async ({ page }) => {
    const frame = await openBuilder(page);
    const banner = page.locator('.cb-shell__session-expired');
    const saveError = page.locator('.cb-shell__save-error');
    await addSection(page, frame);

    const classes = page.locator('.cb-shell__sidebar input[name$="[classes]"]');
    await expect(classes).toBeVisible();

    await expireSession(page);
    await classes.fill('e2e-session-class');
    await classes.press('Tab');

    await expect(banner).toBeVisible();
    await expect(saveError).toBeHidden();
    // The preview did not follow the redirect to the page list.
    await page.waitForTimeout(800);
    await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);
    await expect(frame.locator('.e2e-session-class')).toHaveCount(0);
    // The edit is still on screen.
    await expect(classes).toHaveValue('e2e-session-class');

    await restoreSession(page);
    await returnToTab(page, banner);

    await expect(frame.locator('[data-cb-section-id].e2e-session-class'))
        .toHaveCount(1, { timeout: 10000 });
    await expect(saveError).toBeHidden();
});

test('a block edit into a dead session is kept and sent once back', async ({ page }) => {
    const frame = await openBuilder(page);
    const banner = page.locator('.cb-shell__session-expired');
    await addSection(page, frame);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    const field = sidebar.locator('.cb-block__edit-form input[type=text]').first();
    await expect(field).toBeVisible();
    await page.waitForTimeout(300);

    await expireSession(page);
    await field.fill('written while logged out');
    await field.blur();

    await expect(banner).toBeVisible();
    await expect(page.locator('.cb-shell__save-error'))
        .toBeHidden();
    await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);

    await restoreSession(page);
    await returnToTab(page, banner);

    await expect(frame.locator('[data-cb-block-id]'))
        .toContainText('written while logged out', { timeout: 10000 });
});

test('coming back after a long idle says the session is gone before an edit', async ({ page }) => {
    await openBuilder(page);
    const banner = page.locator('.cb-shell__session-expired');
    // Stands for the idle: any interaction now counts as a return.
    await page.locator('.cb-shell').evaluate((el) => {
        el.setAttribute('data-cb-builder-session-check-after-value', '0');
    });

    await expireSession(page);
    await page.evaluate(() => window.dispatchEvent(new Event('focus')));

    await expect(banner).toBeVisible();
    await expect(banner.locator('a')).toHaveAttribute('target', '_blank');

    await restoreSession(page);
    await returnToTab(page, banner);
});
