<?php

declare(strict_types=1);

namespace ContentBlocks\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the iframe-side overlay JS at a stable URL referenced by
 * BlockRenderer's PREVIEW template. Plain-JS file, no Stimulus.
 */
final class PreviewOverlayController
{
    private const ASSET_PATH = '/../../assets/preview-overlay.js';

    #[Route(
        '/_content-blocks/preview-overlay',
        name: 'content_blocks_preview_overlay',
        methods: ['GET'],
    )]
    public function __invoke(): Response
    {
        $path = __DIR__ . self::ASSET_PATH;
        $content = @file_get_contents($path);

        if ($content === false) {
            return new Response('// preview-overlay.js missing', 500, [
                'Content-Type' => 'application/javascript; charset=UTF-8',
            ]);
        }

        return new Response($content, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            'Cache-Control' => 'public, max-age=300',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
