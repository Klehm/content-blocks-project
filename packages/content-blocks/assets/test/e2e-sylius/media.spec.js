import { test, expect } from '../e2e/helpers/test.js';
import { launchBuilder } from '../e2e/helpers/builder.js';
import { loginAsAdmin } from './helpers.js';

// A real 120x80 PNG: the library reads its dimensions off the bytes.
const PNG_BASE64 = 'iVBORw0KGgoAAAANSUhEUgAAAHgAAABQCAIAAABd+SbeAAAACXBIWXMAAA7EAAAOxAGVKw4bAAAAzElEQVR4nO3QQRHAIADAMEDmhKEJWVOx8liioNf57DP43rod8BdGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOiI0RGjI0ZHjI4YHTE6YnTE6IjREaMjRkeMjhgdMTpidMToiNERoyNGR4yOGB0xOmJ0xOjIC6DdAlg7BAetAAAAAElFTkSuQmCC';

test.describe('Sylius: the media library in the builder', () => {
    test('an administrator uploads into the library and inserts the file', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/content-blocks/content-pages/new');
        const code = 'e2e_media_' + Date.now();
        await page.locator('#content_blocks_sylius_content_page_code').fill(code);
        await page.getByLabel('Fashion Web Store').check();
        await page.locator('#content_blocks_sylius_content_page_translations_en_US_title').fill('Media ' + code);
        await page.getByRole('button', { name: 'Create' }).click();
        await expect(page).toHaveURL(/\/edit$/);

        const frame = await launchBuilder(page);
        await page.locator('.cb-shell').getByRole('button', { name: 'Full width' }).first().click();
        await frame.getByRole('button', { name: '+ Block' }).click();
        await frame.getByRole('button', { name: 'Image', exact: true }).click();
        await expect(frame.locator('.cb-block--image')).toBeAttached();

        await page.locator('.cb-image-upload').first().locator('.cb-image-upload__browse').click();
        const dialog = page.locator('dialog.cb-media');
        await expect(dialog).toBeVisible();
        // A shop administrator manages the library, not only picks from it.
        await expect(dialog.getByRole('button', { name: '+ New folder' })).toBeVisible();

        const name = `${code}.png`;
        await dialog.locator('[data-cb-media-file]').setInputFiles({
            name,
            mimeType: 'image/png',
            buffer: Buffer.from(PNG_BASE64, 'base64'),
        });
        await expect(dialog.locator('.cb-media__item', { hasText: code })).toHaveAttribute('aria-pressed', 'true');
        await dialog.locator('[data-cb-media-insert]').click();
        await expect(dialog).toBeHidden();

        const hidden = page.locator('.cb-image-upload').first().locator('input[type=hidden]');
        await expect(hidden).toHaveValue(/^\/media\/content-blocks_e2e\/.+\.png$/);
        const stored = await hidden.inputValue();
        // Rendered through the shop's LiipImagine, as a sized WebP.
        const img = frame.locator('.cb-block--image img');
        await expect(img).toHaveAttribute('src', new RegExp(`/media/cache/resolve/content_blocks_sylius_w\\d+${stored}$`));
        await expect.poll(() => img.evaluate((node) => node.complete && node.naturalWidth)).toBe(120);
    });
});
