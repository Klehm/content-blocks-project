<?php

declare(strict_types=1);

namespace ContentBlocks\Event;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * The nodes an area holds before an insert, so the after event can name the
 * ones the insert created whatever flow wrote them.
 *
 * @internal
 */
final class InsertedContent
{
    /**
     * @param array<int, true> $known object ids of the nodes already there
     */
    private function __construct(
        private readonly array $known,
    ) {
    }

    public static function before(ContentArea $area): self
    {
        $known = [];
        foreach (self::nodes($area) as $node) {
            $known[spl_object_id($node)] = true;
        }

        return new self($known);
    }

    public function after(ContentArea $area, string $origin): AfterContentInsertEvent
    {
        $sections = [];
        $blocks = [];
        foreach (self::nodes($area) as $node) {
            if (isset($this->known[spl_object_id($node)])) {
                continue;
            }
            if ($node instanceof Section) {
                $sections[] = $node;
            } else {
                $blocks[] = $node;
            }
        }

        return new AfterContentInsertEvent($area, $origin, $sections, $blocks);
    }

    /** @return \Generator<Section|Block> */
    private static function nodes(ContentArea $area): \Generator
    {
        foreach ($area->getSections() as $section) {
            yield $section;
            foreach ($section->getColumns() as $column) {
                foreach ($column->getBlocks() as $block) {
                    yield $block;
                }
            }
        }
    }
}
