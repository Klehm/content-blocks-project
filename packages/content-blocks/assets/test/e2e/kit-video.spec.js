import { test, expect } from '@playwright/test';

/**
 * The kit's video block: a pasted path reaches both the sidebar's <video>
 * preview and the rendered player, and the playback options follow autoplay.
 */

async function createFreshPage(page) {
    const slug = `e2e-video-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

test('a video block renders a native player from a pasted path', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');

    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Vidéo|Video)$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    // No file yet: the view says so instead of an empty player.
    await expect(frame.locator('.cb-kit-video__player')).toHaveCount(0);

    const widget = page.locator('.cb-image-upload').first();
    await expect(widget).toBeVisible();
    await expect(widget.locator('input[type=file]')).toHaveAttribute('accept', /video\/mp4/);

    await widget.locator('.cb-image-upload__path-toggle').click();
    const path = widget.locator('.cb-image-upload__path');
    await path.fill('/uploads/content-blocks/clip.mp4');
    await path.press('Enter');

    // The sidebar previews a video, not a broken <img>.
    await expect(widget.locator('video.cb-thumbnail'))
        .toHaveAttribute('src', '/uploads/content-blocks/clip.mp4');
    await expect(widget.locator('img.cb-thumbnail')).toHaveCount(0);

    const player = frame.locator('video.cb-kit-video__player');
    await expect(player).toHaveAttribute('src', '/uploads/content-blocks/clip.mp4');
    await expect(player).toHaveAttribute('controls', '');

    // Muting is only a choice without autoplay; controls only with it.
    const form = page.locator('.cb-shell__sidebar');
    const muted = form.locator('input[type=checkbox][name$="[muted]"]');
    const controls = form.locator('input[type=checkbox][name$="[controls]"]');
    await expect(muted).toBeVisible();
    await expect(controls).toBeHidden();

    await form.locator('input[type=checkbox][name$="[autoplay]"]').check();
    await expect(muted).toBeHidden();
    await expect(controls).toBeVisible();

    await expect(player).toHaveAttribute('autoplay', '');
    await expect(player).toHaveAttribute('muted', '');
    await expect(player).toHaveAttribute('playsinline', '');
});
