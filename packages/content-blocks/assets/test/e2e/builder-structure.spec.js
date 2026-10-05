import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * `content_blocks.structure`, per area: blocks only, fixed sections, locked
 * columns. The sandbox's E2eBuilderStructureResolver reads the mode from the
 * `cb_e2e_structure` cookie.
 */

async function createFreshPage(page) {
    const slug = `e2e-structure-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

/** Sets the mode, then opens the builder of `url` under it. */
async function openBuilder(page, url, structure) {
    await page.goto(url);
    const { origin } = new URL(page.url());
    await page.context().clearCookies({ name: 'cb_e2e_structure' });
    if (structure) {
        await page.context().addCookies([{ name: 'cb_e2e_structure', value: structure, url: origin }]);
    }
    await page.reload();

    return launchBuilder(page);
}

const sidebar = (page) => page.locator('.cb-shell__sidebar');
const liveBlocks = (frame) => frame.locator('[data-cb-block-id]:not([data-cb-deleted])');

async function pickBlockType(frame, label) {
    await frame.locator('.cb-overlay-popover button', { hasText: label }).click();
}

test.describe('blocks only (sections: hidden)', () => {
    test('an empty area offers a block, and blocks stack without any section UI', async ({ page }) => {
        const url = await createFreshPage(page);
        const frame = await openBuilder(page, url, 'hidden');

        await expect(frame.locator('[data-cb-add-section]')).toHaveCount(0);
        await expect(sidebar(page).locator('.cb-sidebar-empty__sections')).toHaveCount(0);
        await expect(sidebar(page).locator('.cb-sidebar-library')).toHaveCount(0);

        await frame.locator('[data-cb-add-block-area]').click();
        await pickBlockType(frame, /^(Titre|Title)$/);

        await expect.poll(() => liveBlocks(frame).count()).toBe(1);
        await expect(sidebar(page)).toHaveAttribute('data-cb-sidebar-block-id', /\d+/);
        await expect(frame.locator('.cb-section-handle')).toHaveCount(0);
        await expect(frame.locator('[data-cb-add-block-area]')).toHaveCount(0);

        // The second block goes below the first, through its column's pill.
        const column = frame.locator('[data-cb-column-id]').first();
        await column.hover();
        await column.locator('.cb-add-block-inline').click();
        await pickBlockType(frame, /^(Texte|Text)$/);
        await expect.poll(() => liveBlocks(frame).count()).toBe(2);
        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);
    });

    test('a click beside the blocks selects no section', async ({ page }) => {
        const url = await createFreshPage(page);
        const frame = await openBuilder(page, url, 'hidden');
        await frame.locator('[data-cb-add-block-area]').click();
        await pickBlockType(frame, /^(Titre|Title)$/);
        await expect.poll(() => liveBlocks(frame).count()).toBe(1);

        const section = frame.locator('[data-cb-section-id]').first();
        const box = await section.boundingBox();
        // The section's bottom edge, below the block: section ground.
        await page.mouse.click(box.x + 4, box.y + box.height - 4);

        await expect(sidebar(page)).not.toHaveAttribute('data-cb-sidebar-section-id', /.+/);
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toHaveCount(0);
    });
});

test.describe('fixed sections', () => {
    test('a section stays selectable, but nothing adds, moves or deletes one', async ({ page }) => {
        const url = await createFreshPage(page);
        // The host's skeleton: one section, added while sections are editable.
        let frame = await openBuilder(page, url, null);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);

        frame = await openBuilder(page, url, 'fixed');

        await expect(frame.locator('.cb-add-section-tray')).toHaveCount(0);
        await expect(sidebar(page).locator('.cb-sidebar-empty__sections')).toHaveCount(0);

        const section = frame.locator('[data-cb-section-id]').first();
        await section.hover();
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toHaveCount(0);

        await frame.locator('.cb-section-handle').first().click();
        await expect(sidebar(page)).toHaveAttribute('data-cb-sidebar-section-id', /\d+/);
        await expect(sidebar(page).locator('input[name="section_settings[widthMode]"]').first()).toBeAttached();

        // Delete on a focused section is the builder's to refuse; it does.
        await frame.locator('body').press('Delete');
        await page.waitForTimeout(300);
        await expect(frame.locator('[data-cb-section-id]:not([data-cb-deleted])')).toHaveCount(1);
    });
});

test.describe('locked columns', () => {
    test('the section sidebar has neither the Columns nor the Layout panel', async ({ page }) => {
        const url = await createFreshPage(page);
        const frame = await openBuilder(page, url, 'editable:nocolumns');
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();

        await expect(sidebar(page)).toHaveAttribute('data-cb-sidebar-section-id', /\d+/);
        await expect(sidebar(page).locator('input[name="section_settings[widthMode]"]').first()).toBeAttached();
        await expect(sidebar(page).locator('.cb-columns-editor')).toHaveCount(0);
        await expect(sidebar(page).locator('.cb-display-row')).toHaveCount(0);
        await expect(sidebar(page).locator('[name="section_settings[display]"]')).toHaveCount(0);
    });
});
