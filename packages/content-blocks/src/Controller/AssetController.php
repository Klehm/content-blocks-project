<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the package's CSS/JS, imported apart (`routes/public.php`) so it stays
 * outside the admin mount, and without file extensions. Both are deliberate.
 *
 * @see docs/internals/assets.md#asset-routes-are-public-on-purpose
 *
 * @internal The routes are the contract, not this class. See FREEZE-AUDIT.md.
 */
final class AssetController
{
    private const ASSETS_DIR = '/../../assets';

    #[Route(
        '/layout',
        name: 'content_blocks_asset_layout',
        methods: ['GET'],
    )]
    public function layoutCss(): Response
    {
        return $this->asset('/styles/layout.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/styling',
        name: 'content_blocks_asset_styling',
        methods: ['GET'],
    )]
    public function stylingCss(): Response
    {
        return $this->asset('/styles/styling.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/builder',
        name: 'content_blocks_asset_builder',
        methods: ['GET'],
    )]
    public function builderCss(): Response
    {
        // Prepended, not @import-ed: served raw, and an @import would resolve
        // against the public mount, where no route serves the tokens.
        return $this->asset(
            '/styles/builder.css',
            'text/css; charset=UTF-8',
            prepend: '/styles/tokens.css',
        );
    }

    #[Route(
        '/slider',
        name: 'content_blocks_asset_slider',
        methods: ['GET'],
    )]
    public function slider(): Response
    {
        return $this->asset('/slider.js', 'application/javascript; charset=UTF-8');
    }

    #[Route(
        '/preview-overlay',
        name: 'content_blocks_asset_preview_overlay',
        methods: ['GET'],
    )]
    public function previewOverlay(): Response
    {
        return $this->asset('/preview-overlay.js', 'application/javascript; charset=UTF-8');
    }

    private function asset(string $relativePath, string $contentType, ?string $prepend = null): Response
    {
        $content = $this->read($relativePath);

        if ($content === false) {
            return new Response('// asset missing: ' . $relativePath, 500, [
                'Content-Type' => $contentType,
            ]);
        }

        if ($prepend !== null) {
            $head = $this->read($prepend);
            if ($head === false) {
                return new Response('// asset missing: ' . $prepend, 500, [
                    'Content-Type' => $contentType,
                ]);
            }
            $content = $head . "\n" . $content;
        }

        return new Response($content, 200, [
            'Content-Type' => $contentType,
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function read(string $relativePath): string|false
    {
        return @file_get_contents(__DIR__ . self::ASSETS_DIR . $relativePath);
    }
}
