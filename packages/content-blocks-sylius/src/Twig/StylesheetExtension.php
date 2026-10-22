<?php

declare(strict_types=1);

namespace ContentBlocks\Sylius\Twig;

use ContentBlocks\PublicAsset\CssLayer;
use ContentBlocks\PublicAsset\StaticAssetResponse;
use ContentBlocks\Sylius\Controller\Shop\AssetController;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/** `cb_sylius_stylesheet_url()`: the shop stylesheet, versioned by content. */
final class StylesheetExtension extends AbstractExtension
{
    public const ROUTE = 'content_blocks_sylius_asset_css';

    public function __construct(
        private readonly UrlGeneratorInterface $urls,
        private readonly CssLayer $layer,
    ) {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('cb_sylius_stylesheet_url', $this->url(...))];
    }

    public function url(): string
    {
        $version = StaticAssetResponse::version(AssetController::stylesheet($this->layer));

        return $this->urls->generate(self::ROUTE, ['v' => $version]);
    }
}
