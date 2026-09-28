import { test, expect } from '@playwright/test';
import { launchBuilder } from './helpers/builder.js';

/**
 * The sandbox mounts the builder's endpoints under `/admin/content-blocks`
 * (config/routes/content_blocks.yaml), and every other spec in this suite runs
 * against that mount. This one pins the mount itself: the builder calls it,
 * the default one is gone, and the public assets stayed outside `/admin`.
 */

const MOUNT = '/admin/content-blocks';

async function openBuilder(page) {
    const slug = `e2e-mount-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    await page.goto(location);
    await launchBuilder(page);
    await expect(page.locator('.cb-shell')).toBeVisible();
    return page.frameLocator('.cb-shell__iframe');
}

test('the builder calls the endpoints where the host mounted them', async ({ page }) => {
    const mounted = [];
    const defaultMount = [];
    page.on('request', (r) => {
        const { pathname } = new URL(r.url());
        if (pathname.startsWith(`${MOUNT}/`)) mounted.push(pathname);
        if (pathname.startsWith('/_content-blocks/') && !pathname.startsWith('/_content-blocks/public/')) {
            defaultMount.push(pathname);
        }
    });

    const frame = await openBuilder(page);
    await expect(page.locator('.cb-shell')).toHaveAttribute('data-cb-api-base', MOUNT);

    const created = page.waitForResponse((r) => r.url().endsWith('/sections'));
    await frame.locator('.cb-add-section-tray__btn[data-cb-add-section="full"]').click();
    const response = await created;
    expect(response.status()).toBe(200);
    expect(new URL(response.url()).pathname).toMatch(new RegExp(`^${MOUNT}/area/\\d+/sections$`));

    // The settings sidebar it opens, and the form that posts from it.
    const form = page.locator('.cb-shell__sidebar form').first();
    await expect(form).toHaveAttribute('action', new RegExp(`^${MOUNT}/section/\\d+/settings$`));

    expect(mounted.length).toBeGreaterThan(0);
    expect(defaultMount).toEqual([]);
});

test('the default mount is gone, and the public assets stayed public', async ({ page }) => {
    expect((await page.request.get('/_content-blocks/types')).status()).toBe(404);
    expect((await page.request.get(`${MOUNT}/types`)).status()).toBe(200);

    for (const path of ['/_content-blocks/public/layout', '/_content-blocks/public/styling']) {
        const response = await page.request.get(path);
        expect(response.status(), path).toBe(200);
        expect(response.headers()['content-type']).toContain('text/css');
    }
});
