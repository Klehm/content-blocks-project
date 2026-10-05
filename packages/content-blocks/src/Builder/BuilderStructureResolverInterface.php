<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Decides the {@see BuilderStructure} of an area. The default reads
 * `content_blocks.structure`; alias it to vary the mode by owning entity.
 *
 * @see docs/guide/builder-structure.md
 */
interface BuilderStructureResolverInterface
{
    public function forArea(ContentArea $area): BuilderStructure;
}
