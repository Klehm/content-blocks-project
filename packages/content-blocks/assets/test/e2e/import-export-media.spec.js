import { test, expect } from '@playwright/test';

/**
 * Exporting without media: the file keeps this site's paths, a copy on the
 * same site needs nothing more, and a path this site cannot serve is named.
 */

const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'base64',
);

async function openFreshBuilder(page) {
    const slug = `e2e-media-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    await page.goto(response.headers()['location']);
    await page.locator('.cb-launcher__button').click();
    const shell = page.locator('.cb-shell');
    await expect(shell).toBeVisible();

    return {
        frame: page.frameLocator('.cb-shell__iframe'),
        api: await shell.getAttribute('data-cb-api-base'),
        csrf: await shell.getAttribute('data-cb-csrf-token'),
        areaId: await shell.getAttribute('data-cb-builder-area-id-value'),
    };
}

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

const withImage = (src) => ({
    format: 'content-blocks/v1',
    contentArea: { sections: [{
        layout: 'full',
        columns: [{ preset: 'col-12', blocks: [{ type: 'image', data: { src, alt: 'x' } }] }],
    }] },
    assets: {},
});

test.describe('export without media', () => {
    test('keeps the paths, and a copy on the same site imports silently', async ({ page }) => {
        const source = await openFreshBuilder(page);
        const upload = await page.request.post(`${source.api}/upload`, {
            headers: { 'X-CSRF-Token': source.csrf },
            multipart: { file: { name: 'dot.png', mimeType: 'image/png', buffer: PNG } },
        });
        const { url } = await upload.json();
        const panel = await importPayload(page, withImage(url));
        await expect(panel).toBeHidden();

        await page.locator('.cb-shell__actions-toggle').click();
        await page.locator('.cb-shell__import-export').click();
        await expect(panel.locator('[data-cb-builder-target="exportAssets"]')).toBeChecked();
        const bare = await (await page.request.get(
            `${source.api}/area/${source.areaId}/export?assets=0`,
        )).json();
        expect(Object.keys(bare.assets)).toHaveLength(0);
        expect(bare.contentArea.sections[0].columns[0].blocks[0].data.src).toBe(url);

        const target = await openFreshBuilder(page);
        const copied = await importPayload(page, bare);
        await expect(copied).toBeHidden();
        await expect(target.frame.locator(`img[src*="${url.split('/').pop()}"]`)).toHaveCount(1);
    });

    test('a path this site cannot serve is named after the import', async ({ page }) => {
        await openFreshBuilder(page);

        const panel = await importPayload(
            page,
            withImage('/uploads/content-blocks/blocks/never-uploaded.png'),
        );

        await expect(panel).toBeVisible();
        await expect(panel.locator('.cb-import-export-picker__status'))
            .toContainText('never-uploaded.png');
    });
});
