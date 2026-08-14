import { test, expect } from '@playwright/test';

/**
 * Selecting a block whose content is a link.
 *
 * `<a>` is draggable by default: a press that travels a few pixels before
 * release starts a native link drag, and the browser then fires no click at
 * all. A block rendered as one big anchor (a card wrapped in `<a>`, a tile
 * filling its column) therefore became impossible to select — every attempt
 * landed on the link and was swallowed. The overlay cancels native drags, so
 * the press still ends as a click and the block opens its editor.
 */

async function createFreshPage(page) {
    const slug = `e2e-link-focus-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

/** Which block the sidebar currently has open (null = empty state). */
function sidebarBlockId(page) {
    return page.locator('aside[data-cb-builder-target="sidebar"]')
        .getAttribute('data-cb-sidebar-block-id');
}

test.describe('preview — selecting a link block', () => {
    test('a press that drifts a few pixels still selects the block', async ({ page }) => {
        const frame = await openBuilder(page);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);

        // The button block renders a real <a> straight from its defaults.
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Bouton$|^Button$/ }).click();
        await expect.poll(() => frame.locator('.cb-kit-btn').count()).toBe(1);
        const blockId = await frame.locator('[data-cb-block-id]').first()
            .getAttribute('data-cb-block-id');

        // Adding a block focuses it — deselect so the click is what selects.
        await frame.locator('.cb-add-section-tray__label').click();
        await expect.poll(() => sidebarBlockId(page)).toBe(null);

        // Press inside the anchor, drift past the native drag threshold
        // (~5px) without leaving it, release. boundingBox() inside a
        // frameLocator already reports main-page coordinates, so there is no
        // iframe offset to add; the press stays in the anchor's right half,
        // away from the section handle that overlaps its top-left corner.
        const link = await frame.locator('.cb-kit-btn').boundingBox();
        const y = link.y + link.height / 2;
        await page.mouse.move(link.x + link.width - 30, y);
        await page.mouse.down();
        await page.mouse.move(link.x + link.width - 8, y, { steps: 5 });
        await page.mouse.up();

        // The block is selected: sidebar mounted on it, outline pinned on it.
        await expect.poll(() => sidebarBlockId(page)).toBe(blockId);
        await expect(frame.locator(`[data-cb-block-id="${blockId}"].cb-overlay-outline`))
            .toHaveCount(1);
    });
});
