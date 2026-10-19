import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * `content_blocks_i18n.fallback_lang`, on in the sandbox: on the English page,
 * text still in French (the source) says so with `lang="fr"`. Once for the
 * whole block while nothing is translated, then per field.
 */

async function buildAccordionPage(page) {
    const slug = `e2e-i18n-lang-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const url = response.headers()['location'];
    if (!url) throw new Error('Page create did not redirect');

    await page.goto(url);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Accordéon$|^Accordion$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(300);

    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });

    return url.match(/\/admin\/page\/(\d+)/)[1];
}

test('French text on the English page carries lang="fr"', async ({ page, context }) => {
    const pageId = await buildAccordionPage(page);
    const viewer = await context.newPage();

    // Nothing translated: the wrapper says it once, the fields say nothing.
    await viewer.goto(`/en/page/${pageId}`);
    const block = viewer.locator('.cb-block--accordion');
    await expect(block).toHaveAttribute('lang', 'fr');
    await expect(viewer.locator('.cb-kit-accordion__summary span[lang]')).toHaveCount(0);

    // The French page is in its own language: nothing to say.
    await viewer.goto(`/page/${pageId}`);
    await expect(viewer.locator('.cb-block--accordion')).not.toHaveAttribute('lang', /.*/);

    // Translate the question only, and publish English.
    await page.locator('.cb-shell__actions-toggle').click();
    const [workbench] = await Promise.all([
        page.waitForEvent('popup'),
        page.locator('.cb-shell__action--translate').click(),
    ]);
    await workbench.waitForLoadState('domcontentloaded');
    const row = workbench.locator('.cb-wb__row').first();
    await row.locator('[data-target="input"]').fill('Question in English');
    await expect(row).toHaveAttribute('data-status', 'translated', { timeout: 5000 });
    await workbench.locator('[data-act="publishLocale"]').click();
    await expect(workbench.locator('[data-target="toast"]')).toContainText(/EN/);

    // Half translated: the answer alone is marked, the wrapper no longer is.
    await viewer.goto(`/en/page/${pageId}`);
    await expect(viewer.locator('.cb-block--accordion')).not.toHaveAttribute('lang', /.*/);
    const summary = viewer.locator('.cb-kit-accordion__summary span').first();
    await expect(summary).toHaveText('Question in English');
    await expect(summary).not.toHaveAttribute('lang', /.*/);
    await expect(viewer.locator('.cb-kit-accordion__content')).toHaveAttribute('lang', 'fr');
});
