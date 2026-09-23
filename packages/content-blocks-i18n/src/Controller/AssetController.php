<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Controller;

use ContentBlocks\PublicAsset\StaticAssetResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the workbench stylesheet and script. Read-only static files, so no
 * CSRF and no access check — nothing here is not already public in the source.
 *
 * @see docs/internals/i18n.md#why-no-stimulus-controller
 */
final class AssetController
{
    #[Route(
        '/asset/workbench-css',
        name: 'content_blocks_i18n_asset_css',
        methods: ['GET'],
    )]
    public function css(Request $request): Response
    {
        return $this->serve($request, 'workbench.css', 'text/css; charset=UTF-8');
    }

    #[Route(
        '/asset/workbench-js',
        name: 'content_blocks_i18n_asset_js',
        methods: ['GET'],
    )]
    public function js(Request $request): Response
    {
        return $this->serve($request, 'workbench.js', 'text/javascript; charset=UTF-8');
    }

    /** The workbench page links the files by this version. */
    public static function version(string $file): string
    {
        return StaticAssetResponse::version(self::read($file));
    }

    private function serve(Request $request, string $file, string $contentType): Response
    {
        return StaticAssetResponse::create($request, self::read($file), $contentType);
    }

    // Extension kept out of the route: PHP's dev server shortcuts anything
    // that looks like a static file.
    private static function read(string $file): string
    {
        $path = \dirname(__DIR__, 2) . '/assets/' . $file;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
