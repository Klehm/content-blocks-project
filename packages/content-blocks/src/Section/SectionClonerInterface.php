<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Deep-clones a Section into a detached copy, for both the duplicate and the
 * replace-content flows. Override seam: re-alias to change what a copy carries.
 *
 * @see docs/internals/rendering.md#cloning-a-section
 */
interface SectionClonerInterface
{
    /**
     * The copy comes back unattached, draft-born, draft-wins and pruned of
     * soft-deleted descendants. Nothing is persisted or flushed.
     *
     * @see docs/internals/rendering.md#cloning-a-section
     */
    public function cloneSection(Section $source): Section;
}
