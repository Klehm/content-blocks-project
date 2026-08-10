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

        // No click to get here: with nothing selected the sidebar *is* the
        // library.
        const picker = page.locator('.cb-sidebar-library');
        await expect(picker).toBeVisible();

        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        // Inserted as one draft section carrying its block; publish is enabled.
        // The sidebar swaps to the new section's settings, which is what
        // replaces the library rather than any close gesture.
        await expect(picker).toBeHidden();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(1);
        await expect.poll(() => targetFrame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(page.locator('.cb-shell__publish')).toBeEnabled();
    });

    test('saving a section drops the sidebar back on the library, showing the new entry', async ({ page }) => {
        // Saving is triggered from the section's own toolbar, so the sidebar is
        // sitting on that section's settings when it completes — leaving the
        // editor with no sight of what they just created, nor of whether it
        // saved at all. The panel goes back to its default state instead.
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        // Clicking the section to reach its toolbar mounts its settings form.
        await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-sidebar__section-settings')).toBeVisible();

        await saveFirstSectionAsTemplate(page, frame, templateName);

        const picker = page.locator('.cb-sidebar-library');
        await expect(picker).toBeVisible();
        await expect(sidebar.locator('.cb-sidebar__section-settings')).toHaveCount(0);
        // The list re-fetched rather than repainting a stale cache, so the
        // entry that was just created is on screen.
        await expect(
            picker.locator('.cb-template-picker__item-name', { hasText: templateName }),
        ).toHaveCount(1);
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

        // From the preview the button no longer opens a modal — it clears the
        // selection, which is what puts the library back on screen.
        await targetFrame.locator('.cb-add-section-tray__library').click();
        const picker = page.locator('.cb-sidebar-library');
        await expect(picker).toBeVisible();
        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        await expect(picker).toBeHidden();
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(2);
    });

    test('a block carrying styling and a host extension field inserts without warnings', async ({ page }) => {
        // Regression guard. The instantiator flags stored keys that nothing in
        // the block's current shape can hold. Reading getDefaultData() alone
        // made it flag `styling` (added by BlockFormType, deliberately absent
        // from getDefaultData) and every field a host BlockFormExtension
        // contributes — here the sandbox's global `anchorId`.
        //
        // A warning keeps the library on screen with its status line; a clean
        // insert lets the sidebar move on to the new section's settings. So
        // "the settings form is up" *is* the assertion "nothing was flagged".
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const sourceUrl = await createFreshPage(page);
        const sourceFrame = await openBuilder(page, sourceUrl);
        await addFullSection(page, sourceFrame);
        await addFirstBlock(page, sourceFrame);

        // Editing the block persists the whole form — the block's own fields,
        // the `styling` sub-tree and the extension's `anchorId`.
        await page.locator('.cb-shell__iframe').evaluate((iframe) => {
            iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
        });
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
        await page.waitForTimeout(300); // let cb-autosave connect
        await sidebar.locator('.cb-block__tab', { hasText: 'SEO' }).click();
        await sidebar.locator('[name$="[anchorId]"]').fill('tpl-anchor');
        await sidebar.locator('[name$="[anchorId]"]').blur();
        await page.waitForTimeout(1200); // autosave debounce + round-trip

        await saveFirstSectionAsTemplate(page, sourceFrame, templateName);
        await page.locator('.cb-shell__close').click();

        const targetUrl = await createFreshPage(page);
        const targetFrame = await openBuilder(page, targetUrl);
        const picker = page.locator('.cb-sidebar-library');
        await expect(picker).toBeVisible();
        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        const targetSidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(targetSidebar.locator('input[name="section_settings[classes]"]')).toBeVisible();
        await expect(page.locator('.cb-template-picker__status')).toHaveCount(0);
        await expect.poll(() => targetFrame.locator('[data-cb-section-id]').count()).toBe(1);
        // The extension field survived the snapshot round-trip.
        await expect(targetFrame.locator('#tpl-anchor')).toHaveCount(1);
    });
});

/**
 * Stages a library entry directly, bypassing the save endpoint — the only way to
 * get a payload this build cannot fully use, since saving snapshots real,
 * registered blocks. Backed by the sandbox's debug-only fixture route.
 */
async function stageTemplate(page, {
    name,
    blocks,
    blockTypes,
    format = 'content-blocks/section-v1',
    contentVersion = null,
    // Most cases only need one full-width column, so `blocks` is the short
    // form; pass `columns` when the shape of the section is what's under test.
    columns = null,
    layout = 'full',
    settings = null,
}) {
    const response = await page.request.post('/test-fixtures/section-template', {
        data: {
            name,
            blockTypes,
            contentVersion,
            payload: {
                format,
                layout,
                settings,
                columns: columns ?? [{ preset: 'col-12', blocks }],
            },
        },
    });
    if (!response.ok()) throw new Error(`Fixture route failed: ${response.status()}`);
}

