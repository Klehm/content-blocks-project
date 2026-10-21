import { test, expect } from '../e2e/helpers/test.js';
import { launchBuilder } from '../e2e/helpers/builder.js';
import { loginAsAdmin, openSlot } from './helpers.js';

test.describe('Sylius: product content', () => {
    test('the product edit screen leads to its builder, previewed on the product page', async ({ page }) => {
        await loginAsAdmin(page);
        await page.goto('/admin/products/?criteria[search][value]=Bold+Beach+Cap');
        await page.locator('table a[href$="/edit"]').first().click();

        await page.locator('[data-test-content-blocks-product]').click();
        await expect(page).toHaveURL(/\/content-blocks\/slots\/product\//);
        await openSlot(page);

        const frame = await launchBuilder(page);
        // The preview is the product page itself, its native details intact.
        await expect(frame.locator('[data-test-product-name]').first()).toContainText('Bold Beach Cap');
        await expect(frame.locator('.cb-sylius-slot--product')).toBeAttached();
    });
});
