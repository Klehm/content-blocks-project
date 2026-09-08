<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the workbench stylesheet and script. Read-only static files, so no
 * CSRF and no access check — nothing here is not already public in the source.
 *
 * @see docs/internals/i18n.md#why-no-stimulus-controller
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
        // Extension kept out of the route: PHP's dev server shortcuts
        // anything that looks like a static file.
        $path = \dirname(__DIR__, 2) . '/assets/' . $file;
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
