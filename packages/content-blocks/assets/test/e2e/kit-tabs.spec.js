import { test, expect } from '@playwright/test';

/**
 * The kit's Tabs block, whose switching is pure CSS.
 *
 * It used to inline a <style> whose active-tab rule was keyed on the
 * instance's random id — a selector no host could outrank without
 * !important, and whose name it could not even predict. The styling moved to
 * kit.css and now hangs off `:checked + label [+ panel]`, so this covers both
 * halves: the tabs still switch, and a host retheme lands without !important.
 */

async function createFreshPage(page) {
    const slug = `e2e-tabs-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

/** Builds a page holding one Tabs block with two valid tabs. */
async function buildTwoTabPage(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);

    // Open the block editor and add a second tab (each title is NotBlank, so
    // it has to be filled for the autosave to persist).
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    await page.waitForTimeout(300);

    const items = sidebar.locator('.cb-form-collection__item');
    await sidebar.locator('.cb-form-btn--primary').click();
    await expect(items).toHaveCount(2);
    const secondTitle = items.nth(1).locator('input[type="text"]').first();
    await secondTitle.fill('Second');
    await secondTitle.blur();
    await page.waitForTimeout(1200);

    await expect.poll(() => frame.locator('.cb-kit-tabs__tab').count()).toBe(2);
    return frame;
}

/** The preview iframe as a Frame (needed for addStyleTag). */
function previewFrame(page) {
    return page.frames().find((f) => f.url().includes('cb_preview=1'));
}

test.describe('kit tabs — CSS-only switching', () => {
    test('the block ships no inline <style>', async ({ page }) => {
        const frame = await buildTwoTabPage(page);

        await expect(frame.locator('[data-cb-block-id] style')).toHaveCount(0);
    });

    test('clicking a tab reveals its panel and hides the other', async ({ page }) => {
        const frame = await buildTwoTabPage(page);

        const panels = frame.locator('.cb-kit-tabs__panel');
        await expect(panels.nth(0)).toBeVisible();
        await expect(panels.nth(1)).toBeHidden();

        await frame.locator('.cb-kit-tabs__tab').nth(1).click();

        await expect(panels.nth(1)).toBeVisible();
        await expect(panels.nth(0)).toBeHidden();
    });

    test('a host retheme lands through the tokens, without !important', async ({ page }) => {
        const frame = await buildTwoTabPage(page);
        // The active tab is the one a host could not reach before: its rule
        // carried the instance id. Overriding the token is now enough.
        await previewFrame(page).addStyleTag({
            content: '.cb-kit-tabs { --cb-kit-tabs-tab-active: rgb(1, 2, 3); }',
        });

        const active = frame.locator('.cb-kit-tabs__tab').first();
        await expect(active).toHaveCSS('color', 'rgb(1, 2, 3)');
    });
});
