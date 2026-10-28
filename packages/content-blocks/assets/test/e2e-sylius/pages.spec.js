import { test, expect } from '../e2e/helpers/test.js';
import { launchBuilder } from '../e2e/helpers/builder.js';
import { loginAsAdmin } from './helpers.js';

test.describe('Sylius: CMS pages', () => {
    test('a page created in the admin is built, published and served by slug', async ({ page, browser }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/content-blocks/content-pages/new');
        const code = 'e2e_' + Date.now();
        await page.locator('#content_blocks_sylius_content_page_code').fill(code);
        await page.getByLabel('Fashion Web Store').check();
        await page.locator('#content_blocks_sylius_content_page_translations_en_US_title').fill('Spring lookbook ' + code);
        await page.getByRole('button', { name: 'Create' }).click();

        await expect(page).toHaveURL(/\/edit$/);
        const frame = await launchBuilder(page);
        await expect(frame.locator('.cb-sylius-page')).toBeAttached();

        await page.locator('.cb-shell').getByRole('button', { name: 'Full width' }).first().click();
        await frame.getByRole('button', { name: '+ Block' }).click();
        await frame.getByRole('button', { name: 'Button', exact: true }).click();
        // The kit's button, in the shop's Bootstrap markup.
        await expect(frame.locator('.cb-block--button a.btn.btn-primary')).toBeVisible();

        await page.locator('.cb-shell').getByRole('button', { name: 'Publish', exact: true }).click();
        await expect(page.locator('.cb-shell').getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();

        const visitor = await browser.newPage();
        await visitor.goto(new URL('/en_US/pages/spring-lookbook-' + code.replace('_', '-'), page.url()).href);
        await expect(visitor.locator('.cb-sylius-page .cb-block--button a.btn')).toBeVisible();
        await visitor.close();
    });
});
