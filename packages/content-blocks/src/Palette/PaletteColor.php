<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * One named color. The stored value is the hex, not the label, so a palette
 * entry and a custom-picked color are indistinguishable at render time.
 */
final class PaletteColor
{
    public function __construct(
        public readonly string $label,
        public readonly string $color,
    ) {
    }
}
