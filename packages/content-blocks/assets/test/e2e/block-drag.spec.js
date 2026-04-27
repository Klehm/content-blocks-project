import { test, expect } from '@playwright/test';

/**
 * Helper: create a fresh page and navigate to its builder.
 */
async function createPageAndGo(page) {
    const response = await page.request.post('/page/create', {
        form: { title: `E2E Block ${Date.now()}`, slug: `e2e-block-${Date.now()}` },
    });
    const url = response.url();
    await page.goto(url);
    return url;
}

/**
 * Helper: add a section via UI.
 */
async function addSection(page, layout) {
    const buttonText = {
        full: '1 colonne',
        two_cols: '2 colonnes',
        three_cols: '3 colonnes',
    };
    await page.getByRole('button', { name: buttonText[layout] }).click();
    await page.waitForTimeout(500);
}

/**
 * Helper: add a block to a specific column via dropdown.
 */
async function addBlockToColumn(page, columnIndex, blockType = 'Texte') {
    const addButtons = page.locator('.cb-column__add-block .dropdown-toggle');
    await addButtons.nth(columnIndex).click();
    await page.waitForTimeout(200);

    const dropdownItem = page.getByRole('button', { name: blockType }).last();
    await dropdownItem.click();
    await page.waitForTimeout(500);
}

test.describe('Block: add block', () => {
    test('should add a block via dropdown', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');

        await addBlockToColumn(page, 0, 'Texte');

        await expect(page.locator('.cb-block')).toHaveCount(1);
        await expect(page.locator('.cb-block__handle')).toHaveCount(1);
    });

    test('added block should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');

        await page.reload();

        await expect(page.locator('.cb-block')).toHaveCount(1);
    });

    test('should add multiple blocks to different columns', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'two_cols');

        await addBlockToColumn(page, 0, 'Texte');
        await addBlockToColumn(page, 1, 'Texte');

        const columns = page.locator('.cb-column');
        await expect(columns.nth(0).locator('.cb-block')).toHaveCount(1);
        await expect(columns.nth(1).locator('.cb-block')).toHaveCount(1);
    });
});

test.describe('Block: delete block', () => {
    test('should delete block via button (no reload)', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');
        await addBlockToColumn(page, 0, 'Texte');

        await expect(page.locator('.cb-block')).toHaveCount(2);

        // Click delete on first block
        const deleteBtn = page.locator('.cb-block .btn-outline-danger').first();
        await deleteBtn.click();
        await page.waitForTimeout(1000);

        await expect(page.locator('.cb-block')).toHaveCount(1);
    });

    test('block delete should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');
        await addBlockToColumn(page, 0, 'Texte');

        const deleteBtn = page.locator('.cb-block .btn-outline-danger').first();
        await deleteBtn.click();
        await page.waitForTimeout(1000);

        await page.reload();

        await expect(page.locator('.cb-block')).toHaveCount(1);
    });
});

test.describe('Block: drag cross-column', () => {
    test('should drag block from one column to another', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'two_cols');

        // Add a block to first column
        await addBlockToColumn(page, 0, 'Texte');

        const columns = page.locator('[data-cb-block-drop-target]');
        await expect(columns.nth(0).locator('.cb-block')).toHaveCount(1);
        await expect(columns.nth(1).locator('.cb-block')).toHaveCount(0);

        // Drag block handle to second column
        const handle = page.locator('.cb-block__handle').first();
        const targetColumn = columns.nth(1);

        await handle.dragTo(targetColumn);
        await page.waitForTimeout(500);

        // Block should be in second column now
        await expect(columns.nth(0).locator('.cb-block')).toHaveCount(0);
        await expect(columns.nth(1).locator('.cb-block')).toHaveCount(1);
    });

    test('block move API should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'two_cols');
        await addBlockToColumn(page, 0, 'Texte');

        // Get IDs
        const blockId = await page.locator('.cb-block').first().getAttribute('data-block-id');
        const columns = page.locator('[data-cb-block-drop-target]');
        const targetColumnId = await columns.nth(1).getAttribute('data-column-id');

        // Move via API (tests the persistence endpoint directly,
        // visual drag is tested in separate tests above)
        const csrfToken = await page.getAttribute('[data-cb-csrf-token]', 'data-cb-csrf-token');
        const resp = await page.request.fetch(`/_content-blocks/block/${blockId}/move`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken,
            },
            data: JSON.stringify({ columnId: parseInt(targetColumnId), position: 0 }),
        });
        expect(resp.status()).toBe(200);

        await page.reload();

        const reloadedColumns = page.locator('[data-cb-block-drop-target]');
        const col0Blocks = await reloadedColumns.nth(0).locator('.cb-block').count();
        const col1Blocks = await reloadedColumns.nth(1).locator('.cb-block').count();
        console.log(`After reload: col0=${col0Blocks}, col1=${col1Blocks}, totalBlocks=${col0Blocks + col1Blocks}`);

        // Block should have moved from column 0 to column 1
        expect(col0Blocks).toBe(0);
        expect(col1Blocks).toBe(1);
    });

    test('should drag block across sections', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addSection(page, 'full');

        // Add block to first section
        await addBlockToColumn(page, 0, 'Texte');

        const columns = page.locator('[data-cb-block-drop-target]');
        await expect(columns.nth(0).locator('.cb-block')).toHaveCount(1);
        await expect(columns.nth(1).locator('.cb-block')).toHaveCount(0);

        // Drag to second section's column
        const handle = page.locator('.cb-block__handle').first();
        await handle.dragTo(columns.nth(1));
        await page.waitForTimeout(500);

        await expect(columns.nth(0).locator('.cb-block')).toHaveCount(0);
        await expect(columns.nth(1).locator('.cb-block')).toHaveCount(1);
    });
});

test.describe('Block: edit', () => {
    test('should open edit form and save', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');

        // Click edit button
        const editBtn = page.locator('.cb-block .btn-outline-primary').first();
        await editBtn.click();
        await page.waitForTimeout(500);

        // Edit form should be visible
        await expect(page.locator('.cb-block__edit-form')).toBeVisible();

        // Type in the input
        const input = page.locator('.cb-block__edit-form input').first();
        await input.fill('Hello World');

        // Save
        await page.locator('.cb-block__edit-form .btn-primary').click();
        await page.waitForTimeout(500);

        // Preview should show the new content
        await expect(page.locator('.cb-block__preview')).toContainText('Hello World');
    });

    test('edit should persist after reload', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');

        const editBtn = page.locator('.cb-block .btn-outline-primary').first();
        await editBtn.click();
        await page.waitForTimeout(500);

        const input = page.locator('.cb-block__edit-form input').first();
        await input.fill('Persistent text');

        await page.locator('.cb-block__edit-form .btn-primary').click();
        await page.waitForTimeout(500);

        await page.reload();

        await expect(page.locator('.cb-block__preview')).toContainText('Persistent text');
    });

    test('cancel edit should not save changes', async ({ page }) => {
        await createPageAndGo(page);
        await addSection(page, 'full');
        await addBlockToColumn(page, 0, 'Texte');

        const editBtn = page.locator('.cb-block .btn-outline-primary').first();
        await editBtn.click();
        await page.waitForTimeout(500);

        const input = page.locator('.cb-block__edit-form input').first();
        await input.fill('Should not save');

        // Cancel
        await page.locator('.cb-block__edit-form .btn-secondary').click();
        await page.waitForTimeout(500);

        // Should NOT contain the cancelled text
        await expect(page.locator('.cb-block__preview')).not.toContainText('Should not save');
    });
});
