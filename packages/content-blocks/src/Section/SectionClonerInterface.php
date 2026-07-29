<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Section;

/**
 * Deep-clones a Section into a detached copy. Used by both the section
 * "duplicate" flow and the area-level "replace with" flow.
 *
 * Override seam: the bundle aliases this to the shipped {@see SectionCloner}.
 * A host that needs to alter what a copy carries (reset a per-section id,
 * renumber anchors, drop a block type…) re-aliases the interface — typically
 * decorating the default via `#[AsDecorator(SectionClonerInterface::class)]`.
 */
interface SectionClonerInterface
{
    /**
     * Contract of the returned copy:
     *  - **unattached** — no ContentArea, and no previewPosition of its own
     *    (columns and blocks keep theirs); the caller decides where it goes;
     *  - **born as a draft** — all mutable state lands in the draft slots, so
     *    the copy shows up as an unpublished change like any other edit;
     *  - **draft wins** — where the source has an in-flight draft, the copy
     *    carries it rather than the last published value;
     *  - **soft-deleted descendants are skipped**.
     *
     * Nothing is persisted or flushed: that is the caller's call.
     */
    public function cloneSection(Section $source): Section;
}
