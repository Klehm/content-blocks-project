<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

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
    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_color_tone', ColorTone::of(...)),
            new TwigFunction('cb_color_is_dark', ColorTone::isDark(...)),
            new TwigFunction('cb_css_color', CssColor::safe(...)),
        ];
    }
}
