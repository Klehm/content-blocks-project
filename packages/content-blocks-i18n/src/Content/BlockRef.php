<?php

declare(strict_types=1);

namespace ContentBlocks\I18n\Content;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Column;
use ContentBlocks\Entity\Section;

/**
 * A block plus where it sits, so a translation list can name its rows. 1-based
 * and computed at walk time, since reordering moves them.
 *
 * @see docs/internals/i18n.md#one-walk-one-order
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
