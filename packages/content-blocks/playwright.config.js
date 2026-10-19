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
        // Emptied `_e2e` instance, then supervised (`php -S` has segfaulted
        // mid-suite on CI). See docs/internals/testing.md
        command: 'export SANDBOX_SUFFIX=_e2e'
            + ' && ../../apps/content-blocks-sandbox/bin/reset.sh && '
            + `while true; do ${FIXTURE_SERVER}; echo '[fixture] web server exited — restarting';`
            // Workers outlive a crashed parent and keep the port: free it.
            + " pkill -f '^php -S 127.0.0.1:8001'; sleep 0.3; done",
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: true,
        // Includes the reset, which runs before the server answers.
        timeout: 60000,
    },
});