async function openLibraryOn(page, url) {
    const frame = await openBuilder(page, url);
    const picker = page.locator('.cb-sidebar-library');
    await expect(picker).toBeVisible();

    return { frame, picker };
}

test.describe('section-template library — partially usable templates', () => {
    test('a template with a gone block type stays insertable, minus that block', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title', 'countdown'],
            blocks: [
                { type: 'title', data: { text: 'Kept heading', level: 2 } },
                { type: 'countdown', data: { ends: 'never' } },
            ],
        });

        const { frame, picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        // Warned before the click, not blocked: one type is gone, one remains.
        const row = picker.locator('.cb-template-picker__item').first();
        const btn = row.locator('.cb-template-picker__item-btn');
        await expect(btn).toBeEnabled();
        await expect(row).toHaveClass(/cb-template-picker__item--partial/);
        await expect(btn).toHaveAttribute('title', /countdown/);

        await btn.click();

        // The section came in with only the usable block, and the picker stayed
        // open to report what was skipped.
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(frame.locator('[data-cb-block-type="title"]')).toHaveCount(1);
        await expect(picker).toBeVisible();
        await expect(page.locator('.cb-template-picker__status')).toContainText('countdown');
    });

    test('a template whose block types are all gone cannot be inserted', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['countdown'],
            blocks: [{ type: 'countdown', data: {} }],
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const row = picker.locator('.cb-template-picker__item').first();
        await expect(row.locator('.cb-template-picker__item-btn')).toBeDisabled();
        await expect(row).toHaveClass(/cb-template-picker__item--disabled/);
        await expect(row.locator('.cb-template-picker__item-btn')).toHaveAttribute('title', /countdown/);
    });

    test('a template from an older content generation cannot be inserted', async ({ page }) => {
        // The sandbox runs content_version 1 (the default), so a snapshot
        // stamped 0… is impossible (min is 1) — stage one at 2, i.e. content
        // written by a deploy that has since been rolled back. Either way the
        // generations differ and DenyOnMismatchUpgrader refuses.
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title'],
            blocks: [{ type: 'title', data: { text: 'From another generation', size: 'h2', tag: 'h2', color: '' } }],
            contentVersion: 2,
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const btn = picker.locator('.cb-template-picker__item-btn').first();
        await expect(btn).toBeDisabled();
        await expect(btn).toHaveAttribute('title', /schéma de contenu|content schema/i);
    });

    test('a template that predates versioning is still insertable', async ({ page }) => {
        // Every row written before versioning existed carries null. Refusing
        // those would make an upgrading host's whole library unusable.
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title'],
            blocks: [{ type: 'title', data: { text: 'Legacy but fine', size: 'h2', tag: 'h2', color: '' } }],
            contentVersion: null,
        });

        const { frame, picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await picker.locator('.cb-template-picker__item-btn').first().click();

        await expect(picker).toBeHidden();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });

    test('a template saved under an unreadable envelope cannot be inserted', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title'],
            blocks: [{ type: 'title', data: { text: 'Fine block', level: 2 } }],
            format: 'content-blocks/section-v99',
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        // Its block type is perfectly fine — it's the payload structure that
        // this build cannot read, and the tooltip must say so rather than
        // blaming a block type.
        const btn = picker.locator('.cb-template-picker__item-btn').first();
        await expect(btn).toBeDisabled();
        await expect(btn).not.toHaveAttribute('title', /title/);
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
        const picker = page.locator('.cb-sidebar-library');
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

test.describe('section-template library — thumbnails', () => {
    test('a card draws the saved section: real copy, real picture, real proportions', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            layout: 'two_cols',
            blockTypes: ['title', 'text', 'image', 'divider'],
            columns: [
                {
                    preset: 'col-8',
                    blocks: [
                        { type: 'title', data: { text: 'Poster heading', size: 'h2', tag: 'h2' } },
                        { type: 'text', data: { content: 'Body copy that lands on the tile.' } },
                    ],
                },
                {
                    preset: 'col-4',
                    blocks: [
                        { type: 'image', data: { src: '/test-fixtures/pixel', alt: '' } },
                        { type: 'divider', data: { style: 'solid' } },
                    ],
                },
            ],
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const poster = picker.locator('.cb-template-poster');
        await expect(poster).toBeVisible();

        // The presets survive the trip: an 8/4 split stays an 8/4 split.
        const cols = poster.locator('.cb-template-poster__col');
        await expect(cols).toHaveCount(2);
        await expect(cols.nth(0)).toHaveCSS('flex-grow', '8');
        await expect(cols.nth(1)).toHaveCSS('flex-grow', '4');

        // Stored copy reaches the tiles — this is what makes two saved heroes
        // tell each other apart in the library.
        await expect(poster.locator('.cb-template-poster__tile--heading')).toHaveText('Poster heading');
        await expect(poster.locator('.cb-template-poster__tile--text')).toContainText('Body copy');
        await expect(poster.locator('.cb-template-poster__tile--image img')).toHaveAttribute(
            'src',
            '/test-fixtures/pixel',
        );
        await expect(poster.locator('.cb-template-poster__tile--rule')).toHaveCount(1);

        // Decorative: the card's accessible name stays the template name, not
        // a recital of every tile.
        await expect(poster).toHaveAttribute('aria-hidden', 'true');
        await expect(picker.locator('.cb-template-picker__item-btn').first()).toHaveAccessibleName(name);
    });

    test('the thumbnail wears the section background, and repaints its copy for a dark one', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title'],
            blocks: [{ type: 'title', data: { text: 'Dark hero', size: 'h2', tag: 'h2' } }],
            settings: { styling: { backgroundColor: '#101828' } },
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const poster = picker.locator('.cb-template-poster');
        await expect(poster).toHaveCSS('background-color', 'rgb(16, 24, 40)');
        // The heading has to survive its own ground — dark-on-dark is what a
        // background you paint but don't account for looks like.
        await expect(poster.locator('.cb-template-poster__tile--heading'))
            .toHaveCSS('color', 'rgb(255, 255, 255)');
    });

    test('a picture whose upload was deleted degrades to a named tile', async ({ page }) => {
        // Templates reference uploads by path (see the SectionTemplate entity's
        // stated trade-off), so the file can disappear under a saved template.
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['image'],
            blocks: [{ type: 'image', data: { src: '/uploads/deleted-long-ago.png', alt: '' } }],
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const tile = picker.locator('.cb-template-poster__tile');
        await expect(tile).toHaveClass(/cb-template-poster__tile--generic/);
        await expect(tile.locator('img')).toHaveCount(0);
        await expect(tile).not.toHaveText('');
    });

    test('the thumbnail shows where a missing block type sits', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            blockTypes: ['title', 'countdown'],
            blocks: [
                { type: 'title', data: { text: 'Still here', size: 'h2', tag: 'h2' } },
                { type: 'countdown', data: {} },
            ],
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        const missing = picker.locator('.cb-template-poster__tile--missing');
        await expect(missing).toHaveCount(1);
        await expect(missing).toHaveText('countdown');
        // The words are still on the button; the poster adds the "where".
        await expect(picker.locator('.cb-template-picker__item-btn')).toHaveAttribute('title', /countdown/);
    });

    test('a template whose envelope cannot be read renders as a plain named card', async ({ page }) => {
        const name = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;
        await stageTemplate(page, {
            name,
            format: 'content-blocks/section-vX',
            blockTypes: ['title'],
            // An unreadable envelope means the columns cannot be trusted either;
            // the card must degrade to its name rather than frame an empty box.
            columns: [],
            blocks: [],
        });

        const { picker } = await openLibraryOn(page, await createFreshPage(page));
        await picker.locator('.cb-template-picker__search').fill(name);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);

        await expect(picker.locator('.cb-template-poster')).toHaveCount(0);
        await expect(picker.locator('.cb-template-picker__item-name')).toHaveText(name);
        await expect(picker.locator('.cb-template-picker__item-btn')).toBeDisabled();
    });

    test('a section saved from the builder gets a thumbnail without a reload', async ({ page }) => {
        const templateName = `Tpl ${Date.now()}-${Math.random().toString(36).slice(2, 6)}`;

        const frame = await openBuilder(page, await createFreshPage(page));
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        await saveFirstSectionAsTemplate(page, frame, templateName);

        // Saving ran from the section's toolbar, but the sidebar lands back on
        // the library on its own — no click needed. That is where the editor
        // can see what they just created.
        const picker = page.locator('.cb-sidebar-library');
        await expect(picker).toBeVisible();

        // Saving invalidates the cached list, so the library re-fetches — and
        // the poster comes from that same response, no extra round trip.
        await picker.locator('.cb-template-picker__search').fill(templateName);
        await expect.poll(() => picker.locator('.cb-template-picker__item-btn').count()).toBe(1);
        await expect(picker.locator('.cb-template-poster__tile')).not.toHaveCount(0);
    });
});
