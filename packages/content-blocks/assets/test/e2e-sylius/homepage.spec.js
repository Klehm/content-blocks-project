import { test, expect } from '../e2e/helpers/test.js';
import { launchBuilder } from '../e2e/helpers/builder.js';
import { loginAsAdmin, openSlot, pickFromAutocomplete } from './helpers.js';

test.describe('Sylius: the homepage built with the builder', () => {
    test('chosen products replace the native homepage once published', async ({ page, browser }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/content-blocks/homepage');
        await page.locator('[data-test-content-blocks-homepages] a[href$="/homepage/FASHION_WEB"]').click();

        // Opening the screen writes nothing: the slot is created on request.
        await openSlot(page);
        const frame = await launchBuilder(page);

        // The native homepage is still under the empty draft.
        await expect(frame.locator('[data-test-latest-products]')).toBeVisible();
        // Gone after any reload: catalog blocks must be swapped in place.
        const preview = page.locator('.cb-shell__iframe');
        await preview.evaluate((iframe) => { iframe.contentWindow.cbE2eMark = true; });
        const notReloaded = () => preview.evaluate((iframe) => iframe.contentWindow.cbE2eMark === true);

        await page.locator('.cb-shell').getByRole('button', { name: 'Full width' }).first().click();
        await frame.getByRole('button', { name: '+ Block' }).click();
        await frame.getByRole('button', { name: 'Products', exact: true }).click();

        await expect(frame.locator('.cb-block--product_list')).toBeVisible();

        await frame.locator('.cb-block--product_list').click();
        const sidebar = page.locator('[data-cb-sidebar-block-id]');
        await sidebar.locator('select[name$="[source]"]').selectOption('manual');
        await pickFromAutocomplete(page, sidebar.locator('select[name$="[products][]"]'), 'Beach Cap', 'Bold Beach Cap');

        await expect(frame.locator('.cb-sylius-products [data-test-product-name]')).toHaveText(['Bold Beach Cap']);

        // A carousel gets the core slider's controls, still without a reload.
        for (const name of ['Skyline Bliss Jeans', 'Palm Paradise Jeans']) {
            await pickFromAutocomplete(page, sidebar.locator('select[name$="[products][]"]'), name, name);
        }
        await sidebar.locator('select[name$="[layout]"]').selectOption('carousel');
        await sidebar.locator('select[name$="[columns]"]').selectOption('2');
        await expect(frame.locator('.cb-sylius-products--carousel .cb-slider__arrow--next')).toBeVisible();
        expect(await notReloaded()).toBe(true);

        await page.locator('.cb-shell').getByRole('button', { name: 'Publish', exact: true }).click();
        await expect(page.locator('.cb-shell').getByRole('button', { name: 'Publish', exact: true })).toBeDisabled();

        const visitor = await browser.newPage();
        await visitor.goto(new URL('/en_US/', page.url()).href);
        await expect(visitor.locator('.cb-sylius-products [data-test-product-name]').first()).toHaveText('Bold Beach Cap');
        await expect(visitor.locator('[data-test-latest-products]')).toHaveCount(0);
        const next = visitor.locator('.cb-sylius-products--carousel .cb-slider__arrow--next');
        await expect(visitor.locator('.cb-slider__arrow--prev')).toBeDisabled();
        await next.click();
        await expect(visitor.locator('.cb-slider__arrow--prev')).toBeEnabled();
        await visitor.close();
    });
});
