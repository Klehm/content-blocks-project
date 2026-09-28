import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';
import { reveal } from './helpers/sidebar.js';

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
    await launchBuilder(page);
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
    await sidebar.locator('.cb-sidebar-tabs__tab').last().click();
    await (await reveal(sidebar.locator('input[name$="[styling][textAlign]"][value="center"]'))).check();

    const block = column.locator('[data-cb-block-id]');
    await expect(block).toHaveClass(/cb-block--text-center/, { timeout: 10000 });
    expect(await block.evaluate((el) => getComputedStyle(el).textAlign)).toBe('center');
});

test('a section stacks its columns in reverse on mobile', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
    await (await reveal(page.locator('input[name$="[reverseOnMobile]"]'))).check();
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

test('a section gets a background image under a veil', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));
    const sidebar = page.locator('.cb-shell__sidebar');
    const saved = () => page.waitForResponse((r) => /\/section\/\d+\/settings$/.test(r.url())
        && r.request().method() === 'POST');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    let save = saved();
    await (await reveal(sidebar.locator('input[name$="[stylingCustom]"]'))).check();
    await save;

    // The veil fields wait for an image, in the Background panel.
    await reveal(sidebar.locator('.cb-image-upload'));
    const opacity = sidebar.locator('input[name$="[styling][overlayOpacity]"]');
    await expect(opacity).toBeHidden();
    const upload = sidebar.locator('.cb-image-upload').first();
    await upload.locator('.cb-image-upload__path-toggle').click();
    save = saved();
    await upload.locator('.cb-image-upload__path').fill('/uploads/e2e-hero.jpg');
    await upload.locator('.cb-image-upload__path').press('Enter');
    await save;
    await expect(opacity).toBeVisible();

    save = saved();
    await opacity.fill('50');
    await save;

    const section = frame.locator('.cb-section').first();
    await expect(section).toHaveClass(/cb-section--bg-image/, { timeout: 10000 });
    await expect(section).toHaveClass(/cb-section--bg-dark/);
    // Through the host's image resolver: LiipImagine in this sandbox.
    await expect.poll(() => section.getAttribute('style')).toMatch(/--cb-s-bg-img:url\("[^"]*\/uploads\/e2e-hero\.jpg"\)/);
    expect(await section.evaluate((el) => getComputedStyle(el, '::before').opacity)).toBe('0.5');
    expect(await section.evaluate((el) => getComputedStyle(el).backgroundImage)).toContain('/uploads/e2e-hero.jpg');
});

test('a button group puts its buttons in one row', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    const column = frame.locator('[data-cb-column-id]').first();
    await column.locator('.cb-add-block-inline').click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Groupe de boutons|Button group)$/ }).click();

    const group = column.locator('.cb-kit-btn-group');
    await expect(group.locator('a.cb-kit-btn')).toHaveCount(2);
    const tops = await group.locator('a').evaluateAll((links) => links.map((a) => a.getBoundingClientRect().top));
    expect(tops[1]).toBe(tops[0]);

    await publish(page);
    const viewer = await context.newPage();
    await viewer.goto(builderUrl.replace('/admin/page/', '/page/'));
    await expect(viewer.locator('.cb-kit-btn-group a.cb-kit-btn')).toHaveCount(2);
    await viewer.close();
});
