<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Every {@see BuilderShellExtensionInterface}'s fragments for one area, as one
 * ordered list. Nothing is deduplicated — fragments carry no key.
 *
 * @see docs/internals/builder-extensions.md#ordering-and-collisions
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
