<?php

declare(strict_types=1);

namespace ContentBlocks\PublicAsset;

use Symfony\Component\HttpFoundation\Request;
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
    #[Route('/layout', name: 'content_blocks_asset_layout', methods: ['GET'])]
    public function layoutCss(Request $request): Response
    {
        return $this->serve($request, 'layout');
    }

    #[Route('/styling', name: 'content_blocks_asset_styling', methods: ['GET'])]
    public function stylingCss(Request $request): Response
    {
        return $this->serve($request, 'styling');
    }

    #[Route('/builder', name: 'content_blocks_asset_builder', methods: ['GET'])]
    public function builderCss(Request $request): Response
    {
        return $this->serve($request, 'builder');
    }

    #[Route('/slider', name: 'content_blocks_asset_slider', methods: ['GET'])]
    public function slider(Request $request): Response
    {
        return $this->serve($request, 'slider');
    }

    #[Route(
        '/preview-overlay',
        name: 'content_blocks_asset_preview_overlay',
        methods: ['GET'],
    )]
    public function previewOverlay(Request $request): Response
    {
        return $this->serve($request, 'preview_overlay');
    }

    private function serve(Request $request, string $name): Response
    {
        $content = PackageAssets::content($name);
        $type = PackageAssets::contentType($name);

        if ($content === null) {
            return new Response('/* asset missing: ' . $name . ' */', 500, [
                'Content-Type' => $type,
            ]);
        }

        return StaticAssetResponse::create($request, $content, $type);
    }
}
