import { test, expect } from '@playwright/test';

/**
 * Dropping a file anywhere on an image field uploads it, exactly as picking it
 * through the file dialog would.
 *
 * The controller's logic is unit-tested; what only a browser can prove is the
 * wiring — that the widget carries the drag actions, that the drop reaches the
 * builder's upload endpoint with a valid CSRF token, and that the resulting
 * path lands in the model-bound hidden input so autosave persists it.
 */

async function createFreshPage(page) {
    const slug = `e2e-drop-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

// A real 120x80 PNG: the preview is only as tall as the picture it decodes, so
// a fixture with a valid header but corrupt pixels would render a 0px box and
// fail the visibility assertion for the wrong reason.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

/** Dispatches a real drop carrying `file` onto the given selector. */
async function dropFile(page, selector, { name, type, base64 }) {
    await page.locator(selector).evaluate(async (el, f) => {
        const bytes = Uint8Array.from(atob(f.base64), (c) => c.charCodeAt(0));
        const file = new File([bytes], f.name, { type: f.type });
        const dt = new DataTransfer();
        dt.items.add(file);

        for (const kind of ['dragenter', 'dragover', 'drop']) {
            el.dispatchEvent(new DragEvent(kind, { bubbles: true, cancelable: true, dataTransfer: dt }));
        }
    }, { name, type, base64 });
}

test('a dropped image uploads and fills the field', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const widget = page.locator('.cb-image-upload').first();
    await expect(widget).toBeVisible();
    const hidden = widget.locator('input[type=hidden]');
    await expect(hidden).toHaveValue('');

    const upload = page.waitForResponse((r) => r.url().includes('/_content-blocks/upload'));
    await dropFile(page, '.cb-image-upload', { name: 'dropped.png', type: 'image/png', base64: PNG_BASE64 });

    expect((await upload).status()).toBe(200);
    // LocalFileStorage hashes the name, so assert the shape, not the filename.
    await expect(hidden).toHaveValue(/^\/uploads\/.+\.png$/);
    await expect(widget.locator('img.cb-thumbnail')).toBeVisible();
});

test('a pasted path fills the field without an upload, and remove clears it', async ({ page }) => {
    const uploads = [];
    page.on('request', (r) => {
        if (r.url().includes('/_content-blocks/upload')) uploads.push(r.url());
    });

    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();

    const widget = page.locator('.cb-image-upload').first();
    const hidden = widget.locator('input[type=hidden]');
    const path = widget.locator('.cb-image-upload__path');
    await expect(widget).toBeVisible();
    await expect(path).toBeHidden();

    await widget.locator('.cb-image-upload__path-toggle').click();
    await expect(path).toBeVisible();

    // An image that already exists — the whole point of the escape hatch is
    // that referencing it costs no upload.
    await path.fill('/uploads/already-there.png');
    await path.press('Enter');
    await expect(hidden).toHaveValue('/uploads/already-there.png');
    expect(uploads).toHaveLength(0);

    // Same-origin URLs are stored as their path, so the value survives a
    // domain change.
    await path.fill(`${new URL(page.url()).origin}/uploads/pasted.png`);
    await path.press('Enter');
    await expect(path).toHaveValue('/uploads/pasted.png');
    await expect(hidden).toHaveValue('/uploads/pasted.png');

    await widget.locator('button', { hasText: 'Retirer' }).click();
    await expect(hidden).toHaveValue('');
    await expect(widget).toHaveClass(/cb-image-upload--empty/);
});

test('a drag that carries no file leaves the widget alone', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();

    const widget = page.locator('.cb-image-upload').first();
    await expect(widget).toBeVisible();

    // Reordering a collection row drags plain data across the sidebar; the
    // image field must not light up as if a file were incoming.
    await widget.evaluate((el) => {
        const dt = new DataTransfer();
        dt.setData('text/plain', 'row-3');
        el.dispatchEvent(new DragEvent('dragenter', { bubbles: true, cancelable: true, dataTransfer: dt }));
    });

    await expect(widget).not.toHaveClass(/cb-image-upload--dragging/);
});
