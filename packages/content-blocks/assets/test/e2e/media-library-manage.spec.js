import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * Managing the media library from its picker (the sandbox lets every editor
 * manage): folders, an alt text that reaches the block, the pages a file is
 * used on, and removal, which never takes the file from a page.
 */

const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

function unique(prefix) {
    return `${prefix}-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
}

async function createFreshPage(page) {
    const slug = unique('e2e-manage');
    const r = await page.request.post('/page/create', { form: { title: `E2E ${slug}`, slug }, maxRedirects: 0 });
    const location = r.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location;
}

async function builderWithImageBlock(page) {
    await page.goto(await createFreshPage(page));
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    await frame.locator('.cb-add-block-inline').first().click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^Image$/ }).click();
    await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);

    return frame;
}

async function openPicker(page) {
    await page.locator('.cb-image-upload').first().locator('.cb-image-upload__browse').click();
    const dialog = page.locator('dialog.cb-media');
    await expect(dialog).toBeVisible();

    return dialog;
}

async function upload(dialog, name) {
    await dialog.locator('[data-cb-media-file]').setInputFiles({
        name: `${name}.png`,
        mimeType: 'image/png',
        buffer: Buffer.from(PNG_BASE64, 'base64'),
    });
    const item = dialog.locator('.cb-media__item', { hasText: name });
    await expect(item).toHaveAttribute('aria-pressed', 'true');

    return item;
}

/** Answers the next window.prompt / window.confirm. */
function answerNext(page, value) {
    page.once('dialog', (d) => (d.type() === 'prompt' ? d.accept(value) : d.accept()));
}

test('a folder is created, filled by an upload, and left again', async ({ page }) => {
    await builderWithImageBlock(page);
    const dialog = await openPicker(page);
    const folder = unique('Logos');
    const file = unique('in-folder');

    answerNext(page, folder);
    await dialog.locator('[data-cb-media-new-folder]').click();
    await dialog.locator('.cb-media__folder', { hasText: folder }).click();
    await expect(dialog.locator('[data-cb-media-trail] [aria-current]')).toHaveText(folder);

    await upload(dialog, file);

    await dialog.locator('[data-cb-media-trail] button').first().click();
    await expect(dialog.locator('.cb-media__folder', { hasText: folder })).toBeVisible();
    await expect(dialog.locator('.cb-media__item', { hasText: file })).toHaveCount(0);
});

test('the alt written in the library fills the block alt on insert', async ({ page }) => {
    await builderWithImageBlock(page);
    const dialog = await openPicker(page);
    await upload(dialog, unique('with-alt'));

    const alt = dialog.locator('[data-cb-media-field="alt"]');
    const saved = page.waitForResponse((r) => r.url().includes('/admin/media/file/update'));
    await alt.fill('Our team at the office');
    await alt.blur();
    expect((await saved).status()).toBe(200);

    await dialog.locator('[data-cb-media-insert]').click();
    await expect(dialog).toBeHidden();
    await expect(page.locator('input[name$="[alt]"]').first()).toHaveValue('Our team at the office');
});

test('a used file lists its page, and removing it keeps the page whole', async ({ page }) => {
    const frame = await builderWithImageBlock(page);
    const name = unique('used');
    let dialog = await openPicker(page);
    await upload(dialog, name);
    await dialog.locator('[data-cb-media-insert]').click();

    const hidden = page.locator('.cb-image-upload').first().locator('input[type=hidden]');
    await expect(hidden).toHaveValue(/\.png$/);
    const path = await hidden.inputValue();
    // Wait for the draft to hold it: usage reads saved block data.
    await expect(frame.locator('[data-cb-block-id] img').first()).toHaveAttribute('src', new RegExp(path.split('/').pop().replace('.png', '')));

    dialog = await openPicker(page);
    await dialog.locator('[data-cb-media-search]').fill(name);
    await dialog.locator('.cb-media__item', { hasText: name }).click();
    const usage = dialog.locator('[data-cb-media-usage] li');
    await expect(usage).toHaveCount(1);
    // The label is the host's (ContentAreaProviderInterface); the sandbox
    // keeps the default `#id — date`. Unpublished, so marked as a draft.
    await expect(usage.first()).toContainText(/#\d+/);
    await expect(usage.first()).toContainText(/brouillon|draft/);
    await expect(usage.first().locator('a')).toHaveAttribute('href', /.+/);

    let confirmed = '';
    page.once('dialog', (d) => {
        confirmed = d.message();
        d.accept();
    });
    await dialog.locator('[data-cb-media-remove]').click();
    await expect(dialog.locator('.cb-media__item', { hasText: name })).toHaveCount(0);
    expect(confirmed).toContain('1');

    // The row is gone from the library; the file and the page keep it.
    await dialog.locator('.cb-media__close').click();
    await expect(hidden).toHaveValue(path);
    expect((await page.request.get(path)).status()).toBe(200);
});

