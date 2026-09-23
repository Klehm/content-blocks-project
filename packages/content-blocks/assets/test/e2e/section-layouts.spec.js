import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * Host-configured section layouts: the sandbox declares `four_cols`
 * [3,3,3,3] and `sidebar_left` [4,8] in content_blocks.section.layouts.
 */

async function createFreshPage(page) {
    const slug = `e2e-layout-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

/** Column boxes of one section, rounded to the pixel. */
function columnBoxes(section) {
    return section.locator(':scope > .cb-row > [data-cb-column-id]').evaluateAll((cols) =>
        cols.map((c) => {
            const r = c.getBoundingClientRect();
            return { top: Math.round(r.top), width: Math.round(r.width) };
        }),
    );
}

const liveSections = '[data-cb-section-id]:not([data-cb-deleted="1"])';

test.describe('section layouts from host config', () => {
    test('the tray and the sidebar offer the configured layouts', async ({ page }) => {
        const frame = await openBuilder(page);

        const tray = frame.locator('.cb-add-section-tray__btn');
        await expect(tray).toHaveCount(6);
        await expect(frame.locator('.cb-add-section-tray__btn[data-cb-add-section="four_cols"]'))
            .toHaveAttribute('title', '4 colonnes');
        await expect(frame.locator('[data-cb-add-section="four_cols"] rect')).toHaveCount(4);

        await expect(page.locator('.cb-sidebar-empty__btn[data-cb-builder-layout-param="sidebar_left"]'))
            .toBeVisible();
    });

    test('a four-column section renders four equal columns, then wraps', async ({ page }) => {
        const frame = await openBuilder(page);

        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="four_cols"]').click();
        await expect.poll(() => frame.locator(liveSections).count()).toBe(1);
        const section = frame.locator(liveSections).first();
        await expect(section).toHaveClass(/cb-section--four_cols/);
        await expect(section.locator('.cb-col--col-3')).toHaveCount(4);

        // Its settings open straight away; past three columns only "equal"
        // is offered, and it adds up to 100.
        const presets = page.locator('.cb-col-widths__preset:not(.cb-col-widths__preset--custom)');
        await expect(presets).toHaveCount(1);
        await expect(presets.first()).toHaveAttribute('data-cb-widths', '25,25,25,25');

        // Desktop: one row, four equal widths.
        await expect.poll(async () => new Set((await columnBoxes(section)).map((b) => b.top)).size).toBe(1);
        const desktop = await columnBoxes(section);
        const widths = desktop.map((b) => b.width);
        expect(Math.max(...widths) - Math.min(...widths)).toBeLessThanOrEqual(1);

        // Tablet: two per row.
        await page.locator('.cb-shell__viewport-btn[data-cb-builder-viewport-param="tablet"]').click();
        await expect.poll(async () => new Set((await columnBoxes(section)).map((b) => b.top)).size).toBe(2);

        // Mobile: stacked.
        await page.locator('.cb-shell__viewport-btn[data-cb-builder-viewport-param="mobile"]').click();
        await expect.poll(async () => new Set((await columnBoxes(section)).map((b) => b.top)).size).toBe(4);
    });

    test('uneven spans keep their ratio and survive a reload', async ({ page }) => {
        const frame = await openBuilder(page);

        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="sidebar_left"]').click();
        await expect.poll(() => frame.locator(liveSections).count()).toBe(1);

        await page.reload();
        await launchBuilder(page);
        const reloaded = page.frameLocator('.cb-shell__iframe');
        const section = reloaded.locator(liveSections).first();
        await expect(section.locator('[data-cb-column-id]')).toHaveCount(2);
        await expect(section.locator('.cb-col--col-4')).toHaveCount(1);
        await expect(section.locator('.cb-col--col-8')).toHaveCount(1);

        await expect.poll(async () => {
            const [narrow, wide] = await columnBoxes(section);
            return Math.abs(wide.width / narrow.width - 2) < 0.1;
        }).toBe(true);
    });
});
