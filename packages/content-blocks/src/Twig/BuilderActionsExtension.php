<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Builder\BuilderAction;
use ContentBlocks\Builder\BuilderActionCollection;
use ContentBlocks\Entity\ContentArea;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * Exposes `cb_builder_actions(area, own)`, called by the shell itself so a
 * provider's actions show when a host includes the launcher directly.
 *
 * @see docs/internals/builder-extensions.md#two-halves-of-one-seam
 */
final class BuilderActionsExtension extends AbstractExtension
{
    public function __construct(
        private readonly BuilderActionCollection $actions,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_builder_actions', $this->forArea(...)),
        ];
    }

    /**
     * @param array<int, array<string, mixed>|BuilderAction> $own
     *
     * @return list<BuilderAction>
     */
    public function forArea(ContentArea $area, array $own = []): array
    {
        return $this->actions->forArea($area, $own);
    }
}
