import { test, expect } from '@playwright/test';

/**
 * E2E for bundle-contributed shell fragments (BuilderShellExtensionInterface).
 *
 * The sandbox registers one extension (DemoShellExtension) with no service
 * wiring, no Stimulus controller and no compiled asset: its fragment is a Twig
 * template rendered by the package *inside* the builder shell, carrying its
 * own inline module. That module changes the area through a package endpoint
 * and then dispatches `cb:area:changed` — the inbound public event — so the
 * builder reloads the preview and re-syncs Publish / Discard.
 *
 * This exercises the full chain: autoconfigured extension → fragment rendered
 * in the shell, with `area` in scope → fragment script reads the CSRF token
 * off the shell root → server write → `cb:area:changed` → builder catches up.
 */

async function createFreshPage(page) {
    const slug = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return url;
}

test.describe('builder shell — contributed fragments (cb_shell_fragments)', () => {
    test('the fragment is rendered inside the shell with the area in scope', async ({ page }) => {
        const url = await openBuilder(page);

        const fragment = page.locator('.cb-shell [data-sandbox-shell-fragment]');
        await expect(fragment).toHaveCount(1);
        // The fragment's own context variable reached its template …
        await expect(fragment.locator('[data-sandbox-shell-fragment-btn]')).toHaveText('Demo fragment: add a section');
        // … and so did the core-provided `area`.
        const areaId = await page.locator('.cb-shell').getAttribute('data-cb-builder-area-id-value');
        await expect(fragment).toHaveAttribute('data-area-id', areaId);
        expect(url).toMatch(/\/admin\/page\/\d+$/);
    });

    test('a fragment can change the area and ask the builder to catch up', async ({ page }) => {
        await openBuilder(page);
        const frame = page.frameLocator('.cb-shell__iframe');
        const publish = page.locator('.cb-shell__publish');
        const discard = page.locator('.cb-shell__discard');

        // A fresh page: nothing to publish, nothing to discard, no section.
        await expect(publish).toBeDisabled();
        await expect(discard).toBeHidden();
        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(0);

        await page.locator('[data-sandbox-shell-fragment-btn]').click();
        await expect(page.locator('[data-sandbox-shell-fragment-status]')).toHaveText('Added');

        // `cb:area:changed` reloaded the preview: the section the fragment
        // added through the package endpoint is on screen …
        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);
        // … and the draft controls reflect the write.
        await expect(publish).toBeEnabled();
        await expect(discard).toBeVisible();
    });

    test('the write is a draft: Discard reverts what the fragment did', async ({ page }) => {
        await openBuilder(page);
        const frame = page.frameLocator('.cb-shell__iframe');

        await page.locator('[data-sandbox-shell-fragment-btn]').click();
        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);

        page.once('dialog', (dialog) => dialog.accept());
        await page.locator('.cb-shell__discard').click();

        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(0);
        await expect(page.locator('.cb-shell__publish')).toBeDisabled();
    });
});
