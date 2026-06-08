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

    /**
     * Conservative default: full iframe reload. A wrong full reload only
     * costs a little performance, whereas a wrong hot reload leaves a
     * JS-dependent view broken — so blocks opt in explicitly.
     */
    public function supportsPreviewHotReload(): bool
    {
        return false;
    }
}
