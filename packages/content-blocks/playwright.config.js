import { defineConfig } from '@playwright/test';

export default defineConfig({
    testDir: './assets/test/e2e',
    timeout: 30000,
    retries: 0,
    use: {
        baseURL: 'http://127.0.0.1:8001',
        headless: true,
    },
    webServer: {
        command: 'php -S 127.0.0.1:8001 -t ../../apps/content-blocks-sandbox/public',
        url: 'http://127.0.0.1:8001',
        reuseExistingServer: true,
        timeout: 10000,
    },
});
