import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The area as JSON, through the sandbox's own route (App\Controller\
 * HeadlessController): the published tree for a visitor, the draft for an
 * editor asking `?cb_preview=1`, by the same rule as the HTML page.
 */

async function createFreshPage(page) {
    const slug = `e2e-headless-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

test('the JSON render follows publish, and the preview shows the draft', async ({ page }) => {
    const builderUrl = await createFreshPage(page);
    const apiUrl = builderUrl.replace('/admin/page/', '/api/page/');

    await page.goto(builderUrl);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
    await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);
    await frame.locator('.cb-add-block-inline').nth(1).click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);

    // Nothing published yet: the public tree is empty, the draft is not.
    const before = await (await page.request.get(apiUrl)).json();
    expect(before).toMatchObject({ format: 1, mode: 'public', sections: [] });

    const draft = await (await page.request.get(`${apiUrl}?cb_preview=1`)).json();
    expect(draft.mode).toBe('preview');
    expect(draft.sections).toHaveLength(1);
    const columns = draft.sections[0].columns;
    expect(columns.map((c) => c.span)).toEqual([6, 6]);
    expect(columns[0].blocks).toEqual([]);
    expect(columns[1].blocks[0]).toMatchObject({ type: 'title', order: { tablet: null, mobile: null } });
    expect(typeof columns[1].blocks[0].data.text).toBe('string');

    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });

    const after = await (await page.request.get(apiUrl)).json();
    expect(after.mode).toBe('public');
    expect(after.sections[0].columns[1].blocks[0].type).toBe('title');

    // Empty maps are objects, as a typed client expects.
    expect(await (await page.request.get(apiUrl)).text()).toContain('"settings":{}');
});
