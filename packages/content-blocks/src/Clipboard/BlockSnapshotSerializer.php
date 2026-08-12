<?php

declare(strict_types=1);

namespace ContentBlocks\Clipboard;

use ContentBlocks\Entity\Block;

/**
 * Default {@see BlockSnapshotSerializerInterface} — see it for the contract.
 */
final class BlockSnapshotSerializer implements BlockSnapshotSerializerInterface
{
    public function serialize(Block $block): array
    {
        return [
            'format' => self::FORMAT,
            'type' => $block->getType(),
            'data' => $block->getDraftData() ?? $block->getPublishedData() ?? [],
        ];
    }
}
