<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Icon\IconRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the resolved {@see IconRegistry} as `cb_kit_icon()`. The markup is
 * emitted as safe HTML, which is a claim about its sources.
 *
 * @see docs/internals/kit.md#why-views-check-a-token-shape-not-a-value-list
 */
final class IconExtension extends AbstractExtension
{
    public function __construct(private readonly IconRegistry $icons)
    {
    }

    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_kit_icon', $this->icon(...), ['is_safe' => ['html']]),
        ];
    }

    public function icon(string $name, int $size = 24): string
    {
        return $this->icons->svg($name, $size) ?? '';
    }
}
