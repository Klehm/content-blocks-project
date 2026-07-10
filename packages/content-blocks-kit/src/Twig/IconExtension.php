<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Icon\IconSet;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the kit's shipped {@see IconSet} to templates as `cb_kit_icon()`.
 * The SVGs are kit-authored (never user input), so the output is safe HTML.
 */
final class IconExtension extends AbstractExtension
{
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_kit_icon', $this->icon(...), ['is_safe' => ['html']]),
        ];
    }

    public function icon(string $name, int $size = 24): string
    {
        return IconSet::svg($name, $size) ?? '';
    }
}
