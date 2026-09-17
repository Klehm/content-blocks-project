import { test, expect } from '@playwright/test';

/**
 * A section's columns are edited from its sidebar — added, named, removed —
 * and the section can show them as tabs or as an accordion, in the builder
 * and on the page.
 */

async function createFreshPage(page) {
    const slug = `e2e-columns-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

async function publish(page) {
    const button = page.locator('.cb-shell__publish');
    await expect(button).toBeEnabled();
    await button.click();
    await expect(button).toBeDisabled({ timeout: 10000 });
    await page.waitForTimeout(400);
}

const liveColumns = '[data-cb-column-id]:not([data-cb-deleted="1"])';

/** An empty panel has no height, so visibility is read from `display`. */
function shown(locator) {
    return locator.evaluate((el) => getComputedStyle(el).display !== 'none');
}

test('columns are added, named and removed from the section sidebar', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    const sidebar = page.locator('.cb-shell__sidebar');
    const items = sidebar.locator('.cb-columns-editor__item');
    await expect(items).toHaveCount(1);
    // The last column cannot go: a block would have nowhere to live.
    await expect(items.first().locator('.cb-columns-editor__remove')).toBeDisabled();

    await sidebar.locator('.cb-columns-editor__add').click();
    await expect(items).toHaveCount(2);
    await expect.poll(() => frame.locator(liveColumns).count()).toBe(2);
    await expect(frame.locator('.cb-col--col-6')).toHaveCount(2);

    await sidebar.locator('.cb-columns-editor__add').click();
    await expect(items).toHaveCount(3);
    await expect.poll(() => frame.locator(liveColumns).count()).toBe(3);
    await expect(frame.locator('.cb-col--col-4')).toHaveCount(3);

    const save = page.waitForResponse((r) => /\/column\/\d+\/settings$/.test(r.url()));
    await items.nth(0).locator('.cb-columns-editor__label').fill('Specs');
    await items.nth(0).locator('.cb-columns-editor__label').press('Tab');
    expect((await save).status()).toBe(204);

    await items.nth(2).locator('.cb-columns-editor__remove').click();
    await expect(items).toHaveCount(2);
    await expect.poll(() => frame.locator(liveColumns).count()).toBe(2);
    await expect(frame.locator(`${liveColumns}.cb-col--col-6`)).toHaveCount(2);
    // The name survived the remount.
    await expect(items.nth(0).locator('.cb-columns-editor__label')).toHaveValue('Specs');

    // The delete is a draft step like any other.
    await page.keyboard.press('Control+z');
    await expect.poll(() => frame.locator(liveColumns).count()).toBe(3);
});

test('a section shows its columns as tabs, in the builder and on the page', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="tabs"]').click();
    const section = frame.locator('.cb-section--display-tabs');
    await expect(section).toHaveCount(1);
    const tabs = section.locator('.cb-tabs__tab');
    await expect(tabs).toHaveCount(3);
    await expect(tabs.nth(1)).toHaveText(/Onglet 2|Tab 2/);

    // One panel at a time, the first open.
    const panels = section.locator(':scope > .cb-row > [data-cb-column-id]');
    expect(await shown(panels.nth(0))).toBe(true);
    expect(await shown(panels.nth(1))).toBe(false);

    // Naming a column renames its tab in place.
    const sidebar = page.locator('.cb-shell__sidebar');
    const labels = sidebar.locator('.cb-columns-editor__label');
    await labels.nth(0).fill('Description');
    await labels.nth(0).press('Tab');
    await labels.nth(1).fill('Specs');
    await labels.nth(1).press('Tab');
    await expect(tabs.nth(0)).toHaveText('Description');
    await expect(tabs.nth(1)).toHaveText('Specs');

    // A block goes into the second tab, which stays open across the reload.
    await tabs.nth(1).click();
    await expect.poll(() => shown(panels.nth(1))).toBe(true);
    expect(await shown(panels.nth(0))).toBe(false);
    await panels.nth(1).locator('.cb-add-block-inline').click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(panels.nth(1).locator('[data-cb-block-id]')).toHaveCount(1);
    await page.locator('.cb-shell__iframe').evaluate((el) => el.contentWindow.location.reload());
    await expect(frame.locator('.cb-section--display-tabs .cb-tabs__tab')).toHaveCount(3);
    await expect(frame.locator('.cb-section--display-tabs > .cb-row > [data-cb-column-id]').nth(1).locator('[data-cb-block-id]')).toBeVisible();

    await publish(page);

    // The public page gets the same CSS-only tabs, no script needed.
    const viewer = await context.newPage();
    await viewer.goto(builderUrl.replace('/admin/page/', '/page/'));
    const publicSection = viewer.locator('.cb-section--display-tabs');
    const publicPanels = publicSection.locator(':scope > .cb-row > .cb-col');
    await expect(publicSection.locator('.cb-tabs__tab')).toHaveText(['Description', 'Specs', /Onglet 3|Tab 3/]);
    expect(await shown(publicPanels.nth(0))).toBe(true);
    expect(await shown(publicPanels.nth(1))).toBe(false);
    await publicSection.locator('.cb-tabs__tab', { hasText: 'Specs' }).click();
    await expect.poll(() => shown(publicPanels.nth(1))).toBe(true);
    await expect(publicPanels.nth(1).locator('.cb-block')).toHaveCount(1);
    expect(await shown(publicPanels.nth(0))).toBe(false);

    // Switching back to a grid from the sidebar shows every column again.
    // Forced: the open block's toolbar sits over the section's centre.
    await frame.locator('.cb-section-handle').first().click({ force: true });
    await page.locator('input[name$="[display]"][value="grid"]').check();
    await expect(frame.locator('.cb-section--display-tabs')).toHaveCount(0);
    await expect(frame.locator('.cb-tabs__nav')).toHaveCount(0);
    await expect.poll(() => shown(frame.locator(liveColumns).nth(0))).toBe(true);
    expect(await shown(frame.locator(liveColumns).nth(2))).toBe(true);

    await viewer.close();
});

/** Centered and vertically aligned: the tab bar keeps the content's width. */
test('the tab bar follows the section max width and vertical alignment', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="tabs"]').click();
    const sidebar = page.locator('.cb-shell__sidebar');
    const saved = () => page.waitForResponse((r) => /\/section\/\d+\/settings$/.test(r.url())
        && r.request().method() === 'POST');
    let save = saved();
    await sidebar.locator('input[name$="[widthMode]"][value="centered"]').check();
    await save;
    save = saved();
    await sidebar.locator('input[name$="[maxWidth]"]').fill('600');
    await sidebar.locator('input[name$="[maxWidth]"]').press('Tab');
    await save;
    save = saved();
    await sidebar.locator('input[name$="[stylingCustom]"]').check();
    await save;
    // An icon button: the radio itself is visually hidden.
    save = saved();
    await sidebar.locator('.cb-align-btn:has(input[name$="[styling][verticalAlign]"][value="center"])').click();
    await save;

    const section = frame.locator('.cb-section--display-tabs');
    await expect(section).toHaveClass(/cb-section--has-valign/, { timeout: 10000 });
    await expect(section).toHaveClass(/cb-section--centered/);
    await expect.poll(() => section.getAttribute('style')).toContain('--cb-row-max-w:600px');

    const box = (selector) => section.locator(selector)
        .evaluate((el) => { const r = el.getBoundingClientRect(); return [Math.round(r.left), Math.round(r.width)]; });
    await expect.poll(() => box(':scope > .cb-row')).toEqual([expect.any(Number), 600]);
    expect(await box(':scope > .cb-tabs__nav')).toEqual(await box(':scope > .cb-row'));
});

test('a section shows its columns as an accordion, in the builder and on the page', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="three_cols"]').click();
    await page.locator('input[name$="[display]"][value="accordion"]').check();
    const section = frame.locator('.cb-section--display-accordion');
    await expect(section).toHaveCount(1);
    const headers = section.locator('.cb-accordion__header');
    await expect(headers).toHaveCount(3);
    await expect(headers.nth(2)).toHaveText(/Panneau 3|Panel 3/);

    // The first panel opens; the others wait behind their header.
    const panels = section.locator(':scope > .cb-row > [data-cb-column-id]');
    await expect.poll(() => shown(panels.nth(0))).toBe(true);
    expect(await shown(panels.nth(1))).toBe(false);

    // Naming a column renames its header in place.
    const labels = page.locator('.cb-shell__sidebar .cb-columns-editor__label');
    await labels.nth(0).fill('Livraison');
    await labels.nth(0).press('Tab');
    await expect(headers.nth(0)).toHaveText('Livraison');

    // Panels open independently, and stay as left across a reload.
    await headers.nth(1).click();
    await expect.poll(() => shown(panels.nth(1))).toBe(true);
    expect(await shown(panels.nth(0))).toBe(true);
    await headers.nth(0).click();
    await expect.poll(() => shown(panels.nth(0))).toBe(false);
    await panels.nth(1).locator('.cb-add-block-inline').click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(panels.nth(1).locator('[data-cb-block-id]')).toHaveCount(1);
    await page.locator('.cb-shell__iframe').evaluate((el) => el.contentWindow.location.reload());
    await expect(headers).toHaveCount(3);
    await expect.poll(() => shown(panels.nth(1))).toBe(true);
    expect(await shown(panels.nth(0))).toBe(false);

    await publish(page);

    // The public page gets the same CSS-only accordion.
    const viewer = await context.newPage();
    await viewer.goto(builderUrl.replace('/admin/page/', '/page/'));
    const publicSection = viewer.locator('.cb-section--display-accordion');
    const publicPanels = publicSection.locator(':scope > .cb-row > .cb-col');
    await expect(publicSection.locator('.cb-accordion__header')).toHaveText(['Livraison', /Panneau 2|Panel 2/, /Panneau 3|Panel 3/]);
    expect(await shown(publicPanels.nth(0))).toBe(true);
    expect(await shown(publicPanels.nth(1))).toBe(false);
    await publicSection.locator('.cb-accordion__header').nth(1).click();
    await expect.poll(() => shown(publicPanels.nth(1))).toBe(true);
    await expect(publicPanels.nth(1).locator('.cb-block')).toHaveCount(1);
    await publicSection.locator('.cb-accordion__header').nth(0).click();
    await expect.poll(() => shown(publicPanels.nth(0))).toBe(false);
    await viewer.close();

    // Switching to tabs keeps the open panel as the open tab.
    await frame.locator('.cb-section-handle').first().click({ force: true });
    await page.locator('input[name$="[display]"][value="tabs"]').check();
    await expect(frame.locator('.cb-section--display-tabs .cb-tabs__tab')).toHaveCount(3);
    await expect(frame.locator('.cb-accordion__header')).toHaveCount(0);
    const tabPanels = frame.locator('.cb-section--display-tabs > .cb-row > [data-cb-column-id]');
    await expect.poll(() => shown(tabPanels.nth(1))).toBe(true);
    expect(await shown(tabPanels.nth(0))).toBe(false);
});

test('an accordion can open one panel at a time and start closed', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);
    const sidebar = page.locator('.cb-shell__sidebar');
    const saved = () => page.waitForResponse((r) => /\/section\/\d+\/settings$/.test(r.url())
        && r.request().method() === 'POST');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="three_cols"]').click();
    let save = saved();
    await sidebar.locator('input[name$="[display]"][value="accordion"]').check();
    await save;
    save = saved();
    await sidebar.locator('input[name$="[accordionSingle]"]').check();
    await save;

    const section = frame.locator('.cb-section--display-accordion');
    const panels = section.locator(':scope > .cb-row > [data-cb-column-id]');
    // One visible header per column: the open panel shows its closing twin.
    const header = (i) => section.locator('.cb-accordion__header:visible').nth(i);
    await expect(section.locator('.cb-accordion__none')).toHaveCount(1);
    await expect.poll(() => shown(panels.nth(0))).toBe(true);

    await header(1).click();
    await expect.poll(() => shown(panels.nth(1))).toBe(true);
    expect(await shown(panels.nth(0))).toBe(false);

    // Clicking the open panel's header closes it: nothing is left open.
    await header(1).click();
    await expect.poll(() => shown(panels.nth(1))).toBe(false);
    expect(await shown(panels.nth(0))).toBe(false);
    await page.locator('.cb-shell__iframe').evaluate((el) => el.contentWindow.location.reload());
    await expect(section.locator('.cb-accordion__header:visible')).toHaveCount(3);
    expect(await shown(panels.nth(0))).toBe(false);

    await frame.locator('.cb-section-handle').first().click({ force: true });
    save = saved();
    await sidebar.locator('input[name$="[accordionCollapsed]"]').check();
    await save;
    await publish(page);

    const viewer = await context.newPage();
    await viewer.goto(builderUrl.replace('/admin/page/', '/page/'));
    const publicSection = viewer.locator('.cb-section--display-accordion');
    const publicPanels = publicSection.locator(':scope > .cb-row > .cb-col');
    const publicHeader = (i) => publicSection.locator('.cb-accordion__header:visible').nth(i);
    for (let i = 0; i < 3; i++) expect(await shown(publicPanels.nth(i))).toBe(false);
    await publicHeader(0).click();
    await expect.poll(() => shown(publicPanels.nth(0))).toBe(true);
    await publicHeader(2).click();
    await expect.poll(() => shown(publicPanels.nth(2))).toBe(true);
    expect(await shown(publicPanels.nth(0))).toBe(false);
    await viewer.close();
});
