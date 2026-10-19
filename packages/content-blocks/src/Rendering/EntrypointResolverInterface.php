<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\BlockType\BlockAssets;

/**
 * Resolves a bundler entry a block declares (`BlockAssets::$entries`) into the
 * files a page links. Encore's ships; alias it to your own for another.
 *
 * @see docs/internals/rendering.md#css-and-js-a-block-declares
 */
interface EntrypointResolverInterface
{
    /**
     * @return BlockAssets final URLs, linked as given; may leave out what this
     *                     request already linked, as Encore's lookup does
     */
    public function resolve(string $entry): BlockAssets;
}
