<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Builder\BuilderShellFragment;
use ContentBlocks\Builder\BuilderShellFragmentCollection;
use ContentBlocks\Entity\ContentArea;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes the shell fragments a bundle contributes to the builder as
 * `cb_shell_fragments(area)`.
 *
 * The shell template calls it itself rather than having the list threaded in
 * as a variable the way `topbarActions` is: a fragment must appear wherever the
 * shell is rendered — through `ContentAreaType` *or* a host's direct include of
 * the launcher — since the whole point is that the host wires nothing.
 *
 * Its own extension rather than a fourth function on {@see ContentBlocksExtension},
 * for the reason {@see ImageExtension} is: it stays instantiable alone in a
 * test, with no renderer or URL resolver to stub.
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
