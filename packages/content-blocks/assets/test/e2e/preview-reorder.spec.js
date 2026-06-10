import { test, expect } from '@playwright/test';

/**
 * In-place reorder (no full iframe reload).
 *
 * Moving a section or block used to reload the whole preview iframe. Now the
 * parent persists the move (BlocksController/SectionsController) and posts a
 * `*:reorder:apply` / `cb:section:move:apply` message back to the overlay,
 * which relocates the LIVE DOM node — preserving its DOM + JS state instead of
 * rebuilding it from server HTML.
 *
 * Two angles:
 *  - Full stack via the section toolbar arrows: server → parent → overlay,
 *    asserting the move lands, the iframe is NOT reloaded (a window-level
 *    sentinel survives), and the new order persists across a real reload.
 *  - The overlay's apply handlers in isolation: we post the inbound message
 *    straight into the iframe (same-origin, so it passes the origin check) and
 *    assert the live node lands at the right spot — covering placeAmong's three
 *    branches (insert-before, append-before-sentinel, empty/prepend) which the
 *    midpoint-dependent drag can't pin down deterministically.
 */

async function createFreshPage(page) {
    const slug = `e2e-reorder-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addSection(page, frame, layout = 'full') {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator(`.cb-add-section-tray__btn[data-cb-add-section="${layout}"]`).click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200); // let the iframe load settle
}

/**
 * Adds a block to the nth column. Each target column must be empty so its
 * permanent "+ Block" pill is interactable without hovering (on a non-empty
 * column the pill stays pointer-events:none until the column is hovered).
 */
async function addBlockToColumn(page, frame, n = 0) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').nth(n).click();
    await frame.locator('.cb-overlay-popover button').first().click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

function sectionIds(frame) {
    return frame.locator('[data-cb-section-id]').evaluateAll(
        (els) => els.map((el) => el.getAttribute('data-cb-section-id')),
    );
}

function blockIds(frame) {
    return frame.locator('[data-cb-block-id]').evaluateAll(
        (els) => els.map((el) => el.getAttribute('data-cb-block-id')),
    );
}

/** Pins focus on a section by clicking its top strip, then fires a toolbar arrow. */
async function moveSectionViaToolbar(page, frame, nth, action) {
    await frame.locator('[data-cb-section-id]').nth(nth).click({ position: { x: 5, y: 5 } });
    await frame.locator(`.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="${action}"]`).click();
}

/**
 * Stamp a marker on the iframe's window. A `location.reload()` builds a fresh
 * window and wipes it; an in-place DOM move leaves it untouched. So "marker
 * still there after the op" == "no full reload happened".
 */
async function stampReloadSentinel(page) {
    await page.locator('.cb-shell__iframe').evaluate((el) => {
        el.contentWindow.__cbReorderSentinel = 'alive';
    });
}

function reloadSentinelSurvived(page) {
    return page.locator('.cb-shell__iframe').evaluate(
        (el) => el.contentWindow.__cbReorderSentinel === 'alive',
    );
}

/** Posts a typed message straight into the overlay (same-origin → trusted). */
async function postToOverlay(page, message) {
    await page.locator('.cb-shell__iframe').evaluate((el, msg) => {
        el.contentWindow.postMessage(msg, el.contentWindow.location.origin);
    }, message);
}

test.describe('preview reorder — section arrows (full stack)', () => {
    test('move-up relocates the section in place without reloading, and persists', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addSection(page, frame);

        const before = await sectionIds(frame);
        expect(before).toHaveLength(2);

        await stampReloadSentinel(page);
        await moveSectionViaToolbar(page, frame, 1, 'move-up');

        const expected = [before[1], before[0]].join(',');
        await expect.poll(async () => (await sectionIds(frame)).join(',')).toBe(expected);

        // The live node was moved in place — the iframe was never reloaded.
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The move was written to the draft: it survives a genuine reload.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        // Wait for the reopened iframe to finish loading before reading order,
        // otherwise evaluateAll races the in-flight navigation.
        await expect(reloaded.locator('[data-cb-section-id]').first()).toBeVisible();
        await expect.poll(async () => (await sectionIds(reloaded)).join(',')).toBe(expected);
    });

    test('move-down relocates the section in place without reloading', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addSection(page, frame);

        const before = await sectionIds(frame);
        await stampReloadSentinel(page);
        await moveSectionViaToolbar(page, frame, 0, 'move-down');

        await expect.poll(async () => (await sectionIds(frame)).join(',')).toBe([before[1], before[0]].join(','));
        expect(await reloadSentinelSurvived(page)).toBe(true);
    });

    test('move-up on the topmost section is a no-op (server reports no move)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addSection(page, frame);

        const before = await sectionIds(frame);
        await stampReloadSentinel(page);

        // The first section can't move up — the overlay must leave the DOM and
        // the iframe untouched (no reload, no reorder).
        await moveSectionViaToolbar(page, frame, 0, 'move-up');

        await page.waitForTimeout(400);
        expect((await sectionIds(frame)).join(',')).toBe(before.join(','));
        expect(await reloadSentinelSurvived(page)).toBe(true);
    });
});

test.describe('preview reorder — overlay apply handlers', () => {
    test('section reorder:apply slots the live node to the target index, tray stays last', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);
        await addSection(page, frame);
        await addSection(page, frame);

        const ids = await sectionIds(frame); // [s0, s1, s2]
        await stampReloadSentinel(page);

        // Move the last section to the front → [s2, s0, s1].
        await postToOverlay(page, {
            type: 'cb:section:reorder:apply',
            sectionId: parseInt(ids[2], 10),
            position: 0,
        });

        await expect.poll(async () => (await sectionIds(frame)).join(',')).toBe([ids[2], ids[0], ids[1]].join(','));
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The node landed among the sections, never after the add-section tray.
        const trayIsAfterSections = await frame.locator('.cb-content-area').evaluate((area) => {
            const kids = Array.from(area.children);
            const trayIdx = kids.findIndex((k) => k.classList.contains('cb-add-section-tray'));
            const lastSectionIdx = kids.reduce((acc, k, i) => (k.matches('[data-cb-section-id]') ? i : acc), -1);
            return trayIdx > lastSectionIdx;
        });
        expect(trayIsAfterSections).toBe(true);
    });

    test('block reorder:apply past the end appends before the +Block sentinel', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame, 'two_cols');
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(2);

        // One block per (initially empty) column.
        await addBlockToColumn(page, frame, 0);
        await addBlockToColumn(page, frame, 1);

        const colIds = await frame.locator('[data-cb-column-id]').evaluateAll(
            (els) => els.map((el) => parseInt(el.getAttribute('data-cb-column-id'), 10)),
        );
        const blockInCol0 = parseInt(
            await frame.locator(`[data-cb-column-id="${colIds[0]}"] [data-cb-block-id]`).getAttribute('data-cb-block-id'), 10,
        );
        const blockInCol1 = parseInt(
            await frame.locator(`[data-cb-column-id="${colIds[1]}"] [data-cb-block-id]`).getAttribute('data-cb-block-id'), 10,
        );
        await stampReloadSentinel(page);

        // Move col1's block into col0 past the end → lands after col0's block
        // (the "after last sibling" branch), never after the +Block button.
        await postToOverlay(page, {
            type: 'cb:block:reorder:apply',
            blockId: blockInCol1,
            toColumnId: colIds[0],
            position: 5,
        });

        await expect.poll(
            () => frame.locator(`[data-cb-column-id="${colIds[0]}"] [data-cb-block-id]`).count(),
        ).toBe(2);
        const orderInCol0 = await frame.locator(`[data-cb-column-id="${colIds[0]}"] [data-cb-block-id]`).evaluateAll(
            (els) => els.map((el) => parseInt(el.getAttribute('data-cb-block-id'), 10)),
        );
        expect(orderInCol0).toEqual([blockInCol0, blockInCol1]);
        expect(await reloadSentinelSurvived(page)).toBe(true);

        // The moved block landed ahead of the permanent "+ Block" button.
        const lastChildIsAddBtn = await frame.locator(`[data-cb-column-id="${colIds[0]}"]`).evaluate(
            (col) => col.lastElementChild?.classList.contains('cb-add-block-inline') === true,
        );
        expect(lastChildIsAddBtn).toBe(true);
    });

    test('block reorder:apply moves the live node into an empty column (prepend branch)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame, 'two_cols');
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(2);

        await addBlockToColumn(page, frame, 0);

        const colIds = await frame.locator('[data-cb-column-id]').evaluateAll(
            (els) => els.map((el) => parseInt(el.getAttribute('data-cb-column-id'), 10)),
        );
        const blockId = parseInt(await frame.locator('[data-cb-block-id]').first().getAttribute('data-cb-block-id'), 10);
        await stampReloadSentinel(page);

        // Move it into the empty second column → exercises the empty-list
        // prepend branch of placeAmong (lands before that column's +Block btn).
        await postToOverlay(page, {
            type: 'cb:block:reorder:apply',
            blockId,
            toColumnId: colIds[1],
            position: 0,
        });

        await expect.poll(
            () => frame.locator(`[data-cb-column-id="${colIds[1]}"] [data-cb-block-id]`).count(),
        ).toBe(1);
        await expect.poll(
            () => frame.locator(`[data-cb-column-id="${colIds[0]}"] [data-cb-block-id]`).count(),
        ).toBe(0);
        expect(await reloadSentinelSurvived(page)).toBe(true);
    });

    test('reorder:apply for a vanished node asks the parent to reload (desync guard)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addSection(page, frame);

        // No section carries id 999999 — the overlay can't place it, so it must
        // signal cb:reorder:desync, which the parent turns into a full reload.
        // We detect the reload by the window sentinel being wiped.
        await stampReloadSentinel(page);
        await postToOverlay(page, {
            type: 'cb:section:reorder:apply',
            sectionId: 999999,
            position: 0,
        });

        await expect.poll(() => reloadSentinelSurvived(page)).toBe(false);
    });
});
