<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Builder\BuilderStructure;
use ContentBlocks\Builder\BuilderStructureResolverInterface;
use ContentBlocks\Entity\ContentArea;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_builder_structure(area)`: what the builder offers on sections and
 * columns, read by the shell and its sidebar.
 *
 * @see docs/guide/builder-structure.md
 */
final class BuilderStructureExtension extends AbstractExtension
{
    public function __construct(
        private readonly BuilderStructureResolverInterface $resolver,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_builder_structure', $this->forArea(...)),
        ];
    }

    public function forArea(ContentArea $area): BuilderStructure
    {
        return $this->resolver->forArea($area);
    }
}
