import { test, expect } from '@playwright/test';

/**
 * Regression: focusing a block must NOT trigger a spurious autosave.
 *
 * cb-spacing-link engages the box-spacing "link" on connect when the four
 * sides are uniform (the case for a freshly-focused block), checking a hidden
 * [linked] checkbox right after cb-autosave's baseline snapshot. That used to
 * dirty the form and trip an autosave on mere focus — which, with in-place
 * block adds, visibly hot-reloads the block. cb-autosave now excludes [linked]
 * toggles from its dirty-detection, so no save fires until the user edits.
 */

async function createFreshPage(page) {
    const slug = `e2e-spacelink-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

test('adding/focusing a block does not trigger a spurious save', async ({ page }) => {
    const saves = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && /_components\/ContentBlocks:Block\/save/.test(r.url())) {
            saves.push(r.url().replace(/\?.*/, ''));
        }
    });

    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);

    // Add an Image block — its edit form (with the box-spacing styling sub-form)
    // mounts and auto-focuses. No user edit happens.
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    // Give any spurious autosave time to fire.
    await page.waitForTimeout(2000);

    expect(saves).toHaveLength(0);
});
