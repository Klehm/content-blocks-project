<?php

declare(strict_types=1);

namespace ContentBlocks\Kit\Twig;

use ContentBlocks\Kit\Icon\IconRegistry;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the resolved {@see IconRegistry} to templates as `cb_kit_icon()` —
 * the kit's shipped glyphs plus any the host contributed through
 * {@see \ContentBlocks\Kit\Icon\IconProviderInterface}.
 *
 * The markup is emitted as safe HTML. That is a statement about the *wrapper*,
 * which this code writes, and about the shipped glyphs, which are kit-authored;
 * the inner markup of a contributed icon is trusted the same way a host's own
 * template is — it comes from their PHP, not from an editor's input.
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
