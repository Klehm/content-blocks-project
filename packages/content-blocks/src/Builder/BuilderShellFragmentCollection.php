<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Gathers every registered {@see BuilderShellExtensionInterface}'s fragments
 * for one area, as one ordered list.
 *
 * Ordering is by descending priority, and ties keep the order the fragments
 * came in (extensions in service order, each one's fragments in the order it
 * yielded them). Same rule as {@see BuilderActionCollection}: a bundle that
 * needs to render before another says so with a priority rather than by hoping
 * about registration order.
 *
 * Nothing is deduplicated. Fragments carry no key — two extensions rendering
 * the same template is unusual but not a conflict the way two menu entries
 * sharing a key would be.
 */
final class BuilderShellFragmentCollection
{
    /**
     * @param iterable<BuilderShellExtensionInterface> $extensions
     */
    public function __construct(
        private readonly iterable $extensions,
    ) {
    }

    /**
     * @return list<BuilderShellFragment>
     */
    public function forArea(ContentArea $area): array
    {
        $indexed = [];
        $i = 0;
        foreach ($this->extensions as $extension) {
            foreach ($extension->getFragments($area) as $fragment) {
                // usort is not stable across every supported PHP build for
                // equal elements, so carry the original index as tiebreaker.
                $indexed[] = [$fragment, $i++];
            }
        }

        usort($indexed, static fn (array $a, array $b) => $b[0]->priority <=> $a[0]->priority ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $pair) => $pair[0], $indexed);
    }
}
