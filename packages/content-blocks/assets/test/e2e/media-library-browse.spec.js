import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The core half of the media library: *Browse library* shows only for the
 * kinds a MediaLibraryInterface supports, and what a dialog hands to
 * `pick()` reaches the draft like an upload. The sandbox's E2eMediaLibrary
 * reads the kinds from a cookie; the dialog is a listener the test installs.
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
    await launchBuilder(page);
    await addBlock(page, /^Image$/);

    await expect(page.locator('.cb-image-upload').first()).toBeVisible();
    await expect(page.locator('.cb-image-upload__browse')).toHaveCount(0);
});

test('a picked file fills the field and its empty alt, then saves', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await withLibrary(page, 'image');
    await launchBuilder(page);
    const frame = await addBlock(page, /^Image$/);

    // Stands for the dialog a library ships as a shell fragment.
    await page.evaluate(() => {
        document.addEventListener('cb:media:browse', (event) => {
            event.preventDefault();
            window.__browsed = { kind: event.detail.kind, value: event.detail.value };
            event.detail.pick({ path: '/uploads/library/logo.png', alt: 'Our logo' });
        });
    });

    const widget = page.locator('.cb-image-upload').first();
    await widget.locator('.cb-image-upload__browse').click();

    expect(await page.evaluate(() => window.__browsed)).toEqual({ kind: 'image', value: '' });
    await expect(widget.locator('input[type=hidden]')).toHaveValue('/uploads/library/logo.png');
    await expect(page.locator('input[name$="[alt]"]').first()).toHaveValue('Our logo');

    // The draft render is the proof both fields were saved, not just typed.
    await expect(frame.locator('[data-cb-block-id] img').first()).toHaveAttribute('alt', 'Our logo');
    await expect(frame.locator('[data-cb-block-id] img').first()).toHaveAttribute('src', /logo\.png/);
});

test('an unclaimed browse says no library answered', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await withLibrary(page, 'image');
    await launchBuilder(page);
    await addBlock(page, /^Image$/);

    const widget = page.locator('.cb-image-upload').first();
    await widget.locator('.cb-image-upload__browse').click();

    await expect(widget.locator('.cb-upload-status--error')).toBeVisible();
    await expect(widget.locator('input[type=hidden]')).toHaveValue('');
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
