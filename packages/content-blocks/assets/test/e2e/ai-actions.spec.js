import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * E2E for the AI actions, against the sandbox's offline demo provider
 * (App\Ai\DemoAiProvider): deterministic, so the assertions can name words.
 *
 * What needs a real browser: the ✦ button drawn inside the Live-rendered
 * sidebar, a proposal written only on Replace and then saved by the
 * autosave, generated sections landing in the draft (not on the public page)
 * as one undo step, and each action going away when the provider stops
 * supporting it.
 */

async function createFreshPage(page) {
    const slug = `e2e-ai-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    const id = location.match(/(\d+)\/?$/)?.[1];

    return { url: location, publicUrl: `/page/${id}` };
}

async function openBuilder(page, url) {
    await page.goto(url);

    return launchBuilder(page);
}

const liveSections = (frame) => frame.locator('[data-cb-section-id]:not([data-cb-deleted])').count();

async function addBlock(page, frame, label) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => liveSections(frame)).toBe(1);
    const column = frame.locator('[data-cb-column-id]').first();
    await column.hover();
    await column.locator('.cb-add-block-inline').click();
    await frame.locator('.cb-overlay-popover button', { hasText: label }).click();
    await expect(page.locator('[data-cb-sidebar-block-id]')).toBeAttached();
}

test.describe('AI actions', () => {
    test('adds a page of content into the draft, as one undo step', async ({ page }) => {
        const { url, publicUrl } = await createFreshPage(page);
        const frame = await openBuilder(page, url);

        await page.locator('.cb-shell__ai').click();
        const dialog = page.locator('.cb-ai-dialog');
        await expect(dialog).toBeVisible();
        await dialog.locator('textarea').fill('Our trail running shoe');
        await dialog.locator('input[value="add"]').check();
        await dialog.locator('.cb-ai-dialog__submit').click();

        await expect(dialog).toBeHidden();
        await expect(frame.locator('h1', { hasText: 'Our trail running shoe' })).toBeVisible();
        await expect(frame.locator('h2', { hasText: 'Why choose it' })).toBeVisible();
        await expect.poll(() => liveSections(frame)).toBe(4);

        // A draft: the public page has none of it until Publish.
        const published = await page.request.get(publicUrl);
        expect(published.status()).toBe(200);
        expect(await published.text()).not.toContain('Why choose it');

        await page.locator('.cb-shell__history-btn--undo').click();
        await expect.poll(() => liveSections(frame)).toBe(0);
    });

    test('revises one section in its place, from its toolbar', async ({ page }) => {
        const { url } = await createFreshPage(page);
        const frame = await openBuilder(page, url);

        await page.locator('.cb-shell__ai').click();
        await page.locator('.cb-ai-dialog textarea').fill('Our trail running shoe');
        await page.locator('.cb-ai-dialog input[value="add"]').check();
        await page.locator('.cb-ai-dialog__submit').click();
        await expect.poll(() => liveSections(frame)).toBe(4);
        const headings = () => frame.locator('[data-cb-section-id]:not([data-cb-deleted]) h1, '
            + '[data-cb-section-id]:not([data-cb-deleted]) h2').allTextContents();
        const before = await headings();

        // The second section's own toolbar: ✦ opens the dialog on it.
        const second = frame.locator('[data-cb-section-id]:not([data-cb-deleted])').nth(1);
        await second.click({ position: { x: 5, y: 5 } });
        await frame.locator('.cb-overlay-toolbar.is-visible [data-cb-action="ai-edit"]').click();
        const dialog = page.locator('.cb-ai-dialog');
        await expect(dialog.locator('input[value="edit_section"]')).toBeChecked();
        await dialog.locator('textarea').fill('Make it punchier');
        await dialog.locator('.cb-ai-dialog__submit').click();
        await expect(dialog).toBeHidden();

        // Same place, same neighbours; only that section changed.
        await expect.poll(headings).toEqual([before[0], `✦ ${before[1]}`, ...before.slice(2)]);
        await expect.poll(() => liveSections(frame)).toBe(4);

        await page.locator('.cb-shell__history-btn--undo').click();
        await expect.poll(headings).toEqual(before);
    });

    test('revises the whole page from what is there', async ({ page }) => {
        const { url } = await createFreshPage(page);
        const frame = await openBuilder(page, url);
        await page.locator('.cb-shell__ai').click();
        await page.locator('.cb-ai-dialog textarea').fill('Our trail running shoe');
        await page.locator('.cb-ai-dialog input[value="add"]').check();
        await page.locator('.cb-ai-dialog__submit').click();
        await expect.poll(() => liveSections(frame)).toBe(4);

        await page.locator('.cb-shell__ai').click();
        await page.locator('.cb-ai-dialog textarea').fill('Warmer tone everywhere');
        await page.locator('.cb-ai-dialog input[value="edit_page"]').check();
        await page.locator('.cb-ai-dialog__submit').click();

        await expect(frame.locator('h1', { hasText: '✦ Our trail running shoe' })).toBeVisible();
        await expect(frame.locator('h2', { hasText: '✦ Why choose it' })).toBeVisible();
        await expect.poll(() => liveSections(frame)).toBe(4);
    });

    test('rewrites a title only once the editor replaces it', async ({ page }) => {
        const { url } = await createFreshPage(page);
        const frame = await openBuilder(page, url);
        await addBlock(page, frame, /^(Titre|Title)$/);

        const input = page.locator('.cb-shell__sidebar input[name$="[text]"]');
        await input.fill('A long title. With a second sentence.');
        await expect(frame.locator('[data-cb-block-id]').first()).toContainText('A long title.');

        await page.locator('.cb-form-row--ai .cb-ai-trigger').first().click();
        await page.locator('.cb-ai-popover [data-cb-ai-action="shorten"]').click();
        await expect(page.locator('.cb-ai-popover__text')).toHaveText('A long title.');
        await expect(input).toHaveValue('A long title. With a second sentence.');

        await page.locator('.cb-ai-popover__btn--primary').click();
        await expect(input).toHaveValue('A long title.');
        await expect(frame.locator('[data-cb-block-id]').first()).not.toContainText('second sentence');
    });

    test('describes the image beside an alt field', async ({ page }) => {
        const { url } = await createFreshPage(page);
        const frame = await openBuilder(page, url);
        await addBlock(page, frame, /^Image$/);

        // The path the field would hold after an upload.
        await page.locator('.cb-shell__sidebar input[type="hidden"][name$="[src]"]')
            .evaluate((el) => { el.value = '/uploads/red-trail-shoe-6650f1c2.webp'; });

        await page.locator('.cb-ai-trigger[data-cb-builder-kind-param="alt"]').click();
        await page.locator('.cb-ai-popover [data-cb-ai-action="alt_text"]').click();
        await expect(page.locator('.cb-ai-popover__text')).toHaveText('Red trail shoe');
        await page.locator('.cb-ai-popover__btn--primary').click();

        await expect(page.locator('.cb-shell__sidebar input[name$="[alt]"]')).toHaveValue('Red trail shoe');
    });

    test('an action the provider does not support is not offered', async ({ page, context, baseURL }) => {
        await context.addCookies([{
            name: 'cb_e2e_ai_off',
            value: 'add,edit_section,edit_page,rewrite,shorten,tone',
            url: baseURL,
        }]);
        const { url } = await createFreshPage(page);
        const frame = await openBuilder(page, url);

        await expect(page.locator('.cb-shell__ai')).toHaveCount(0);
        await expect(page.locator('.cb-ai-dialog')).toHaveCount(0);

        await addBlock(page, frame, /^(Titre|Title)$/);
        await expect(page.locator('.cb-shell__sidebar input[name$="[text]"]')).toBeVisible();
        await expect(page.locator('.cb-ai-trigger')).toHaveCount(0);
        await frame.locator('[data-cb-section-id]').first().click({ position: { x: 5, y: 5 } });
        await expect(frame.locator('.cb-overlay-toolbar.is-visible [data-cb-action="delete"]')).toBeVisible();
        await expect(frame.locator('.cb-overlay-toolbar [data-cb-action="ai-edit"]')).toHaveCount(0);

        // The endpoint is gone too, not only the button.
        const csrf = await page.locator('.cb-shell').getAttribute('data-cb-csrf-token');
        const api = await page.locator('.cb-shell').getAttribute('data-cb-api-base');
        const areaId = await page.locator('.cb-shell').getAttribute('data-cb-area-id');
        const response = await page.request.post(`${api}/area/${areaId}/ai/content`, {
            headers: { 'X-CSRF-Token': csrf },
            data: { action: 'add', prompt: 'x' },
        });
        expect(response.status()).toBe(404);
    });
});
