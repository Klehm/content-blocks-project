import { test, expect } from '@playwright/test';

/**
 * E2E for the section-template library: save a section (with its block) as a
 * named, reusable snapshot, then insert it into a different page's area, and
 * delete it from the library picker (management mode is enabled in the
 * sandbox via AllowAllSectionTemplateManager).
 */

async function createFreshPage(page) {
    const slug = `e2e-tpl-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    return page.frameLocator('.cb-shell__iframe');
}

async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

async function addFirstBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').last().click({ position: { x: 8, y: 3 } });
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

/**
 * Saves the first section of the area under edit into the library under
 * `name`. Handles the native name prompt.
 */
async function saveFirstSectionAsTemplate(page, frame, name) {
    // Pin the section's toolbar (click away from the inner block).
    await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
    page.once('dialog', async (dialog) => {
        expect(dialog.type()).toBe('prompt');
        await dialog.accept(name);
    });
    await frame
        .locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="save-template"]')
        .click();
    await page.waitForTimeout(300);
}

test.describe('section-template library — round trip', () => {
    test('save a section, then insert it into another page as a draft', async ({ page }) => {
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        // Source page: one section + one block, saved to the library.
        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        await saveFirstSectionAsTemplate(page, sourceFrame, templateName);
        await page.locator('.cb-shell__close').click();

        // Target page: empty. Insert the saved template from the empty sidebar.
        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(0);

        await page.locator('.cb-sidebar-empty__library').click();
        const picker = page.locator('.cb-template-picker');
        await expect(picker).toBeVisible();

        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        // Inserted as one draft section carrying its block; publish is enabled.
        await expect(picker).toBeHidden();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(1);
        await expect.poll(() => targetFrame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(page.locator('.cb-shell__publish')).toBeEnabled();
    });

    test('opening the library from the in-iframe add-section tray works too', async ({ page }) => {
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        await saveFirstSectionAsTemplate(page, sourceFrame, templateName);
        await page.locator('.cb-shell__close').click();

        // A NON-empty target so the tray shows below existing sections.
        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);
        await addFullSection(page, targetFrame);

        await targetFrame.locator('.cb-add-section-tray__library').click();
        const picker = page.locator('.cb-template-picker');
        await expect(picker).toBeVisible();
        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        await expect(picker).toBeHidden();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(2);
    });
});

test.describe('section-template library — management', () => {
    test('delete removes the template from the library', async ({ page }) => {
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);
        await saveFirstSectionAsTemplate(page, sourceFrame, templateName);

        // Open the library from the in-iframe tray (the sidebar currently shows
        // the section settings, not the empty state). Confirm the row is
        // present with a delete affordance (management is allowed in sandbox).
        await sourceFrame.locator('.cb-add-section-tray__library').click();
        const picker = page.locator('.cb-template-picker');
        await expect(picker).toBeVisible();
        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item').count()).toBe(1);

        const row = picker.locator('.cb-template-picker__item').first();
        await expect(row.locator('.cb-template-picker__delete')).toBeVisible();

        page.once('dialog', async (dialog) => {
            expect(dialog.type()).toBe('confirm');
            await dialog.accept();
        });
        await row.locator('.cb-template-picker__delete').click();

        // The list reloads without the deleted template.
        await expect.poll(() => picker.locator('.cb-template-picker__item').count()).toBe(0);
    });
});
