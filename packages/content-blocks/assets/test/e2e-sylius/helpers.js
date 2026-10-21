import { expect } from '@playwright/test';

/** Logs in as the fixtures' administrator. */
export async function loginAsAdmin(page) {
    await page.goto('/admin/login');
    await page.getByLabel('Username').fill('sylius');
    await page.getByLabel('Password').fill('sylius');
    await page.getByRole('button', { name: 'Login' }).click();
    await expect(page).toHaveURL(/\/admin\/$/);
}

/** Picks an option of a Tom Select field by searching for it. */
export async function pickFromAutocomplete(page, field, search, label) {
    const control = field.locator('xpath=..').locator('.ts-control');
    await control.click();
    await control.locator('input').fill(search);
    await page.locator('.ts-dropdown .option', { hasText: label }).first().click();
}

/** Creates the slot on its admin screen, unless a retry finds it there. */
export async function openSlot(page) {
    const create = page.locator('[data-test-content-blocks-slot-create]');
    if (await create.count() > 0) {
        await create.click();
    }
    await expect(page.locator('.cb-launcher__button')).toBeVisible();
}
