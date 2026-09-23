import { test, expect } from '@playwright/test';

/**
 * "Insert content" copies another area's draft, so it takes edit rights on
 * that area: the picker must not offer an area the user cannot edit, and the
 * replace itself must be refused.
 *
 * The sandbox allows everything; its E2eAccessChecker refuses canEdit() on
 * the area named by the `cb_e2e_deny_area` cookie.
 */

async function createFreshPage(page) {
    const slug = `e2e-deny-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page, url) {
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
}

async function readAreaId(page) {
    return page.locator('.cb-shell').getAttribute('data-cb-builder-area-id-value');
}

async function denyArea(page, areaId) {
    const { origin } = new URL(page.url());
    await page.context().addCookies([
        { name: 'cb_e2e_deny_area', value: String(areaId), url: origin },
    ]);
}

/** The candidates the endpoint returns for an id search, from the shell. */
async function candidateIds(page, query) {
    return page.evaluate(async (q) => {
        const shell = document.querySelector('.cb-shell');
        const url = `${shell.dataset.cbApiBase}/area/${shell.dataset.cbAreaId}`
            + `/replace-candidates?q=${encodeURIComponent(q)}`;
        const body = await (await fetch(url)).json();

        return body.items.map((item) => String(item.id));
    }, String(query));
}

async function searchPicker(page, query) {
    await page.locator('.cb-shell__actions-toggle').click();
    await page.locator('.cb-shell__replace').click();
    const picker = page.locator('.cb-replace-picker');
    await picker.locator('.cb-replace-picker__search').fill(String(query));

    return picker;
}

test.afterEach(async ({ page }) => {
    await page.context().clearCookies({ name: 'cb_e2e_deny_area' });
});

test('an area the user cannot edit is neither offered nor copied', async ({ page }) => {
    const sourceUrl = await createFreshPage(page);
    await openBuilder(page, sourceUrl);
    const sourceId = await readAreaId(page);
    await page.locator('.cb-shell__close').click();

    await openBuilder(page, await createFreshPage(page));

    // Control: while editable, the source is found — the check is not vacuous.
    expect(await candidateIds(page, sourceId)).toEqual([sourceId]);

    await denyArea(page, sourceId);

    expect(await candidateIds(page, sourceId)).toEqual([]);

    // The picker says so once its search has come back, rather than
    // showing a row that would fail on click.
    const picker = await searchPicker(page, sourceId);
    await expect(picker.locator('[data-cb-builder-target="replacePickerStatus"]'))
        .toHaveText(/No results|Aucun résultat/);
    await expect(picker.locator('.cb-replace-picker__item-btn')).toHaveCount(0);

    // The action itself, forged past the picker: a 403, not a 500.
    const status = await page.evaluate(async (id) => {
        const shell = document.querySelector('.cb-shell');
        const response = await fetch(
            `${shell.dataset.cbApiBase}/area/${shell.dataset.cbAreaId}/replace-with/${id}`,
            { method: 'POST', headers: { 'X-CSRF-Token': shell.dataset.cbCsrfToken } },
        );

        return response.status;
    }, sourceId);
    expect(status).toBe(403);
});
