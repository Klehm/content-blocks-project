<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the workbench's stylesheet and script at stable URLs.
 *
 * ---- Why the workbench does not use Stimulus ----
 *
 * Every other controller in this suite is a Stimulus controller declared in
 * `assets/package.json` and enabled in each host's `controllers.json`. This one
 * is deliberately not, and the reason is what the workbench *is*: a standalone
 * page the package renders in full, not a widget mounted inside the host's admin
 * layout. It never loads the host's JavaScript bundle, so there is no Stimulus
 * application for a controller to register with.
 *
 * Serving a self-contained ES module instead means the workbench works
 * identically under AssetMapper and Webpack Encore, needs no entry in any host's
 * `controllers.json`, and needs no asset recompilation in any sandbox. For one
 * page with one behaviour, that is a better trade than the wiring.
 *
 * The routes are read-only static files (by default under
 * `/_content-blocks/i18n/asset/*`, wherever the host mounts the package's route
 * file), so they carry no CSRF and no access check — there is nothing here that
 * is not already public in the package's source.
 */
final class AssetController
{
    #[Route(
        '/asset/workbench-css',
        name: 'content_blocks_i18n_asset_css',
        methods: ['GET'],
    )]
    public function css(): Response
    {
        return $this->serve('workbench.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/asset/workbench-js',
        name: 'content_blocks_i18n_asset_js',
        methods: ['GET'],
    )]
    public function js(): Response
    {
        return $this->serve('workbench.js', 'text/javascript; charset=UTF-8');
    }

    private function serve(string $file, string $contentType): Response
    {
        // The `.css` / `.js` extension is kept out of the route for the same
        // reason the kit does it: PHP's built-in dev server shortcuts anything
        // that looks like a static file and never reaches the front controller.
        $path = \dirname(__DIR__, 2) . '/assets/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
