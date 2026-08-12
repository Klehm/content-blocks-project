import { defineConfig } from '@playwright/test';

/**
 * Second browser suite, aimed at the *install path* rather than at behaviour.
 *
 * The main suite (playwright.config.js) runs against the AssetMapper sandbox
 * and covers what the builder does. This one runs against
 * apps/content-blocks-encore-sandbox — Symfony 6.4, Doctrine ORM 2, Webpack
 * Encore, and `symfony/asset-mapper` in composer's `conflict` so it can never
 * creep in — and only asks whether the packages install, boot and run at all
 * under a bundler we do not develop against day to day.
 *
 * Keep it small. Anything that would pass identically under either bundler
 * belongs in the main suite.
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
        // Port 8002 so this suite can run alongside the AssetMapper sandbox on
        // 8001. See the main config for why PHP_CLI_SERVER_WORKERS is needed,
        // and why the server runs under a restart loop (it has segfaulted
        // mid-suite there; nothing makes this sandbox immune).
        command: 'while true; do PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8002'
            + " -t ../../apps/content-blocks-encore-sandbox/public;"
            + " echo '[fixture] web server exited — restarting'; sleep 0.3; done",
        url: 'http://127.0.0.1:8002',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
