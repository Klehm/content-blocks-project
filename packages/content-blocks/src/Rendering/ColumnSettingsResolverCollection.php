<?php

declare(strict_types=1);

namespace ContentBlocks\Rendering;

use ContentBlocks\Entity\Column;

/**
 * Threads a column's settings through every
 * {@see ColumnSettingsResolverInterface}. Empty by default.
 */
final class ColumnSettingsResolverCollection
{
    /**
     * @param iterable<ColumnSettingsResolverInterface> $resolvers
     */
    public function __construct(
        private readonly iterable $resolvers = [],
    ) {
    }

    /**
     * @param array<string, mixed> $settings
     *
     * @return array<string, mixed>
     */
    public function resolve(Column $column, RenderContext $context, array $settings): array
    {
        foreach ($this->resolvers as $resolver) {
            $settings = $resolver->resolve($column, $context, $settings);
        }

        return $settings;
    }
}
