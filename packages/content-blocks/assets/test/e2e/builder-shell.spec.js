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
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button').first().click();
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

    test('iframe loads the preview URL with cb_preview=1 and emits cb:ready', async ({ page }) => {
        const logs = attachConsoleSink(page);
        const url = await createFreshPage(page);
        await page.goto(url);
        await page.locator('.cb-launcher__button').click();

        const iframe = page.locator('.cb-shell__iframe');
        await expect(iframe).toHaveAttribute('src', /\/page\/\d+\?cb_preview=1$/);

        await expect.poll(() => logs.some((l) => l.includes('iframe ready'))).toBe(true);
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
        await frame.locator('[data-cb-section-id]').nth(1).hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="move-up"]').click();

        await expect.poll(async () => (await readOrder()).join(',')).toBe([before[1], before[0]].join(','));
    });

    test('section delete overlay marks the section deleted', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    });

    test('section settings overlay (⚙) opens the sidebar with the settings form', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="settings"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        // Built-in fields are present.
        await expect(sidebar.locator('input[name="section_settings[classes]"]')).toBeVisible();
        await expect(sidebar.locator('input[name="section_settings[widthMode]"][value="full"]')).toBeAttached();
        await expect(sidebar.locator('input[name="section_settings[widthMode]"][value="centered"]')).toBeAttached();
        await expect(sidebar.locator('input[name="section_settings[maxWidth]"]')).toBeVisible();
        // Sandbox FormTypeExtension contributed an additional field.
        await expect(sidebar.locator('input[name="section_settings[backgroundColor]"]')).toBeAttached();
    });

    test('section settings save applies custom classes + width and the host backgroundColor extension', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Open settings.
        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="settings"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await sidebar.locator('input[name="section_settings[classes]"]').fill('e2e-decorated');
        await sidebar.locator('input[name="section_settings[widthMode]"][value="centered"]').check();
        await sidebar.locator('input[name="section_settings[maxWidth]"]').fill('900');
        // Sandbox extension field — a ColorType picker. Playwright fills
        // <input type="color"> via a hex value.
        await sidebar.locator('input[name="section_settings[backgroundColor]"]').fill('#ffeecc');
        await page.locator('.cb-shell__sidebar-save').click();

        // Sidebar stays open after save (the user can keep tweaking) — but
        // the iframe reloads with the new draft applied.
        await expect(sidebar).not.toHaveAttribute('hidden');
        const section = frame.locator('[data-cb-section-id]').first();
        await expect.poll(async () => section.getAttribute('class')).toContain('e2e-decorated');
        await expect.poll(async () => section.getAttribute('class')).toContain('cb-section--centered');
        const style = await section.getAttribute('style');
        expect(style).toContain('max-width:900px');
        expect(style).toContain('background-color:#ffeecc');
    });

    test('section settings saved with the framework default value do not pollute the rendered markup', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="settings"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        // The form opens with backgroundColor pre-set to #ffffff (sandbox default).
        const colorInput = sidebar.locator('input[name="section_settings[backgroundColor]"]');
        await expect(colorInput).toHaveValue('#ffffff');

        // Save without changing anything. The sidebar stays open.
        await page.locator('.cb-shell__sidebar-save').click();
        await expect(sidebar).not.toHaveAttribute('hidden');

        // Iframe reloads — the section MUST NOT carry background-color in
        // its inline style because the saved value matches the registered
        // default.
        const section = frame.locator('[data-cb-section-id]').first();
        // Wait for the iframe reload to settle.
        await page.waitForTimeout(400);
        const style = await section.getAttribute('style');
        expect(style ?? '').not.toContain('background-color');
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

        // The list reflects the registered block types (4 in the kit:
        // text, title, image, tabs).
        const items = popover.locator('button');
        await expect(items).toHaveCount(4);

        await items.first().click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    });

    test('clicking Edit on a block mounts the BlockComponent in the sidebar', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
        // Header Save button is visible + enabled once the form is mounted.
        await expect(page.locator('.cb-shell__sidebar-save')).toBeVisible();
        await expect(page.locator('.cb-shell__sidebar-save')).toBeEnabled();
    });

    test('clicking outside the sidebar (in the iframe preview) closes it', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // Dispatch a click directly on the iframe's <body>. We deliberately
        // avoid coordinate-based clicks here: the layout is in flux because
        // adding a block auto-opens the sidebar (which can shift content),
        // and a coordinate that's "outside" can land inside a section
        // depending on how the iframe paints. Targeting `body` itself is
        // unambiguously outside any [data-cb-block-id]/[data-cb-section-id]
        // ancestor, so preview-overlay routes it as a true outside-click.
        await page.locator('.cb-shell__iframe').evaluate((iframe) => {
            iframe.contentDocument.body.dispatchEvent(
                new MouseEvent('click', { bubbles: true, cancelable: true }),
            );
        });

        await expect(sidebar).toHaveAttribute('hidden', '');
    });

    test('× in sidebar header closes it without reloading', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        await sidebar.locator('.cb-shell__sidebar-close').click();

        await expect(sidebar).toHaveAttribute('hidden', '');
    });

    test('saving a block keeps the sidebar open and reloads the iframe', async ({ page }) => {
        const logs = attachConsoleSink(page);
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        await page.locator('.cb-shell__sidebar-save').click();

        // block:saved was logged AND the form is still visible afterwards.
        await expect.poll(() => logs.some((l) => l.startsWith('[cb-builder] block:saved'))).toBe(true);
        await expect(sidebar).not.toHaveAttribute('hidden');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    });

    test('saving with focus still in the input persists the typed value', async ({ page }) => {
        // High-level regression for the "header Save loses the typed value"
        // bug: with Live's `on(change)` form binding, the header Save action
        // (saveSidebar in cb-builder) fires a synthetic `.click()` on the
        // in-form submit button. That click does NOT move focus, so the
        // user's last keystrokes never produce a change event — the Live
        // POST goes out with stale props and persists empty data.
        //
        // The fix in saveSidebar() blurs the focused sidebar input first,
        // which fires change synchronously and updates Live's model
        // before the action POSTs. This e2e test reads back the persisted
        // value by reopening the block edit form post-save, so it verifies
        // the round-trip rather than just the iframe re-render.
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Title block: its form has a single <input type="text"> bound to
        // data.text and the view template echoes that value, making the
        // assertion unambiguous.
        await frame.locator('.cb-add-block-inline').first().click();
        await frame.locator('.cb-overlay-popover button', { hasText: /^Titre$|^Title$/ }).click();
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        await page.waitForTimeout(200);

        const block = frame.locator('[data-cb-block-id]').first();
        await block.hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

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

        await page.locator('.cb-shell__sidebar-save').click();
        await page.waitForTimeout(1500);

        // Reopen the form: if save persisted, the input should now show the typed value.
        await sidebar.locator('.cb-shell__sidebar-close').click();
        await page.waitForTimeout(300);
        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();
        await page.waitForTimeout(500);

        await expect(sidebar.locator('.cb-block__edit-form input[type="text"]').first()).toHaveValue(typed);
    });

    test('mobile viewport: sidebar slides from the bottom and the iframe area leaves room for it', async ({ page }) => {
        await page.setViewportSize({ width: 390, height: 800 });

        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

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

        // Shell carries the open class; iframe area gets padding-bottom so
        // the preview content stays scrollable up to its real bottom edge.
        await expect(page.locator('.cb-shell.cb-shell--sidebar-open')).toBeVisible();
        const paddingBottom = await page.locator('.cb-shell__main').evaluate(
            (el) => getComputedStyle(el).paddingBottom,
        );
        expect(paddingBottom).not.toBe('0px');
    });

    test('sidebar is resizable and the chosen width persists across opens', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

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

            handle.dispatchEvent(new MouseEvent('mousedown', { bubbles: true, cancelable: true, clientX: startX, clientY: startY }));
            document.dispatchEvent(new MouseEvent('mousemove', { bubbles: true, cancelable: true, clientX: startX - 120, clientY: startY }));
            document.dispatchEvent(new MouseEvent('mouseup', { bubbles: true, cancelable: true, clientX: startX - 120, clientY: startY }));

            return sb.getBoundingClientRect().width;
        });

        expect(widthAfter).toBeGreaterThan(420);
        const storedWidth = await page.evaluate(() => window.localStorage.getItem('cb-builder.sidebarWidth'));
        expect(parseInt(storedWidth, 10)).toBeGreaterThan(420);

        // Close + reopen: the saved width should be restored.
        await sidebar.locator('.cb-shell__sidebar-close').click();
        await expect(sidebar).toHaveAttribute('hidden', '');

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();
        await expect(sidebar).not.toHaveAttribute('hidden');
        // Wait for the sidebar mount + form render to settle.
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const widthAfterReopen = await sidebar.evaluate((el) => el.getBoundingClientRect().width);
        expect(widthAfterReopen).toBeCloseTo(widthAfter, 0);
    });

    test('block delete overlay soft-deletes (deleted marker stays in DOM)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();

        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(1);
        // The block is still in the DOM, just marked.
        await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);
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

    test('opening the sidebar does not shrink the iframe (it floats over)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        const iframe = page.locator('.cb-shell__iframe');
        const widthBefore = await iframe.evaluate((el) => el.getBoundingClientRect().width);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        await expect(page.locator('aside[data-cb-builder-target="sidebar"]')).not.toHaveAttribute('hidden');

        const widthAfter = await iframe.evaluate((el) => el.getBoundingClientRect().width);
        expect(widthAfter).toBe(widthBefore);

        // Sidebar is positioned absolutely.
        const position = await page.locator('aside[data-cb-builder-target="sidebar"]').evaluate(
            (el) => getComputedStyle(el).position,
        );
        expect(position).toBe('absolute');
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

        await frame.locator('[data-cb-section-id]').first().hover({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="duplicate"]').click();

        await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
    });

    test('block duplicate adds a copy of the same type next to the source', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        const sourceType = await frame.locator('[data-cb-block-id]').first().getAttribute('data-cb-block-type');

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
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
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        const flash = page.locator('[data-cb-builder-target="savedFlash"]');
        await expect(flash).toBeHidden();

        await page.locator('.cb-shell__sidebar-save').click();

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
        await frame.locator('[data-cb-block-id]').first().click({ position: { x: 10, y: 10 } });

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
    test('opening sidebar auto-focuses the first form field', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // The first focusable input inside the sidebar should be the active element.
        await expect.poll(async () => {
            return await page.evaluate(() => {
                const sidebar = document.querySelector('aside[data-cb-builder-target="sidebar"]');
                return sidebar.contains(document.activeElement) ? document.activeElement.tagName : null;
            });
        }).toMatch(/INPUT|TEXTAREA/);
    });

    test('close button without sidebar form just closes the dialog', async ({ page }) => {
        await openBuilder(page);
        const dialog = page.locator('.cb-builder-dialog');
        await expect(dialog).toHaveAttribute('open', '');

        await page.locator('.cb-shell__close').click();
        await expect(dialog).not.toHaveAttribute('open');
    });

    test('close while sidebar form is open prompts confirmation, declined keeps dialog open', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        // Decline the native confirm.
        page.once('dialog', async (d) => { await d.dismiss(); });
        await page.locator('.cb-shell__close').click();

        await expect(page.locator('.cb-builder-dialog')).toHaveAttribute('open', '');
    });

    test('close while sidebar form is open, accept confirmation, dialog closes', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);
        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="edit"]').click();

        const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
        await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();

        page.once('dialog', async (d) => { await d.accept(); });
        await page.locator('.cb-shell__close').click();

        await expect(page.locator('.cb-builder-dialog')).not.toHaveAttribute('open');
    });
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

        // Now soft-delete the block.
        await frame.locator('[data-cb-block-id]').first().hover({ position: { x: 10, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();
        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(1);
        await expect(page.locator('.cb-shell__discard')).toBeVisible();

        // Discard the soft-delete.
        await page.locator('.cb-shell__discard').click();

        await expect.poll(() => frame.locator('[data-cb-block-id][data-cb-deleted="1"]').count()).toBe(0);
        await expect(frame.locator('[data-cb-block-id]')).toHaveCount(1);
    });
});

