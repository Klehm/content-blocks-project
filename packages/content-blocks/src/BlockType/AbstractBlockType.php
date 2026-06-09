<?php

declare(strict_types=1);

namespace ContentBlocks\BlockType;

/**
 * Base class for block types providing sensible defaults.
 */
abstract class AbstractBlockType implements BlockTypeInterface
{
    /**
     * No icon by default — the picker shows a generic fallback glyph.
     * Override to return inline SVG markup (see BlockTypeInterface::getIcon).
     */
    public static function getIcon(): ?string
    {
        return null;
    }

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
