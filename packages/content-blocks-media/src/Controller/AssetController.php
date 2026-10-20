<?php

declare(strict_types=1);

namespace ContentBlocks\Media\Controller;

use ContentBlocks\PublicAsset\StaticAssetResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Serves the picker's stylesheet and script: static files, no access check.
 * Served here, so a host declares no Stimulus controller and rebuilds nothing.
 */
final class AssetController
{
    public const FILES = [
        'css' => ['picker.css', 'text/css; charset=UTF-8'],
        'js' => ['picker.js', 'text/javascript; charset=UTF-8'],
    ];

    // Extension kept out of the route: PHP's dev server shortcuts anything
    // that looks like a static file.
    #[Route('/asset/picker-{type}', name: 'content_blocks_media_asset', requirements: ['type' => 'css|js'], methods: ['GET'])]
    public function serve(Request $request, string $type): Response
    {
        [$file, $contentType] = self::FILES[$type];

        return StaticAssetResponse::create($request, self::read($file), $contentType);
    }

    public static function version(string $type): string
    {
        return StaticAssetResponse::version(self::read(self::FILES[$type][0]));
    }

    private static function read(string $file): string
    {
        $path = \dirname(__DIR__, 2) . '/assets/' . $file;

        return is_file($path) ? (string) file_get_contents($path) : '';
    }
}