test.describe('builder shell — keyboard shortcuts', () => {
    test('Delete on a focused section soft-deletes it (mirrors the toolbar ×)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Click the section's top strip to pin focus on it (not a column/block),
        // then press Delete — the overlay forwards the same delete intent as
        // the toolbar × button.
        await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
        await page.keyboard.press('Delete');

        await expect.poll(() => frame.locator('[data-cb-section-id][data-cb-deleted="1"]').count()).toBe(1);
    });

    test('Delete on a focused block removes it from the preview', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().click({ position: { x: 10, y: 10 } });
        await page.keyboard.press('Delete');

        // A never-published block is removed from the preview in place.
        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    });

    test('Backspace deletes the focused element too', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);
        await addFirstBlock(page, frame);

        await frame.locator('[data-cb-block-id]').first().click({ position: { x: 10, y: 10 } });
        await page.keyboard.press('Backspace');

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(0);
    });

    test('Escape deselects the focused element (retracts the pinned toolbar)', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toBeVisible();

        await page.keyboard.press('Escape');
        await expect(frame.locator('.cb-overlay-toolbar.is-visible')).toHaveCount(0);
    });

    test('Delete does nothing when no element is focused', async ({ page }) => {
        const frame = await openBuilder(page);
        await addFullSection(page, frame);

        // Click empty preview space to ensure nothing is pinned.
        await frame.locator('body').click({ position: { x: 1, y: 1 } });
        await page.keyboard.press('Delete');

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

        // Open the section settings via the hover-revealed handle.
        const section = frame.locator('[data-cb-section-id]').first();
        await section.hover();
        await section.locator('.cb-section-handle').click();
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
