import { defineConfig } from '@playwright/test';
import { fixtureServer } from './playwright.server.js';

/**
 * The Sylius plugin in a real Sylius-Standard shop: what PHPUnit cannot see,
 * the admin's own build, its Live route and the shop iframe. Keep it small.
 *
 * @see docs/internals/sylius.md#testing
 */
const PORT = process.env.E2E_SYLIUS_PORT || '8007';
const SUFFIX = process.env.E2E_SUFFIX || '_e2e';
const ORIGIN = `http://127.0.0.1:${PORT}`;

export default defineConfig({
    testDir: './assets/test/e2e-sylius',
    timeout: 60000,
    retries: process.env.CI ? 2 : 1,
    // One shop, one homepage: specs that publish it must not race.
    workers: 1,
    use: {
        baseURL: ORIGIN,
        headless: true,
    },
    webServer: {
        command: `export SANDBOX_SUFFIX=${SUFFIX}`
            + ' && ../../scripts/demo.sh reset shop && '
            + fixtureServer(PORT, 'content-blocks-sylius-shop-sandbox'),
        url: ORIGIN + '/admin/login',
        reuseExistingServer: true,
        // The reset loads Sylius's fixtures before the server answers.
        timeout: 240000,
    },
});
