import { defineConfig } from '@playwright/test';
import { fixtureServer } from './playwright.server.js';

// Overridable so two worktrees can run the suite side by side.
// See docs/internals/testing.md#running-two-checkouts-at-once
const PORT = process.env.E2E_PORT || '8001';
const SUFFIX = process.env.E2E_SUFFIX || '_e2e';
const ORIGIN = `http://127.0.0.1:${PORT}`;

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
            + fixtureServer(PORT, 'content-blocks-sandbox'),
        url: ORIGIN,
        reuseExistingServer: true,
        // Includes the reset, which runs before the server answers.
        timeout: 60000,
    },
});
