import { test, expect } from '@playwright/test';

/**
 * End-to-end coverage for the per-block form extension seam
 * (BlockFormExtensionInterface + #[AsBlockFormExtension]).
 *
 * The sandbox registers the two reference examples:
 *   - App\ContentBlocks\Form\ButtonRelExtension — targeted (`button` only),
 *     rendered by the app's override of the kit button template;
 *   - App\ContentBlocks\Form\AnchorIdExtension  — global (`'*'`), rendered by
 *     App\ContentBlocks\Block\AnchorIdBlockDecorator as the wrapper's `id`.
 *
 * Both fields declare `data-cb-group: SEO`, so they land in their own sidebar
 * tab. What this spec proves that the PHP unit tests cannot: the fields really
 * reach the rendered edit form, their values persist into `Block.data` through
 * autosave, and they survive a full round-trip to the rendered preview.
 */

async function createFreshPage(page) {
    const slug = `e2e-ext-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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

async function addBlock(page, frame, label) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    // Popover buttons carry the translated block label as their text.
    await frame.locator('.cb-overlay-popover button', { hasText: label }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);
}

/** Opens the block editor by clicking the block element (click-to-edit). */
async function openBlockEditor(page, frame) {
    await frame.locator('[data-cb-block-id]').first().waitFor();
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    // Let cb-autosave connect before editing — an edit fired before connect
    // never persists.
    await page.waitForTimeout(300);
    return sidebar;
}

/**
 * Field names of the first sidebar tab ("General"), in DOM order — that is the
 * order the form builder produced. `content_block[align]` → `align`.
 */
async function fieldNamesOfFirstTab(sidebar) {
    const controls = sidebar.locator('.cb-block__tabpanel').first().locator('input[name], select[name], textarea[name]');

    return (await controls.evaluateAll((els) => els.map((el) => el.getAttribute('name'))))
        .map((name) => name.match(/\[([^\][]+)]$/)?.[1])
        .filter((name, i, all) => name && all.indexOf(name) === i); // checkboxes can repeat
}

/** Extension fields sit in the "SEO" tab (data-cb-group), hidden until selected. */
async function openSeoTab(sidebar) {
    const tab = sidebar.locator('.cb-block__tab', { hasText: 'SEO' });
    await expect(tab).toHaveCount(1);
    await tab.click();
}

test.describe('block form extensions', () => {
    test('targeted + global extension fields render, persist and reach the preview', async ({ page }) => {
        const pageUrl = await createFreshPage(page);
        let frame = await openBuilder(page, pageUrl);
        await addBlock(page, frame, /^Bouton$|^Button$/);

        let sidebar = await openBlockEditor(page, frame);
        await openSeoTab(sidebar);

        // Both extensions contributed to the SAME block form: the global one
        // (every block) and the targeted one (button only).
        const anchorInput = sidebar.locator('[name$="[anchorId]"]');
        const relSelect = sidebar.locator('[name$="[rel]"]');
        await expect(anchorInput).toBeVisible();
        await expect(relSelect).toBeVisible();

        await anchorInput.fill('cta-anchor');
        await anchorInput.blur();
        await page.waitForTimeout(600);
        await relSelect.selectOption('nofollow');
        await page.waitForTimeout(1200); // autosave debounce + round-trip

        // Round-trip 1 — the values are in Block.data: reopen the builder from
        // scratch and the form comes back pre-filled from the stored draft.
        frame = await openBuilder(page, pageUrl);
        sidebar = await openBlockEditor(page, frame);
        await openSeoTab(sidebar);
        await expect(sidebar.locator('[name$="[anchorId]"]')).toHaveValue('cta-anchor');
        await expect(sidebar.locator('[name$="[rel]"]')).toHaveValue('nofollow');

        // Round-trip 2 — the render side. The global field becomes the block
        // wrapper's id (decorator) and the targeted one the link's rel (host
        // template override of the kit button view).
        const wrapper = frame.locator('#cta-anchor');
        await expect(wrapper).toHaveAttribute('data-cb-block-type', 'button');
        await expect(wrapper.locator('a.cb-kit-btn')).toHaveAttribute('rel', 'nofollow');
    });

    test('an extension removes a field from the block form', async ({ page }) => {
        // App\ContentBlocks\Form\ButtonFieldRemovalExtension drops `fullWidth`
        // from the button block ("no full-width buttons" design rule).
        const frame = await openBuilder(page, await createFreshPage(page));
        await addBlock(page, frame, /^Bouton$|^Button$/);
        const sidebar = await openBlockEditor(page, frame);

        await expect(sidebar.locator('[name$="[fullWidth]"]')).toHaveCount(0);
        // Only that one: the block's other checkbox is still there, so this is
        // a surgical removal, not a broken form.
        await expect(sidebar.locator('[name$="[newTab]"]')).toHaveCount(1);
    });

    test('an extension reorders the block fields', async ({ page }) => {
        // App\ContentBlocks\Form\ButtonFieldOrderExtension pulls `url` in front
        // of `text`; the rest keeps the kit's order.
        const frame = await openBuilder(page, await createFreshPage(page));
        await addBlock(page, frame, /^Bouton$|^Button$/);
        const sidebar = await openBlockEditor(page, frame);

        const names = await fieldNamesOfFirstTab(sidebar);
        expect(names.slice(0, 2)).toEqual(['url', 'text']);
        // Untouched fields keep their relative order behind the pinned ones.
        expect(names.filter((n) => ['variant', 'size', 'align'].includes(n)))
            .toEqual(['variant', 'size', 'align']);
    });

    test('a targeted extension does not leak into other block types', async ({ page }) => {
        const frame = await openBuilder(page, await createFreshPage(page));
        await addBlock(page, frame, /^Titre$|^Title$/);

        const sidebar = await openBlockEditor(page, frame);
        await openSeoTab(sidebar);

        // Global extension: present on every block.
        await expect(sidebar.locator('[name$="[anchorId]"]')).toBeVisible();
        // Targeted at `button`: must be absent here.
        await expect(sidebar.locator('[name$="[rel]"]')).toHaveCount(0);
    });
});
