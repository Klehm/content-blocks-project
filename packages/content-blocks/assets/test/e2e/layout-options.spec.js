import { test, expect } from '@playwright/test';

/**
 * Layout options set from the sidebars: a block's text alignment, and a
 * section whose columns stack in reverse on mobile.
 */

async function createFreshPage(page) {
    const slug = `e2e-layout-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

async function addTitle(frame, column) {
    await column.locator('.cb-add-block-inline').click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(column.locator('[data-cb-block-id]')).toHaveCount(1);
}

async function publish(page) {
    const button = page.locator('.cb-shell__publish');
    await expect(button).toBeEnabled();
    await button.click();
    await expect(button).toBeDisabled({ timeout: 10000 });
    await page.waitForTimeout(400);
}

test('a block is centred from its Style tab', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    const column = frame.locator('[data-cb-column-id]').first();
    await addTitle(frame, column);

    const sidebar = page.locator('.cb-shell__sidebar');
    await sidebar.locator('.cb-block__tab').last().click();
    await sidebar.locator('input[name$="[styling][textAlign]"][value="center"]').check({ force: true });

    const block = column.locator('[data-cb-block-id]');
    await expect(block).toHaveClass(/cb-block--text-center/, { timeout: 10000 });
    expect(await block.evaluate((el) => getComputedStyle(el).textAlign)).toBe('center');
});

test('a section stacks its columns in reverse on mobile', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
    await page.locator('input[name$="[reverseOnMobile]"]').check();
    const section = frame.locator('.cb-section').first();
    await expect(section).toHaveClass(/cb-section--reverse-mobile/);

    const columns = section.locator(':scope > .cb-row > [data-cb-column-id]');
    await addTitle(frame, columns.nth(0));
    await addTitle(frame, columns.nth(1));
    await publish(page);

    const viewer = await context.newPage();
    const publicUrl = builderUrl.replace('/admin/page/', '/page/');
    const tops = () => viewer.locator('.cb-section--reverse-mobile > .cb-row > .cb-col')
        .evaluateAll((cols) => cols.map((c) => c.getBoundingClientRect().top));

    // Side by side on desktop, the second column above the first on mobile.
    await viewer.setViewportSize({ width: 1200, height: 800 });
    await viewer.goto(publicUrl);
    const [desktopFirst, desktopSecond] = await tops();
    expect(desktopSecond).toBe(desktopFirst);

    await viewer.setViewportSize({ width: 380, height: 800 });
    const [mobileFirst, mobileSecond] = await tops();
    expect(mobileSecond).toBeLessThan(mobileFirst);

    await viewer.close();
});
