<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves `kit.css` at a stable **public** route, so a host locking down admin
 * endpoints does not 404 its own front page.
 *
 * @see docs/internals/kit.md#blocks-are-autonomous
 */
final class AssetController
{
    #[Route(
        '/_content-blocks-kit/public/kit',
        name: 'content_blocks_kit_asset_css',
        methods: ['GET'],
    )]
    public function kitCss(): Response
    {
        $path = \dirname(__DIR__, 2) . '/assets/styles/kit.css';
        $body = is_file($path) ? (string) file_get_contents($path) : '';

        return new Response($body, 200, [
            'Content-Type' => 'text/css; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }
}
