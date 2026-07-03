<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * One named color of the host project's palette. The stored value is the
 * hex string itself (not the label), so decorators and view templates keep
 * reading a plain `#hex` from settings/data — a palette entry and a
 * custom-picked color are indistinguishable at render time.
 */
final class PaletteColor
{
    public function __construct(
        public readonly string $label,
        public readonly string $color,
    ) {
    }
}
