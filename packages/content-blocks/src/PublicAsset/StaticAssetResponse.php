<?php

declare(strict_types=1);

namespace ContentBlocks\PublicAsset;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A served CSS/JS file: ETag from its content, and cached for a year when the
 * URL names that content (`?v=`), briefly otherwise.
 *
 * @see docs/internals/assets.md#caching-the-served-files
 *
 * @internal Shared by the three packages' asset controllers.
 */
final class StaticAssetResponse
{
    public const SHORT_MAX_AGE = 300;
    public const VERSIONED_MAX_AGE = 31536000;

    public static function version(string $content): string
    {
        return substr(hash('xxh128', $content), 0, 12);
    }

    public static function create(Request $request, string $content, string $contentType): Response
    {
        $version = self::version($content);
        $response = new Response($content, 200, [
            'Content-Type' => $contentType,
            'X-Content-Type-Options' => 'nosniff',
        ]);
        $response->setPublic();
        $response->setEtag($version);

        if ($request->query->get('v') === $version) {
            $response->setMaxAge(self::VERSIONED_MAX_AGE);
            $response->headers->addCacheControlDirective('immutable');
        } else {
            $response->setMaxAge(self::SHORT_MAX_AGE);
        }

        $response->isNotModified($request);

        return $response;
    }
}