test('a renamed folder keeps its files, a deleted one hands them to the root', async ({ page }) => {
    await builderWithImageBlock(page);
    const dialog = await openPicker(page);
    const folder = unique('Temp');
    const renamed = unique('Kept');
    const file = unique('orphaned');

    answerNext(page, folder);
    await dialog.locator('[data-cb-media-new-folder]').click();
    await dialog.locator('.cb-media__folder', { hasText: folder }).click();
    await upload(dialog, file);
    await dialog.locator('[data-cb-media-trail] button').first().click();

    answerNext(page, renamed);
    await dialog.locator(`[data-folder-act="rename"][title$="${folder}"]`).click();
    await expect(dialog.locator('.cb-media__folder', { hasText: renamed })).toBeVisible();

    answerNext(page);
    await dialog.locator(`[data-folder-act="delete"][title$="${renamed}"]`).click();
    await expect(dialog.locator('.cb-media__folder', { hasText: renamed })).toHaveCount(0);
    await expect(dialog.locator('.cb-media__item', { hasText: file })).toBeVisible();
});

test('files dropped on the picker are uploaded into the folder shown', async ({ page }) => {
    await builderWithImageBlock(page);
    const dialog = await openPicker(page);
    const folder = unique('Dropped');
    answerNext(page, folder);
    await dialog.locator('[data-cb-media-new-folder]').click();
    await dialog.locator('.cb-media__folder', { hasText: folder }).click();
    await expect(dialog.locator('[data-cb-media-trail] [aria-current]')).toHaveText(folder);

    const names = [unique('drop-a'), unique('drop-b')];
    await dialog.locator('[data-cb-media-main]').evaluate((zone, { names, base64 }) => {
        const bytes = Uint8Array.from(atob(base64), (c) => c.charCodeAt(0));
        const dt = new DataTransfer();
        for (const name of names) dt.items.add(new File([bytes], `${name}.png`, { type: 'image/png' }));
        for (const type of ['dragenter', 'dragover', 'drop']) {
            zone.dispatchEvent(new DragEvent(type, { bubbles: true, cancelable: true, dataTransfer: dt }));
        }
    }, { names, base64: PNG_BASE64 });

    for (const name of names) {
        await expect(dialog.locator('.cb-media__item', { hasText: name })).toHaveAttribute('aria-pressed', 'true');
    }
    await expect(dialog.locator('[data-cb-media-selection]')).toContainText('2');
    await expect(dialog.locator('[data-cb-media-insert]')).toBeDisabled();
});

test('a selection is let go of, and several files are dragged into a folder', async ({ page }) => {
    await builderWithImageBlock(page);
    const dialog = await openPicker(page);
    const folder = unique('Target');
    answerNext(page, folder);
    await dialog.locator('[data-cb-media-new-folder]').click();
    await expect(dialog.locator('.cb-media__folder', { hasText: folder })).toBeVisible();

    const first = await upload(dialog, unique('drag-a'));
    const second = await upload(dialog, unique('drag-b'));

    // A click on the selected file alone lets go of it; Escape does too.
    await second.click();
    await expect(second).toHaveAttribute('aria-pressed', 'false');
    await second.click();
    await page.keyboard.press('Escape');
    await expect(second).toHaveAttribute('aria-pressed', 'false');
    await expect(dialog).toBeVisible();

    await first.click();
    await second.click({ modifiers: ['ControlOrMeta'] });
    await expect(dialog.locator('.cb-media__item[aria-pressed="true"]')).toHaveCount(2);

    const moved = page.waitForResponse((r) => r.url().includes('/admin/media/file/move'));
    await second.dragTo(dialog.locator('.cb-media__folder', { hasText: folder }));
    expect((await moved).status()).toBe(200);
    await expect(first).toHaveCount(0);
    await expect(second).toHaveCount(0);

    await dialog.locator('.cb-media__folder', { hasText: folder }).click();
    await expect(dialog.locator('.cb-media__item')).toHaveCount(2);
});

test('the Actions menu opens the whole library, with nothing to insert', async ({ page }) => {
    await builderWithImageBlock(page);

    await page.locator('.cb-shell__actions-toggle').click();
    await page.getByRole('menuitem', { name: /Bibliothèque de médias|Media library/ })
        .or(page.locator('button', { hasText: /Bibliothèque de médias|Media library/ })).first().click();

    const dialog = page.locator('dialog.cb-media');
    await expect(dialog).toBeVisible();
    await expect(dialog.locator('.cb-media__title')).toHaveText(/Bibliothèque de médias|Media library/);
    await expect(dialog.locator('[data-cb-media-insert]')).toBeHidden();
    await expect(dialog.locator('[data-cb-media-file]')).toHaveAttribute('accept', 'image/*,video/*');

    // Uploading ahead of time works the same way.
    const name = unique('ahead');
    await upload(dialog, name);
    await page.keyboard.press('Escape');
    await page.keyboard.press('Escape');
    await expect(dialog).toBeHidden();

    // Then a field finds it, with Insert back.
    await openPicker(page);
    await expect(dialog.locator('[data-cb-media-insert]')).toBeVisible();
    await dialog.locator('[data-cb-media-search]').fill(name);
    await expect(dialog.locator('.cb-media__item', { hasText: name })).toHaveCount(1);
});
