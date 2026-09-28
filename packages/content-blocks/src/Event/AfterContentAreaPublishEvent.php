<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Publishing\PublishContext;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An area's draft was published and flushed: the public page has changed.
 * The place to purge a cache or call a webhook.
 *
 * @see docs/guide/events.md
 */
final class AfterContentAreaPublishEvent extends Event
{
    public function __construct(
        public readonly ContentArea $area,
        public readonly PublishContext $context,
    ) {
    }
}
