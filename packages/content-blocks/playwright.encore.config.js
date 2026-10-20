import { defineConfig } from '@playwright/test';

/**
 * The *install path* under a bundler we do not develop against daily — not
 * behaviour, which is the main suite's job. Keep it small.
 *
 * @see docs/internals/testing.md#two-playwright-suites-two-jobs
 */
// Overridable so two worktrees can run the suite side by side.
// See docs/internals/testing.md#running-two-checkouts-at-once
const PORT = process.env.E2E_ENCORE_PORT || '8002';
const SUFFIX = process.env.E2E_SUFFIX || '_e2e';
const ORIGIN = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: './assets/test/e2e-encore',
    timeout: 30000,
    retries: process.env.CI ? 2 : 1,
    use: {
        baseURL: ORIGIN,
        headless: true,
    },
    webServer: {
        // 8002, so this can run alongside the main sandbox on 8001. Same
        // worker, restart-loop and `_e2e` reasoning: docs/internals/testing.md
        command: `export SANDBOX_SUFFIX=${SUFFIX}`
            + ' && ../../scripts/demo.sh reset encore && '
            + `while true; do PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:${PORT}`
            + ' -t ../../apps/content-blocks-encore-sandbox/public'
            + ' ../../apps/content-blocks-encore-sandbox/public/router.php;'
            + " echo '[fixture] web server exited — restarting';"
            // Workers outlive a crashed parent and keep the port: free it.
            + ` pkill -f '^php -S 127.0.0.1:${PORT}'; sleep 0.3; done`,
        url: ORIGIN,
        reuseExistingServer: true,
        // Includes the reset, which runs before the server answers.
        timeout: 60000,
    },
});
