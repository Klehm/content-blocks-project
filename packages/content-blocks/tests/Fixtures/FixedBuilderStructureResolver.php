<?php

declare(strict_types=1);

namespace ContentBlocks\Tests\Fixtures;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Entity\ContentArea;

/** One structure for every area, whatever the config says. */
final class FixedBuilderStructureResolver implements BuilderStructureResolverInterface
{
    public function __construct(
        private readonly BuilderStructure $structure,
    ) {
    }

    public function forArea(ContentArea $area): BuilderStructure
    {
        return $this->structure;
    }
}
