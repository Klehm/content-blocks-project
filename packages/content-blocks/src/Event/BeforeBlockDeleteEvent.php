<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;

/**
 * An editor is about to delete a block. Refusing it keeps the block and
 * tells the editor why.
 *
 * @see docs/guide/events.md
 */
final class BeforeBlockDeleteEvent extends RefusableEvent
{
    public function __construct(
        public readonly Block $block,
        public readonly ContentArea $area,
    ) {
    }
}
