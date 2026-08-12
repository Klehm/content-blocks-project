import { test, expect } from '@playwright/test';

/**
 * E2E for the copy/paste clipboard.
 *
 * The feature is keyboard-only (Ctrl/Cmd-C, Ctrl/Cmd-V) and its store is the
 * browser's `localStorage`, so the two things worth driving a real browser for
 * are exactly those: the shortcut has to survive being pressed *inside the
 * preview iframe* (a separate document, whose keydown never reaches the builder
 * window on its own), and a copy has to survive leaving the page — which is the
 * whole reason the clipboard is not in memory.
 *
 * The refusal path gets a real browser too: an entry the server will not accept
 * must produce a message, not a silent nothing.
 */

const CLIPBOARD_KEY = 'cb-builder.clipboard';

async function createFreshPage(page) {
    const slug = `e2e-clip-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

async function addFirstBlock(page, frame, text) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').last().click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);

    if (text) {
        // Real content, so a paste that silently blanked the block's data
        // would be caught — the whole point of the strict replay is that it
        // drops what a form refuses *without* touching what it accepts.
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await sidebar.locator('.cb-block__edit-form input[type="text"]').first().fill(text);
        await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();
        await page.waitForTimeout(400);
    }
}

/**
 * Clicking an element is what selects it — the sidebar it opens *is* the
 * selection, so these wait for the sidebar to actually carry the id rather than
 * for the click to land. The corner offset keeps the pointer clear of the
 * floating toolbar, which sits over the element's top edge.
 */
async function selectBlock(page, frame, index = 0) {
    const el = frame.locator('[data-cb-block-id]').nth(index);
    const id = await el.getAttribute('data-cb-block-id');
    // Bottom edge: the block's own toolbar hovers over its top-right corner and
    // the section handle over the top-left one, so both corners are taken.
    const box = await el.boundingBox();
    await page.mouse.click(box.x + 20, box.y + box.height - 4);
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-block-id', id);

    return id;
}

async function selectSection(page, frame, index = 0) {
    const el = frame.locator('[data-cb-section-id]').nth(index);
    const id = await el.getAttribute('data-cb-section-id');
    await el.click({ position: { x: 5, y: 5 } });
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-section-id', id);

    return id;
}

/** Fires the shortcut with focus inside the preview, i.e. through the relay. */
async function copy(page) {
    await page.keyboard.press('Control+c');
    await expect(page.locator('.cb-shell__undo')).toBeVisible();
}

async function paste(page) {
    await page.keyboard.press('Control+v');
    await page.waitForTimeout(500);
}

function readClipboard(page) {
    return page.evaluate((key) => window.localStorage.getItem(key), CLIPBOARD_KEY);
}

test.describe('clipboard — copy / paste', () => {
    test('copies a block in the preview and pastes it after the selected block', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addFirstBlock(page, frame, 'e2e-clipboard-title');

        await selectBlock(page, frame);
        await copy(page);

        // The acknowledgement is the only sign a copy happened — and the entry
        // is really in the browser's store, not in a variable.
        expect(await readClipboard(page)).toContain('content-blocks/clipboard-v1');

        await paste(page);
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);

        // The copy carries the block's actual content — the replay filters
        // what a form refuses, it does not blank what it accepts.
        await expect(frame.locator('[data-cb-block-id]').nth(1)).toContainText('e2e-clipboard-title');

        // Draft-written: it survives a real reload of the whole page.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator('[data-cb-block-id]').first()).toBeVisible();
        await expect.poll(() => reloaded.locator('[data-cb-block-id]').count()).toBe(2);
    });

    test('pastes a copied block into another page — the point of a stored clipboard', async ({ page }) => {
        const sourceFrame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame, 'e2e-across-pages');
        await selectBlock(page, sourceFrame);
        await copy(page);
        await page.locator('.cb-shell__close').click();

        // Another page, another area, another builder session entirely.
        const targetFrame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, targetFrame);
        await selectSection(page, targetFrame);
        await paste(page);

        // It landed in the selected section's first column, content and all.
        await expect.poll(() => targetFrame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(targetFrame.locator('[data-cb-block-id]').first()).toContainText('e2e-across-pages');
    });

    test('pastes a copied section right after the selected one', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addFirstBlock(page, frame, 'e2e-section-copy');
        await addFullSection(page, frame);

        await selectSection(page, frame, 0);
        await copy(page);
        await paste(page);

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(3);

        // Position, not just count: the copy sits between the two originals,
        // and it brought its block along.
        const blocksPerSection = await frame.locator('[data-cb-section-id]').evaluateAll(
            (els) => els.map((el) => el.querySelectorAll('[data-cb-block-id]').length),
        );
        expect(blocksPerSection).toEqual([1, 1, 0]);
        await expect(frame.locator('[data-cb-section-id]').nth(1)).toContainText('e2e-section-copy');
    });

    test('refuses a tampered entry out loud, and forgets it', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);

        // Hand-written entry: exactly what localStorage lets anyone do.
        await page.evaluate(([key, entry]) => {
            window.localStorage.setItem(key, entry);
        }, [CLIPBOARD_KEY, JSON.stringify({
            format: 'content-blocks/clipboard-v1',
            scope: 'section',
            contentVersion: 99,
            payload: { format: 'content-blocks/section-v1', layout: 'full', columns: [] },
        })]);

        await selectSection(page, frame);
        await paste(page);

        // Nothing was written, and the editor was told why.
        await expect(page.locator('.cb-shell__undo')).toBeVisible();
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        expect(await readClipboard(page)).toBeNull();
    });
});
