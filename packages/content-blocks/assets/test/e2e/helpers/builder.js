import { expect } from '@playwright/test';

/**
 * Opens the builder from the host page, once each half can take a click: the
 * launcher's controller, then the preview overlay.
 *
 * Two races this closes, both seen as intermittent failures under load:
 * - the launcher's connect() moves its dialog under <body>; a click before
 *   that lands on a button with no controller behind it yet;
 * - the overlay appends its toolbar in the same run that installs its click
 *   and tray listeners, so before the toolbar exists a click in the preview
 *   (a link, an add-section button) goes nowhere.
 *
 * Pass `{ preview: false }` when the preview is not expected to load.
 */
export async function launchBuilder(page, { preview = true } = {}) {
    await expect(page.locator('body > dialog[data-cb-builder-launcher-target="dialog"]')).toBeAttached();
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');
    if (preview) {
        await expect(frame.locator('.cb-overlay-toolbar').first()).toBeAttached();
    }

    return frame;
}
