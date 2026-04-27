import { test, expect } from '@playwright/test';

/**
 * Helper: create a fresh page and navigate to its builder.
 */
async function createPageAndGo(page) {
    await page.request.post('/page/create', {
        form: { title: `Test ${Date.now()}`, slug: `test-${Date.now()}` },
    });
    // Get the page ID from the redirect
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${Date.now()}`, slug: `e2e-${Date.now()}` },
    });
    const url = response.url();
    await page.goto(url);
    return url;
}

/**
 * Helper: add sections via UI buttons.
 */
async function addSection(page, layout) {
    const buttonText = {
        full: '1 colonne',
        two_cols: '2 colonnes',
        three_cols: '3 colonnes',
    };
    await page.getByRole('button', { name: buttonText[layout] }).click();
    // Wait for Live Component re-render
    await page.waitForTimeout(500);
}

/**
 * Helper: get all section labels in order.
 */
async function getSectionLayouts(page) {
    const sections = page.locator('.cb-section');
    const count = await sections.count();
    const layouts = [];
    for (let i = 0; i < count; i++) {
        layouts.push(await sections.nth(i).getAttribute('data-layout'));
    }
    return layouts;
}

test.describe('Section: add sections', () => {
    test('should add 1-col, 2-cols, 3-cols sections via buttons', async ({ page }) => {
        await createPageAndGo(page);

        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        await expect(page.locator('.cb-section')).toHaveCount(3);

        // Verify column counts
        const sections = page.locator('.cb-section');
        await expect(sections.nth(0).locator('.cb-column')).toHaveCount(1);
        await expect(sections.nth(1).locator('.cb-column')).toHaveCount(2);
        await expect(sections.nth(2).locator('.cb-column')).toHaveCount(3);
    });

    test('sections should persist after reload', async ({ page }) => {
        await createPageAndGo(page);

        await addSection(page, 'full');
        await addSection(page, 'three_cols');
        await page.waitForTimeout(300);

        await page.reload();

        await expect(page.locator('.cb-section')).toHaveCount(2);
        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'three_cols']);
    });
});

test.describe('Section: grab handle', () => {
    test('should display grab handle on each section', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const handles = page.locator('.cb-section__handle');
        await expect(handles).toHaveCount(2);
    });

    test('grab handle should persist after moveUp', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        // Move second section up
        const moveUpButtons = page.locator('[data-action="cb-section-move#moveUp"]');
        await moveUpButtons.nth(1).click();
        await page.waitForTimeout(300);

        // All handles still present
        await expect(page.locator('.cb-section__handle')).toHaveCount(2);
    });

    test('grab handle should persist after moveDown', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const moveDownButtons = page.locator('[data-action="cb-section-move#moveDown"]');
        await moveDownButtons.first().click();
        await page.waitForTimeout(300);

        await expect(page.locator('.cb-section__handle')).toHaveCount(2);
    });
});

test.describe('Section: moveUp', () => {
    test('should swap section with previous (no reload)', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        // Move third section (three_cols) up
        const moveUpButtons = page.locator('[data-action="cb-section-move#moveUp"]');
        await moveUpButtons.nth(2).click();
        await page.waitForTimeout(300);

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'three_cols', 'two_cols']);
    });

    test('moveUp should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        const moveUpButtons = page.locator('[data-action="cb-section-move#moveUp"]');
        await moveUpButtons.nth(2).click();
        await page.waitForTimeout(500);

        await page.reload();

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'three_cols', 'two_cols']);
    });

    test('moveUp on first section should do nothing', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const moveUpButtons = page.locator('[data-action="cb-section-move#moveUp"]');
        await moveUpButtons.first().click();
        await page.waitForTimeout(300);

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'two_cols']);
    });

    test('columns should remain visible after moveUp', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        const moveUpButtons = page.locator('[data-action="cb-section-move#moveUp"]');
        await moveUpButtons.nth(2).click();
        await page.waitForTimeout(300);

        // three_cols (now at position 2) should still have 3 columns
        const sections = page.locator('.cb-section');
        await expect(sections.nth(1).locator('.cb-column')).toHaveCount(3);
        // two_cols (now at position 3) should still have 2 columns
        await expect(sections.nth(2).locator('.cb-column')).toHaveCount(2);
    });
});

test.describe('Section: moveDown', () => {
    test('should swap section with next (no reload)', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        const moveDownButtons = page.locator('[data-action="cb-section-move#moveDown"]');
        await moveDownButtons.first().click();
        await page.waitForTimeout(300);

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['two_cols', 'full', 'three_cols']);
    });

    test('moveDown should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const moveDownButtons = page.locator('[data-action="cb-section-move#moveDown"]');
        await moveDownButtons.first().click();
        await page.waitForTimeout(500);

        await page.reload();

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['two_cols', 'full']);
    });

    test('moveDown on last section should do nothing', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const moveDownButtons = page.locator('[data-action="cb-section-move#moveDown"]');
        await moveDownButtons.last().click();
        await page.waitForTimeout(300);

        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'two_cols']);
    });
});

test.describe('Section: delete', () => {
    test('should remove section from DOM', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        const deleteButtons = page.locator('[data-action="cb-section-move#removeSection"]');
        await deleteButtons.nth(1).click(); // delete two_cols
        await page.waitForTimeout(300);

        await expect(page.locator('.cb-section')).toHaveCount(2);
        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full', 'three_cols']);
    });

    test('delete should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');

        const deleteButtons = page.locator('[data-action="cb-section-move#removeSection"]');
        await deleteButtons.last().click();
        await page.waitForTimeout(500);

        await page.reload();

        await expect(page.locator('.cb-section')).toHaveCount(1);
        const layouts = await getSectionLayouts(page);
        expect(layouts).toEqual(['full']);
    });

    test('remaining sections should keep their columns after delete', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        // Delete middle section (two_cols)
        const deleteButtons = page.locator('[data-action="cb-section-move#removeSection"]');
        await deleteButtons.nth(1).click();
        await page.waitForTimeout(300);

        const sections = page.locator('.cb-section');
        await expect(sections).toHaveCount(2);
        // three_cols should still have 3 columns
        await expect(sections.nth(1).locator('.cb-column')).toHaveCount(3);
    });

    test('labels should update after delete', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'two_cols');
        await addSection(page, 'three_cols');

        const deleteButtons = page.locator('[data-action="cb-section-move#removeSection"]');
        await deleteButtons.first().click(); // delete first (full)
        await page.waitForTimeout(300);

        const labels = page.locator('.cb-section__label');
        await expect(labels.first()).toContainText('Section 1');
        await expect(labels.nth(1)).toContainText('Section 2');
    });
});
