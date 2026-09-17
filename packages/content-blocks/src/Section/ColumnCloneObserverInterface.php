<?php

declare(strict_types=1);

namespace ContentBlocks\Section;

use ContentBlocks\Entity\Column;

/**
 * Told which column copy came from which source during a deep clone, for
 * anything stored beside a column. Autoconfigured; `$copy` has no id yet.
 *
 * @see docs/internals/rendering.md#why-the-clone-notification-is-an-observer
 */
interface ColumnCloneObserverInterface
{
    public function columnCloned(Column $source, Column $copy): void;
}
