<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * One named color, stored by `color`, not by label. `swatch` is the hex the
 * builder shows for a value it cannot read itself, such as `var(--brand)`.
 *
 * @see docs/guide/styling.md#colours-from-a-back-office
 */
final class PaletteColor
{
    public function __construct(
        public readonly string $label,
        public readonly string $color,
        public readonly ?string $swatch = null,
    ) {
    }
}
