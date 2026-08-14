import { test, expect } from '@playwright/test';

/**
 * `content_blocks_kit.blocks.<type>` reaching a block a host has subclassed.
 *
 * Subclassing a kit block means turning the kit's own service off — two
 * services cannot claim one type id — and that is exactly what used to drop the
 * config: the bundle wired `options` / `choices` / `defaults` while registering
 * its own services, so a subclass came up with the constructor defaults and its
 * whole YAML applied to nothing, silently.
 *
 * The sandbox now carries that setup (`App\ContentBlocks\Block\DividerBlock`,
 * `divider: { enabled: false, choices: …, defaults: … }`), and this asserts the
 * config arrives where only a real container can prove it: in the rendered
 * `<select>` of the block's sidebar.
 */

async function createFreshPage(page) {
    const slug = `e2e-subclass-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

test('kit subclass — the host config reaches a subclassed block', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Séparateur$|^Divider$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

    const style = sidebar.locator('select').filter({ has: page.locator('option') }).first();

    // The map form of `choices` replaced the coded set: the kit's dashed and
    // dotted are gone, and `double` — a value the kit never coded — is offered.
    await expect(style.locator('option')).toHaveText(['Trait plein', 'Double trait']);
    // And `defaults` picked the added value for a new block.
    await expect(style).toHaveValue('double');

    // The field the subclass added is there too, so this really is the host's
    // class and not the kit's.
    await expect(sidebar.locator('input[type="checkbox"][name$="[printOnly]"]')).toHaveCount(1);
});
