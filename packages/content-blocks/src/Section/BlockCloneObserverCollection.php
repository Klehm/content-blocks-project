<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;

/**
 * Fans a clone notification out to every {@see BlockCloneObserverInterface}.
 * The package registers none of its own, so it is empty by default.
 */
final class BlockCloneObserverCollection
{
    /**
     * @param iterable<BlockCloneObserverInterface> $observers
     */
    public function __construct(
        private readonly iterable $observers,
    ) {
    }

    public function blockCloned(Block $source, Block $copy): void
    {
        foreach ($this->observers as $observer) {
            $observer->blockCloned($source, $copy);
        }
    }
}
