<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the package's front + builder assets at stable URLs the render
 * template can reference via <link>/<script> tags.
 *
 * These routes live under `/_content-blocks/public/*` (rather than the
 * `/_content-blocks/*` admin namespace) so a host that locks down the
 * admin endpoints behind ROLE_ADMIN does not accidentally 404 the CSS
 * loaded inside the public preview iframe. Hosts should keep this prefix
 * publicly accessible.
 *
 * Note on URLs: extensions (.css, .js) are intentionally omitted because
 * PHP's built-in dev server treats those paths as static files and 404s
 * before Symfony's router can pick them up. Content-Type headers cover
 * the actual MIME negotiation.
 *
 * Three assets:
 *  - /public/layout           → text/css     (PUBLIC + PREVIEW)
 *  - /public/builder          → text/css     (PREVIEW only)
 *  - /public/preview-overlay  → application/javascript (PREVIEW only)
 */
final class AssetController
{
    private const ASSETS_DIR = '/../../assets';

    #[Route(
        '/_content-blocks/public/layout',
        name: 'content_blocks_asset_layout',
        methods: ['GET'],
    )]
    public function layoutCss(): Response
    {
        return $this->asset('/styles/layout.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/_content-blocks/public/builder',
        name: 'content_blocks_asset_builder',
        methods: ['GET'],
    )]
    public function builderCss(): Response
    {
        return $this->asset('/styles/builder.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/_content-blocks/public/preview-overlay',
        name: 'content_blocks_asset_preview_overlay',
        methods: ['GET'],
    )]
    public function previewOverlay(): Response
    {
        return $this->asset('/preview-overlay.js', 'application/javascript; charset=UTF-8');
    }

    private function asset(string $relativePath, string $contentType): Response
    {
        $path = __DIR__ . self::ASSETS_DIR . $relativePath;
        $content = @file_get_contents($path);

        if ($content === false) {
            return new Response('// asset missing: ' . $relativePath, 500, [
                'Content-Type' => $contentType,
            ]);
        }

        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
