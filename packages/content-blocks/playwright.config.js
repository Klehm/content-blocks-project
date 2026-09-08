import { defineConfig } from '@playwright/test';

// PHP_CLI_SERVER_WORKERS and the router script are both load-bearing, and
// neither is obvious. See docs/internals/testing.md#the-fixture-server
const FIXTURE_SERVER = 'PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8001'
    + ' -t ../../apps/content-blocks-sandbox/public'
    + ' ../../apps/content-blocks-sandbox/public/router.php';

export default defineConfig({
    testDir: './assets/test/e2e',
    timeout: 30000,
    // A safety net that should rarely fire: robustness lives in the specs.
    // See docs/internals/testing.md
    retries: process.env.CI ? 2 : 1,
    use: {
        baseURL: 'http://127.0.0.1:8001',
        headless: true,
    },
    webServer: {
        // Supervised, because the fixture server does die: CI has caught a
        // segfault from `php -S` mid-suite. See docs/internals/testing.md
        command: `while true; do ${FIXTURE_SERVER}; echo '[fixture] web server exited — restarting'; sleep 0.3; done`,
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
