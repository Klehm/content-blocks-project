<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Builder\BuilderShellFragmentCollection;
use ContentBlocks\Entity\ContentArea;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes a bundle's shell fragments as `cb_shell_fragments(area)`, which the
 * shell template calls itself rather than receiving as a variable.
 *
 * @see docs/internals/builder-extensions.md#why-the-twig-extensions-are-split
 */
final class ShellFragmentsExtension extends AbstractExtension
{
    public function __construct(
        private readonly BuilderShellFragmentCollection $fragments,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_shell_fragments', $this->forArea(...)),
        ];
    }

    /**
     * @return list<BuilderShellFragment>
     */
    public function forArea(ContentArea $area): array
    {
        return $this->fragments->forArea($area);
    }
}
