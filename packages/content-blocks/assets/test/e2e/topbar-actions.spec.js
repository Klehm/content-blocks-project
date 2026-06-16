import { test, expect } from '@playwright/test';

/**
 * E2E for host-provided builder topbar actions.
 *
 * The package renders extra topbar buttons from the `topbar_actions` form
 * option (here threaded through the sandbox's launcher include) and dispatches
 * ONE generic `cb:builder:action` event carrying `detail.key`. The sandbox
 * wires a host listener (cb-host-actions controller) that reacts to the
 * "save-as-model" key by cloning the area into a new model Page.
 *
 * This exercises the full chain: button render → generic event → host listener
 * → host endpoint round-trip → observable result in the host page.
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

/** Adds a 1-column section via the in-iframe add-section tray. */
async function addFullSection(page) {
    const frame = page.frameLocator('.cb-shell__iframe');
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

test.describe('builder topbar — host actions (cb:builder:action)', () => {
    test('the host action renders as a topbar button with its key class', async ({ page }) => {
        await openBuilder(page);

        const actionBtn = page.locator('.cb-shell__action--save-as-model');
        await expect(actionBtn).toBeVisible();
        await expect(actionBtn).toHaveText(/Save as model/);
        // The optional title falls through to both title + aria-label.
        await expect(actionBtn).toHaveAttribute('aria-label', 'Save this content as a reusable model');
    });

    test('clicking the button dispatches one generic event carrying the key + area id', async ({ page }) => {
        await openBuilder(page);

        // Capture the bubbled event off `document` (the dialog is re-parented
        // to <body>, so this is where host listeners actually receive it).
        const detail = await page.evaluate(() => new Promise((resolve) => {
            document.addEventListener('cb:builder:action', (e) => resolve(e.detail), { once: true });
            document.querySelector('.cb-shell__action--save-as-model').click();
        }));

        expect(detail.key).toBe('save-as-model');
        expect(typeof detail.areaId).toBe('number');
        expect(detail.areaId).toBeGreaterThan(0);
    });

    test('the host listener clones the area into a new "(model)" page', async ({ page }) => {
        const url = await openBuilder(page);
        // Seed a section so the model is cloned with real content.
        await addFullSection(page);

        await page.locator('.cb-shell__action--save-as-model').click();

        // The host controller appends a link to the freshly-created model once
        // the round-trip resolves. Waiting on the href implicitly waits for it.
        const link = page.locator('[data-cb-host-actions-target="status"] a[data-cb-model-link]');
        await expect(link).toHaveAttribute('href', /\/admin\/page\/\d+$/);

        const sourceId = url.match(/\/admin\/page\/(\d+)/)[1];
        const modelHref = await link.getAttribute('href');
        const modelId = modelHref.match(/\/admin\/page\/(\d+)/)[1];
        // A model is a brand-new page, not the source.
        expect(modelId).not.toBe(sourceId);

        // Round-trip proof: the new model page exists, its builder loads, and
        // it carries the "(model)" title.
        await page.goto(modelHref);
        await expect(page.locator('.cb-launcher__button')).toBeVisible();
        await expect(page.locator('h1')).toContainText('(model)');
    });
});
