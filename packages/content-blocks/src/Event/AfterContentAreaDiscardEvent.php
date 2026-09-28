<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\PublishContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An area's unpublished changes were dropped and flushed. The public page is
 * unchanged; the draft is back to it.
 *
 * @see docs/guide/events.md
 */
final class AfterContentAreaDiscardEvent extends Event
{
    public function __construct(
        public readonly ContentArea $area,
        public readonly PublishContext $context,
    ) {
    }
}
