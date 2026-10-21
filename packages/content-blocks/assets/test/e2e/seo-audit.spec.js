import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The SEO audit (klehm/content-blocks-seo): a menu action opens a panel that
 * audits the draft page, a finding selects its block through `cb:focus`, and
 * fixing the block clears the finding on the next save.
 */

async function openFreshBuilder(page) {
    const slug = `e2e-seo-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    await page.goto(location);
    await launchBuilder(page);

    return page.frameLocator('.cb-shell__iframe');
}

async function addTitleBlock(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    const column = frame.locator('[data-cb-column-id]').first();
    await column.hover();
    await column.locator('.cb-add-block-inline').click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-block-id', /\d+/);

    return page.locator('.cb-shell__sidebar').getAttribute('data-cb-sidebar-block-id');
}

async function openAudit(page) {
    await page.locator('.cb-shell__actions-toggle').click();
    await page.getByRole('menuitem', { name: /^(Audit SEO|SEO audit)$/ }).click();
    const panel = page.locator('[data-cb-seo]');
    await expect(panel).toBeVisible();

    return panel;
}

test.describe('SEO audit', () => {
    test('a clean page says so; the host template h1 counts', async ({ page }) => {
        await openFreshBuilder(page);
        const panel = await openAudit(page);

        await expect(panel.locator('[data-cb-seo-status]')).toHaveText(/Aucun problème|No issue/);
        await expect(panel.locator('.cb-seo__heading')).toHaveCount(1);
    });

    test('a finding selects its block, and fixing it clears the finding', async ({ page }) => {
        const frame = await openFreshBuilder(page);
        const first = await addTitleBlock(page, frame);
        const sidebar = page.locator('.cb-shell__sidebar');
        await sidebar.locator('input[name="content_block[text]"]').fill('Alpha');
        await sidebar.locator('select[name="content_block[tag]"]').selectOption('h4');
        await expect(frame.locator('h4', { hasText: 'Alpha' })).toBeVisible({ timeout: 10000 });

        // A second block open, so the click is what moves the selection.
        const column = frame.locator('[data-cb-column-id]').first();
        await column.hover();
        await column.locator('.cb-add-block-inline').last().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
        await expect(sidebar).not.toHaveAttribute('data-cb-sidebar-block-id', first);

        const panel = await openAudit(page);
        // The template's h1, then this block's h4.
        const finding = panel.locator('button[data-check="heading_level_skipped"]');
        await expect(finding).toHaveAttribute('data-block-id', first);

        await finding.click();
        await expect(sidebar).toHaveAttribute('data-cb-sidebar-block-id', first);

        await sidebar.locator('select[name="content_block[tag]"]').selectOption('h2');
        await expect(finding).toHaveCount(0, { timeout: 10000 });
        await expect(panel.locator('[data-cb-seo-status]')).toHaveText(/Aucun problème|No issue/);
    });

    test('a second h1 is reported on the block, not on the template', async ({ page }) => {
        const frame = await openFreshBuilder(page);
        await addTitleBlock(page, frame);
        await page.locator('.cb-shell__sidebar input[name="content_block[text]"]').fill('Hero');
        await page.locator('.cb-shell__sidebar select[name="content_block[tag]"]').selectOption('h1');
        await expect(frame.locator('h1', { hasText: 'Hero' })).toBeVisible({ timeout: 10000 });

        const panel = await openAudit(page);

        await expect(panel.locator('button[data-check="heading_h1_multiple"]')).toHaveCount(1);
    });

    test('the panel closes, and stays closed through saves', async ({ page }) => {
        const frame = await openFreshBuilder(page);
        const panel = await openAudit(page);
        await panel.locator('[data-cb-seo-close]').click();
        await expect(panel).toBeHidden();

        await addTitleBlock(page, frame);

        await expect(panel).toBeHidden();
    });
});
