<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Entity\Block;

/**
 * Snapshots a single Block into a self-contained array — the one-level-down
 * counterpart of {@see \ContentBlocks\SectionTemplate\SectionTemplateSerializerInterface},
 * for the copy/paste clipboard.
 *
 * Override seam: the bundle aliases this to the shipped {@see BlockSnapshotSerializer}.
 *
 * Asset references stay plain storage paths, same reasoning as the section
 * serializer: both ends of a copy live in the same app, so the pasted block
 * points at the very same stored file rather than a copy of it.
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
