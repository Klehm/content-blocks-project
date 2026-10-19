import { test, expect } from '../e2e/helpers/test.js';
import { launchBuilder } from '../e2e/helpers/builder.js';

/**
 * A block whose JS is a Webpack Encore entry (BlockAssets `entries`): the
 * entry file alone never runs without the runtime and the chunk it shares,
 * which this sandbox's public page does not otherwise load.
 */

const files = (scope) => scope.locator('script[src^="/build/"]').evaluateAll(
    (scripts) => scripts.map((s) => s.getAttribute('src').replace(/\.[0-9a-f]+\.js$/, '.js')),
);

test('an Encore entry runs with its runtime, in the preview and on the page', async ({ page }) => {
    const response = await page.request.post('/page/create', { form: { title: 'Encore — entry' }, maxRedirects: 0 });
    const location = response.headers()['location'];
    await page.goto(location);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Compteur$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const field = page.locator('aside[data-cb-builder-target="sidebar"] input[name="content_block[value]"]');
    await field.fill('42');
    await field.blur();
    await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();

    // Hot-inserted: the overlay linked the runtime first, then the entry.
    await expect(frame.locator('.demo-counter__value')).toHaveText('42', { timeout: 5000 });
    const inPreview = await files(frame);
    expect(inPreview.indexOf('/build/runtime.js')).toBeGreaterThanOrEqual(0);
    expect(inPreview.indexOf('/build/runtime.js')).toBeLessThan(inPreview.indexOf('/build/blocks/counter.js'));

    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled();

    await page.goto(location.replace('/admin/page/', '/page/'));
    await expect(page.locator('.demo-counter__value')).toHaveText('42', { timeout: 5000 });
    await expect(page.locator('.demo-counter__value')).toHaveCSS('font-weight', '800');
    const onPage = await files(page);
    expect(onPage.filter((f) => f === '/build/runtime.js')).toHaveLength(1);
});
