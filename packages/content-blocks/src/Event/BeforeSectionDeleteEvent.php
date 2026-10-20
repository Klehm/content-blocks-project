<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * An editor is about to delete a section and everything in it. Refusing it
 * keeps the section and tells the editor why.
 *
 * @see docs/guide/events.md
 */
final class BeforeSectionDeleteEvent extends RefusableEvent
{
    public function __construct(
        public readonly Section $section,
        public readonly ContentArea $area,
    ) {
    }
}
