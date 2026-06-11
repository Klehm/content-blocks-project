import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './assets/test/e2e',
    timeout: 30000,
    // One retry locally / two on CI as a safety net for genuinely timing-
    // sensitive flows. The goal is for the retry to rarely fire — root-cause
    // robustness (stable selectors, no fixed-position hovers) lives in the
    // specs themselves, not here.
    retries: process.env.CI ? 2 : 1,
    use: {
        baseURL: 'http://127.0.0.1:8001',
        headless: true,
    },
    webServer: {
        // PHP's built-in server is single-process by default: under Playwright's
        // parallel workers it serializes every request (the preview iframe pulls
        // its HTML + assets concurrently), which starves requests and produces
        // 30s timeouts that look like test failures. PHP_CLI_SERVER_WORKERS forks
        // worker processes so concurrent requests are actually served in
        // parallel. Note: only takes effect when this command STARTS the server
        // (a reused, already-running server keeps its own worker count).
        command: 'PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8001 -t ../../apps/content-blocks-sandbox/public',
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
