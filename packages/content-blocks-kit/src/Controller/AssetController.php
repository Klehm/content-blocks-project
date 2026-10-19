<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Controller;

use ContentBlocks\PublicAsset\CssLayer;
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

    /** Null on a core older than the option: served unlayered. */
    public function __construct(
        private readonly ?CssLayer $layer = null,
    ) {
    }

    /** As served: what the route answers, and what its `?v=` hashes. */
    public static function stylesheet(?CssLayer $layer): string
    {
        $css = is_file(self::STYLESHEET) ? (string) file_get_contents(self::STYLESHEET) : '';

        return $layer === null ? $css : $layer->wrap($css);
    }

    #[Route(
        '/_content-blocks-kit/public/kit',
        name: 'content_blocks_kit_asset_css',
        methods: ['GET'],
    )]
    public function kitCss(Request $request): Response
    {
        return StaticAssetResponse::create($request, self::stylesheet($this->layer), 'text/css; charset=UTF-8');
    }
}
