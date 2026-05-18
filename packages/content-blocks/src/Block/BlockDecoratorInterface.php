<?php

declare(strict_types=1);

namespace ContentBlocks\Block;

use ContentBlocks\Entity\Block;

/**
 * Extension point for the block render pipeline — the block-side mirror
 * of {@see \ContentBlocks\Section\SectionDecoratorInterface}.
 *
 * Tag with `content_blocks.block_decorator` (or autoconfigure) and the
 * package's collection will call you for every block being rendered.
 * Return a {@see BlockDecoration} contributing classes, attributes or
 * inline styles based on the block's data.
 *
 * Multiple decorators are merged in service-order.
 */
interface BlockDecoratorInterface
{
    /** @param array<string, mixed> $data effective block data (draft or published) */
    public function decorate(array $data, Block $block): BlockDecoration;
}
