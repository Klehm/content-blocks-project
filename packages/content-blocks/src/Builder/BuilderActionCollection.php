<?php

declare(strict_types=1);

namespace ContentBlocks\Builder;

use ContentBlocks\Entity\ContentArea;

/**
 * Gathers the topbar's Actions menu: every registered provider's contribution,
 * plus whatever the form declared inline, as one ordered list.
 *
 * Ordering is by descending priority, and ties keep the order the actions came
 * in — providers first (in service order), then the form's own entries. A
 * bundle that wants to sit above or below the host's actions says so with a
 * priority rather than by hoping about registration order.
 *
 * Duplicate keys collapse to the first occurrence: a form-level action cannot
 * silently shadow a provider's, and two providers claiming the same key is a
 * wiring mistake that should not render twice.
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
     * @param array<int, array<string, mixed>|BuilderAction> $formActions the `topbar_actions` form option
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
