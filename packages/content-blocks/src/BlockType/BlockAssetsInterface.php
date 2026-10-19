<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Optional companion to {@see BlockTypeInterface}: the CSS and JS a block's
 * view needs, linked only on a page that renders the block.
 *
 * @see docs/guide/custom-blocks.md#css-and-js-a-block-needs
 */
interface BlockAssetsInterface
{
    /**
     * @param array<string, mixed> $data resolved, as the view receives it, so
     *                                   a carousel can ask for its JS only
     */
    public function assets(array $data): BlockAssets;
}
