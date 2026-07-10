<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Controller;

use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the kit's self-contained front stylesheet at a stable, public URL.
 *
 * Kit blocks render on the host's front page (and inside the builder preview
 * iframe) with neutral `cb-kit-*` classes; this stylesheet gives them their
 * look with zero framework dependency. Include it once in the host layout:
 *
 *     <link rel="stylesheet" href="{{ path('content_blocks_kit_asset_css') }}">
 *
 * The route lives under `/_content-blocks-kit/public/*` (not the admin
 * namespace) so hosts that lock down admin endpoints don't 404 the CSS in the
 * public preview. The `.css` extension is omitted so PHP's built-in dev server
 * doesn't shortcut the router; the Content-Type header carries the MIME type.
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
