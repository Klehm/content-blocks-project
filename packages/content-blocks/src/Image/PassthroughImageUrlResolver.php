<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * Default resolver: returns the stored source untouched, with no responsive
 * candidates — i.e. the zero-dependency behavior that predates this seam.
 *
 * It is aliased to {@see ImageUrlResolverInterface} out of the box, so a fresh
 * install renders exactly the markup it always did. Hosts opt into real
 * optimization by aliasing the interface to their own implementation (a CDN URL
 * builder, a LiipImagine bridge…).
 */
final class PassthroughImageUrlResolver implements ImageUrlResolverInterface
{
    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        return new ResolvedImage($src);
    }
}
