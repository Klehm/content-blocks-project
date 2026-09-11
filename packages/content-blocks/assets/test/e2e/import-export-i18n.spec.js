import { createHash } from 'node:crypto';
import { test, expect } from '@playwright/test';

/**
 * Translations across an export and an import (klehm/content-blocks-i18n).
 *
 * The rows live in a table of their own, so nothing carries them for free:
 * every flow that duplicates a block has to be taught. This is that proof for
 * the transfer flow, end to end — a page exported here and imported there comes
 * back translated, not merely structurally identical.
 *
 * It lives in this suite because the fixture is the shared sandbox: the real
 * builder, the real export endpoint, the real workbench.
 */

const SOURCE_TEXT = 'Titre importé';
const ENGLISH_TEXT = 'Imported heading in English';
const BLOCK_REF = 's0.c0.b0';
/** What SourceDigest::of() writes for the source text — 16 hex chars. */
const SOURCE_DIGEST = createHash('sha256').update(SOURCE_TEXT).digest('hex').slice(0, 16);
const I18N_KEY = 'content-blocks/i18n';

/** A payload as another installation would have written it. */
function translatedPayload() {
    return {
        format: 'content-blocks/v1',
        exportedAt: '2026-09-11T12:00:00+00:00',
        contentArea: {
            sections: [{
                layout: 'full',
                settings: null,
                columns: [{
                    preset: 'col-12',
                    blocks: [{
                        ref: BLOCK_REF,
                        type: 'title',
                        data: { text: SOURCE_TEXT, size: 'h2', tag: 'h2', color: '' },
                    }],
                }],
            }],
        },
        assets: {},
        extensions: {
            [I18N_KEY]: {
                blocks: {
                    [BLOCK_REF]: {
                        en: {
                            values: { text: ENGLISH_TEXT },
                            digests: { text: SOURCE_DIGEST },
                        },
                    },
                },
            },
        },
    };
}

async function createFreshPage(page) {
    const slug = `e2e-i18n-transfer-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

/** Opens the builder on a fresh page and returns its area id. */
async function openFreshBuilder(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    return page.locator('[data-cb-builder-area-id-value]')
        .first()
        .getAttribute('data-cb-builder-area-id-value');
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
    await expect(panel).toBeHidden();
}

/**
 * The workbench is a page of the satellite, mounted by the sandbox under
 * /admin/translations — the mount point is the host's to choose.
 */
async function expectWorkbenchShows(page, areaId, value) {
    await page.goto(`/admin/translations/workbench/${areaId}/en`);
    const row = page.locator('.cb-wb__row').first();
    await expect(row).toBeVisible();
    await expect(row.locator('[data-target="input"]')).toHaveValue(value);

    // The digest travelled beside its value, so the field reads as done — an
    // import must not flag a whole page for re-translation.
    await expect(row).toHaveAttribute('data-status', 'translated');
}

test.describe('transfer — translations travel with the content', () => {
    test('an imported fragment lands on the block, and a re-export carries it back', async ({ page }) => {
        const source = await openFreshBuilder(page);
        await importPayload(page, translatedPayload());
        await expectWorkbenchShows(page, source, ENGLISH_TEXT);

        // Export what we just imported: the fragment is rebuilt from the rows,
        // addressed by the ref the payload gives that same block.
        const response = await page.request.get(`/_content-blocks/area/${source}/export`);
        expect(response.ok()).toBeTruthy();
        const exported = await response.json();

        const blockRef = exported.contentArea.sections[0].columns[0].blocks[0].ref;
        expect(exported.extensions[I18N_KEY].blocks[blockRef].en.values.text).toBe(ENGLISH_TEXT);
        // Carried, never recomputed: a digest means "translated from *this*
        // source", which only the side that translated can know.
        expect(exported.extensions[I18N_KEY].blocks[blockRef].en.digests.text).toBe(SOURCE_DIGEST);

        // And the loop closes: that export, imported into another area, is a
        // translated page again rather than an untranslated copy.
        const target = await openFreshBuilder(page);
        await importPayload(page, exported);
        await expectWorkbenchShows(page, target, ENGLISH_TEXT);
    });
});
