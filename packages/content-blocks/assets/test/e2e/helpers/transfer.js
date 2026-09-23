import { expect } from '@playwright/test';
import { readDirectory, entryBlob } from '../../../transfer/zip-reader.js';

/**
 * The Import / Export dialog as an editor drives it, and the export read back
 * with the same zip reader the builder uses.
 */

export async function openTransfer(page, tab = 'export') {
    await page.locator('.cb-shell__actions-toggle').click();
    await page.locator('.cb-shell__import-export').click();
    const dialog = page.locator('dialog.cb-transfer');
    await expect(dialog).toBeVisible();
    if (tab === 'import') await dialog.locator('[data-cb-transfer-tab="import"]').click();

    return dialog;
}

/** Picks a file in the import tab and waits for its review. */
export async function reviewImport(page, file) {
    const dialog = await openTransfer(page, 'import');
    await dialog.locator('[data-cb-transfer="file"]').setInputFiles({
        mimeType: 'application/octet-stream',
        ...file,
    });
    await expect(dialog).toHaveAttribute('data-step', 'review');

    return dialog;
}

/** Runs the reviewed import and waits for its outcome. */
export async function runImport(dialog, step = 'done') {
    await dialog.locator('[data-cb-transfer="run"]').click();
    await expect(dialog).toHaveAttribute('data-step', step, { timeout: 15000 });

    return dialog;
}

export function jsonFile(payload, name = 'area.json') {
    return { name, mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(payload)) };
}

/** The shell's API base and area id, read off the open builder. */
export async function shellInfo(page) {
    const shell = page.locator('.cb-shell');

    return {
        api: await shell.getAttribute('data-cb-api-base'),
        areaId: await shell.getAttribute('data-cb-builder-area-id-value'),
        csrf: await shell.getAttribute('data-cb-csrf-token'),
    };
}

/** Downloads an export and reads it: its raw bytes, entries and manifest. */
export async function fetchExport(page, { media = true } = {}) {
    const { api, areaId } = await shellInfo(page);
    const response = await page.request.get(`${api}/area/${areaId}/export${media ? '' : '?assets=0'}`);
    expect(response.ok()).toBeTruthy();
    expect(response.headers()['content-type']).toBe('application/zip');
    const bytes = await response.body();
    expect(Number(response.headers()['content-length'])).toBe(bytes.length);

    const blob = new Blob([bytes]);
    const entries = new Map();
    for (const [name, entry] of await readDirectory(blob)) {
        entries.set(name, Buffer.from(await (await entryBlob(blob, entry)).arrayBuffer()));
    }

    return { bytes, entries, manifest: JSON.parse(entries.get('content.json').toString()) };
}
