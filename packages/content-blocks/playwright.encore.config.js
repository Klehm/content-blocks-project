import { defineConfig } from '@playwright/test';

/**
 * The *install path* under a bundler we do not develop against daily — not
 * behaviour, which is the main suite's job. Keep it small.
 *
 * @see docs/internals/testing.md#two-playwright-suites-two-jobs
 */
export default defineConfig({
    testDir: './assets/test/e2e-encore',
    timeout: 30000,
    retries: process.env.CI ? 2 : 1,
    use: {
        baseURL: 'http://127.0.0.1:8002',
        headless: true,
    },
    webServer: {
        // 8002, so this can run alongside the main sandbox on 8001. Same
        // worker and restart-loop reasoning: docs/internals/testing.md
        command: 'while true; do PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8002'
            + " -t ../../apps/content-blocks-encore-sandbox/public;"
            + " echo '[fixture] web server exited — restarting'; sleep 0.3; done",
        url: 'http://127.0.0.1:8002',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
