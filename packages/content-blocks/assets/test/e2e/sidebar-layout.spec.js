import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';
import { openTab, reveal } from './helpers/sidebar.js';

/**
 * The sidebars' layout: tabs from `cb_group`, collapsible panels from
 * `cb_panel` (one open per group), icon choices from `cb_icons`, a tooltip
 * from `cb_help_tooltip`. The Ornaments panel is the sandbox's own
 * App\ContentBlocks\Form\SectionOrnamentExtension: the host side of the API.
 */

async function createFreshPage(page) {
    const slug = `e2e-sidebar-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

/** Adds a two-column section; its settings open in the sidebar. */
async function addSection(page, frame) {
    const sidebar = page.locator('.cb-shell__sidebar');
    // The previous section's sidebar has tabs too: wait for the new one.
    const previous = await sidebar.getAttribute('data-cb-sidebar-section-id');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
    await expect(sidebar).not.toHaveAttribute('data-cb-sidebar-section-id', previous ?? '');
    await expect(sidebar.locator('.cb-sidebar__section-settings .cb-sidebar-tabs__tab').first()).toBeVisible();
    // Let cb-autosave connect before editing.
    await page.waitForTimeout(300);
    return sidebar;
}

function settingsSaved(page) {
    return page.waitForResponse((r) => /\/section\/\d+\/settings$/.test(r.url())
        && r.request().method() === 'POST');
}

/** A panel by its label, in either sandbox locale. */
const panel = (sidebar, ...labels) => sidebar.locator(
    labels.map((label) => `details.cb-panel[data-cb-panel="${label}"]`).join(', '),
);

test('the section settings open on Structure, one panel at a time', async ({ page }) => {
    const sidebar = await addSection(page, await openBuilder(page, await createFreshPage(page)));

    await expect(sidebar.locator('.cb-sidebar-tabs__tab')).toHaveText([/Structure/, /Style|Styling/]);
    const columns = panel(sidebar, 'Colonnes', 'Columns');
    const width = panel(sidebar, 'Largeur', 'Width');
    await expect(columns).toHaveAttribute('open', '');
    await expect(sidebar.locator('.cb-columns-editor__item')).toHaveCount(2);

    // The display sits with every field it gates, in one panel.
    const layout = panel(sidebar, 'Disposition', 'Layout');
    await expect(layout.locator('.cb-display-row')).toHaveCount(1);
    await expect(layout.locator('.cb-col-widths')).toHaveCount(1);
    await expect(layout.locator('input[name$="[reverseOnMobile]"]')).toHaveCount(1);

    // Opening another panel of the tab closes the first: the browser's own
    // exclusive <details>, nothing scripted.
    await width.locator(':scope > summary').click();
    await expect(width).toHaveAttribute('open', '');
    await expect(columns).not.toHaveAttribute('open');
});

test('the tab and panel last used come back on the next section', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));
    const sidebar = await addSection(page, frame);

    // The styling panels show once "Customize styling" is on.
    const save = settingsSaved(page);
    await (await reveal(sidebar.locator('input[name$="[stylingCustom]"]'))).check();
    await save;
    const background = panel(sidebar, 'Fond', 'Background');
    await background.locator(':scope > summary').click();
    await expect(background).toHaveAttribute('open', '');

    await addSection(page, frame);
    await expect(sidebar.locator('.cb-sidebar-tabs__tab--active')).toHaveText(/Style|Styling/);
    const again = settingsSaved(page);
    await sidebar.locator('input[name$="[stylingCustom]"]').check();
    await again;
    await expect(panel(sidebar, 'Fond', 'Background')).toHaveAttribute('open', '');
    await expect(panel(sidebar, 'Espacements', 'Spacing')).not.toHaveAttribute('open');
});

test('a closed panel sums up what it holds', async ({ page }) => {
    const sidebar = await addSection(page, await openBuilder(page, await createFreshPage(page)));

    let save = settingsSaved(page);
    await (await reveal(sidebar.locator('input[name$="[stylingCustom]"]'))).check();
    await save;
    const spacing = panel(sidebar, 'Espacements', 'Spacing');
    const top = await reveal(sidebar.locator('input[name$="[styling][padding][desktop][top]"]'));
    save = settingsSaved(page);
    await top.fill('40');
    await top.press('Tab');
    await save;

    await spacing.locator(':scope > summary').click();
    await expect(spacing).not.toHaveAttribute('open');
    const summary = spacing.locator('.cb-panel__summary');
    // The four sides are linked by default: one value, four times.
    await expect(summary).toHaveAttribute('data-cb-summary', /^40 · 40 · 40 · 40/);
    await expect(summary).toBeVisible();
    await expect(spacing).toHaveAttribute('data-cb-filled', '');
});

test('icon choices save like the radios and checkboxes they are', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));
    const sidebar = await addSection(page, frame);

    let save = settingsSaved(page);
    await (await reveal(sidebar.locator('input[name$="[stylingCustom]"]'))).check();
    await save;

    // A core field: the display, a grid of labelled icons.
    save = settingsSaved(page);
    await (await reveal(sidebar.locator('input[name$="[display]"][value="accordion"]'))).check();
    await save;
    await expect(frame.locator('.cb-section--display-accordion')).toHaveCount(1);

    // Host fields: a 2×2 grid of radios and a row of checkboxes.
    const corner = await reveal(sidebar.locator('input[name$="[styling][ornamentCorner]"][value="tr"]'));
    await expect(panel(sidebar, 'Ornements', 'Ornaments')).toHaveAttribute('open', '');
    await expect(corner.locator('xpath=ancestor::label')).toHaveAttribute('title', /En haut à droite|Top right/);
    save = settingsSaved(page);
    await corner.check();
    await save;
    save = settingsSaved(page);
    await sidebar.locator('input[name$="[styling][ornamentSides][]"][value="top"]').check();
    await save;
    save = settingsSaved(page);
    await sidebar.locator('input[name$="[styling][ornamentSides][]"][value="bottom"]').check();
    await save;

    // Reopened from the server, the form holds what was stored.
    await frame.locator('.cb-section-handle').first().click({ force: true });
    await expect(sidebar.locator('input[name$="[styling][ornamentCorner]"][value="tr"]')).toBeChecked();
    await expect(sidebar.locator('input[name$="[styling][ornamentSides][]"]:checked')).toHaveCount(2);
    await expect(sidebar.locator('input[name$="[display]"][value="accordion"]')).toBeChecked();
});

test('the long help of a field waits behind its (i)', async ({ page }) => {
    const sidebar = await addSection(page, await openBuilder(page, await createFreshPage(page)));
    await openTab(sidebar, /Style|Styling/);

    const help = sidebar.locator('.cb-form-help:has(.cb-help-tip)').first();
    const bubble = help.locator('.cb-help-tip__bubble');
    await expect(help).toBeVisible();
    await expect(bubble).toBeHidden();

    await help.locator('.cb-help-tip__button').hover();
    await expect(bubble).toBeVisible();
    await expect(bubble).toHaveText(/prédéfini|preset/);

    // Keyboard users reach it too: the button takes focus.
    await page.mouse.move(0, 0);
    await expect(bubble).toBeHidden();
    await help.locator('.cb-help-tip__button').focus();
    await expect(bubble).toBeVisible();
});
