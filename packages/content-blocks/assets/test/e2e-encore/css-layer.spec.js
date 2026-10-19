import { test, expect } from '@playwright/test';
import { launchBuilder } from '../e2e/helpers/builder.js';

/**
 * This sandbox sets `content_blocks.css_layer: content-blocks`: the public
 * stylesheets (core and kit) are served layered, so any unlayered host rule
 * wins over them whatever its specificity. The builder chrome is not.
 */

async function publishedTitle(page) {
    const response = await page.request.post('/page/create', {
        form: { title: 'Encore — css layer' },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    await page.goto(location);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    const field = page.locator('aside[data-cb-builder-target="sidebar"] .cb-block__edit-form input[type="text"]').first();
    await field.fill('Layered heading');
    await field.blur();
    await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();

    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled();

    return location.replace('/admin/page/', '/page/');
}

test('the public stylesheets are served in the configured layer', async ({ page }) => {
    await page.goto(await publishedTitle(page));

    const hrefs = await page.locator('link[rel="stylesheet"]').evaluateAll(
        (links) => links.map((link) => link.getAttribute('href')),
    );
    const linked = hrefs.filter((href) => /\/_content-blocks(-kit)?\/public\/(layout|styling|kit)\b/.test(href));
    expect(linked).toHaveLength(3);
    for (const href of linked) {
        // The `?v=` names the layered bytes, so it is served immutable.
        const response = await page.request.get(href);
        expect(response.headers()['cache-control']).toContain('immutable');
        expect(await response.text()).toMatch(/^@layer content-blocks \{\n/);
    }

    // The chrome must beat a host's page CSS: never layered.
    const builder = await page.request.get('/_content-blocks/public/builder');
    expect(await builder.text()).not.toContain('@layer');
});

test('a host rule of lower specificity wins over the package', async ({ page }) => {
    await page.goto(await publishedTitle(page));
    const title = page.locator('.cb-kit-title', { hasText: 'Layered heading' });
    const weight = () => title.evaluate((el) => getComputedStyle(el).fontWeight);

    // `.cb-kit-title { font-weight: 700 }` from kit.css.
    expect(await weight()).toBe('700');

    // A bare type selector: unlayered, `.cb-kit-title` would still win.
    await page.addStyleTag({ content: 'h2 { font-weight: 300; }' });
    expect(await weight()).toBe('300');
});
