<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * The same structure for every area, from `content_blocks.structure`.
 */
final class ConfiguredBuilderStructureResolver implements BuilderStructureResolverInterface
{
    private readonly BuilderStructure $structure;

    public function __construct(
        string $structureSections = BuilderStructure::SECTIONS_EDITABLE,
        bool $structureColumns = true,
    ) {
        $this->structure = new BuilderStructure($structureSections, $structureColumns);
    }

    public function forArea(ContentArea $area): BuilderStructure
    {
        return $this->structure;
    }
}
