import { test, expect } from './helpers/test.js';
import { launchBuilder } from './helpers/builder.js';

/**
 * The add-block picker groups its tiles. The sandbox lists `text` then a host
 * group ("Mise en avant", holding the kit's card and alert through
 * `picker.blocks`), and subclasses the kit's divider without naming a
 * category, so it must keep the kit's "layout". Its counter block declares
 * none and lands in "other", last.
 */

async function openPicker(page) {
    const slug = `e2e-picker-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');

    await page.goto(location);
    await launchBuilder(page);
    const frame = page.frameLocator('.cb-shell__iframe');
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(1);
    await frame.locator('.cb-add-block-inline').first().click();
    await expect(frame.locator('.cb-overlay-popover')).toBeVisible();

    return frame;
}

function group(frame, name) {
    return frame.locator(`.cb-overlay-popover__group[data-cb-category="${name}"]`);
}

test.describe('block picker categories', () => {
    test('groups the tiles, listed groups first, then the built-ins', async ({ page }) => {
        const frame = await openPicker(page);

        const names = await frame.locator('.cb-overlay-popover__group')
            .evaluateAll((els) => els.map((el) => el.dataset.cbCategory));
        expect(names).toEqual(['text', 'highlights', 'media', 'layout', 'actions', 'advanced', 'other']);

        await expect(group(frame, 'highlights').locator('.cb-overlay-popover__group-title'))
            .toHaveText('Mise en avant');
        await expect(group(frame, 'highlights').getByRole('button'))
            .toHaveText([/Carte|Card/, /Alerte|Alert/]);
        // The group carries its label for assistive tech.
        await expect(frame.getByRole('group', { name: 'Mise en avant' })).toBeVisible();

        await expect(group(frame, 'text').getByRole('button', { name: /^(Titre|Title)$/ }))
            .toBeVisible();
        await expect(group(frame, 'media').getByRole('button', { name: /^Image$/ }))
            .toBeVisible();
    });

    test('a subclassed kit block keeps the kit\'s group', async ({ page }) => {
        const frame = await openPicker(page);

        await expect(group(frame, 'layout').getByRole('button', { name: /Séparateur|Divider/ }))
            .toBeVisible();
    });

    test('a block without a category lands in "other"', async ({ page }) => {
        const frame = await openPicker(page);

        await expect(group(frame, 'other').getByRole('button'))
            .toHaveText(['Compteur']);
        await expect(group(frame, 'other').locator('.cb-overlay-popover__group-title'))
            .toHaveText('Autres');
    });

    test('a tile in a group still adds its block', async ({ page }) => {
        const frame = await openPicker(page);

        await group(frame, 'highlights').getByRole('button', { name: /Alerte|Alert/ }).click();

        await expect.poll(() => frame.locator('[data-cb-block-id]').count()).toBe(1);
        await expect(frame.locator('.cb-overlay-popover')).toBeHidden();
    });
});
