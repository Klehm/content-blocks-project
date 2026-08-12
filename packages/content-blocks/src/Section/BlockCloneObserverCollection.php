<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;

/**
 * Fans a clone notification out to every registered
 * {@see BlockCloneObserverInterface}.
 *
 * An empty collection — the default, since the package registers no observer of
 * its own — makes {@see SectionCloner} behave exactly as it did before the seam
 * existed.
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
