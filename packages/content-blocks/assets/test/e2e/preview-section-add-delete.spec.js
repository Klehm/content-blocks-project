import { test, expect } from '@playwright/test';

/**
 * Adding and deleting a section patch the preview in place: the new, empty
 * section is inserted ahead of the add-section tray, and a delete flags the
 * section hidden exactly as a reload would render it.
 */

async function createFreshPage(page) {
    const slug = `e2e-sec-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

async function stampReloadSentinel(page) {
    await page.locator('.cb-shell__iframe').evaluate((el) => {
        el.contentWindow.__cbReloadSentinel = 'alive';
    });
}

function reloadSentinelSurvived(page) {
    return page.locator('.cb-shell__iframe').evaluate(
        (el) => el.contentWindow.__cbReloadSentinel === 'alive',
    );
}

const liveSections = '[data-cb-section-id]:not([data-cb-deleted="1"])';

test.describe('preview section add/delete — in place', () => {
    test('adds sections without reloading, focused, and persists', async ({ page }) => {
        const frame = await openBuilder(page);
        const area = frame.locator('.cb-content-area');
        await expect(area).toHaveClass(/cb-content-area--empty/);
        await stampReloadSentinel(page);

        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await expect.poll(() => frame.locator(liveSections).count()).toBe(1);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
        await expect.poll(() => frame.locator(liveSections).count()).toBe(2);

        expect(await reloadSentinelSurvived(page)).toBe(true);
        await expect(area).not.toHaveClass(/cb-content-area--empty/);

        // Order is creation order, both ahead of the tray, and the last one
        // is the one pinned and open in the sidebar.
        const second = frame.locator(liveSections).nth(1);
        await expect(second).toHaveClass(/cb-section--two_cols/);
        await expect(second.locator('[data-cb-column-id]')).toHaveCount(2);
        const trayIsLast = await area.evaluate(
            (el) => el.querySelector(':scope > .cb-add-section-tray')
                .previousElementSibling?.matches('[data-cb-section-id]') === true,
        );
        expect(trayIsLast).toBe(true);
        await expect(second).toHaveClass(/cb-overlay-outline/);
        const secondId = await second.getAttribute('data-cb-section-id');
        await expect(page.locator('.cb-shell__sidebar'))
            .toHaveAttribute('data-cb-sidebar-section-id', secondId);

        // An inserted section is fully wired: it takes a block in place too.
        await second.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
        await expect.poll(() => second.locator('[data-cb-block-id]').count()).toBe(1);

        await page.reload();
        await page.locator('.cb-launcher__button').click();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator(liveSections).first()).toBeVisible();
        await expect.poll(() => reloaded.locator(liveSections).count()).toBe(2);
    });

    test('deletes a section without reloading, back to the empty state', async ({ page }) => {
        const frame = await openBuilder(page);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
        await expect.poll(() => frame.locator(liveSections).count()).toBe(1);
        const section = frame.locator(liveSections).first();
        await section.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
        await expect(page.locator('.cb-shell__sidebar'))
            .toHaveAttribute('data-cb-sidebar-block-id', /\d+/);
        const sectionId = await section.getAttribute('data-cb-section-id');
        await stampReloadSentinel(page);

        // Posted from the preview, as the section toolbar does, so the block
        // inside stays open in the sidebar while its section goes.
        await page.locator('.cb-shell__iframe').evaluate((iframe, id) => {
            const win = iframe.contentWindow;
            win.parent.postMessage(
                { type: 'cb:section:delete-requested', sectionId: Number(id) },
                win.location.origin,
            );
        }, sectionId);

        const flagged = frame.locator(`[data-cb-section-id="${sectionId}"]`);
        await expect(flagged).toHaveAttribute('data-cb-deleted', '1');
        await expect(flagged).toBeHidden();
        await expect(flagged.locator('[data-cb-block-id]')).toHaveAttribute('data-cb-deleted', '1');
        expect(await reloadSentinelSurvived(page)).toBe(true);

        await expect(frame.locator('.cb-content-area')).toHaveClass(/cb-content-area--empty/);
        await expect(frame.locator('.cb-overlay-toolbar')).not.toHaveClass(/is-visible/);
        // The block that was open lived in the section: its form is gone.
        await expect(page.locator('.cb-shell__sidebar'))
            .not.toHaveAttribute('data-cb-sidebar-block-id', /\d+/);

        // A reload renders the very same state.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect(reloaded.locator('.cb-add-section-tray')).toBeVisible();
        await expect(reloaded.locator('.cb-content-area')).toHaveClass(/cb-content-area--empty/);
        await expect(reloaded.locator(`[data-cb-section-id="${sectionId}"]`))
            .toHaveAttribute('data-cb-deleted', '1');
    });
});
