import { test, expect } from '@playwright/test';

/**
 * End-to-end coverage for the kit's rich-text block in the real builder.
 *
 * The editor's own JavaScript is deliberately blocked here, for two reasons:
 * it makes the run independent of a CDN (and of having any network at all),
 * and it exercises the promise the block makes when the editor cannot load —
 * the plain textarea keeps holding the HTML, stays editable, and still saves.
 * That the editor *does* mount when its script is available is covered by the
 * kit's unit suites against a stubbed editor.
 *
 * What only this spec can prove: the adapter's values survive the whole way to
 * the rendered sidebar, and the field is still bound to autosave after the
 * form theme stopped naming an editor.
 */

async function createFreshPage(page) {
    const slug = `e2e-rt-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function openBuilderWithRichText(page) {
    // Block the editor CDN before anything loads it.
    await page.route('**/tinymce**', (route) => route.abort());

    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: 'Texte enrichi' }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);

    // Click-to-edit opens the block's form in the sidebar.
    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')?.dispatchEvent(
            new MouseEvent('click', { bubbles: true, cancelable: true }),
        );
    });
    const sidebar = page.locator('aside[data-cb-builder-target="sidebar"]');
    await expect(sidebar.locator('.cb-block__edit-form')).toBeVisible();
    await page.waitForTimeout(300); // let cb-autosave connect

    return { frame, sidebar };
}

test('rich text — the selected editor is wired onto the field', async ({ page }) => {
    const { sidebar } = await openBuilderWithRichText(page);

    // The sandbox runs the default editor, so the wrapper names its controller.
    const wrapper = sidebar.locator('[data-controller="cb-tinymce"]');
    await expect(wrapper).toHaveCount(1);

    // The morpher must leave the editor's injected DOM alone.
    await expect(wrapper).toHaveAttribute('data-live-ignore', '');

    // Uploads point at the builder's own endpoint, not at anything editor-specific.
    await expect(wrapper).toHaveAttribute('data-cb-tinymce-upload-url-value', '/_content-blocks/upload');
    // The palette reaches the editor's swatches.
    const palette = await wrapper.getAttribute('data-cb-tinymce-palette-value');
    expect(JSON.parse(palette).length).toBeGreaterThan(0);

    // And the textarea it mounts on is the field itself.
    const textarea = wrapper.locator('textarea[data-cb-tinymce-target="textarea"]');
    await expect(textarea).toHaveCount(1);
    expect(await textarea.getAttribute('name')).toMatch(/\[content]$/);
});

test('rich text — content is still editable and saved when the editor cannot load', async ({ page }) => {
    const { frame, sidebar } = await openBuilderWithRichText(page);

    const textarea = sidebar.locator('[data-controller="cb-tinymce"] textarea');
    await textarea.fill('<p>Rescue copy</p>');
    await textarea.dispatchEvent('change');

    // Autosave writes the draft; the preview re-renders with it.
    await expect(frame.locator('[data-cb-block-id]')).toContainText('Rescue copy', { timeout: 10000 });
});
