import { defineConfig } from '@playwright/test';

// Overridable so two worktrees can run the suite side by side.
// See docs/internals/testing.md#running-two-checkouts-at-once
const PORT = process.env.E2E_PORT || '8001';
const SUFFIX = process.env.E2E_SUFFIX || '_e2e';
const ORIGIN = `http://127.0.0.1:${PORT}`;

// PHP_CLI_SERVER_WORKERS and the router script are both load-bearing, and
// neither is obvious. See docs/internals/testing.md#the-fixture-server
const FIXTURE_SERVER = `PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:${PORT}`
    + ' -t ../../apps/content-blocks-sandbox/public'
    + ' ../../apps/content-blocks-sandbox/public/router.php';

export default defineConfig({
    testDir: './assets/test/e2e',
    timeout: 30000,
    // A safety net that should rarely fire: robustness lives in the specs.
    // See docs/internals/testing.md
    retries: process.env.CI ? 2 : 1,
    use: {
        baseURL: ORIGIN,
        headless: true,
    },
    webServer: {
        // Emptied `_e2e` instance, then supervised (`php -S` has segfaulted
        // mid-suite on CI). See docs/internals/testing.md
        command: `export SANDBOX_SUFFIX=${SUFFIX}`
            + ' && ../../apps/content-blocks-sandbox/bin/reset.sh && '
            + `while true; do ${FIXTURE_SERVER}; echo '[fixture] web server exited — restarting';`
            // Workers outlive a crashed parent and keep the port: free it.
            + ` pkill -f '^php -S 127.0.0.1:${PORT}'; sleep 0.3; done`,
        url: ORIGIN,
        reuseExistingServer: true,
        // Includes the reset, which runs before the server answers.
        timeout: 60000,
    },
});
