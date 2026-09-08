<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Optional companion to {@see BlockTypeInterface}: what a block should look
 * like in a section-library thumbnail. Not implementing it is fine.
 *
 * @see docs/internals/blocks.md#preview-hints-and-why-they-stay-tiny
 */
interface BlockPreviewHintInterface
{
    /**
     * @param array<string, mixed> $data raw stored, unresolved, read it
     *                                   defensively
     *
     * @return BlockPreviewHint|null null is {@see BlockPreviewHint::generic()}
     */
    public function previewHint(array $data): ?BlockPreviewHint;
}
