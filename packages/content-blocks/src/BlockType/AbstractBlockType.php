<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Base class for block types providing sensible defaults.
 */
abstract class AbstractBlockType implements BlockTypeInterface
{
    public function getFormTheme(): ?string
    {
        return null;
    }

    public function getViewTemplate(): ?string
    {
        return null;
    }
}
