<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Content;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * A block together with where it sits in the area.
 *
 * The position numbers exist for one reason: a translation list has to name its
 * rows, and "Section 2 · Column 1 · Block 3" is the only name available that
 * does not require understanding the block's contents. They are 1-based because
 * they are shown to humans, and they are computed at walk time rather than
 * stored, since they change whenever anything is reordered.
 */
final class BlockRef
{
    public function __construct(
        public readonly Block $block,
        public readonly Column $column,
        public readonly Section $section,
        public readonly int $sectionNumber,
        public readonly int $columnNumber,
        public readonly int $blockNumber,
    ) {
    }
}
