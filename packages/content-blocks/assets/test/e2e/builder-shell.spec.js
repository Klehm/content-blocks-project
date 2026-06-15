import { test, expect } from '@playwright/test';

/**
 * E2E for the builder shell + structural ops.
 *
 * Each test creates its own fresh Page (via the sandbox's /page/create
 * endpoint) so structural mutations don't leak between tests. Tests that
 * need pre-existing content (a section, a block) seed it through the same
 * UI flow they exercise — that way the seed step also doubles as
 * regression coverage for the action it triggers.
 */

async function createFreshPage(page) {
    const slug = `e2e-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilder(page) {
    const url = await createFreshPage(page);
    await page.goto(url);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

/** Adds a 1-column section by clicking the in-iframe add-section tray. */
async function addFullSection(page, frame) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    // Allow the iframe load to fully settle so subsequent locators don't hit
    // a destroyed execution context.
    await page.waitForTimeout(200);
}

/**
 * Adds the first available block type to the first column via the permanent
 * inline `+ Block` affordance rendered at the bottom of every column.
 */
async function addFirstBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    // Target the LAST pill (the just-added, empty section) and click its upper
    // half: the pill floats half-below its column (translateY 50%), so an
    // earlier section's pill is both pointer-events:none (non-empty column) and
    // overlapped by the next section. The last section's column is empty (pill
    // always interactive) with nothing below to intercept the pointer.
    await frame.locator('.cb-add-block-inline').last().click({ position: { x: 8, y: 3 } });
    await clickPopoverTile(page, frame);
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

/**
 * Dispatches a click on the nth element matching `selector` INSIDE the preview
 * iframe. Routing the click through the overlay's own document handler (rather
 * than a coordinate click) sidesteps two obstacles that otherwise intercept the
 * pointer: the section's top-left "select" handle (z-index 5, revealed on
 * hover) and the sidebar that floats over the iframe once it's open. Same
 * technique the outside-click test already relies on.
 */
async function clickInPreview(page, selector, n = 0) {
    await page.locator('.cb-shell__iframe').evaluate((iframe, [sel, idx]) => {
        const els = iframe.contentDocument.querySelectorAll(sel);
        els[idx]?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, [selector, n]);
}

/**
 * Dispatches a keydown on the preview iframe's document, driving the overlay's
 * keyboard-shortcut handler directly. A real `page.keyboard.press` would land
 * in whatever currently holds focus (often a sidebar form field that the editor
 * auto-focuses), never reaching the in-iframe handler.
 */
async function pressInPreview(page, key) {
    await page.locator('.cb-shell__iframe').evaluate((iframe, k) => {
        iframe.contentDocument.dispatchEvent(
            new KeyboardEvent('keydown', { key: k, bubbles: true, cancelable: true }),
        );
    }, key);
}

/**
 * Opens a block's editor in the sidebar. The overlay toolbar no longer carries
 * an "edit" button — clicking the block itself opens the editor.
 */
async function openBlockEditor(page, frame, n = 0) {
    await clickInPreview(page, '[data-cb-block-id]', n);
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
}

/**
 * Opens a section's settings in the sidebar. As with blocks, the toolbar has no
 * "settings" button — clicking the section opens it.
 */
async function openSectionSettings(page, frame, n = 0) {
    await clickInPreview(page, '[data-cb-section-id]', n);
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('input[name="section_settings[classes]"]')).toBeVisible();
    // Give the form's autosave controller a beat to connect before callers
    // start editing — a fill fired before connect never triggers a save.
    await page.waitForTimeout(300);
}

/**
 * Picks a block type from the (already-open) overlay popover by dispatching the
 * tile's click in-iframe. On mobile the sidebar bottom-sheet floats over the
 * lower preview and intercepts a real pointer click on the popover; dispatching
 * the tile's own click event drives the overlay handler directly. `title`
 * matches the tile's title attribute; omit it to pick the first tile.
 */
async function clickPopoverTile(page, frame, { title } = {}) {
    await frame.locator('.cb-overlay-popover button').first().waitFor();
    await page.locator('.cb-shell__iframe').evaluate((iframe, t) => {
        const tiles = Array.from(iframe.contentDocument.querySelectorAll('.cb-overlay-popover button'));
        const tile = t ? tiles.find((b) => new RegExp(t).test(b.getAttribute('title') || '')) : tiles[0];
        tile?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    }, title ?? null);
}

/**
 * Adds a Title block (a deterministic single <input type="text"> bound to
 * data.text) to the just-added section — handy for autosave assertions that
 * need a known editable text field.
 */
async function addTitleBlock(page, frame) {
    const before = await frame.locator('[data-cb-block-id]').count();
    await frame.locator('.cb-add-block-inline').last().click({ position: { x: 8, y: 3 } });
    await clickPopoverTile(page, frame, { title: '^(Titre|Title)$' });
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(before + 1);
    await page.waitForTimeout(200);
}

function attachConsoleSink(page) {
    const lines = [];
    page.on('console', (msg) => {
        if (msg.type() === 'log') {
            const text = msg.text();
            if (text.startsWith('[cb-builder]')) lines.push(text);
        }
    });
    return lines;
}

test.describe('builder shell — basics', () => {
    test('launcher opens the dialog and renders shell skeleton', async ({ page }) => {
        const url = await createFreshPage(page);
        await page.goto(url);

        const launcher = page.locator('.cb-launcher__button');
        await expect(launcher).toBeVisible();

        const dialog = page.locator('.cb-builder-dialog');
        await expect(dialog).not.toHaveAttribute('open');

        await launcher.click();

        await expect(dialog).toHaveAttribute('open', '');
        await expect(page.locator('.cb-shell__topbar')).toBeVisible();
        await expect(page.locator('.cb-shell__iframe')).toBeVisible();
    });

    test('iframe loads the preview URL with cb_preview=1 and the overlay comes up', async ({ page }) => {
        const url = await createFreshPage(page);
        await page.goto(url);
        await page.locator('.cb-launcher__button').click();

        const iframe = page.locator('.cb-shell__iframe');
        await expect(iframe).toHaveAttribute('src', /\/page\/\d+\?cb_preview=1$/);

        // The preview rendered and the overlay is live (the in-iframe add-section
        // tray is injected by preview-overlay once it signals cb:ready).
        await expect(
            page.frameLocator('.cb-shell__iframe').locator('.cb-add-section-tray__btn').first(),
        ).toBeVisible();
    });
});

test.describe('builder shell — sections', () => {
    test('in-iframe add-section tray creates a new section', async ({ page }) => {
        const frame = await openBuilder(page);
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(0);

        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        // two_cols → 2 columns inside the new section.
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(2);
    });

    test('section move-up overlay swaps order with previous section', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFullSection(page, frame);

        const readOrder = async () => {
            const count = await frame.locator('[data-cb-section-id]').count();
            const ids = [];
            for (let i = 0; i < count; i++) {
                ids.push(await frame.locator('[data-cb-section-id]').nth(i).getAttribute('data-cb-section-id'));
            }
            return ids;
        };

        const before = await readOrder();
        expect(before).toHaveLength(2);

        // Hover the top strip of the section (above the column grid) so the
        // section toolbar wins over the inner column toolbar.
        await clickInPreview(page, '[data-cb-section-id]', 1);
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="move-up"]').click();

        await expect.poll(async () => (await readOrder()).join(',')).toBe([before[1], before[0]].join(','));
    });

    test('section delete overlay marks the section deleted', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await clickInPreview(page, '[data-cb-section-id]');
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    });

    test('section settings overlay (⚙) opens the sidebar with the settings form', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await openSectionSettings(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        // Built-in fields are present.
        await expect(sidebar.locator('input[name="section_settings[classes]"]')).toBeVisible();
        await expect(sidebar.locator('input[name="section_settings[widthMode]"][value="full"]')).toBeAttached();
        await expect(sidebar.locator('input[name="section_settings[widthMode]"][value="centered"]')).toBeAttached();
        // maxWidth only un-hides once the section is "centered"; in the default
        // "full" mode it's present but hidden.
        await expect(sidebar.locator('input[name="section_settings[maxWidth]"]')).toBeAttached();
        // Sandbox StylingPaletteExtension overrides the Styling sub-form's
        // backgroundColor with a brand-palette <select>.
        await expect(sidebar.locator('select[name="section_settings[styling][backgroundColor]"]')).toBeAttached();
    });

    test('section settings save applies custom classes + width and the host backgroundColor extension', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Open settings.
        await openSectionSettings(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await sidebar.locator('input[name="section_settings[classes]"]').fill('e2e-decorated');
        await sidebar.locator('input[name="section_settings[widthMode]"][value="centered"]').check();
        await sidebar.locator('input[name="section_settings[maxWidth]"]').fill('900');
        // Sandbox StylingPaletteExtension field — a brand-palette <select>.
        await sidebar.locator('select[name="section_settings[styling][backgroundColor]"]').selectOption('#0a84ff');
        // No manual Save button anymore — autosave persists each field change
        // (fill/check/select fire input/change, which the cb-autosave controller
        // debounces into a save). The section hot-reloads with the new draft.
        // The sidebar stays on screen (permanent-sidebar model).
        const section = frame.locator('[data-cb-section-id]').first();
        await expect.poll(async () => section.getAttribute('class')).toContain('e2e-decorated');
        await expect.poll(async () => section.getAttribute('class')).toContain('cb-section--centered');
        // The decorators emit CSS custom properties (responsive-friendly), not
        // raw properties: maxWidth → --cb-row-max-w, backgroundColor → --cb-s-bg.
        await expect.poll(async () => section.getAttribute('style')).toContain('--cb-row-max-w:900px');
        await expect.poll(async () => section.getAttribute('style')).toContain('--cb-s-bg:#0a84ff');
    });

    test('section settings saved with the framework default value do not pollute the rendered markup', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await openSectionSettings(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        // The brand-palette backgroundColor <select> opens unset (placeholder) —
        // its default is "no color".
        await expect(sidebar.locator('select[name="section_settings[styling][backgroundColor]"]')).toHaveValue('');

        // Autosave only fires on a change, so force a save via an unrelated
        // field (classes) while leaving backgroundColor unset — the point is
        // that the default (no color) must not leak into the markup.
        await sidebar.locator('input[name="section_settings[classes]"]').fill('e2e-default-probe');

        const section = frame.locator('[data-cb-section-id]').first();
        // Wait until the save round-trip applied the class to the section,
        // then assert no background var was emitted.
        await expect.poll(async () => section.getAttribute('class')).toContain('e2e-default-probe');
        const style = await section.getAttribute('style');
        expect(style ?? '').not.toContain('--cb-s-bg');
    });
});

test.describe('builder shell — blocks', () => {
    test('inline + Block button + popover adds a block of the chosen type', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);

        await frame.locator('.cb-add-block-inline').first().click();

        const popover = frame.locator('.cb-overlay-popover');
        await expect(popover).toBeVisible();

        // The list reflects the registered block types (5 in the kit:
        // text, title, image, tabs, richtext).
        const items = popover.locator('button');
        await expect(items).toHaveCount(5);

        await items.first().click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });

    test('clicking a block mounts the BlockComponent in the sidebar', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await openBlockEditor(page, frame);

        // Permanent-sidebar model: the sidebar is always on screen (never
        // `hidden`) and now carries the mounted block edit form. There is no
        // manual Save button — autosave persists changes.
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    });

    test('clicking outside the form (in the iframe preview) reverts the sidebar to its empty state', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await openBlockEditor(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // Click empty preview space. The permanent sidebar stays on screen but
        // drops the mounted form and reverts to its empty placeholder.
        await clickInPreview(page, 'body');

        await expect(sidebar.locator('.cb-block__edit-form')).toHaveCount(0);
    });

    test('autosave persists a block edit and keeps the form mounted', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        // addTitleBlock auto-opens the editor (and waits long enough for the
        // autosave controller to connect), so we don't re-open it.
        await addTitleBlock(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // No manual Save — type into the title field and let autosave persist.
        // The "Saved" pill flashing is the observable signal a save happened.
        await sidebar.locator('.cb-block__edit-form input[type="text"]').first().fill('e2e-autosave');
        await expect(page.locator('[data-cb-builder-target="savedFlash"]')).toBeVisible();

        // The form stays mounted after the autosave (permanent-sidebar model).
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    });

    test('autosave persists the typed value across a reopen (no value loss with focus still in the field)', async ({ page }) => {
        // Regression for the "last keystrokes lost" class of bug: with Live's
        // `on(change)` binding, a save fired while the input still holds focus
        // must flush the pending value first. We type, let autosave persist
        // while focus stays in the field, then reopen the form and read the
        // value back — verifying the round-trip, not just the iframe re-render.
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Title block: its form has a single <input type="text"> bound to
        // data.text and the view template echoes that value, making the
        // assertion unambiguous.
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        await page.waitForTimeout(200);

        await openBlockEditor(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const typed = `e2e-typed-${Date.now()}`;
        const field = sidebar.locator('.cb-block__edit-form input[type="text"]').first();
        await field.click();
        await field.fill('');
        // pressSequentially mimics real keystrokes (input events only, no
        // implicit change) — leaves focus on the input at the end.
        await field.pressSequentially(typed, { delay: 5 });
        await expect(field).toBeFocused();
        // Let the debounced autosave fire and the save round-trip complete.
        await page.waitForTimeout(1500);

        // Revert the sidebar to empty, then reopen: if autosave persisted, the
        // input should now show the typed value.
        await clickInPreview(page, 'body');
        await expect(sidebar.locator('.cb-block__edit-form')).toHaveCount(0);
        await openBlockEditor(page, frame);

        await expect(sidebar.locator('.cb-block__edit-form input[type="text"]').first()).toHaveValue(typed);
    });

    test('mobile viewport: sidebar slides from the bottom and the iframe area leaves room for it', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });

        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await openBlockEditor(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // On mobile the sidebar is full-width (not the 380px desktop fixed
        // size) and its computed `bottom` is 0 — i.e., it's pinned to the
        // bottom of its containing dialog rather than the right edge.
        const box = await sidebar.boundingBox();
        expect(box.width).toBeGreaterThan(300);
        const computedBottom = await sidebar.evaluate((el) => getComputedStyle(el).bottom);
        expect(computedBottom).toBe('0px');
    });

    test('sidebar is resizable and the chosen width persists across opens', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await openBlockEditor(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');

        // Synthesize the drag from inside the page: Playwright's mouse API
        // routing through the dialog overlay is flaky for resize handles
        // pinned to the iframe boundary; firing the event chain in-page is
        // both faster and more deterministic here.
        const widthAfter = await page.evaluate(() => {
            const sb = document.querySelector('aside[data-cb-builder-target="sidebar"]');
            const handle = sb.querySelector('.cb-shell__sidebar-resize');
            const rect = handle.getBoundingClientRect();
            const startX = rect.x + rect.width / 2;
            const startY = rect.y + rect.height / 2;

            // Sidebar is left-anchored with the handle on its right edge —
            // drag the handle RIGHT to grow it.
            handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, clientX: startX, clientY: startY }));
            document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, cancelable: true, clientX: startX + 120, clientY: startY }));
            document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, clientX: startX + 120, clientY: startY }));

            return sb.getBoundingClientRect().width;
        });

        expect(widthAfter).toBeGreaterThan(420);
        const storedWidth = await page.evaluate(() => window.localStorage.getItem('cb-builder.sidebarWidth'));
        expect(parseInt(storedWidth, 10)).toBeGreaterThan(420);

        // Re-mount a form into the (permanent) sidebar: the resized width is
        // held on the shell, so it survives the content swap.
        await openBlockEditor(page, frame);
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const widthAfterReopen = await sidebar.evaluate((el) => el.getBoundingClientRect().width);
        expect(widthAfterReopen).toBeCloseTo(widthAfter, 0);
    });

    test('block delete removes the block from the preview in place', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await clickInPreview(page, '[data-cb-block-id]');
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();

        // A never-published block is dropped from the preview in place
        // (soft-deleted on the server; Discard can still bring it back).
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    });
});

test.describe('builder shell — preview hardening', () => {
    test('three-column section renders columns side by side, not stacked', async ({ page }) => {
        const frame = await openBuilder(page);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="three_cols"]').click();

        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(3);

        const tops = await frame.locator('[data-cb-column-id]').evaluateAll((els) =>
            els.map((el) => Math.round(el.getBoundingClientRect().top)),
        );
        // All three columns share the same top → they're on the same row.
        expect(new Set(tops).size).toBe(1);
    });

    test('a wide column gap keeps two columns side by side, not stacked', async ({ page }) => {
        // Regression: the col presets subtracted a hardcoded 1rem gap from
        // flex-basis. Once the configured gap grew past 1rem (here 40px) the
        // two col-6 columns no longer fit on one row and wrapped. The presets
        // now reserve the actual --cb-gap-d, so they stay side by side.
        const frame = await openBuilder(page);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(2);
        await page.waitForTimeout(200);

        await openSectionSettings(page, frame);
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');

        // Set the desktop column gap to 40px (the 'd' viewport tab is active by
        // default, so its input is the visible one). Autosave persists it and
        // the preview reloads with --cb-gap-d:40px on the section.
        await sidebar.locator('input[name="section_settings[styling][gap][d]"]').fill('40');
        const section = frame.locator('[data-cb-section-id]').first();
        await expect.poll(async () => section.getAttribute('style')).toContain('--cb-gap-d:40px');

        const tops = await frame.locator('[data-cb-column-id]').evaluateAll((els) =>
            els.map((el) => Math.round(el.getBoundingClientRect().top)),
        );
        // Both columns share the same top → the 40px gap did not push the
        // second column onto a new row.
        expect(new Set(tops).size).toBe(1);
    });

    test('mounting a block form does not shrink the iframe', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        const iframe = page.locator('.cb-shell__iframe');
        const widthBefore = await iframe.evaluate((el) => el.getBoundingClientRect().width);

        await openBlockEditor(page, frame);

        await expect(page.locator('aside[data-cb-builder-target="sidebar"]')).not.toHaveAttribute('hidden');

        // The sidebar is permanent, so mounting a form into it doesn't reflow
        // the iframe — its width is unchanged.
        const widthAfter = await iframe.evaluate((el) => el.getBoundingClientRect().width);
        expect(widthAfter).toBe(widthBefore);
    });

    test('clicks on links inside the iframe preview are intercepted', async ({ page }) => {
        const frame = await openBuilder(page);

        // Inject a real <a> into the iframe and verify the click is blocked.
        const initialUrl = await page.locator('.cb-shell__iframe').evaluate((el) => el.src);

        const blocked = await page.locator('.cb-shell__iframe').evaluate((iframe) => {
            const idoc = iframe.contentDocument;
            const a = idoc.createElement('a');
            a.href = 'http://example.test/somewhere-else';
            a.id = '__cb_test_link__';
            a.textContent = 'External';
            idoc.body.appendChild(a);

            const event = new MouseEvent('click', { bubbles: true, cancelable: true });
            a.dispatchEvent(event);

            return {
                defaultPrevented: event.defaultPrevented,
                stillSameUrl: iframe.contentWindow.location.href === iframe.src,
            };
        });

        expect(blocked.defaultPrevented).toBe(true);
        expect(blocked.stillSameUrl).toBe(true);
    });
});

test.describe('builder shell — duplicate', () => {
    test('section duplicate creates a sibling immediately after the source', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFullSection(page, frame);
        const before = await frame.locator('[data-cb-section-id]').count();

        await clickInPreview(page, '[data-cb-section-id]');
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="duplicate"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    });

    test('block duplicate adds a copy of the same type next to the source', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        const sourceType = await frame.locator('[data-cb-block-id]').first().getAttribute('data-cb-block-type');

        // The freshly-added block is already focused (its toolbar is pinned
        // visible), so fire the duplicate action straight from the toolbar —
        // no hover needed (a top-left hover would hit the section handle).
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="duplicate"]').click();

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(2);
        // Both blocks share the same type — the copy carries the source's data.
        const types = await frame.locator('[data-cb-block-id]').evaluateAll(
            (els) => els.map((el) => el.getAttribute('data-cb-block-type')),
        );
        expect(types.every((t) => t === sourceType)).toBe(true);
    });
});

test.describe('builder shell — feedback', () => {
    test('saving a block flashes a "Saved" pill in the sidebar header', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        // addTitleBlock auto-opens the editor (and waits long enough for the
        // autosave controller to connect), so we don't re-open it.
        await addTitleBlock(page, frame);

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const flash = page.locator('[data-cb-builder-target="savedFlash"]');
        await expect(flash).toBeHidden();

        // Autosave: typing into the title field persists it and flashes the pill.
        await sidebar.locator('.cb-block__edit-form input[type="text"]').first().fill('e2e-pill');

        // Visible briefly, then auto-hidden — assert the visible window only.
        await expect(flash).toBeVisible();
    });

    test('a structural mutation toggles the cb-shell--loading flag while in flight', async ({ page }) => {
        const frame = await openBuilder(page);

        // Watch the shell class around the section-create AJAX. We can't
        // assert the *exact* in-flight moment reliably, but we can verify
        // the class returns to a stable "not loading" state after the op.
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        // Once the iframe has fully reloaded, the loading class is cleared.
        await expect(page.locator('.cb-shell.cb-shell--loading')).toHaveCount(0);
    });
});

test.describe('builder shell — focus + permanent affordances', () => {
    test('inline + Block button is rendered for every column without hovering', async ({ page }) => {
        const frame = await openBuilder(page);
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="three_cols"]').click();
        await expect.poll(() => frame.locator('[data-cb-column-id]').count()).toBe(3);

        // No hover — every column has a permanent +Block affordance below it.
        await expect(frame.locator('.cb-add-block-inline')).toHaveCount(3);
    });

    test('clicking a block pins the toolbar (focus mode) so it stays visible after hovering away', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        // Click the block — pinning the focus instead of just hovering.
        await clickInPreview(page, '[data-cb-block-id]');

        const toolbar = frame.locator('.cb-overlay-toolbar.is-visible');
        await expect(toolbar).toBeVisible();

        // Move the cursor far from the block + toolbar; the toolbar should
        // stay because the block is now focused.
        await frame.locator('body').hover({ position: { x: 5, y: 5 }, force: true });
        await page.waitForTimeout(300);
        await expect(toolbar).toBeVisible();
    });

    test('section toolbar is rendered as an overlapping chip on the section top border', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        const section = frame.locator('[data-cb-section-id]').first();
        await section.hover({ position: { x: 5, y: 5 } });

        const toolbar = frame.locator('.cb-overlay-toolbar.is-visible');
        await expect(toolbar).toBeVisible();

        // The toolbar's vertical center is within a few pixels of the
        // section's top edge — it overlaps the border like a "chip".
        const toolbarBox = await toolbar.boundingBox();
        const sectionBox = await section.boundingBox();
        const toolbarCenterY = toolbarBox.y + toolbarBox.height / 2;
        expect(Math.abs(toolbarCenterY - sectionBox.y)).toBeLessThan(8);
    });
});

test.describe('builder shell — polish', () => {
    // The "opening sidebar auto-focuses the first form field" test was removed:
    // block edit forms no longer auto-focus a field on mount (only the section
    // settings form focuses its width input), so the behavior it asserted is
    // gone.

    test('close button without sidebar form just closes the dialog', async ({ page }) => {
        await openBuilder(page);
        const dialog = page.locator('.cb-builder-dialog');
        await expect(dialog).toHaveAttribute('open', '');

        await page.locator('.cb-shell__close').click();
        await expect(dialog).not.toHaveAttribute('open');
    });

    // The "close while a form is open prompts a confirmation" tests were
    // removed: autosave replaced manual save, so there are no unsaved changes
    // to confirm — close() now just closes the dialog (covered above).
});

test.describe('builder shell — publish / discard', () => {
    test('Publish flushes drafts: never-published blocks become public, area is clean', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        // Before publish: badge present, Discard visible, Publish enabled.
        await expect(page.locator('.cb-shell__discard')).toBeVisible();
        await expect(page.locator('.cb-shell__publish')).toBeEnabled();

        await page.locator('.cb-shell__publish').click();

        // After publish: badge gone, Discard hidden, Publish disabled.
        await expect(page.locator('.cb-shell__discard')).toBeHidden();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled();
        await expect(page.locator('.cb-launcher__badge')).toHaveCount(0);

        // The block is still there and no longer flagged deleted (it never
        // was, but we want to verify the section/block didn't disappear).
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBeGreaterThanOrEqual(1);
    });

    test('Discard removes a never-published section entirely', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        expect(await frame.locator('[data-cb-section-id]').count()).toBe(1);

        await page.locator('.cb-shell__discard').click();

        // Section was added but never published → discardDraft removes it.
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(0);
        // Discard button is hidden (no pending changes left), Publish disabled.
        await expect(page.locator('.cb-shell__discard')).toBeHidden();
        await expect(page.locator('.cb-shell__publish')).toBeDisabled();
    });

    test('Discard restores a soft-deleted block from a published area', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        // Snapshot current block id, publish so it's now part of the public state.
        await page.locator('.cb-shell__publish').click();
        await expect(page.locator('.cb-shell__discard')).toBeHidden();

        // Now soft-delete the block — it's dropped from the preview in place
        // (soft-deleted on the server since the area is published).
        await clickInPreview(page, '[data-cb-block-id]');
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
        await expect(page.locator('.cb-shell__discard')).toBeVisible();

        // Discard the soft-delete → the published block comes back.
        await page.locator('.cb-shell__discard').click();

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });
});

test.describe('builder shell — keyboard shortcuts', () => {
    test('Delete on a focused section soft-deletes it (mirrors the toolbar ×)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Click the section's top strip to pin focus on it (not a column/block),
        // then press Delete — the overlay forwards the same delete intent as
        // the toolbar × button.
        await clickInPreview(page, '[data-cb-section-id]');
        await pressInPreview(page, 'Delete');

        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    });

    test('Delete on a focused block removes it from the preview', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await clickInPreview(page, '[data-cb-block-id]');
        await pressInPreview(page, 'Delete');

        // A never-published block is removed from the preview in place.
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    });

    test('Backspace deletes the focused element too', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await clickInPreview(page, '[data-cb-block-id]');
        await pressInPreview(page, 'Backspace');

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    });

    test('Escape deselects the focused element (retracts the pinned toolbar)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await clickInPreview(page, '[data-cb-section-id]');
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toBeVisible();

        await pressInPreview(page, 'Escape');
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toHaveCount(0);
    });

    test('Delete does nothing when no element is focused', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Click empty preview space to ensure nothing is pinned.
        await clickInPreview(page, 'body');
        await pressInPreview(page, 'Delete');

        // The section is untouched.
        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(0);
        await expect(frame.locator('[data-cb-section-id]')).toHaveCount(1);
    });
});

test.describe('builder shell — selection affordances', () => {
    test('section handle selects a section even when it is full of blocks', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        // The block fills the section, so a plain click inside would hit the
        // block. The hover-revealed handle is the dependable way in.
        const section = frame.locator('[data-cb-section-id]').first();
        await section.hover();
        await section.locator('.cb-section-handle').click();

        // The section settings sidebar opens (its width radios are unique to it).
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('input[name="section_settings[widthMode]"][value="full"]')).toBeAttached();
    });

    test('+ Block pill is interactable on an empty column without hovering first', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Empty column → the pill is revealed (pointer-events:auto) right away.
        const addBtn = frame.locator('.cb-add-block-inline').first();
        await addBtn.click();
        await expect(frame.locator('.cb-overlay-popover')).toBeVisible();
    });
});

test.describe('builder shell — column widths', () => {
    test('applying a 40/60 preset weights the two columns and persists after reload', async ({ page }) => {
        const frame = await openBuilder(page);

        // Add a two-column section.
        await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="two_cols"]').click();
        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
        await page.waitForTimeout(200);

        // Open the section settings (clicking the section opens them).
        await openSectionSettings(page, frame);
        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-col-widths')).toBeVisible();

        // Apply the 40/60 preset → save → preview reload re-renders weighted.
        await sidebar.locator('.cb-col-widths__preset', { hasText: '40/60' }).click();
        await expect
            .poll(() => frame.locator('[data-cb-column-id]').first().getAttribute('style'))
            .toContain('--cb-col-grow: 40');

        // Reopen the builder: the weight is persisted to the draft.
        await page.reload();
        await page.locator('.cb-launcher__button').click();
        await expect(page.locator('.cb-shell')).toBeVisible();
        const reloaded = page.frameLocator('.cb-shell__iframe');
        await expect
            .poll(() => reloaded.locator('[data-cb-column-id]').first().getAttribute('style'))
            .toContain('--cb-col-grow: 40');
    });
});
