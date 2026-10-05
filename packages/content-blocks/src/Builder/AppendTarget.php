<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Content\ContentManipulationException;
use ContentBlocks\Content\ContentManipulatorInterface;
use ContentBlocks\Content\DraftOrder;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\ContentArea;
use ContentBlocks\Entity\Section;

/**
 * The column a block appended "at the end of the area" lands in: the first
 * column of the last section, or of a new full section when there is none.
 *
 * @internal
 */
final class AppendTarget
{
    /** Null when the area is empty and no section may be created. */
    public static function column(
        ?ContentManipulatorInterface $content,
        ContentArea $area,
        BuilderStructure $structure,
    ): ?Column {
        $sections = DraftOrder::sections($area);
        $last = $sections === [] ? null : $sections[array_key_last($sections)];
        if ($last !== null) {
            return DraftOrder::columns($last)[0] ?? null;
        }

        if ($content === null || $structure->sections === BuilderStructure::SECTIONS_FIXED) {
            return null;
        }

        try {
            $section = $content->addSection($area, Section::LAYOUT_FULL);
        } catch (ContentManipulationException) {
            return null;
        }

        $first = $section->getColumns()->first();

        return $first instanceof Column ? $first : null;
    }
}
