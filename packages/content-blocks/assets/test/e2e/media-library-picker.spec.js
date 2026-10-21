import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The media library's picker (klehm/content-blocks-media), a shell fragment
 * answering *Browse library*: upload into the library, find a file again,
 * insert it. Its keys must stay its own while the builder sits behind it.
 */

// A real 120x80 PNG: the library reads its dimensions off the bytes.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

async function createFreshPage(page) {
    const slug = `e2e-picker-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
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

/** A unique name, so files other specs uploaded never match a search. */
function pngNamed(prefix) {
    return {
        name: `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}.png`,
        mimeType: 'image/png',
        buffer: Buffer.from(PNG_BASE64, 'base64'),
    };
}

async function openPicker(page) {
    await page.locator('.cb-image-upload').first().locator('.cb-image-upload__browse').click();
    const dialog = page.locator('dialog.cb-media');
    await expect(dialog).toBeVisible();

    return dialog;
}

async function uploadInPicker(dialog, file) {
    await dialog.locator('[data-cb-media-file]').setInputFiles(file);
    const title = file.name.replace(/\.png$/, '');
    const item = dialog.locator('.cb-media__item', { hasText: title });
    await expect(item).toHaveAttribute('aria-pressed', 'true');

    return item;
}

test('a file uploaded in the picker is selected, then inserted', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    const frame = await addBlock(page, /^Image$/);

    const dialog = await openPicker(page);
    await expect(dialog.locator('.cb-media__title')).toHaveText(/image/i);

    const file = pngNamed('picker-upload');
    await uploadInPicker(dialog, file);
    await expect(dialog.locator('[data-cb-media-selection]')).toContainText('120 × 80');

    await dialog.locator('[data-cb-media-insert]').click();
    await expect(dialog).toBeHidden();

    const hidden = page.locator('.cb-image-upload').first().locator('input[type=hidden]');
    await expect(hidden).toHaveValue(/^\/uploads\/.+\.png$/);
    // Saved, not only typed: the draft render shows the picked file.
    const stored = await hidden.inputValue();
    const name = stored.split('/').pop().replace(/\.png$/, '');
    await expect(frame.locator('[data-cb-block-id] img').first()).toHaveAttribute('src', new RegExp(name));
});

test('a file already in the library is found again by search', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);

    const dialog = await openPicker(page);
    const wanted = pngNamed('findme');
    const other = pngNamed('another');
    await uploadInPicker(dialog, wanted);
    await uploadInPicker(dialog, other);
    await dialog.locator('.cb-media__close').click();
    await expect(dialog).toBeHidden();

    await openPicker(page);
    await dialog.locator('[data-cb-media-search]').fill(wanted.name.slice(0, 20));
    const items = dialog.locator('.cb-media__item');
    await expect(items).toHaveCount(1);
    await expect(items.first()).toContainText(wanted.name.replace(/\.png$/, ''));

    await items.first().dblclick();
    await expect(dialog).toBeHidden();
    await expect(page.locator('.cb-image-upload').first().locator('input[type=hidden]')).toHaveValue(/\.png$/);
});

test('the picker lists only the kind of the field that opened it', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^(Vidéo|Video)$/);

    // Other specs filled the library with PNGs: none may show for a video.
    const videoField = page.locator('.cb-image-upload').filter({ has: page.locator('video') });
    await videoField.locator('.cb-image-upload__browse').click();
    const dialog = page.locator('dialog.cb-media');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.cb-media__title')).toHaveText(/vid/i);
    await expect(dialog.locator('[data-cb-media-status]')).not.toHaveText(/Chargement|Loading/);
    await expect(dialog.locator('.cb-media__item img')).toHaveCount(0);
    await expect(dialog.locator('[data-cb-media-file]')).toHaveAttribute('accept', /video/);
});

test('Escape closes the picker, not the Navigator behind it', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);

    await page.locator('.cb-shell__tree-toggle').click();
    await expect(page.locator('.cb-tree')).toBeVisible();

    const dialog = await openPicker(page);
    await page.keyboard.press('Escape');

    await expect(dialog).toBeHidden();
    await expect(page.locator('.cb-tree')).toBeVisible();
});

test('the builder shortcuts do not act behind the picker', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    const frame = await addBlock(page, /^Image$/);

    // Something to paste, so a leaked Ctrl-V would add a block. The sidebar
    // shows the new block already, and a copy yields to a focused field.
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-block-id', /\d+/);
    await page.evaluate(() => document.activeElement?.blur());
    await page.keyboard.press('Control+c');
    await expect(page.locator('.cb-shell__undo')).toBeVisible();

    const dialog = await openPicker(page);
    const item = await uploadInPicker(dialog, pngNamed('shortcut'));
    await item.focus();
    await page.keyboard.press('Control+v');
    await page.waitForTimeout(500);

    await expect(dialog).toBeVisible();
    expect(await frame.locator('[data-cb-block-id]').count()).toBe(1);
});

test('a folder longer than a page loads the rest on scroll', async ({ page }) => {
    test.setTimeout(90_000);
    // Short enough that a page of 40 overflows the zone and its look-ahead.
    await page.setViewportSize({ width: 1280, height: 600 });
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);
    const dialog = await openPicker(page);

    const folder = `scroll-${Date.now()}`;
    page.once('dialog', (d) => d.accept(folder));
    await dialog.locator('[data-cb-media-new-folder]').click();
    await dialog.locator('.cb-media__folder', { hasText: folder }).click();

    // One page is 40 files: 45 make a second one.
    await dialog.locator('[data-cb-media-main]').evaluate((zone, base64) => {
        const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
        const dt = new DataTransfer();
        for (let i = 1; i <= 45; i++) dt.items.add(new File([bytes], `scroll-${i}.png`, { type: 'image/png' }));
        for (const type of ['dragenter', 'dragover', 'drop']) {
            zone.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt }));
        }
    }, PNG_BASE64);
    await expect(dialog.locator('.cb-media__item[aria-pressed="true"]')).not.toHaveCount(0, { timeout: 60_000 });

    // Reopened, the folder shows its first page only.
    await dialog.locator('[data-cb-media-trail] button').first().click();
    const lists = [];
    page.on('request', (r) => { if (r.url().includes('/admin/media/list')) lists.push(new URL(r.url()).searchParams.get('page')); });
    await dialog.locator('.cb-media__folder', { hasText: folder }).click();
    await expect(dialog.locator('.cb-media__item')).toHaveCount(40);

    await dialog.locator('[data-cb-media-main]').evaluate((zone) => { zone.scrollTop = zone.scrollHeight; });
    await expect(dialog.locator('.cb-media__item')).toHaveCount(45);
    expect(lists).toEqual(['1', '2']);
});

test('a refused upload is said in the editor\'s language, in view', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);
    const dialog = await openPicker(page);

    await dialog.locator('[data-cb-media-file]').setInputFiles({
        name: 'notes.txt',
        mimeType: 'text/plain',
        buffer: Buffer.from('not a picture'),
    });

    const notice = dialog.locator('[data-cb-media-notice]');
    await expect(notice).toBeVisible();
    await expect(notice).toContainText("« notes.txt » n'est pas un type de fichier accepté");
    await expect(notice).toHaveClass(/cb-media__notice--error/);
});

test('a file over the upload limit is refused before it is sent', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);
    const dialog = await openPicker(page);
    const limit = Number(await dialog.getAttribute('data-max-size'));
    expect(limit).toBeGreaterThan(0);

    // The endpoint itself: thumbnails of other specs' files are /uploads/ too.
    const endpoint = JSON.parse(await dialog.getAttribute('data-urls')).upload;
    const uploads = [];
    page.on('request', (r) => {
        if (r.method() === 'POST' && new URL(r.url()).pathname === endpoint) uploads.push(r.url());
    });
    await dialog.locator('[data-cb-media-file]').setInputFiles({
        name: 'huge.png',
        mimeType: 'image/png',
        buffer: Buffer.alloc(limit + 1024),
    });

    await expect(dialog.locator('[data-cb-media-notice]')).toContainText('« huge.png » dépasse les');
    expect(uploads).toEqual([]);
});

// Over post_max_size PHP empties the body, the area with it: before, that
// answered "page not found".
test('a body PHP dropped as too large is said as too large', async ({ page }) => {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    await addBlock(page, /^Image$/);
    const dialog = await openPicker(page);

    const answer = await dialog.evaluate(async (node) => {
        const urls = JSON.parse(node.dataset.urls);
        const body = new FormData();
        body.append('area', node.dataset.area);
        body.append('file', new File([new Uint8Array(20 * 1024 * 1024)], 'huge.png', { type: 'image/png' }));
        const response = await fetch(urls.upload, {
            method: 'POST',
            headers: { 'X-CSRF-Token': node.dataset.csrfToken, 'X-Requested-With': 'XMLHttpRequest' },
            body,
        });

        return { status: response.status, body: await response.json() };
    });

    expect(answer.status).toBe(413);
    expect(answer.body.code).toBe('too_large');
    expect(answer.body.error).toContain('Le fichier dépasse les');
});
