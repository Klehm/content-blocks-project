<?php

declare(strict_types=1);

namespace ContentBlocks\Image;

/**
 * Seam between a stored image path and the URLs actually rendered. An
 * implementation must be safe on any input and must never throw.
 *
 * @see docs/internals/assets.md#the-image-seam-ships-a-passthrough
 */
interface ImageUrlResolverInterface
{
    /**
     * @param string   $src    exactly as persisted in the block data
     * @param int|null $width  display width in px, when the view knows one
     * @param int|null $height display height in px, when the view pins one
     */
    public function resolve(string $src, ?int $width = null, ?int $height = null): ResolvedImage;
}
