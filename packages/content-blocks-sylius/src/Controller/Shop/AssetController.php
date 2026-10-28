<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Controller\Shop;

use ContentBlocks\PublicAsset\CssLayer;
use ContentBlocks\PublicAsset\StaticAssetResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the plugin's shop stylesheet at a public route, in the core's
 * cascade layer when the host set one.
 */
final class AssetController
{
    public const STYLESHEET = __DIR__ . '/../../../assets/styles/shop.css';

    public function __construct(private readonly CssLayer $layer)
    {
    }

    /** As served: what the route answers, and what its `?v=` hashes. */
    public static function stylesheet(CssLayer $layer): string
    {
        return $layer->wrap((string) file_get_contents(self::STYLESHEET));
    }

    public function __invoke(Request $request): Response
    {
        return StaticAssetResponse::create($request, self::stylesheet($this->layer), 'text/css; charset=UTF-8');
    }
}
