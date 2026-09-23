<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * A stored colour, fit to be written in a `style` attribute: one colour value
 * — hex, a name, `rgb()`/`hsl()`/`var()` — never a second declaration.
 */
final class CssColor
{
    public static function safe(mixed $color): ?string
    {
        if (!\is_string($color)) {
            return null;
        }
        $color = trim($color);
        if ($color === '' || preg_match('/^[#a-z0-9(),.%\s-]+$/i', $color) !== 1) {
            return null;
        }
        // Allowed characters still spell a fetch or a legacy IE script.
        if (preg_match('/url|image|expression|element/i', $color) === 1) {
            return null;
        }

        return $color;
    }
}
