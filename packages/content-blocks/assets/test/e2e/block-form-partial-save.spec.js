import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * An invalid field keeps its stored value while the valid ones save, and its
 * error shows only once the editor has touched it. The kit's alert block has
 * a required message, empty when the block is added.
 */

async function createFreshPage(page) {
    const slug = `e2e-partial-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

const sidebar = (page) => page.locator('aside[data-cb-builder-target="sidebar"]');
const typeSelect = (page) => sidebar(page).locator('select[name="content_block[type]"]');
const message = (page) => sidebar(page).locator('textarea[name="content_block[content]"]');
const errors = (page) => sidebar(page).locator('.cb-form-errors');

/** A page with one empty alert block, its sidebar open. */
async function addAlert(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    const frame = await launchBuilder(page);
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Alerte|Alert)$/ }).click();
    await expect(typeSelect(page)).toBeVisible();
    // Let cb-autosave snapshot the baseline before the edit.
    await page.waitForTimeout(300);

    return { url, frame };
}

async function reopenFirstBlock(page, url) {
    await page.goto(url);
    const frame = await launchBuilder(page);
    const block = frame.locator('[data-cb-block-id]').first();
    const box = await block.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + Math.min(box.height / 2, 20));
    await expect(typeSelect(page)).toBeVisible();
}

test('changing the type saves it, with no error on the message left empty', async ({ page }) => {
    const { url } = await addAlert(page);

    await typeSelect(page).selectOption('warning');
    await page.waitForTimeout(1200);

    await expect(errors(page)).toHaveCount(0);

    await reopenFirstBlock(page, url);
    await expect(typeSelect(page)).toHaveValue('warning');
});

test('a message the editor empties shows its error, and keeps its last value', async ({ page }) => {
    const { url, frame } = await addAlert(page);
    await message(page).click();
    await page.keyboard.type('Mind the step');
    await page.keyboard.press('Tab');
    await expect(frame.locator('.cb-kit-alert__message')).toHaveText('Mind the step');

    // As an editor does: fill('') + blur() never commits the empty value.
    await message(page).click();
    await page.keyboard.press('ControlOrMeta+A');
    await page.keyboard.press('Backspace');
    await page.keyboard.press('Tab');
    await expect(errors(page)).toHaveCount(1);
    await typeSelect(page).selectOption('error');
    await expect(frame.locator('.cb-kit-alert--error')).toBeVisible();

    await reopenFirstBlock(page, url);
    await expect(typeSelect(page)).toHaveValue('error');
    await expect(message(page)).toHaveValue('Mind the step');
});
