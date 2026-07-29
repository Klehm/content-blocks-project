import { test, expect } from '@playwright/test';

/**
 * The Webpack Encore install path, end to end.
 *
 * These tests exist because of a bug a unit test could not have caught on its
 * own: both bundles used to register their assets/ directory under
 * `framework.asset_mapper.paths` unconditionally, which *enables* a component
 * neither package requires — so the container refused to build on any Encore
 * host. `AssetMapperPrependTest` now guards that one line; this suite guards
 * everything downstream of it, where the failure modes are not PHP at all:
 * stimulus-bridge resolving our controllers out of node_modules, the Stimulus
 * identifiers surviving the bundler, and Live Components round-tripping through
 * a build we never produce ourselves.
 *
 * Scope discipline: assert what differs under Encore. Builder *behaviour* is
 * covered once, in the main suite.
 */

async function createPage(page, title) {
    const response = await page.request.post('/page/create', {
        form: { title },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

async function openBuilder(page, title) {
    await page.goto(await createPage(page, title));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    return page.frameLocator('.cb-shell__iframe');
}

test.describe('Webpack Encore install path', () => {
    test('the app is served by an Encore build, with no AssetMapper anywhere', async ({ page }) => {
        await page.goto(await createPage(page, 'Encore — asset wiring'));

        // Encore's manifest-driven tags, not importmap().
        await expect(page.locator('script[src^="/build/"]').first()).toBeAttached();
        await expect(page.locator('link[rel="stylesheet"][href^="/build/"]').first()).toBeAttached();
        await expect(page.locator('script[type="importmap"]')).toHaveCount(0);

        // The front/preview assets take a different road entirely: they are
        // served by AssetController routes, so they must work with no bundler
        // involved. If these ever start 404-ing, the preview iframe goes
        // unstyled while the admin build still looks fine.
        for (const path of ['/_content-blocks/public/layout', '/_content-blocks/public/styling']) {
            const response = await page.request.get(path);
            expect(response.status(), `${path} should be served by the route`).toBe(200);
            expect(response.headers()['content-type']).toContain('text/css');
        }
    });

    test('stimulus-bridge registers the packaged controllers under their declared names', async ({ page }) => {
        await page.goto(await createPage(page, 'Encore — controller identity'));

        // The identifiers matter as much as the code: stimulus-bridge derives a
        // name from the package path (`klehm--content-blocks--cb-builder-launcher`)
        // unless the package's assets/package.json declares one. Ours does, and
        // the templates' data-controller attributes depend on it — so a
        // regression here silently disconnects every controller.
        const launcher = page.locator('[data-controller~="cb-builder-launcher"]');
        await expect(launcher).toBeAttached();

        // Attached markup proves nothing on its own; the controller has to have
        // connected. Its click handler opening the dialog is the proof.
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
    });

    test('Live Components round-trip through the Encore bundle and reach the public page', async ({ page }) => {
        const frame = await openBuilder(page, 'Encore — live round trip');

        // Each step here is a Live Component request whose response is morphed
        // into the DOM by JS that came out of webpack.
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);

        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const heading = `Encore heading ${Date.now()}`;
        const textField = sidebar.locator('.cb-block__edit-form input[type="text"]').first();
        await textField.fill(heading);
        await textField.blur();

        // Autosave is debounced, so wait for it to confirm rather than racing
        // publish against it — publishing an unsaved draft would flush an empty
        // block and the assertion below would fail for a reason that has
        // nothing to do with Encore.
        await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();

        // Publish, then read the public page — the render path is server-side,
        // so this confirms the whole edit actually landed in the database
        // rather than only in the preview's DOM.
        const publicUrl = page.url().replace('/admin/page/', '/page/');
        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled();

        const publicPage = await page.request.get(publicUrl);
        expect(publicPage.status()).toBe(200);
        expect(await publicPage.text()).toContain(heading);
    });

    test('third-party controller dependencies resolve through the bundler', async ({ page }) => {
        const frame = await openBuilder(page, 'Encore — third-party deps');

        // cb-collection-sort imports sortablejs and @symfony/ux-live-component
        // by bare specifier. Under AssetMapper those come from the importmap;
        // under Encore they must be real npm dependencies of the host. If either
        // failed to resolve, webpack would fail the build — but a *stale* build
        // would still serve, so assert the controller is wired in the DOM the
        // browser actually received.
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Liste$|^List$/ }).click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('[data-controller~="cb-collection-sort"]')).toBeAttached();
    });
});
