<?php

declare(strict_types=1);

namespace ContentBlocks\Palette;

/**
 * Whether a stored colour reads as dark or light, so text over it can be
 * picked. Judged on its {@see CssColor::swatch()}; without one, no tone.
 *
 * @see docs/guide/styling.md#dark-and-light-backgrounds
 */
final class ColorTone
{
    public const DARK = 'dark';
    public const LIGHT = 'light';

    /** Luminance at and above which a colour counts as light. */
    public const LIGHT_THRESHOLD = 0.55;

    /** Perceived luminance, 0 black to 1 white; null without a swatch. */
    public static function luminance(mixed $color): ?float
    {
        $color = CssColor::swatch($color);
        if ($color === null) {
            return null;
        }

        $hex = substr($color, 1);
        if (\strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        $r = hexdec(substr($hex, 0, 2)) / 255;
        $g = hexdec(substr($hex, 2, 2)) / 255;
        $b = hexdec(substr($hex, 4, 2)) / 255;

        return 0.2126 * $r + 0.7152 * $g + 0.0722 * $b;
    }

    /** `dark`, `light`, or null when the value has no swatch. */
    public static function of(mixed $color): ?string
    {
        $luminance = self::luminance($color);

        return match (true) {
            $luminance === null => null,
            $luminance >= self::LIGHT_THRESHOLD => self::LIGHT,
            default => self::DARK,
        };
    }

    public static function isDark(mixed $color): bool
    {
        return self::of($color) === self::DARK;
    }

    public static function isLight(mixed $color): bool
    {
        return self::of($color) === self::LIGHT;
    }
}
