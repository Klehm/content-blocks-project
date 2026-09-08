<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Entity\Block;

/**
 * Snapshots one Block into a self-contained array for the clipboard. Asset
 * references stay plain storage paths.
 *
 * @see docs/internals/clipboard.md#replay-and-placement
 */
interface BlockSnapshotSerializerInterface
{
    /** Identifier written to the payload's `format` key. */
    public const FORMAT = 'content-blocks/block-v1';

    /**
     * Draft state takes precedence over published state — an editor copying a
     * block means the one on screen, not the one visitors last saw.
     *
     * @return array<string, mixed>
     */
    public function serialize(Block $block): array;
}
