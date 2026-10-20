<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\ContentArea;

/**
 * Content from a payload is about to be written into an area's draft: a
 * paste, a section template, an import or *Insert content*. Refusable.
 *
 * @see docs/guide/events.md#content-inserted-from-a-payload
 */
final class BeforeContentInsertEvent extends RefusableEvent
{
    /**
     * @param string $origin one of the {@see ContentInsertOrigin} constants
     */
    public function __construct(
        public readonly ContentArea $area,
        public readonly string $origin,
    ) {
    }
}
