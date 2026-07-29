const Encore = require('@symfony/webpack-encore');

if (!Encore.isRuntimeEnvironmentConfigured()) {
    Encore.configureRuntimeEnvironment(process.env.NODE_ENV || 'dev');
}

Encore
    .setOutputPath('public/build/')
    .setPublicPath('/build')
    .addEntry('app', './assets/app.js')
    .splitEntryChunks()
    .enableSingleRuntimeChunk()
    .cleanupOutputBeforeBuild()
    .enableSourceMaps(!Encore.isProduction())
    .enableVersioning(Encore.isProduction())

    // The line this whole sandbox exists to exercise: stimulus-bridge reads
    // assets/controllers.json, resolves each package from node_modules, and
    // compiles the controllers its assets/package.json declares.
    .enableStimulusBridge('./assets/controllers.json')

    .configureBabelPresetEnv((config) => {
        config.useBuiltIns = 'usage';
        config.corejs = '3.38';
    })
;

const config = Encore.getWebpackConfig();

// Monorepo-only. Composer's `path` repository symlinks vendor/klehm/* back to
// packages/*, and webpack resolves a module's imports from its *realpath* — so
// `import Sortable from 'sortablejs'` inside a controller is looked up starting
// at packages/content-blocks/assets/ and never reaches this app's node_modules.
// Adding this directory as a last-resort resolution root fixes that without
// changing how anything else resolves.
//
// (`resolve.symlinks = false` also fixes it, but it makes every dependency
// resolve from its symlinked location — which breaks @symfony/ux-live-component,
// whose shipped tsconfig.json extends a path that only exists inside the
// symfony/ux monorepo.)
//
// A real install needs none of this: `composer require` writes a genuine
// directory under vendor/, which already resolves upward correctly. This is why
// the line is here and not in the installation docs.
config.resolve.modules = [
    ...(config.resolve.modules ?? []),
    require('path').resolve(__dirname, 'node_modules'),
];

// Not monorepo-specific — worth knowing if you build with Encore. webpack 5.109
// auto-enables `resolve.tsconfig` when it thinks the project uses TypeScript,
// and the resolver then reads the nearest tsconfig.json to any module it
// touches. @symfony/ux-live-component ships one that extends
// "../../../tsconfig.package.json" — a path that only exists inside the
// symfony/ux monorepo — so resolving its controller fails with a bare ENOENT
// naming a file nobody wrote. This is a plain JS project; say so.
config.resolve.tsconfig = false;

module.exports = config;
