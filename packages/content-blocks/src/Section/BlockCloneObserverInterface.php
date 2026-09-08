<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Block;

/**
 * Told which copy came from which source when a section is deep-cloned, for
 * anything stored beside a block. Autoconfigured; `$copy` has no id yet.
 *
 * @see docs/internals/rendering.md#why-the-clone-notification-is-an-observer
 */
interface BlockCloneObserverInterface
{
    public function blockCloned(Block $source, Block $copy): void;
}
