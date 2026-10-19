<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Controller\AssetController;
use ContentBlocks\PublicAsset\CssLayer;
use ContentBlocks\PublicAsset\StaticAssetResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_kit_stylesheet_url()`: the kit.css URL carrying its content hash, which
 * the route answers with a year of cache.
 */
final class StylesheetExtension extends AbstractExtension
{
    public function __construct(
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly ?CssLayer $layer = null,
    ) {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_kit_stylesheet_url', $this->stylesheetUrl(...)),
        ];
    }

    public function stylesheetUrl(): string
    {
        return $this->urlGenerator->generate('content_blocks_kit_asset_css', [
            'v' => StaticAssetResponse::version(AssetController::stylesheet($this->layer)),
        ]);
    }
}
