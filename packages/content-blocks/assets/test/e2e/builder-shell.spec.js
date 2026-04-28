import { test, expect } from '@playwright/test';

/**
 * Phase 1 e2e — verifies the iframe + sidebar shell plumbing end-to-end:
 *
 *  1. Page renders the launcher button.
 *  2. Click opens the <dialog> with topbar + iframe + bottom add-section bar.
 *  3. Iframe loads the preview URL and the overlay JS sends cb:ready to the
 *     parent admin window.
 *  4. Hover a block in the iframe → floating action toolbar appears.
 *  5. Click overlay buttons / topbar buttons / footer buttons → the parent's
 *     cb-builder Stimulus controller logs the matching intents.
 *
 * No actual editing happens in phase 1. This test is purely about plumbing.
 */

const PAGE_URL = '/admin/page/1';

/**
 * Capture the parent's console.log output. We assert against the strings the
 * cb-builder Stimulus controller emits when it receives postMessage events
 * or its own action methods fire.
 */
function attachConsoleSink(page) {
    const lines = [];
    page.on('console', (msg) => {
        if (msg.type() === 'log') {
            const text = msg.text();
            if (text.startsWith('[cb-builder]')) lines.push(text);
        }
    });
    return lines;
}

test.describe('builder shell — phase 1 plumbing', () => {
    test('launcher button is present and opens the dialog', async ({ page }) => {
        await page.goto(PAGE_URL);

        // The launcher renders translation keys when no message catalog is
        // present in the sandbox — that's fine, we match on the key.
        const launcher = page.locator('.cb-launcher__button');
        await expect(launcher).toBeVisible();

        // Dialog starts closed.
        const dialog = page.locator('.cb-builder-dialog');
        await expect(dialog).not.toHaveAttribute('open');

        await launcher.click();

        // Dialog is open + shell skeleton visible.
        await expect(dialog).toHaveAttribute('open', '');
        await expect(page.locator('.cb-shell')).toBeVisible();
        await expect(page.locator('.cb-shell__topbar')).toBeVisible();
        await expect(page.locator('.cb-shell__iframe')).toBeVisible();
        await expect(page.locator('.cb-shell__bottom')).toBeVisible();
    });

    test('iframe loads the preview URL with cb_preview=1', async ({ page }) => {
        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        const iframe = page.locator('.cb-shell__iframe');
        await expect(iframe).toHaveAttribute('src', /\/page\/1\?cb_preview=1$/);

        // Wait for the iframe to actually load + the overlay script to set
        // up the markers and the toolbar root.
        const frame = page.frameLocator('.cb-shell__iframe');
        await expect(frame.locator('[data-cb-block-id]').first()).toBeAttached();
        await expect(frame.locator('[data-cb-section-id]').first()).toBeAttached();
        await expect(frame.locator('.cb-overlay-toolbar')).toBeAttached();
    });

    test('iframe sends cb:ready to the parent on load', async ({ page }) => {
        const logs = attachConsoleSink(page);

        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        await expect.poll(() => logs.some((l) => l.includes('iframe ready'))).toBe(true);
    });

    test('hover over a block reveals the floating toolbar', async ({ page }) => {
        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        const frame = page.frameLocator('.cb-shell__iframe');
        const block = frame.locator('[data-cb-block-id]').first();
        await expect(block).toBeAttached();

        await block.hover();
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toBeVisible();
    });

    test('clicking Edit on a block mounts the BlockComponent in the sidebar', async ({ page }) => {
        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        const frame = page.frameLocator('.cb-shell__iframe');
        const block = frame.locator('[data-cb-block-id]').first();
        await block.hover();
        await frame.locator('.cb-overlay-toolbar__btn').first().click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
        await expect(sidebar.locator('button.btn-primary')).toBeVisible();
    });

    test('cancel in sidebar unmounts without reloading iframe', async ({ page }) => {
        const logs = attachConsoleSink(page);
        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        const frame = page.frameLocator('.cb-shell__iframe');
        await frame.locator('[data-cb-block-id]').first().hover();
        await frame.locator('.cb-overlay-toolbar__btn').first().click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        await sidebar.locator('button.btn-secondary').click();

        await expect(sidebar).toHaveAttribute('hidden', '');
        await expect.poll(() => logs.some((l) => l.startsWith('[cb-builder] block:cancel'))).toBe(true);
    });

    test('topbar Publish + Discard buttons fire their intents', async ({ page }) => {
        const logs = attachConsoleSink(page);

        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        await page.locator('.cb-shell__publish').click();
        await expect.poll(() => logs.some((l) => l.startsWith('[cb-builder] publish requested'))).toBe(true);

        const discard = page.locator('.cb-shell__discard');
        if (await discard.isEnabled()) {
            await discard.click();
            await expect.poll(() => logs.some((l) => l.startsWith('[cb-builder] discard requested'))).toBe(true);
        }
    });

    test('footer add-section buttons fire cb-builder#addSection with the right layout', async ({ page }) => {
        const logs = attachConsoleSink(page);

        await page.goto(PAGE_URL);
        await page.locator('.cb-launcher__button').click();

        const buttons = page.locator('.cb-shell__bottom button[data-action*="cb-builder#addSection"]');
        await expect(buttons).toHaveCount(3);

        await buttons.nth(0).click(); // full
        await buttons.nth(1).click(); // two_cols
        await buttons.nth(2).click(); // three_cols

        await expect.poll(() => logs.filter((l) => l.startsWith('[cb-builder] addSection')).length).toBeGreaterThanOrEqual(3);
    });
});
