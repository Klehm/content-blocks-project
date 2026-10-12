<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Palette\ColorPaletteRegistry;
use ContentBlocks\Palette\ColorTone;
use ContentBlocks\Palette\CssColor;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_color_tone(color)`: `dark`, `light` or null, for picking the text
 * colour over a background; `cb_css_color(color)`: the colour if style-safe.
 */
final class ColorToneExtension extends AbstractExtension
{
    public function __construct(
        private readonly ColorPaletteRegistry $palette = new ColorPaletteRegistry(),
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_color_tone', $this->palette->tone(...)),
            new TwigFunction(
                'cb_color_is_dark',
                fn (mixed $color): bool => $this->palette->tone($color) === ColorTone::DARK,
            ),
            new TwigFunction('cb_css_color', CssColor::safe(...)),
        ];
    }
}
