import { defineConfig } from 'vitest/config';
import { fileURLToPath } from 'node:url';

export default defineConfig({
    resolve: {
        alias: {
            // `@symfony/ux-live-component` is supplied at runtime by the
            // sandboxes' AssetMapper importmap, not by npm — point vitest at a
            // stub so controllers that import getComponent can be unit-tested.
            '@symfony/ux-live-component': fileURLToPath(
                new URL('./assets/test/__stubs__/ux-live-component.js', import.meta.url),
            ),
            // sortablejs is supplied at runtime by the host importmap, not npm.
            sortablejs: fileURLToPath(
                new URL('./assets/test/__stubs__/sortablejs.js', import.meta.url),
            ),
        },
    },
    test: {
        environment: 'jsdom',
        include: ['assets/test/**/*.test.js'],
    },
});
