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
     * Conservative default: full iframe reload.
     *
     * @see docs/internals/blocks.md#preview-hot-reload-is-opt-in
     */
    public function supportsPreviewHotReload(): bool
    {
        return false;
    }
}
