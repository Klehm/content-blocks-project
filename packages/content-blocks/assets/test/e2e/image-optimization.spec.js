import { test, expect } from '@playwright/test';

/**
 * The sandbox aliases `ImageUrlResolverInterface` to a LiipImagine-backed
 * implementation (see `apps/content-blocks-sandbox/src/Image/`), which is the
 * documented recipe for image optimization. This spec is what keeps the recipe
 * honest: it uploads a picture through the builder's own endpoint and checks
 * that the public page serves WebP variants sized for the box.
 *
 * It is deliberately end-to-end. The seam has unit tests on both sides; what
 * only a browser proves is that the whole chain — upload, resolver, filter set,
 * lazy cache resolution — produces an image a browser actually decodes.
 */

const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

async function createFreshPage(page) {
    const slug = `e2e-imgopt-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

test('an uploaded image is served as WebP variants on the public page', async ({ page }) => {
    const pageUrl = await createFreshPage(page);
    await page.goto(pageUrl);
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    // Upload through the widget so the stored src is a real path under
    // /uploads/ — the only shape the resolver transforms.
    const widget = page.locator('.cb-image-upload').first();
    await widget.locator('.cb-image-upload__file').setInputFiles({
        name: 'photo.png',
        mimeType: 'image/png',
        buffer: Buffer.from(PNG_BASE64, 'base64'),
    });
    await expect(widget.locator('input[type=hidden]')).toHaveValue(/^\/uploads\/.+\.png$/);
    // The upload only writes the field; the draft is what publish commits, so
    // wait for autosave to have carried the value to the server.
    await expect.poll(
        async () => frame.locator('.cb-kit-image__img').count(),
        { timeout: 10000 },
    ).toBe(1);

    // Publish, then read the public page rather than the preview: this is about
    // what a visitor is served.
    const publish = page.locator('.cb-shell__publish');
    await expect(publish).toBeEnabled();
    await publish.click();
    await expect(publish).toBeDisabled();

    // `pageUrl` is the edit form; the public page is what the preview iframe
    // loads, minus its preview flag.
    const previewSrc = await page.locator('.cb-shell__iframe').getAttribute('src');
    await page.goto(new URL(previewSrc, page.url()).pathname);

    const img = page.locator('.cb-kit-image__img');
    await expect(img).toHaveCount(1);

    const srcset = await img.getAttribute('srcset');
    const src = await img.getAttribute('src');
    const sizes = await img.getAttribute('sizes');

    // Four candidates for an md image (800px display, capped at 2×).
    expect(srcset).toContain('/media/cache/');
    expect(srcset.split(',').map((c) => c.trim().split(' ')[1])).toEqual(['400w', '800w', '1200w', '1600w']);
    // src covers the display box without overshooting it.
    expect(src).toContain('cb_w800');
    // The view knows its width, so it derives the sizes the resolver left out.
    expect(sizes).toBe('(max-width: 800px) 100vw, 800px');

    // The variant is really generated, really WebP, and really decodable — a
    // 404 or a broken file would leave naturalWidth at 0.
    const firstCandidate = srcset.split(',')[0].trim().split(' ')[0];
    const response = await page.request.get(firstCandidate);
    expect(response.status()).toBe(200);
    expect(response.body().then ? await response.body() : response.body()).toBeTruthy();
    const bytes = await response.body();
    // WebP files start with "RIFF....WEBP".
    expect(bytes.subarray(0, 4).toString('ascii')).toBe('RIFF');
    expect(bytes.subarray(8, 12).toString('ascii')).toBe('WEBP');

    const decoded = await img.evaluate((el) => el.decode().then(() => [el.naturalWidth, el.naturalHeight]).catch(() => [0, 0]));
    expect(decoded[0]).toBeGreaterThan(0);
});

test('a source the resolver does not own is left untouched', async ({ page }) => {
    // Section templates are the shortest route to a block holding an arbitrary
    // src — here a path served by a controller, not a file under /uploads/.
    const name = `Tpl imgopt ${Date.now()}`;
    await page.request.post('/test-fixtures/section-template', {
        data: {
            name,
            blockTypes: ['image'],
            contentVersion: null,
            payload: {
                format: 'content-blocks/section-v1',
                layout: 'full',
                settings: null,
                columns: [{
                    preset: 'col-12',
                    blocks: [{ type: 'image', data: { src: '/test-fixtures/pixel', alt: '', size: 'md', align: 'center', fit: 'cover' } }],
                }],
            },
        },
    });

    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    const frame = page.frameLocator('.cb-shell__iframe');
    await page.locator('.cb-sidebar-library .cb-template-picker__search').fill(name);
    await expect.poll(() => page.locator('.cb-template-picker__item-btn').count()).toBe(1);
    await page.locator('.cb-template-picker__item-btn').first().click();
    await expect.poll(() => frame.locator('.cb-kit-image__img').count()).toBe(1);

    const img = frame.locator('.cb-kit-image__img');
    await expect(img).toHaveAttribute('src', '/test-fixtures/pixel');
    expect(await img.getAttribute('srcset')).toBeNull();
});
