import { test, expect } from '@playwright/test';

/**
 * E2E for the optimistic import: a payload from "another installation" carries a
 * block type this app has never heard of. What must happen, end to end:
 *
 *  - the import succeeds (refusing would make cross-install transfer useless);
 *  - the unknown block does NOT come in (it would be inert here — no view
 *    template, no edit form — and the JSON file remains the archive);
 *  - the editor is told, and can actually read the message.
 *
 * The whole payload is built in the spec and uploaded through the real file
 * input, so nothing about the flow is stubbed.
 */

async function createFreshPage(page) {
    const slug = `e2e-imp-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

function exportPayload(blocks) {
    return {
        format: 'content-blocks/v1',
        exportedAt: '2026-07-29T12:00:00+00:00',
        contentArea: {
            sections: [{
                layout: 'full',
                settings: null,
                columns: [{ preset: 'col-12', blocks }],
            }],
        },
        assets: {},
    };
}

/** Uploads the payload through the topbar panel and accepts the overwrite confirm. */
async function importPayload(page, payload) {
    await page.locator('.cb-shell__actions-toggle').click();
    await page.locator('.cb-shell__import-export').click();
    const panel = page.locator('.cb-import-export-picker');
    await expect(panel).toBeVisible();

    await panel.locator('.cb-import-export-picker__file').setInputFiles({
        name: 'area.json',
        mimeType: 'application/json',
        buffer: Buffer.from(JSON.stringify(payload)),
    });

    page.once('dialog', (dialog) => dialog.accept());
    await panel.locator('.cb-import-export-picker__btn--primary').last().click();

    return panel;
}

test.describe('import — unusable blocks are skipped, not silently kept', () => {
    test('an unknown block type is left out and reported, the rest comes in', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));

        const panel = await importPayload(page, exportPayload([
            { type: 'title', data: { text: 'Imported heading', size: 'h2', tag: 'h2', color: '' } },
            { type: 'countdown', data: { endsAt: '2027-01-01' } },
            { type: 'countdown', data: { endsAt: '2028-01-01' } },
        ]));

        // The panel stays open on its status line — closing it would blank the
        // only element carrying the message.
        await expect(panel).toBeVisible();
        const status = panel.locator('.cb-import-export-picker__status');
        await expect(status).toContainText('countdown');
        await expect(status).toContainText('2', { timeout: 5000 });

        // One section, and only the block this app can actually render.
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(frame.locator('[data-cb-block-type="title"]')).toHaveCount(1);
    });

    test('a fully usable payload imports silently and closes the panel', async ({ page }) => {
        // The counterpart: no warning means the panel gets out of the way, so
        // the assertion above is about the skip and not about the panel always
        // staying open. The block data must use the kit title block's real keys
        // (text/size/tag/color) — anything else is an unknown field, which is
        // itself a warning.
        const frame = await openBuilder(page, await createFreshPage(page));

        const panel = await importPayload(page, exportPayload([
            { type: 'title', data: { text: 'All good', size: 'h2', tag: 'h2', color: '' } },
        ]));

        await expect(panel).toBeHidden();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });
});
