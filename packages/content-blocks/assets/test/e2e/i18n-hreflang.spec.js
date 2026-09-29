import { test, expect } from '@playwright/test';

/**
 * `cb_i18n_hreflang()` in the sandbox's public `<head>`: one alternate per
 * language from the host's LocalizedPageUrlResolver, absolute, plus x-default
 * on the source (French) page.
 */

async function createFreshPage(page) {
    const slug = `e2e-hreflang-${Date.now()}-${Math.random().toString(36).slice(2, 7)}`;
    const response = await page.request.post('/page/create', {
        form: { title: `E2E ${slug}`, slug },
        maxRedirects: 0,
    });
    const location = response.headers()['location'];
    if (!location) throw new Error('Page create did not redirect');
    return location.match(/\/(\d+)$/)[1];
}

test('every language of a page is announced as an hreflang alternate', async ({ page, baseURL }) => {
    const id = await createFreshPage(page);

    await page.goto(`/de/page/${id}`);

    await expect(page.locator('html')).toHaveAttribute('lang', 'de');

    const alternates = await page.locator('head link[rel="alternate"]').evaluateAll(
        (links) => links.map((l) => [l.getAttribute('hreflang'), l.getAttribute('href')]),
    );
    const origin = new URL(baseURL).origin;

    expect(alternates).toEqual([
        ['fr', `${origin}/page/${id}`],
        ['en', `${origin}/en/page/${id}`],
        ['de', `${origin}/de/page/${id}`],
        ['es', `${origin}/es/page/${id}`],
        ['x-default', `${origin}/page/${id}`],
    ]);
});
