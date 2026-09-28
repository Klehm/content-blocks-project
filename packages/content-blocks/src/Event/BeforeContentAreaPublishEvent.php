<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\PublishContext;

/**
 * An area is about to be published; nothing is written yet. Refusing it
 * makes the publisher throw {@see ActionRefusedException}.
 *
 * @see docs/guide/events.md
 */
final class BeforeContentAreaPublishEvent extends RefusableEvent
{
    public function __construct(
        public readonly ContentArea $area,
        public readonly PublishContext $context,
    ) {
    }
}
