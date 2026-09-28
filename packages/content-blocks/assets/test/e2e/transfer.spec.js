import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';
import { makeZip } from '../helpers/make-zip.js';
import {
    fetchExport, jsonFile, openTransfer, reviewImport, runImport, shellInfo,
} from './helpers/transfer.js';

/**
 * Import / Export as a zip: the export streamed with its media beside the
 * content, the import sent in steps, media this site holds never sent twice.
 */

const PNG = Buffer.from(
    'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==',
    'base64',
);
const sha256 = (bytes) => createHash('sha256').update(bytes).digest('hex');

/** A PNG no page of the sandbox has: its bytes carry a unique suffix. */
const freshPng = () => Buffer.concat([PNG, Buffer.from(`${Date.now()}${Math.random()}`)]);

async function openFreshBuilder(page) {
    const slug = `e2e-transfer-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    await page.goto(response.headers()['location']);
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();

    return page.frameLocator('.cb-shell__iframe');
}

/** An export as another site would have written it: one image block. */
function exportOf(hash, { path = '/uploads/content-blocks/blocks/elsewhere.png' } = {}) {
    return {
        format: 'content-blocks/v1',
        contentArea: { sections: [{
            layout: 'full',
            columns: [{ preset: 'col-12', blocks: [{ type: 'image', data: { src: `asset://${hash}`, alt: 'x' } }] }],
        }] },
        assets: { [hash]: { mimeType: 'image/png', extension: 'png', size: PNG.length, path } },
    };
}

const zipFile = (manifest, media = {}) => ({
    name: 'area.zip',
    mimeType: 'application/zip',
    buffer: Buffer.from(makeZip([
        { name: 'content.json', data: JSON.stringify(manifest), deflate: true },
        ...Object.entries(media).map(([hash, bytes]) => ({ name: `media/${hash}.png`, data: bytes })),
    ])),
});

/** Uploads through the builder's own endpoint, as the image widget does. */
async function upload(page, bytes) {
    const { api, areaId, csrf } = await shellInfo(page);
    const response = await page.request.post(`${api}/upload`, {
        headers: { 'X-CSRF-Token': csrf },
        multipart: {
            file: { name: 'dot.png', mimeType: 'image/png', buffer: bytes },
            // The endpoint checks edit rights on the area the file is for.
            area: areaId,
        },
    });

    return (await response.json()).url;
}

async function seedImage(page, bytes) {
    const url = await upload(page, bytes);
    await runImport(await reviewImport(page, jsonFile({
        format: 'content-blocks/v1',
        contentArea: { sections: [{
            layout: 'full',
            columns: [{ preset: 'col-12', blocks: [{ type: 'image', data: { src: url, alt: 'x' } }] }],
        }] },
        assets: {},
    })));
    await page.locator('dialog.cb-transfer [data-cb-transfer="close"]').last().click();

    return url;
}

const checks = (dialog) => dialog.locator('[data-cb-transfer="checks"]');

