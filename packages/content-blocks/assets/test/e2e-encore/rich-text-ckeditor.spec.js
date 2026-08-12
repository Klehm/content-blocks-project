import { test, expect } from '@playwright/test';

/**
 * The rich-text block on CKEditor, bundled by the host.
 *
 * This belongs in the Encore suite rather than the main one for the same
 * reason the suite exists: it is a *path*, not a behaviour. The kit's
 * `cdn: false` option says "load nothing, the host bundled the editor", and
 * only a host with a bundler can demonstrate that. This sandbox imports
 * `ckeditor5` in its webpack entry and publishes it as `window.CKEDITOR`
 * (see apps/content-blocks-encore-sandbox/assets/app.js).
 *
 * Two things follow. The editor here is the real CKEditor, not a stub — so
 * this is the only place the mount, the toolbar and the write-back are
 * exercised for real. And it needs no network: the main suite's rich-text spec
 * has to block a CDN to be deterministic, this one never reaches for one.
 */

async function openRichTextEditor(page) {
    const response = await page.request.post('/page/create', {
        form: { title: `Encore — CKEditor ${Date.now()}` },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    await page.goto(location);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();

    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(200);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: 'Rich text' }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
    await page.waitForTimeout(200);

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

test.describe('rich text on a host-bundled CKEditor', () => {
    test('the kit loads no editor script and mounts the bundled global', async ({ page }) => {
        const requests = [];
        page.on('request', (r) => requests.push(r.url()));

        const { sidebar } = await openRichTextEditor(page);

        const wrapper = sidebar.locator('[data-controller="cb-ckeditor"]');
        await expect(wrapper).toHaveCount(1);
        // `cdn: false` renders an empty script URL — the "you bundled it" signal.
        await expect(wrapper).toHaveAttribute('data-cb-ckeditor-script-url-value', '');
        await expect(wrapper).toHaveAttribute('data-cb-ckeditor-style-url-value', '');

        // The real editor is up.
        await expect(wrapper.locator('.ck-editor__editable')).toBeVisible();

        // …and nothing was fetched from a CDN to get there.
        expect(requests.filter((url) => !url.startsWith('http://127.0.0.1:8002'))).toEqual([]);
    });

    test('editing writes back to the textarea and reaches the preview', async ({ page }) => {
        const { frame, sidebar } = await openRichTextEditor(page);

        const editable = sidebar.locator('[data-controller="cb-ckeditor"] .ck-editor__editable');
        await editable.click();
        await page.keyboard.type('Bundled copy');

        // The textarea is the Live binding: the controller syncs CKEditor's
        // HTML into it on every change, before the event bubbles.
        const textarea = sidebar.locator('[data-controller="cb-ckeditor"] textarea');
        await expect.poll(() => textarea.inputValue()).toContain('Bundled copy');

        // Autosave persists the draft and the preview re-renders from it.
        await expect(frame.locator('[data-cb-block-id]')).toContainText('Bundled copy', { timeout: 10000 });
    });

    test('the upload button is wired to the builder endpoint, not to CKEditor\'s own', async ({ page }) => {
        const { sidebar } = await openRichTextEditor(page);

        const wrapper = sidebar.locator('[data-controller="cb-ckeditor"]');
        await expect(wrapper).toHaveAttribute('data-cb-ckeditor-upload-url-value', '/_content-blocks/upload');

        // The image plugins made it into the build: the toolbar carries the
        // upload button, which only exists when uploads are wired. The sidebar
        // is narrow, so CKEditor collapses most of the toolbar behind its
        // overflow menu — open it before looking.
        await wrapper.locator('.ck-toolbar button[data-cke-tooltip-text="Show more items"]').click();
        await expect(wrapper.locator('button.ck-file-dialog-button')).toHaveCount(1);
    });
});
