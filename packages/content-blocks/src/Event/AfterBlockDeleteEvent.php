<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An editor deleted a block: it is flagged deleted in the draft and flushed.
 * The row and the public page stay until Publish.
 *
 * @see docs/guide/events.md
 */
final class AfterBlockDeleteEvent extends Event
{
    public function __construct(
        public readonly Block $block,
        public readonly ContentArea $area,
    ) {
    }
}
