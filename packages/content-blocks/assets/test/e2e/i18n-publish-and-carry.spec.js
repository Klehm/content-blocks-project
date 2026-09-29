import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * Translations beyond the workbench's own rows: publishing one language, and
 * translations carried by a section template and by the clipboard.
 *
 * The first two tests pin the bug that started it: a translation typed on an
 * already-published page left the builder's Publish button greyed out.
 */

async function createFreshPage(page) {
    const slug = `e2e-i18n-pub-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

/** One section holding one Tabs block, whose default tab title is the text. */
async function buildPage(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(300);

    return { url, frame, pageId: url.match(/\/admin\/page\/(\d+)/)[1] };
}

async function publishFromBuilder(page) {
    const publish = page.locator('.cb-shell__publish');
    await expect(publish).toBeEnabled();
    await publish.click();
    await expect(publish).toBeDisabled({ timeout: 10000 });
}

async function reopenBuilder(page) {
    await page.bringToFront();
    await page.reload();
    await launchBuilder(page);

    return page.frameLocator('.cb-shell__iframe');
}

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

/** Types into the first row and waits for the server's view of it. */
async function translateFirstRow(workbench, text) {
    const row = workbench.locator('.cb-wb__row').first();
    await row.locator('[data-target="input"]').fill(text);
    await expect(row).toHaveAttribute('data-status', 'translated', { timeout: 5000 });
}

test('a translation of a published page is published from the workbench alone', async ({ page, context }) => {
    const { pageId } = await buildPage(page);
    await publishFromBuilder(page);

    const workbench = await openWorkbench(page);
    const publish = workbench.locator('[data-act="publishLocale"]');
    await expect(publish).toBeDisabled();
    // Off, and hovering it says why.
    await expect(publish).toHaveAttribute('title', /(Nothing to publish|Rien à publier)/);
    await expect(workbench.locator('.cb-wb__publish-hint')).toBeHidden();

    await translateFirstRow(workbench, 'Translated tab');
    await expect(publish).toBeEnabled();

    // The bug: the area was clean, so the builder had nothing to publish.
    await reopenBuilder(page);
    await expect(page.locator('.cb-shell__publish')).toBeEnabled();

    await workbench.bringToFront();
    await publish.click();
    await expect(workbench.locator('[data-target="toast"]')).toContainText(/EN/);
    await expect(publish).toBeDisabled();

    await reopenBuilder(page);
    await expect(page.locator('.cb-shell__publish')).toBeDisabled();

    const viewer = await context.newPage();
    await viewer.goto(`/en/page/${pageId}`);
    await expect(viewer.locator('body')).toContainText('Translated tab');
    await viewer.goto(`/page/${pageId}`);
    await expect(viewer.locator('body')).not.toContainText('Translated tab');
    await viewer.close();
});

// Publishing EN would put the editor's unpublished page draft live with it.
test('the workbench does not publish a language while the page has a draft', async ({ page }) => {
    await buildPage(page);

    const workbench = await openWorkbench(page);
    await translateFirstRow(workbench, 'Translated tab');

    const publish = workbench.locator('[data-act="publishLocale"]');
    await expect(publish).toBeDisabled();
    await expect(publish).toHaveAttribute('title', /(unpublished changes|modifications non publiées)/);
    await expect(workbench.locator('.cb-wb__publish-hint')).toBeVisible();

    // A click on it does nothing, although it can be hovered.
    await publish.click({ force: true });
    await expect(workbench.locator('.cb-wb__toast')).toBeHidden();
});

test('a section saved as a template keeps its translations', async ({ page }) => {
    const { frame } = await buildPage(page);
    const source = await openWorkbench(page);
    await translateFirstRow(source, 'Translated tab');
    await source.close();

    const name = `Tpl i18n ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
    await page.bringToFront();
    await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
    page.once('dialog', (dialog) => dialog.accept(name));
    await frame
        .locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="save-template"]')
        .click();

    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    const picker = page.locator('.cb-sidebar-library');
    await picker.locator('.cb-template-picker__search').fill(name);
    await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
    await picker.locator('.cb-template-picker__item-btn').first().click();
    const target = page.frameLocator('.cb-shell__iframe');
    await expect.poll(() => target.locator('[data-cb-block-id]').count()).toBe(1);

    const workbench = await openWorkbench(page);
    const row = workbench.locator('.cb-wb__row').first();
    await expect(row).toHaveAttribute('data-status', 'translated');
    await expect(row.locator('[data-target="input"]')).toHaveValue('Translated tab');
});

test('a pasted section keeps its translations', async ({ page }) => {
    const { frame } = await buildPage(page);
    const source = await openWorkbench(page);
    await translateFirstRow(source, 'Translated tab');
    await source.close();

    await page.bringToFront();
    const section = frame.locator('[data-cb-section-id]').first();
    const id = await section.getAttribute('data-cb-section-id');
    await section.click({ position: { x: 5, y: 5 } });
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-section-id', id);
    await page.keyboard.press('Control+c');
    await expect(page.locator('.cb-shell__undo')).toBeVisible();
    await page.keyboard.press('Control+v');
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);

    const workbench = await openWorkbench(page);
    const values = await workbench.locator('.cb-wb__row [data-target="input"]').evaluateAll(
        (inputs) => inputs.map((input) => input.value),
    );
    // Every row of the copy is translated as its original is.
    expect(values.filter((v) => v === 'Translated tab')).toHaveLength(2);
});
