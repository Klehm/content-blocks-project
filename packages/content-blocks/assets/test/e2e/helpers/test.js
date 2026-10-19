import { test as base, expect } from '@playwright/test';

/**
 * Playwright's `test`, plus one automatic fixture: a retry first waits for
 * the fixture server to answer. See docs/internals/testing.md
 */
export const test = base.extend({
    serverReady: [async ({ request }, use, testInfo) => {
        if (testInfo.retry > 0) {
            await expect.poll(async () => {
                try {
                    const response = await request.get('/', { timeout: 2000, maxRedirects: 0 });

                    return response.status() < 500;
                } catch {
                    return false;
                }
            }, { timeout: 30000, intervals: [500, 1000] }).toBe(true);
        }
        await use();
    }, { auto: true }],
});

export { expect };
