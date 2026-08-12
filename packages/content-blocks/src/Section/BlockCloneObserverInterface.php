<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;

/**
 * Told which copy came from which source whenever a section is deep-cloned.
 *
 * ---- Why it exists ----
 *
 * `SectionCloner` copies a block's `data` wholesale, so anything stored *inside*
 * `Block.data` rides along for free. Anything stored **beside** a block —
 * a satellite package's own table keyed by `block_id` — does not, and there was
 * no way to learn about the copy: `cloneSection()` returns the new Section and
 * the source→copy correspondence is discarded inside the walk.
 *
 * Content translation is the first case (per-locale field values live in their
 * own table, and duplicating a section must duplicate them), but the shape is
 * general: per-block analytics, A/B variants, review state.
 *
 * ---- Why an observer rather than a return type ----
 *
 * The obvious alternative — have `cloneSection()` return the correspondence
 * alongside the copy — changes a published interface's signature, which breaks
 * every implementor. A tagged collection is additive: `SectionCloner` gains a
 * constructor dependency, the interface never moves, and an installation with
 * no observers behaves exactly as before.
 *
 * ---- Contract ----
 *
 *  - Called once per copied block, **during** the walk — so `$copy` is not yet
 *    persisted and **has no id**. Attach to the object, not to an identifier.
 *  - `$copy` is persisted by the *caller*, after the walk (see
 *    {@see SectionClonerInterface}). An observer that persists its own rows
 *    against `$copy` must therefore let the caller's flush commit both; do not
 *    flush here.
 *  - Stay cheap and side-effect-light: this runs inside duplicate, paste and
 *    replace-content, all of which are interactive.
 *
 * Tag with `content_blocks.block_clone_observer`, or just implement the
 * interface — it is autoconfigured.
 */
interface BlockCloneObserverInterface
{
    public function blockCloned(Block $source, Block $copy): void;
}
