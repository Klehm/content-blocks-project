<?php

declare(strict_types=1);

namespace ContentBlocks\Twig;

use ContentBlocks\Publishing\UnpublishedChanges;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

/**
 * `cb_has_unpublished_changes(area)`: the area's draft or a bundle's beside
 * it, where `area.hasUnpublishedChanges` sees the first only.
 */
final class UnpublishedChangesExtension extends AbstractExtension
{
    public function __construct(
        private readonly UnpublishedChanges $changes,
    ) {
    }

    /**
     * @return list<TwigFunction>
     */
    public function getFunctions(): array
    {
        return [
            new TwigFunction('cb_has_unpublished_changes', $this->changes->of(...)),
        ];
    }
}
