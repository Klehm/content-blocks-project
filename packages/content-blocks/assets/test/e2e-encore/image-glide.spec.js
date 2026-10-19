import { test, expect } from '@playwright/test';
import { launchBuilder } from '../e2e/helpers/builder.js';

/**
 * The Glide recipe (docs/guide/recipes/glide.md), run for real: this sandbox
 * aliases `ImageUrlResolverInterface` to `App\Image\GlideImageUrlResolver`.
 * The LiipImagine twin is `e2e/image-optimization.spec.js`.
 */

// 120×80 PNG: smaller than every candidate, so `fit=max` must not upscale it.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

async function createPage(page, title) {
    const response = await page.request.post('/page/create', { form: { title }, maxRedirects: 0 });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

test('an uploaded image is served as signed WebP variants by Glide', async ({ page }) => {
    await page.goto(await createPage(page, 'Encore — Glide'));
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    const form = page.locator('aside[data-cb-builder-target="sidebar"] .cb-block__edit-form');
    const widget = form.locator('.cb-image-upload').first();
    await widget.locator('.cb-image-upload__file').setInputFiles({
        name: 'photo.png',
        mimeType: 'image/png',
        buffer: Buffer.from(PNG_BASE64, 'base64'),
    });
    await expect(widget.locator('input[type=hidden]')).toHaveValue(/^\/uploads\/.+\.png$/);
    // Wait for autosave: the preview renders the image once the draft has it.
    await expect.poll(() => frame.locator('.cb-kit-image__img').count(), { timeout: 10000 }).toBe(1);

    const publish = page.locator('.cb-shell__publish');
    await publish.click();
    await expect(publish).toBeDisabled();

    await page.goto(page.url().replace('/admin/page/', '/page/'));
    const img = page.locator('.cb-kit-image__img');
    await expect(img).toHaveCount(1);

    const srcset = await img.getAttribute('srcset');
    const candidates = srcset.split(',').map((c) => c.trim().split(' '));
    // An md image: 800px box, candidates capped at 2×.
    expect(candidates.map(([, w]) => w)).toEqual(['400w', '800w', '1200w', '1600w']);
    for (const [url] of candidates) {
        expect(url).toMatch(/^\/img\/.+\.png\?.*fm=webp.*&s=[0-9a-f]{32}$/);
    }
    expect(await img.getAttribute('src')).toContain('w=800');
    expect(await img.getAttribute('sizes')).toBe('(max-width: 800px) 100vw, 800px');

    const response = await page.request.get(candidates[0][0]);
    expect(response.status()).toBe(200);
    expect(response.headers()['content-type']).toBe('image/webp');
    const bytes = await response.body();
    expect(bytes.subarray(0, 4).toString('ascii')).toBe('RIFF');
    expect(bytes.subarray(8, 12).toString('ascii')).toBe('WEBP');

    // Decodable, and not blown up past its 120px original.
    const [width] = await img.evaluate((el) => el.decode()
        .then(() => [el.naturalWidth]).catch(() => [0]));
    expect(width).toBe(120);

    // The signature pins the parameters: any other width is refused.
    const forged = candidates[0][0].replace('w=400', 'w=3000');
    expect((await page.request.get(forged)).status()).toBe(403);
    const unsigned = candidates[0][0].replace(/&s=[0-9a-f]+$/, '');
    expect((await page.request.get(unsigned)).status()).toBe(403);
});
