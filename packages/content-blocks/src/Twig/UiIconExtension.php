<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Icon\UiIconRegistry;
use Twig\Extension\AbstractExtension;
use Twig\Markup;
use Twig\TwigFunction;

/**
 * `cb_ui_icon(name)`: a builder control icon from {@see UiIconRegistry}, as
 * inline SVG — an empty string for a name no provider knows.
 */
final class UiIconExtension extends AbstractExtension
{
    public function __construct(
        private readonly UiIconRegistry $icons,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_ui_icon', [$this, 'icon'], ['is_safe' => ['html']]),
        ];
    }

    public function icon(string $name): Markup
    {
        return new Markup($this->icons->svg($name) ?? '', 'UTF-8');
    }
}
