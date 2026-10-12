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

    /**
     * The hex a colour can be previewed as outside the host's page: itself, or
     * the fallback of `var(--x, #hex)`. Null when no hex is to be found.
     *
     * @see docs/guide/styling.md#theme-variables-in-the-palette
     */
    public static function swatch(mixed $color): ?string
    {
        $color = self::safe($color);
        if ($color === null) {
            return null;
        }
        if (preg_match('/^#(?:[0-9a-f]{3}|[0-9a-f]{6})$/i', $color) === 1) {
            return $color;
        }
        if (preg_match('/^var\(\s*--[\w-]+\s*,(.*)\)$/is', $color, $m) === 1) {
            return self::swatch($m[1]);
        }

        return null;
    }
}
