<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Controller\AssetController;
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
        $css = is_file(AssetController::STYLESHEET)
            ? (string) file_get_contents(AssetController::STYLESHEET)
            : '';

        return $this->urlGenerator->generate('content_blocks_kit_asset_css', [
            'v' => StaticAssetResponse::version($css),
        ]);
    }
}
