<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * An editor deleted a section: it is flagged deleted in the draft and
 * flushed. Its blocks are not flagged one by one; Publish removes them all.
 *
 * @see docs/guide/events.md
 */
final class AfterSectionDeleteEvent extends Event
{
    public function __construct(
        public readonly Section $section,
        public readonly ContentArea $area,
    ) {
    }
}
