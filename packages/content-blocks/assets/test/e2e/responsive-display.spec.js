import { test, expect } from '@playwright/test';
import { reveal } from './helpers/sidebar.js';

/**
 * A section's display per viewport (a grid becoming a slider, tabs becoming
 * an accordion), the order of sections per viewport, and the section label
 * as a drag handle.
 */

async function createFreshPage(page) {
    const slug = `e2e-responsive-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
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
    await page.locator('.cb-launcher__button').click();
    await expect(page.locator('.cb-shell')).toBeVisible();
    const frame = page.frameLocator('.cb-shell__iframe');
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    return frame;
}

async function addSection(frame, layout) {
    const before = await frame.locator('[data-cb-section-id]').count();
    await frame.locator(`.cb-add-section-tray__btn[data-cb-add-section="${layout}"]`).click();
    await expect.poll(() => frame.locator('[data-cb-section-id]').count()).toBe(before + 1);
}

async function addBlockTo(frame, column) {
    const before = await column.locator('[data-cb-block-id]').count();
    // Dispatched: the section toolbar sits over a short, empty column.
    await column.locator('.cb-add-block-inline').dispatchEvent('click');
    await frame.locator('.cb-overlay-popover button', { hasText: /^(Titre|Title)$/ }).click();
    await expect(column.locator('[data-cb-block-id]')).toHaveCount(before + 1);
}

async function publish(page) {
    const button = page.locator('.cb-shell__publish');
    await expect(button).toBeEnabled();
    await button.click();
    await expect(button).toBeDisabled({ timeout: 10000 });
    await page.waitForTimeout(400);
}

/** Picks a display for one viewport in the section sidebar. */
async function chooseDisplay(page, viewport, value) {
    const row = await reveal(page.locator('.cb-shell__sidebar .cb-display-row'));
    await row.locator(`.cb-viewport-tabs__btn[data-viewport="${viewport}"]`).click();
    const field = { desktop: 'display', tablet: 'displayTablet', mobile: 'displayMobile' }[viewport];
    const saved = page.waitForResponse((r) => /\/section\/\d+\/settings$/.test(r.url())
        && r.request().method() === 'POST');
    await row.locator(`input[name$="[${field}]"][value="${value}"]`).check();
    expect((await saved).status()).toBe(204);
}

function shown(locator) {
    return locator.evaluate((el) => getComputedStyle(el).display !== 'none');
}

function publicUrl(builderUrl) {
    return builderUrl.replace('/admin/page/', '/page/');
}

/** Drags from one element's centre to a point just above another. */
async function dragAbove(page, from, to) {
    await from.hover({ force: true });
    const start = await from.boundingBox();
    const target = await to.boundingBox();
    await page.mouse.move(start.x + start.width / 2, start.y + start.height / 2);
    await page.mouse.down();
    await page.mouse.move(start.x + start.width / 2 + 10, start.y + start.height / 2 + 10, { steps: 3 });
    await page.mouse.move(target.x + target.width / 2, target.y + 4, { steps: 8 });
    await page.mouse.up();
}

test('three columns on desktop become a one-at-a-time slider on mobile', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await addSection(frame, 'three_cols');
    const columns = frame.locator('[data-cb-column-id]');
    for (let i = 0; i < 3; i++) await addBlockTo(frame, columns.nth(i));

    await frame.locator('.cb-section-handle').first().click({ force: true });
    await chooseDisplay(page, 'mobile', 'slider');
    // Tabs are never offered below: they would ship a second structure.
    const row = page.locator('.cb-shell__sidebar .cb-display-row');
    await expect(row.locator('input[name$="[displayMobile]"][value="accordion"]')).toBeEnabled();
    const perView = page.locator('.cb-shell__sidebar [data-viewport="mobile"] input[name$="[sliderPerView][mobile]"]');
    await expect(perView).toBeAttached();

    const section = frame.locator('[data-cb-section-id]').first();
    await expect(section).toHaveClass(/cb-section--m-slider/);
    await expect(section).toHaveAttribute('data-cb-slider', /"controls":"both"/);
    // The builder still shows desktop: a plain grid, no controls.
    expect(await section.locator('.cb-row').evaluate((el) => getComputedStyle(el).scrollSnapType)).toBe('none');

    await publish(page);

    const viewer = await context.newPage();
    await viewer.setViewportSize({ width: 375, height: 800 });
    await viewer.goto(publicUrl(builderUrl));
    const slider = viewer.locator('.cb-section--m-slider');
    const track = slider.locator(':scope > .cb-row');
    await expect.poll(() => track.evaluate((el) => getComputedStyle(el).scrollSnapType)).toContain('x');
    await expect(track).toHaveAttribute('aria-roledescription', /carrousel|carousel/);
    const dots = slider.locator('.cb-slider__dot');
    await expect(dots).toHaveCount(3);
    await expect(dots.nth(0)).toHaveAttribute('aria-current', 'true');
    await expect(slider.locator('.cb-slider__arrow--prev')).toBeDisabled();

    await slider.locator('.cb-slider__arrow--next').click();
    await expect.poll(() => track.evaluate((el) => el.scrollLeft)).toBeGreaterThan(100);
    await expect(dots.nth(1)).toHaveAttribute('aria-current', 'true');

    await dots.nth(2).click();
    await expect(slider.locator('.cb-slider__arrow--next')).toBeDisabled();

    // Wider than a phone, the same page is three columns again.
    await viewer.setViewportSize({ width: 1280, height: 800 });
    await expect.poll(() => track.evaluate((el) => getComputedStyle(el).scrollSnapType)).toBe('none');
    await expect(slider.locator('.cb-slider__controls')).toBeHidden();
    await expect(track).not.toHaveAttribute('aria-roledescription');

    await viewer.close();
});

test('tabs on desktop turn into an accordion on mobile', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await addSection(frame, 'tabs');
    const section = frame.locator('[data-cb-section-id]').first();
    await addBlockTo(frame, section.locator('[data-cb-column-id]').first());
    await section.locator(':scope > .cb-section-handle').click({ force: true });

    const row = await reveal(page.locator('.cb-shell__sidebar .cb-display-row'));
    await row.locator('.cb-viewport-tabs__btn[data-viewport="mobile"]').click();
    await expect(row.locator('input[name$="[displayMobile]"][value="grid"]')).toBeDisabled();
    await expect(row.locator('input[name$="[displayMobile]"][value="slider"]')).toBeDisabled();
    await chooseDisplay(page, 'mobile', 'accordion');
    await expect(section).toHaveClass(/cb-section--m-accordion/);

    await publish(page);

    const viewer = await context.newPage();
    await viewer.setViewportSize({ width: 375, height: 800 });
    await viewer.goto(publicUrl(builderUrl));
    const publicSection = viewer.locator('.cb-section--display-tabs');
    const headers = publicSection.locator(':scope > .cb-row > .cb-accordion__header');
    const panels = publicSection.locator(':scope > .cb-row > .cb-col');
    await expect(publicSection.locator('.cb-tabs__nav')).toBeHidden();
    await expect(headers).toHaveCount(3);
    await expect(headers.nth(1)).toBeVisible();
    expect(await shown(panels.nth(0))).toBe(true);

    await headers.nth(1).click();
    await expect.poll(() => shown(panels.nth(1))).toBe(true);
    expect(await shown(panels.nth(0))).toBe(false);

    // The same radios drive the tabs on desktop: the choice carries over.
    await viewer.setViewportSize({ width: 1280, height: 800 });
    await expect(publicSection.locator('.cb-tabs__nav')).toBeVisible();
    await expect(headers.nth(0)).toBeHidden();
    expect(await shown(panels.nth(1))).toBe(true);

    await viewer.close();
});

test('sections dragged on mobile keep their desktop order', async ({ page, context }) => {
    const builderUrl = await createFreshPage(page);
    const frame = await openBuilder(page, builderUrl);

    await addSection(frame, 'full');
    await addSection(frame, 'full');
    const sections = frame.locator('[data-cb-section-id]');
    // An empty section has no height on the page: nothing to see reordered.
    for (let i = 0; i < 2; i++) await addBlockTo(frame, sections.nth(i).locator('[data-cb-column-id]'));
    const [first, second] = await sections.evaluateAll((els) => els.map((el) => el.dataset.cbSectionId));

    await page.locator('.cb-shell__viewport-btn[data-cb-builder-viewport-param="mobile"]').click();
    const hint = page.locator('.cb-shell__viewport-order');
    // The iframe narrows over 200ms, through the tablet range.
    await expect(hint).toHaveText(/mobile/i);

    const saved = page.waitForResponse((r) => /\/viewport-order$/.test(r.url()));
    await dragAbove(
        page,
        frame.locator(`[data-cb-section-id="${second}"] > .cb-section-handle`),
        frame.locator(`[data-cb-section-id="${first}"]`),
    );
    expect((await saved).status()).toBe(200);

    // The DOM did not move; the second section is simply drawn first.
    const top = (id) => frame.locator(`[data-cb-section-id="${id}"]`).evaluate((el) => el.getBoundingClientRect().top);
    await expect.poll(async () => (await top(second)) < (await top(first))).toBe(true);
    expect(await sections.evaluateAll((els) => els.map((el) => el.dataset.cbSectionId))).toEqual([first, second]);

    await page.locator('.cb-shell__viewport-btn[data-cb-builder-viewport-param="desktop"]').click();
    await expect(hint).toBeHidden();
    await expect.poll(async () => (await top(first)) < (await top(second))).toBe(true);

    await publish(page);

    const viewer = await context.newPage();
    await viewer.setViewportSize({ width: 375, height: 800 });
    await viewer.goto(publicUrl(builderUrl));
    const publicTops = () => viewer.locator('.cb-section').evaluateAll(
        (els) => els.map((el) => Math.round(el.getBoundingClientRect().top)),
    );
    let [a, b] = await publicTops();
    expect(b).toBeLessThan(a);
    await viewer.setViewportSize({ width: 1280, height: 800 });
    [a, b] = await publicTops();
    expect(a).toBeLessThan(b);
    await viewer.close();

    // Reset brings the mobile order back to desktop's.
    await page.locator('.cb-shell__viewport-btn[data-cb-builder-viewport-param="mobile"]').click();
    await expect(hint).toHaveText(/mobile/i);
    const reset = page.waitForResponse((r) => /\/viewport-order\/reset$/.test(r.url()));
    const reloaded = page.waitForEvent('framenavigated', (f) => f.url().includes('cb_preview=1'));
    await page.locator('.cb-shell__viewport-order-reset').click();
    expect((await reset).status()).toBe(200);
    // The preview reloads from the server: no rank left to draw.
    await reloaded;
    await expect(frame.locator('.cb-add-section-tray')).toBeVisible();
    await expect(frame.locator('.cb-content-area--ordered')).toHaveCount(0);
    await expect.poll(async () => (await top(first)) < (await top(second))).toBe(true);
});

test('a section is dragged by its label, and a click on it still selects', async ({ page }) => {
    const frame = await openBuilder(page, await createFreshPage(page));

    await addSection(frame, 'full');
    await addSection(frame, 'full');
    const ids = () => frame.locator('[data-cb-section-id]').evaluateAll((els) => els.map((el) => el.dataset.cbSectionId));
    const [first, second] = await ids();

    const moved = page.waitForResponse((r) => /\/section\/\d+\/move$/.test(r.url()));
    await dragAbove(
        page,
        frame.locator(`[data-cb-section-id="${second}"] > .cb-section-handle`),
        frame.locator(`[data-cb-section-id="${first}"]`),
    );
    expect((await moved).status()).toBe(200);
    await expect.poll(ids).toEqual([second, first]);

    await frame.locator(`[data-cb-section-id="${first}"] > .cb-section-handle`).click({ force: true });
    await expect(page.locator('.cb-shell__sidebar')).toHaveAttribute('data-cb-sidebar-section-id', first);
    // Focus lands in the preview, where the keyboard shortcuts listen.
    expect(await page.evaluate(() => document.activeElement?.tagName)).toBe('IFRAME');
});
