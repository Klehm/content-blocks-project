<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * A section's sidebar form passed validation and `$settings` is about to
 * become its draft. Refusing it keeps the draft and tells the editor why.
 *
 * @see docs/guide/events.md
 */
final class BeforeSectionSaveEvent extends RefusableEvent
{
    /**
     * @param array<string, mixed> $settings the draft settings about to be
     *      written
     */
    public function __construct(
        public readonly Section $section,
        public readonly ContentArea $area,
        public readonly array $settings,
    ) {
    }
}
