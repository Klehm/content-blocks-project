<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\PublishContext;

/**
 * An area's unpublished changes are about to be dropped. Refusing it makes
 * the publisher throw {@see ActionRefusedException}.
 *
 * @see docs/guide/events.md
 */
final class BeforeContentAreaDiscardEvent extends RefusableEvent
{
    public function __construct(
        public readonly ContentArea $area,
        public readonly PublishContext $context,
    ) {
    }
}
