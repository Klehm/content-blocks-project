<?php

declare(strict_types=1);

namespace ContentBlocks\Ai;

use ContentBlocks\Entity\Block;
use ContentBlocks\Entity\Section;

/**
 * What a generated document left in the draft, and what it lost on the way:
 * the same two facts a paste reports.
 */
final class GenerationResult
{
    /**
     * @param list<Section> $sections
     * @param list<Block>   $blocks            when the area takes blocks only
     * @param list<string>  $skippedBlockTypes
     * @param list<array{
     *     blockType: string,
     *     droppedFields: list<string>,
     * }> $droppedFields
     */
    public function __construct(
        public readonly array $sections,
        public readonly array $blocks,
        public readonly array $skippedBlockTypes,
        public readonly array $droppedFields,
    ) {
    }
}
