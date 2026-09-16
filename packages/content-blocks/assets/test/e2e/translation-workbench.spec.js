import { test, expect } from '@playwright/test';

/**
 * The translation workbench (klehm/content-blocks-i18n) against the sandbox.
 *
 * It lives in this suite rather than the satellite's because the fixture is the
 * shared sandbox: the workbench needs a real page, built through the real
 * builder, with real translatable content in it. The satellite owns the
 * workbench's unit tests (Vitest) and its PHP tests; what only a browser can
 * answer is what this file covers — the two panes' layout, and the fact that
 * the preview really is the host's own page with the editing chrome gone.
 *
 * The sandbox mounts the package's routes under `/admin/translations`, so every
 * URL here doubles as proof that the mount point is the host's to choose.
 */

const LONG_TITLE = 'Un titre volontairement long, écrit pour occuper plusieurs lignes '
    + 'dans la colonne source et forcer le champ de traduction à prendre la même hauteur.';

async function createFreshPage(page) {
    const slug = `e2e-i18n-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

/**
 * Builds a page holding one Tabs block whose first tab title is long.
 *
 * Tabs is the cheapest fixture with translatable text: it ships one valid
 * default item, so the only edit needed is making its title long enough to wrap
 * — which is what the height assertion needs.
 */
async function buildPageWithTranslatableText(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);

    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(400);

    // Click-to-edit, dispatched in-iframe so the section's hover handle cannot
    // intercept a coordinate click.
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    await page.waitForTimeout(300); // let cb-autosave connect

    const title = sidebar.locator('.cb-form-collection__item input[type="text"]').first();
    await title.fill(LONG_TITLE);
    await title.blur();
    await page.waitForTimeout(1200); // autosave round-trip
}

/** Opens the workbench through the host's own topbar action. */
async function openWorkbench(page) {
    await page.locator('.cb-shell__actions-toggle').click();

    const [workbench] = await Promise.all([
        page.waitForEvent('popup'),
        page.locator('.cb-shell__action--translate').click(),
    ]);

    await workbench.waitForLoadState('domcontentloaded');
    await expect(workbench.locator('.cb-wb__row').first()).toBeVisible();

    return workbench;
}

test.describe('translation workbench', () => {
    /**
     * The pane is the host's page in preview mode — draft content, so an
     * unpublished translation shows — but with `cb_chrome=0`, so none of the
     * builder's editing furniture comes with it. Hiding it in CSS was not
     * enough: the overlay script still ran and still bound hover handlers.
     */
    test('the preview is the raw page: draft content, no editing chrome', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const workbench = await openWorkbench(page);

        const preview = workbench.frameLocator('.cb-wb__preview iframe');

        // The content is there, and it is the draft (never published).
        await expect(preview.locator('[data-cb-block-id]').first()).toBeVisible();

        // None of the chrome is, not even hidden: it was never rendered.
        await expect(preview.locator('.cb-overlay-toolbar')).toHaveCount(0);
        await expect(preview.locator('.cb-add-section-tray')).toHaveCount(0);
        await expect(preview.locator('.cb-section-handle')).toHaveCount(0);
        await expect(preview.locator('.cb-add-block-inline')).toHaveCount(0);

        // Hovering a block used to reveal the toolbar; there is nothing to
        // reveal.
        await preview.locator('[data-cb-block-id]').first().hover();
        await workbench.waitForTimeout(200);
        await expect(preview.locator('.cb-overlay-toolbar')).toHaveCount(0);

        // builder.css is what styles that chrome — it is not loaded either.
        const stylesheets = await workbench.locator('.cb-wb__preview iframe').evaluate(
            (el) => [...el.contentDocument.querySelectorAll('link[rel="stylesheet"]')].map((l) => l.getAttribute('href')),
        );
        expect(stylesheets.some((href) => href?.includes('/public/builder'))).toBe(false);
        expect(stylesheets.some((href) => href?.includes('/public/layout'))).toBe(true);
    });

    /**
     * The translation field is as tall as the source text beside it. A long
     * source in a three-row textarea means scrolling a box to check a
     * translation against a paragraph that is fully visible next to it.
     */
    test('the translation field matches the height of its source text', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const workbench = await openWorkbench(page);

        const row = workbench.locator('.cb-wb__row').first();
        const source = row.locator('.cb-wb__source');
        const input = row.locator('[data-target="input"]');

        const sourceBox = await source.boundingBox();
        const inputBox = await input.boundingBox();

        // Tall enough to be the wrapped case rather than a one-liner, so the
        // assertion below is actually about matching and not about the floor.
        expect(sourceBox.height).toBeGreaterThan(40);
        // Same height give or take the row's own padding/border.
        expect(Math.abs(inputBox.height - sourceBox.height)).toBeLessThanOrEqual(8);
    });

    /**
     * Hovering the machine-translation button must not erase its own label.
     *
     * `.cb-wb__btn:hover` is a class plus a pseudo-class, so it outranks the
     * plain `--accent` rule and used to repaint the button pale while its
     * `color: #fff` stayed — white text on a near-white ground. A computed-
     * style assertion is the only kind that catches this: the markup is
     * unchanged and the button is still perfectly clickable.
     */
    test('the translate-all button stays readable while hovered', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const workbench = await openWorkbench(page);

        const button = workbench.locator('[data-act="translateAll"]');
        await expect(button).toBeVisible();

        const styles = async () => button.evaluate((el) => {
            const s = getComputedStyle(el);
            return { background: s.backgroundColor, color: s.color };
        });

        const resting = await styles();
        await button.hover();
        await workbench.waitForTimeout(150);
        const hovered = await styles();

        // The accent ground survives the hover — that is the whole bug.
        expect(hovered.background).toBe(resting.background);
        expect(hovered.color).toBe(resting.color);
        expect(hovered.background).not.toBe(hovered.color);
    });

    /**
     * The sandbox wires a LocalizedPageUrlResolver: every language gets a link
     * to its own public page, the open one flagged, source first.
     */
    test('the topbar links the published page in each language', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const publicUrl = await page.locator('.cb-shell__public-link').getAttribute('href');
        const id = publicUrl.match(/\/page\/(\d+)/)[1];
        const workbench = await openWorkbench(page);
        const locale = new URL(workbench.url()).pathname.split('/').pop();

        const links = workbench.locator('.cb-wb__public-link');
        await expect(links).toHaveText(['FR', 'EN', 'DE', 'ES']);
        await expect(links.nth(0)).toHaveAttribute('href', `/page/${id}`);
        await expect(links.nth(2)).toHaveAttribute('href', `/de/page/${id}`);
        await expect(links.nth(0)).toHaveAttribute('target', '_blank');

        const current = workbench.locator('.cb-wb__public-link[aria-current="true"]');
        await expect(current).toHaveText(locale.toUpperCase());
        await expect(current).toHaveAttribute('href', `/${locale}/page/${id}`);

        const response = await workbench.request.get(`/de/page/${id}`);
        expect(response.status()).toBe(200);
    });

    /**
     * The preview's left edge is a drag handle, as the builder's sidebar is:
     * dragging over the frame still works, and the width survives a reload.
     */
    test('the preview is resized by dragging its edge, and stays that way', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const workbench = await openWorkbench(page);
        await workbench.setViewportSize({ width: 1600, height: 900 });

        const pane = workbench.locator('.cb-wb__preview');
        const handle = workbench.locator('.cb-wb__resize');
        const before = (await pane.boundingBox()).width;
        expect(Math.abs(before - 800)).toBeLessThan(4);

        const box = await handle.boundingBox();
        const y = box.y + box.height / 2;
        await workbench.mouse.move(box.x + box.width / 2, y);
        await workbench.mouse.down();
        // Ends over the frame: the drag must not lose the pointer to it.
        await workbench.mouse.move(box.x + 200, y, { steps: 8 });
        await workbench.mouse.move(box.x - 300, y, { steps: 8 });
        await workbench.mouse.up();

        const after = (await pane.boundingBox()).width;
        expect(Math.abs(after - (before + 300))).toBeLessThan(6);
        await expect(workbench.locator('.cb-wb__preview iframe')).toHaveCSS('pointer-events', 'auto');

        await workbench.reload();
        await expect(workbench.locator('.cb-wb__row').first()).toBeVisible();
        expect(Math.abs((await pane.boundingBox()).width - after)).toBeLessThan(6);

        // Neither pane can be squeezed out.
        const edge = await handle.boundingBox();
        await workbench.mouse.move(edge.x + 4, y);
        await workbench.mouse.down();
        await workbench.mouse.move(10, y, { steps: 8 });
        await workbench.mouse.up();
        expect((await workbench.locator('.cb-wb__list').boundingBox()).width).toBeGreaterThanOrEqual(319);
    });

    /** Typing a translation saves it and repaints the row and the counters. */
    test('a typed translation is saved and the row turns translated', async ({ page }) => {
        await buildPageWithTranslatableText(page);
        const workbench = await openWorkbench(page);

        const row = workbench.locator('.cb-wb__row').first();
        await expect(row).toHaveAttribute('data-status', 'missing');

        await row.locator('[data-target="input"]').fill('A deliberately long English tab title');

        // Debounced save, then the server's own view of the row comes back.
        await expect(row).toHaveAttribute('data-status', 'translated', { timeout: 5000 });
        await expect(workbench.locator('[data-target="countTranslated"]')).toHaveText('1');
        await expect(workbench.locator('[data-target="percent"]')).toHaveText('100%');

        // It survives a reload, which is what proves it reached the draft.
        await workbench.reload();
        await expect(workbench.locator('.cb-wb__row').first()).toHaveAttribute('data-status', 'translated');
        await expect(workbench.locator('[data-target="input"]').first())
            .toHaveValue('A deliberately long English tab title');
    });
});
