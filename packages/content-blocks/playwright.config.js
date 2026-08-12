import { defineConfig } from '@playwright/test';

// PHP's built-in server is single-process by default: under Playwright's
// parallel workers it serializes every request (the preview iframe pulls its
// HTML + assets concurrently), which starves requests and produces 30s timeouts
// that look like test failures. PHP_CLI_SERVER_WORKERS forks worker processes so
// concurrent requests are actually served in parallel. Note: only takes effect
// when this command STARTS the server (a reused, already-running server keeps
// its own worker count).
//
// The router script is what makes the built-in server behave like a real one:
// without it, any URL that looks like a file (LiipImagine's
// `/media/cache/resolve/…/photo.png`) 404s instead of reaching the front
// controller, so no image variant is ever generated.
const FIXTURE_SERVER = 'PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8001'
    + ' -t ../../apps/content-blocks-sandbox/public'
    + ' ../../apps/content-blocks-sandbox/public/router.php';

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
        // Supervised, because the fixture server does die: CI has caught a
        // `Segmentation fault (core dumped)` from `php -S` mid-suite, after
        // which every remaining spec failed with ECONNREFUSED — 30-odd red
        // tests from one crash. The built-in server is explicitly not built for
        // sustained concurrent load, and PHP_CLI_SERVER_WORKERS is experimental,
        // so the answer is to survive a crash rather than to pretend it cannot
        // happen: the loop brings the server straight back, and the retries
        // above absorb the handful of requests in flight when it went down.
        command: `while true; do ${FIXTURE_SERVER}; echo '[fixture] web server exited — restarting'; sleep 0.3; done`,
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
