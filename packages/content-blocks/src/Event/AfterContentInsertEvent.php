<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * Content from a payload was written into an area's draft and flushed, with
 * the sections and blocks it created.
 *
 * @see docs/guide/events.md#content-inserted-from-a-payload
 */
final class AfterContentInsertEvent extends Event
{
    /**
     * @param string        $origin   one of the {@see ContentInsertOrigin}
     *      constants
     * @param list<Section> $sections the sections created, `[]` for a block
     * @param list<Block>   $blocks   every block created, inside those
     *      sections or not
     */
    public function __construct(
        public readonly ContentArea $area,
        public readonly string $origin,
        public readonly array $sections,
        public readonly array $blocks,
    ) {
    }
}
