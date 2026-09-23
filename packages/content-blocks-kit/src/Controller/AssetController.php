<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Controller;

use ContentBlocks\PublicAsset\StaticAssetResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves `kit.css` at a stable **public** route, so a host locking down admin
 * endpoints does not 404 its own front page.
 *
 * @see docs/internals/kit.md#blocks-are-autonomous
 *
 * @internal The route is the contract, not this class.
 */
final class AssetController
{
    public const STYLESHEET = __DIR__ . '/../../assets/styles/kit.css';

    #[Route(
        '/_content-blocks-kit/public/kit',
        name: 'content_blocks_kit_asset_css',
        methods: ['GET'],
    )]
    public function kitCss(Request $request): Response
    {
        $body = is_file(self::STYLESHEET) ? (string) file_get_contents(self::STYLESHEET) : '';

        return StaticAssetResponse::create($request, $body, 'text/css; charset=UTF-8');
    }
}
