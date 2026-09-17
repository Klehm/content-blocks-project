<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Column;

/**
 * Fans a column clone notification out to every
 * {@see ColumnCloneObserverInterface}. Empty by default.
 */
final class ColumnCloneObserverCollection
{
    /**
     * @param iterable<ColumnCloneObserverInterface> $observers
     */
    public function __construct(
        private readonly iterable $observers = [],
    ) {
    }

    public function columnCloned(Column $source, Column $copy): void
    {
        foreach ($this->observers as $observer) {
            $observer->columnCloned($source, $copy);
        }
    }
}
