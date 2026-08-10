<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Optional companion to {@see BlockTypeInterface}: lets a block say what it
 * should look like inside a section-library thumbnail.
 *
 * Optional on purpose. A block type that does not implement this still shows
 * up in the library poster — as a tile bearing its label — so nothing breaks
 * and no existing block needs touching. Implement it when a block owns
 * something worth seeing at thumbnail size: a picture, a heading, a line of
 * copy.
 *
 * The `data` handed over is the raw stored payload from the snapshot, exactly
 * as `Block::getData()` would return it. Two consequences worth remembering:
 *
 *  - It is **untrusted and possibly stale** — written by an older version of
 *    the block, or by a host that has since changed its schema. Read
 *    defensively (null-coalesce, check types) and return
 *    {@see BlockPreviewHint::generic()} rather than assuming a shape.
 *  - No {@see \ContentBlocks\Rendering\BlockDataResolverInterface} has run on
 *    it. A hint sees stored values, not resolved ones.
 */
interface BlockPreviewHintInterface
{
    /**
     * @param array<string, mixed> $data raw stored block data
     *
     * @return BlockPreviewHint|null null is equivalent to
     *                               {@see BlockPreviewHint::generic()} — use it
     *                               when this particular data has nothing to show
     */
    public function previewHint(array $data): ?BlockPreviewHint;
}
