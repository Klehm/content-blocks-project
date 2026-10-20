<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * A section's settings were written to its draft and flushed. The public
 * page keeps the published settings until Publish.
 *
 * @see docs/guide/events.md
 */
final class AfterSectionSaveEvent extends Event
{
    public function __construct(
        public readonly Section $section,
        public readonly ContentArea $area,
    ) {
    }
}
