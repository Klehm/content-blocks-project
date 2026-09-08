<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * Default resolver: the stored source untouched, no responsive candidates —
 * the zero-dependency behaviour that predates this seam.
 *
 * @see docs/internals/assets.md#the-image-seam-ships-a-passthrough
 */
final class PassthroughImageUrlResolver implements ImageUrlResolverInterface
{
    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage
    {
        return new ResolvedImage($src);
    }
}
