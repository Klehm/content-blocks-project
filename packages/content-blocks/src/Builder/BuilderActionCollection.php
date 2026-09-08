<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Gathers the topbar's Actions menu — every provider's contribution plus the
 * form's own — into one ordered, key-deduplicated list.
 *
 * @see docs/internals/builder-extensions.md#ordering-and-collisions
 */
final class BuilderActionCollection
{
    /**
     * @param iterable<BuilderActionProviderInterface> $providers
     */
    public function __construct(
        private readonly iterable $providers,
    ) {
    }

    /**
     * @param array<int, array<string, mixed>|BuilderAction> $formActions
     *
     * @return list<BuilderAction>
     */
    public function forArea(ContentArea $area, array $formActions = []): array
    {
        /** @var list<BuilderAction> $actions */
        $actions = [];
        foreach ($this->providers as $provider) {
            foreach ($provider->getActions($area) as $action) {
                $actions[] = $action;
            }
        }
        foreach ($formActions as $definition) {
            $actions[] = $definition instanceof BuilderAction
                ? $definition
                : BuilderAction::fromArray($definition);
        }

        $unique = [];
        foreach ($actions as $action) {
            $unique[$action->key] ??= $action;
        }
        $actions = array_values($unique);

        // usort is not stable across every supported PHP build for equal
        // elements, so carry the original index as the tiebreaker.
        $indexed = [];
        foreach ($actions as $i => $action) {
            $indexed[] = [$action, $i];
        }
        usort($indexed, static fn (array $a, array $b) => $b[0]->priority <=> $a[0]->priority ?: $a[1] <=> $b[1]);

        return array_map(static fn (array $pair) => $pair[0], $indexed);
    }
}
