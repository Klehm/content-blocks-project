import { test, expect } from '@playwright/test';

/**
 * Regression for the LiveCollection autosave bug: adding/removing a collection
 * item goes through a Live action that re-renders the sidebar without emitting
 * any field input/change event, so cb-autosave used to miss it and the change
 * was never persisted to the draft. The MutationObserver added to cb-autosave
 * now reconciles those structural edits into a save.
 *
 * The kit's Tabs block (type `tabs`) renders a LiveCollectionType with
 * allow_add / allow_delete and ships one default item, which makes a clean
 * fixture.
 */

async function createFreshPage(page) {
    const slug = `e2e-coll-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

async function addTabsBlock(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    // Popover buttons carry the translated block label as their text.
    await frame.locator('.cb-overlay-popover button', { hasText: /^Onglets$|^Tabs$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);
}

/** Opens the block editor by clicking the block element (click-to-edit). */
async function openBlockEditor(page, frame) {
    // Wait for the block to exist (the iframe may still be loading after a
    // reload), then dispatch the click in-iframe so the section's top-left
    // select handle (revealed on hover) can't intercept a coordinate click.
    await frame.locator('[data-cb-block-id]').first().waitFor();
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    // Let the autosave controller connect before callers start editing — an
    // edit fired before connect never persists.
    await page.waitForTimeout(300);
    return sidebar;
}

test.describe('builder shell — collection autosave', () => {
    test('deleting a collection item persists to the draft without editing a field', async ({ page }) => {
        const frame = await openBuilder(page);
        await addTabsBlock(page, frame);

        let sidebar = await openBlockEditor(page, frame);
        const items = sidebar.locator('.cb-form-collection__item');

        // The Tabs block ships one valid default item ("Tab 1") and its
        // collection has Assert\Count(min: 1). Each tab title is NotBlank, so
        // build a second VALID tab before deleting — otherwise the empty new
        // tab would fail validation and abort the save (correct behavior).
        await expect(items).toHaveCount(1);
        await sidebar.locator('.cb-form-btn--success').click(); // "+ Add tab"
        await expect(items).toHaveCount(2);
        const secondTitle = items.nth(1).locator('input[type="text"]').first();
        await secondTitle.fill('Second');
        await secondTitle.blur();
        await page.waitForTimeout(1200); // field-edit autosave persists 2 valid tabs

        // Delete the FIRST tab ("Tab 1"). This is a pure structural edit — a
        // Live action that re-renders the sidebar with NO field input/change
        // event — so only the MutationObserver path can save it. One valid tab
        // ("Second") remains, satisfying the min-1 constraint.
        await sidebar.locator('.cb-form-collection__delete').first().click();
        await expect(items).toHaveCount(1);
        await page.waitForTimeout(1200); // mutation-debounced autosave + round-trip

        // Persistence check: reopen the builder from scratch. Without the fix
        // the delete never reached the draft, so the block would reappear with
        // both tabs. With it, exactly one tab remains — and it's "Second", not
        // the default "Tab 1", proving the structural delete persisted.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        sidebar = await openBlockEditor(page, page.frameLocator('.cb-shell__iframe'));

        const reopened = sidebar.locator('.cb-form-collection__item');
        await expect(reopened).toHaveCount(1);
        await expect(reopened.first().locator('input[type="text"]').first()).toHaveValue('Second');
    });
});
