import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * A listener on a "before" event can refuse the action: the builder says why
 * and changes nothing. The sandbox's E2eRefusalListener refuses the actions
 * named by the `cb_e2e_refuse` cookie.
 */

async function createFreshPage(page) {
    const slug = `e2e-refuse-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    return location;
}

async function openBuilder(page, url) {
    await page.goto(url);
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();

    return page.frameLocator('.cb-shell__iframe');
}

async function refuse(page, actions) {
    const { origin } = new URL(page.url());
    await page.context().addCookies([
        { name: 'cb_e2e_refuse', value: actions, url: origin },
    ]);
}

const live = (frame) => frame.locator('[data-cb-block-id]:not([data-cb-deleted])').count();
const snackbarLabel = (page) => page.locator('.cb-shell__undo .cb-shell__undo-label');
const textInput = (page) => page.locator('.cb-shell__sidebar input[name="content_block[text]"]');

async function addTitleBlock(page, frame) {
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await page.waitForTimeout(300);
    const column = frame.locator('[data-cb-column-id]').first();
    await column.hover();
    await column.locator('.cb-add-block-inline').click();
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect.poll(() => live(frame)).toBe(1);
    await page.waitForTimeout(300);
}

async function openBlockSidebar(page, frame) {
    const block = frame.locator('[data-cb-block-id]').first();
    const id = await block.getAttribute('data-cb-block-id');
    const box = await block.boundingBox();
    await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-block-id', id);
    await expect(textInput(page)).toBeVisible();
    // Let the mount settle, so the fill lands in the form that stays.
    await page.waitForTimeout(500);
}

test('a refused publish is said in the snackbar and publishes nothing', async ({ page }) => {
    const url = await createFreshPage(page);
    const frame = await openBuilder(page, url);
    await addTitleBlock(page, frame);
    await refuse(page, 'publish');

    await page.locator('.cb-shell__publish').click();

    await expect(snackbarLabel(page)).toHaveText('Refused by the e2e listener (publish).');
    await expect(page.locator('.cb-shell__publish')).toBeEnabled();
    const publicPage = await page.request.get(url);
    expect(await publicPage.text()).not.toContain('data-cb-block-id');
});

test('a refused delete keeps the block in the preview', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));
    await addTitleBlock(page, frame);
    await refuse(page, 'block.delete');

    await page.locator('.cb-shell__iframe').evaluate((iframe) => {
        iframe.contentDocument.querySelector('[data-cb-block-id]')
            ?.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true }));
    });
    await frame.locator('.cb-overlay-toolbar.is-visible .cb-overlay-toolbar__btn[data-cb-action="delete"]').click();

    await expect(snackbarLabel(page)).toHaveText('Refused by the e2e listener (block.delete).');
    expect(await live(frame)).toBe(1);
});

test('a refused save keeps the draft and says why in the sidebar', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));
    await addTitleBlock(page, frame);
    await openBlockSidebar(page, frame);
    const before = await frame.locator('[data-cb-block-id]').first().innerText();
    await refuse(page, 'block.save');

    await textInput(page).fill('Refused title');
    await textInput(page).blur();

    await expect(page.locator('.cb-shell__sidebar [data-cb-block-refusal]'))
        .toHaveText('Refused by the e2e listener (block.save).');
    await page.waitForTimeout(800);
    expect(await frame.locator('[data-cb-block-id]').first().innerText()).toBe(before);

    // Once the listener lets it through, the next edit saves.
    await page.context().clearCookies({ name: 'cb_e2e_refuse' });
    await textInput(page).fill('Accepted title');
    await textInput(page).blur();
    await expect(page.locator('.cb-shell__sidebar [data-cb-block-refusal]')).toHaveCount(0);
    await expect(frame.locator('[data-cb-block-id]').first()).toContainText('Accepted title');
});
