import { test, expect } from '@playwright/test';

/**
 * Regression: moving the slider must update the editable number input AND
 * survive the Live Component re-render.
 *
 * The range widget submits the number input (the model-bound field), not the
 * slider. Setting `number.value` programmatically fires no event, so a slider
 * move never reached Live's `change`-driven model binding — autosave POSTed the
 * stale value and the morph reverted the number. cb-range now re-dispatches the
 * slider's native input/change onto the number input, so the new value reaches
 * the server and persists across the morph.
 */

async function createFreshPage(page) {
    const slug = `e2e-range-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

test('slider moves update the number input and persist across the Live morph', async ({ page }) => {
    const saves = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && /_components\/ContentBlocks:Block\/save/.test(r.url())) {
            saves.push(r.url());
        }
    });

    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    // Seed a section + an Image block (its edit form carries the width/height
    // RangeType fields and auto-opens in the sidebar).
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    const wrap = sidebar.locator('.cb-form-range-wrap').first();
    const slider = wrap.locator('input[type=range]');
    const number = wrap.locator('input[type=number]');
    await expect(number).toBeVisible();
    // Let cb-autosave snapshot the baseline before we edit.
    await page.waitForTimeout(300);

    // Drive the slider exactly as a real drag would: input during the move,
    // change on release.
    await slider.evaluate((el) => {
        el.value = '500';
        el.dispatchEvent(new Event('input', { bubbles: true }));
        el.dispatchEvent(new Event('change', { bubbles: true }));
    });

    // The number input mirrors the slider immediately (client-side).
    await expect(number).toHaveValue('500');

    // The change drives an autosave; wait for the round trip + morph.
    await expect.poll(() => saves.length, { timeout: 5000 }).toBeGreaterThan(0);
    await page.waitForTimeout(800);

    // The morph re-renders from server state — the persisted value must still
    // be 500 (before the fix it reverted to the pre-drag value).
    await expect(number).toHaveValue('500');
});
