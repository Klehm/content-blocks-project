import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * BlockAssetsInterface, through the sandbox's counter block
 * (apps/content-blocks-sandbox/src/ContentBlocks/Block/CounterBlock.php):
 * its CSS and JS are linked only where a counter renders. Its module is an
 * importmap entrypoint passed to no importmap() call, so its relative import
 * resolves while it runs nowhere else: a counter reaching its value proves
 * the import resolved.
 */

async function createFreshPage(page) {
    const slug = `e2e-assets-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function builderWithCounter(page) {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Compteur$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    return frame;
}

const counterCss = (scope) => scope.locator('link[rel="stylesheet"][href*="/blocks/counter-"]');
const counterJs = (scope) => scope.locator('script[type="module"][src*="/blocks/counter-"]');

async function setValue(page, value) {
    const field = page.locator('aside[data-cb-builder-target="sidebar"] input[name="content_block[value]"]');
    await field.fill(String(value));
    await field.blur();
    await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();
}

test('a block added in the builder brings its CSS and JS into the preview', async ({ page }) => {
    const frame = await builderWithCounter(page);

    // Hot-inserted: the overlay linked what the block declared, once.
    await expect(counterCss(frame)).toHaveCount(1);
    await expect(counterJs(frame)).toHaveCount(1);
    const value = frame.locator('.demo-counter__value');
    await expect(value).toHaveCSS('font-weight', '800');

    // A replaced block runs its script again (`cb:block:rendered`).
    await setValue(page, 42);
    await expect(value).toHaveText('42', { timeout: 5000 });
    await expect(counterCss(frame)).toHaveCount(1);
});

test('the public page links them once, and only where a counter renders', async ({ page }) => {
    const url = await createFreshPage(page);
    const id = url.match(/\/admin\/page\/(\d+)/)[1];

    // A page without one links nothing of the counter's, and its module,
    // an importmap entrypoint, is not run: nothing of it is even fetched.
    const fetched = [];
    const onRequest = (r) => { if (r.url().includes('/assets/blocks/')) fetched.push(r.url()); };
    page.on('request', onRequest);
    await page.goto(`/page/${id}`);
    await expect(counterCss(page)).toHaveCount(0);
    await page.waitForTimeout(500);
    expect(fetched).toEqual([]);
    page.off('request', onRequest);

    await page.goto(url);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    for (let i = 0; i < 2; i++) {
        // A non-empty column shows its "+ Bloc" pill on hover only.
        const column = frame.locator('[data-cb-column-id]').first();
        await column.hover();
        await column.locator('.cb-add-block-inline').click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Compteur$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(i + 1);
    }
    await setValue(page, 7);
    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });

    await page.goto(`/page/${id}`);
    await expect(counterCss(page)).toHaveCount(1);
    await expect(counterJs(page)).toHaveCount(1);
    // The value was set on the open form: the last counter added.
    await expect(page.locator('.demo-counter__value').last()).toHaveText('7', { timeout: 5000 });
    // Public markup carries no asset list: that is the preview's.
    await expect(page.locator('[data-cb-assets]')).toHaveCount(0);
});

test('what is linked follows the data: no animation, no script', async ({ page }) => {
    const url = await createFreshPage(page);
    const id = url.match(/\/admin\/page\/(\d+)/)[1];
    await page.goto(url);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Compteur$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    await page.locator('aside[data-cb-builder-target="sidebar"] input[name="content_block[animate]"]').uncheck();
    await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();
    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });

    await page.goto(`/page/${id}`);
    await expect(counterCss(page)).toHaveCount(1);
    await expect(counterJs(page)).toHaveCount(0);
});
