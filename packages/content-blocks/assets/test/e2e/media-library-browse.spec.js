import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The core half of the media library: *Browse library* shows only for the
 * kinds a MediaLibraryInterface supports. The sandbox's E2eMediaLibrary
 * reads the kinds from a cookie; media-library-picker.spec.js drives the
 * dialog that answers the button.
 */

async function createFreshPage(page) {
    const slug = `e2e-media-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function withLibrary(page, kinds) {
    const origin = new URL(page.url() === 'about:blank' ? 'http://127.0.0.1' : page.url()).origin;
    await page.context().addCookies([{ name: 'cb_e2e_media', value: kinds, url: origin }]);
}

async function addBlock(page, label) {
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: label }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    return frame;
}

test('no library, no browse button', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await withLibrary(page, 'none');
    await launchBuilder(page);
    await addBlock(page, /^Image$/);

    await expect(page.locator('.cb-image-upload').first()).toBeVisible();
    await expect(page.locator('.cb-image-upload__browse')).toHaveCount(0);
});

test('the button follows the kinds the library supports', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await withLibrary(page, 'image');
    await launchBuilder(page);
    await addBlock(page, /^(Vidéo|Video)$/);

    // The video block holds a video file and an image poster.
    const fields = page.locator('.cb-image-upload');
    await expect(fields).toHaveCount(2);
    await expect(fields.filter({ has: page.locator('video') }).locator('.cb-image-upload__browse')).toHaveCount(0);
    await expect(fields.filter({ has: page.locator('img') }).locator('.cb-image-upload__browse')).toHaveCount(1);
});