test.describe('import / export as a zip', () => {
    test('the export streams a zip holding the content and its media', async ({ page }) => {
        await openFreshBuilder(page);
        const bytes = freshPng();
        const url = await seedImage(page, bytes);

        const { entries, manifest } = await fetchExport(page);

        const hash = sha256(bytes);
        expect([...entries.keys()]).toEqual(['content.json', `media/${hash}.png`]);
        expect(entries.get(`media/${hash}.png`).equals(bytes)).toBe(true);
        expect(manifest.assets[hash].path).toBe(url);
        expect(manifest.contentArea.sections[0].columns[0].blocks[0].data.src).toBe(`asset://${hash}`);

        const bare = await fetchExport(page, { media: false });
        expect([...bare.entries.keys()]).toEqual(['content.json']);
    });

    test('the export tab says what the archive holds and follows the switch', async ({ page }) => {
        await openFreshBuilder(page);
        await seedImage(page, freshPng());

        const dialog = await openTransfer(page);
        await expect(dialog.locator('[data-cb-transfer="stats"]')).toContainText('1');
        const download = dialog.locator('[data-cb-transfer="download"]');
        await expect(download).not.toHaveAttribute('href', /assets=0/);
        await dialog.locator('.cb-transfer__option').click();
        await expect(download).toHaveAttribute('href', /\?assets=0$/);

        await page.keyboard.press('Escape');
        await expect(dialog).toBeHidden();
    });

    test('a copy on the same site sends no media and reuses the files', async ({ page }) => {
        await openFreshBuilder(page);
        const url = await seedImage(page, freshPng());
        const { bytes } = await fetchExport(page);

        const frame = await openFreshBuilder(page);
        const uploads = [];
        page.on('request', (r) => r.url().includes('/import/asset') && uploads.push(r.url()));
        const dialog = await reviewImport(page, { name: 'copy.zip', mimeType: 'application/zip', buffer: bytes });
        await expect(checks(dialog)).toContainText(/1 .*(already|déjà)/);
        await runImport(dialog);

        expect(uploads).toEqual([]);
        await expect(frame.locator(`img[src*="${url.split('/').pop().split('.')[0]}"]`)).toHaveCount(1);
    });

    test('media from another site are sent one by one and stored here', async ({ page }) => {
        const frame = await openFreshBuilder(page);
        const bytes = freshPng();
        const hash = sha256(bytes);

        const dialog = await reviewImport(page, zipFile(exportOf(hash), { [hash]: bytes }));
        await expect(checks(dialog)).toContainText(/1 .*(to send|à envoyer)/);
        await runImport(dialog);
        await expect(dialog.locator('[data-cb-transfer="warnings"] li')).toHaveCount(0);

        const { manifest } = await fetchExport(page);
        const stored = manifest.assets[hash].path;
        expect(stored).toMatch(/^\/uploads\/content-blocks\/blocks\/[0-9a-f]+\.png$/);
        await expect(frame.locator('.cb-kit-image__img')).toHaveCount(1);
    });

    test('a missing file is named, and can be dropped in, checked against the export', async ({ page }) => {
        await openFreshBuilder(page);
        const bytes = freshPng();
        const hash = sha256(bytes);

        const dialog = await reviewImport(page, zipFile(exportOf(hash)));
        await expect(checks(dialog)).toContainText('elsewhere.png');
        const supply = dialog.locator('[data-cb-transfer="supply-file"]');

        await supply.setInputFiles({ name: 'wrong.png', mimeType: 'image/png', buffer: freshPng() });
        await expect(dialog.locator('[data-cb-transfer="review-message"]')).toContainText('wrong.png');

        await supply.setInputFiles({ name: 'right.png', mimeType: 'image/png', buffer: bytes });
        await expect(checks(dialog)).not.toContainText('elsewhere.png');
        await expect(dialog.locator('[data-cb-transfer="supply"]')).toBeHidden();

        await runImport(dialog);
        await expect(dialog.locator('[data-cb-transfer="warnings"] li')).toHaveCount(0);
    });

    test('a script dressed as an image is refused, and nothing is replaced', async ({ page }) => {
        const frame = await openFreshBuilder(page);
        const script = Buffer.from(`<?php echo 'owned'; // ${Date.now()}`);
        const hash = sha256(script);

        const dialog = await reviewImport(page, zipFile(exportOf(hash), { [hash]: script }));
        await runImport(dialog, 'error');

        await expect(dialog.locator('[data-cb-transfer="error"]')).toContainText('not allowed');
        await expect(frame.locator('[data-cb-block-id]')).toHaveCount(0);
    });

    test('a file that is not an export is said so before anything else', async ({ page }) => {
        await openFreshBuilder(page);
        const dialog = await openTransfer(page, 'import');

        await dialog.locator('[data-cb-transfer="file"]').setInputFiles({
            name: 'photo.png', mimeType: 'image/png', buffer: PNG,
        });

        await expect(dialog.locator('[data-cb-transfer="pick-message"]')).not.toBeEmpty();
        await expect(dialog).not.toHaveAttribute('data-step', 'review');
    });
});
