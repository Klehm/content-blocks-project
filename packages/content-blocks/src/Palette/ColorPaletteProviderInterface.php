<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * The project palette behind `PaletteColorType`. Autoconfigured — though the
 * simplest setup needs no PHP at all, just `content_blocks.palette`.
 *
 * @see docs/internals/forms.md#palettecolortype-and-its-empty-state
 */
interface ColorPaletteProviderInterface
{
    /**
     * @return iterable<PaletteColor>
     */
    public function getColors(): iterable;
}
