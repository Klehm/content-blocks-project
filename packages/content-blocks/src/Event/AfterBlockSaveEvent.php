<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A block's sidebar form wrote its draft data and flushed. Draft only: the
 * public page changes at {@see AfterContentAreaPublishEvent}.
 *
 * @see docs/guide/events.md
 */
final class AfterBlockSaveEvent extends Event
{
    public function __construct(
        public readonly Block $block,
        public readonly ContentArea $area,
    ) {
    }
}
